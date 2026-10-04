<?php
/**
 * Media Scanner: detecteert ongebruikte afbeeldingen in de mediabibliotheek
 * en verplaatst ze veilig (terugzetbaar) naar de prullenbak.
 *
 * Werkwijze: bouwt een referentie-index uit alle plekken waar een afbeelding
 * gebruikt kan worden (uitgelichte afbeelding, WooCommerce-galerij, WPClever
 * variatie-foto's, content-afbeeldingen via ID en URL, logo/site-icon/customizer).
 * Alles wat niet in die index zit is een wees. Verwijderen gebeurt via de
 * prullenbak (wp_trash_post) — bestanden blijven op schijf tot definitief wissen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Media_Scanner {

	/** Option waarin het laatste scanresultaat + wees-ID-lijst staat. */
	const SCAN_OPT = 'mcm_media_scan';

	/** Postmeta-markering op afbeeldingen die door deze tool zijn geprulld. */
	const TRASH_META = '_mcm_media_trashed';

	/** Aantal items per AJAX-batch. */
	const BATCH = 20;

	public function __construct() {
		add_action( 'wp_ajax_mcm_media_scan',    [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_mcm_media_live',    [ $this, 'ajax_live' ] );
		add_action( 'wp_ajax_mcm_media_trash',   [ $this, 'ajax_trash' ] );
		add_action( 'wp_ajax_mcm_media_restore', [ $this, 'ajax_restore' ] );
		add_action( 'wp_ajax_mcm_media_purge',   [ $this, 'ajax_purge' ] );

		add_action( 'mcm_optimizer_render_cards', [ $this, 'render_card' ] );
		add_action( 'admin_enqueue_scripts',      [ $this, 'assets' ] );
	}

	/* ---------------------------------------------------------------
	 * Referentie-index
	 * ------------------------------------------------------------- */

	/**
	 * Bouwt de verzameling van afbeeldingen die ergens gebruikt worden.
	 *
	 * Bestanden worden op PAD vergeleken (2026/02/foto.jpg), niet alleen op
	 * bestandsnaam: anders verbergt een dubbele upload (zelfde naam, andere map)
	 * een echte wees, of omgekeerd. 'bases' telt bestandsnamen als vangnet voor
	 * verwijzingen met een afwijkend pad.
	 *
	 * @return array{ids: array<int,int>, paths: array<string,int>, bases: array<string,int>}
	 */
	private static function build_reference_index() {
		global $wpdb;

		$ids   = [];
		$paths = [];
		$bases = [];

		// Uitgelichte afbeeldingen (alle posttypes).
		foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value > 0" ) as $v ) {
			$ids[ (int) $v ] = 1;
		}

		// WPClever variatie-foto's.
		foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'woovr_image_id' AND meta_value > 0" ) as $v ) {
			$ids[ (int) $v ] = 1;
		}

		// WooCommerce product-galerijen (komma-gescheiden).
		foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value <> ''" ) as $g ) {
			foreach ( explode( ',', (string) $g ) as $x ) {
				$x = (int) trim( $x );
				if ( $x ) {
					$ids[ $x ] = 1;
				}
			}
		}

		// Logo, site-icon en numerieke customizer-waarden.
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( $logo ) {
			$ids[ $logo ] = 1;
		}
		$icon = (int) get_option( 'site_icon' );
		if ( $icon ) {
			$ids[ $icon ] = 1;
		}
		foreach ( (array) get_theme_mods() as $mv ) {
			if ( is_numeric( $mv ) ) {
				$ids[ (int) $mv ] = 1;
			}
		}

		// Afbeeldingen die in post-content staan: via wp-image-ID, via
		// shortcode-ID's, en via /uploads/-URL's (op bestandsnaam gematcht).
		// In BATCHES lezen, en revisies overslaan. Zonder deze twee dingen
		// haalde deze query op een Avada-site 47,6 MB aan post_content in één
		// keer op (1.782 rijen, waarvan 1.361 revisies = 41,5 MB) en liep de
		// scan na 25 minuten nog steeds. Zonder revisies blijft er 6,1 MB over.
		//
		// Let op: staat een afbeelding ALLEEN in een oude revisie, dan geldt
		// hij nu als ongebruikt. De scanner gooit hem in de prullenbak (niet
		// weg), dus dat is terug te draaien. Wil je revisies tóch meenemen:
		// add_filter( 'mcm_optimizer_scan_revisions', '__return_true' );
		// Zoeken op de NAAM van de uploadmap zonder slashes: page builders slaan
		// URL's JSON-ge-escapet op (https:\/\/…\/uploads\/…), en een site kan een
		// eigen uploadpad hebben (UPLOADS-constante). Het eigenlijke matchen
		// gebeurt daarna in collect_upload_urls().
		$like_seg  = '%' . $wpdb->esc_like( self::upload_segment() ) . '%';
		$type_skip = '';
		if ( ! apply_filters( 'mcm_optimizer_scan_revisions', false ) ) {
			$type_skip = " AND post_type NOT IN ( 'revision', 'customize_changeset', 'oembed_cache' )
			               AND post_status NOT IN ( 'auto-draft', 'trash' )";
		}

		$batch   = max( 20, (int) apply_filters( 'mcm_optimizer_scan_batch_size', 200 ) );
		$laatste = 0;

		do {
			$rijen = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts}
					 WHERE ( post_content LIKE %s OR post_content LIKE %s ) {$type_skip} AND ID > %d
					 ORDER BY ID ASC LIMIT %d",
					'%wp-image-%',
					$like_seg,
					$laatste,
					$batch
				)
			);

			foreach ( $rijen as $rij ) {
				$laatste = (int) $rij->ID;
				$c       = (string) $rij->post_content;
			if ( preg_match_all( '/wp-image-(\d+)/', $c, $m ) ) {
				foreach ( $m[1] as $x ) {
					$ids[ (int) $x ] = 1;
				}
			}
			// Bijlage-ID's in shortcode-attributen (Avada image_id, galerijen,
			// WPBakery image="123" / images="1,2"). Alleen numerieke waarden.
			if ( preg_match_all( '/\b(?:image_id|attachment_id|ids|image|images|img|bg_image|background_image_id)=["\']?(\d[0-9|,\s]*)/', $c, $m ) ) {
				foreach ( $m[1] as $list ) {
					foreach ( preg_split( '/[|,\s]+/', $list ) as $x ) {
						$x = (int) $x;
						if ( $x ) {
							$ids[ $x ] = 1;
						}
					}
				}
			}
			self::collect_upload_urls( $c, $paths, $bases );
			}

			$aantal = count( $rijen );
			unset( $rijen );
		} while ( $aantal === $batch );

		// Opties: Avada Global Options (logo's, favicons, achtergronden van
		// paginatitel/footer/404), widgets, theme-mods van elk thema, en alles van
		// andere plugins dat een /uploads/-URL of een Avada-mediaveld (url + id)
		// bevat. Aanleiding: op pensioenfonds-sagittarius.nl stond de favicon
		// alleen in fusion_options — de scanner zette hem bij de wezen.
		// In porties op option_id: optie-blobs van andere plugins kunnen groot zijn.
		$laatste = 0;
		do {
			$rijen = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_value FROM {$wpdb->options}
					 WHERE option_id > %d AND option_value LIKE %s
					   AND option_name NOT LIKE %s AND option_name NOT LIKE %s
					   AND option_name NOT LIKE %s AND option_name NOT LIKE %s
					 ORDER BY option_id ASC LIMIT %d",
					$laatste,
					$like_seg,
					$wpdb->esc_like( '_transient' ) . '%',
					$wpdb->esc_like( '_site_transient' ) . '%',
					$wpdb->esc_like( 'wpvivid' ) . '%',
					$wpdb->esc_like( 'mcm_' ) . '%',
					$batch
				)
			);
			foreach ( $rijen as $rij ) {
				$laatste = (int) $rij->option_id;
				self::walk_value( maybe_unserialize( $rij->option_value ), $ids, $paths, $bases );
			}
			$aantal = count( $rijen );
			unset( $rijen );
		} while ( $aantal === $batch );

		// Term- en gebruikersmeta: categorie-afbeeldingen (WooCommerce
		// thumbnail_id), merk-logo's, avatars — als ID of als URL.
		$id_keys = (array) apply_filters( 'mcm_optimizer_media_id_meta_keys', [ 'thumbnail_id' ] );
		if ( $id_keys ) {
			$in = implode( ',', array_fill( 0, count( $id_keys ), '%s' ) );
			foreach ( [ $wpdb->termmeta, $wpdb->postmeta ] as $table ) {
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL
				foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE meta_key IN ($in) AND meta_value > 0", $id_keys ) ) as $v ) {
					$ids[ (int) $v ] = 1;
				}
			}
		}
		foreach ( [ $wpdb->termmeta, $wpdb->usermeta ] as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE meta_value LIKE %s", $like_seg ) ) as $v ) {
				self::walk_value( maybe_unserialize( $v ), $ids, $paths, $bases );
			}
		}

		// Postmeta van niet-bijlagen: Avada pagina-opties (_fusion), menu-
		// afbeeldingen (megamenu), sliders, page builders. Bijlage-meta en
		// back-up-administratie (WPvivid) tellen niet als "gebruik".
		$laatste = 0;
		do {
			$rijen = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_id, pm.meta_value FROM {$wpdb->postmeta} pm
					 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_id > %d
					   AND pm.meta_value LIKE %s
					   AND p.post_type NOT IN ( 'attachment', 'revision' )
					   AND pm.meta_key NOT LIKE %s
					   AND pm.meta_key NOT LIKE %s
					 ORDER BY pm.meta_id ASC LIMIT %d",
					$laatste,
					$like_seg,
					$wpdb->esc_like( 'wpvivid' ) . '%',
					$wpdb->esc_like( '_mcm' ) . '%',
					$batch
				)
			);
			foreach ( $rijen as $rij ) {
				$laatste = (int) $rij->meta_id;
				self::walk_value( maybe_unserialize( $rij->meta_value ), $ids, $paths, $bases );
			}
			$aantal = count( $rijen );
			unset( $rijen );
		} while ( $aantal === $batch );

		// Avada-mediavelden met alleen een ID (url leeg) in de Global Options.
		$fo = get_option( 'fusion_options' );
		if ( is_array( $fo ) ) {
			self::walk_value( $fo, $ids, $paths, $bases );
		}

		return [ 'ids' => $ids, 'paths' => $paths, 'bases' => $bases ];
	}

	/**
	 * Loopt een (geneste) waarde door: /uploads/-URL's in strings, en Avada-
	 * mediavelden ['url' => …, 'id' => 123] als bijlage-ID.
	 */
	private static function walk_value( $v, array &$ids, array &$paths, array &$bases, $depth = 0 ) {
		if ( $depth > 12 ) {
			return;
		}
		if ( is_object( $v ) ) {
			$v = get_object_vars( $v );
		}
		if ( is_array( $v ) ) {
			if ( isset( $v['id'] ) && is_numeric( $v['id'] ) && (int) $v['id'] > 0 && array_key_exists( 'url', $v ) ) {
				$ids[ (int) $v['id'] ] = 1;
			}
			foreach ( $v as $x ) {
				self::walk_value( $x, $ids, $paths, $bases, $depth + 1 );
			}
			return;
		}
		if ( is_string( $v ) && false !== stripos( $v, self::upload_segment() ) ) {
			if ( is_serialized( $v ) ) {
				self::walk_value( maybe_unserialize( $v ), $ids, $paths, $bases, $depth + 1 );
				return;
			}
			self::collect_upload_urls( $v, $paths, $bases );
		}
	}

	/** Naam van de uploadmap in URL's: meestal "uploads", anders bv. "media" (UPLOADS-constante). */
	private static function upload_segment() {
		static $seg = null;
		if ( null === $seg ) {
			$dir  = wp_upload_dir( null, false );
			$path = trim( (string) wp_parse_url( (string) ( $dir['baseurl'] ?? '' ), PHP_URL_PATH ), '/' );
			$seg  = '' !== $path ? strtolower( basename( $path ) ) : 'uploads';
		}
		return $seg;
	}

	/** Ankers waarachter het pad relatief aan de uploadmap begint. */
	private static function upload_anchors() {
		$a = [ '/uploads/' ];
		if ( 'uploads' !== self::upload_segment() ) {
			$a[] = '/' . self::upload_segment() . '/';
		}
		return $a;
	}

	/** Haalt upload-URL's uit tekst en voegt hun genormaliseerde pad toe. */
	private static function collect_upload_urls( $text, array &$paths, array &$bases ) {
		// Ook JSON-ge-escapete slashes (\/uploads\/) van page builders.
		$text    = str_replace( '\\/', '/', (string) $text );
		$anchors = implode( '|', array_map( static function ( $a ) {
			return preg_quote( $a, '#' );
		}, self::upload_anchors() ) );
		if ( preg_match_all( '#(?:' . $anchors . ')[^"\'\s)<>]+?\.(?:jpe?g|png|gif|webp|svg|avif|ico|bmp)(?:\.webp)?#i', $text, $m ) ) {
			foreach ( $m[0] as $u ) {
				$p = self::normalize_path( $u );
				if ( '' !== $p ) {
					$paths[ $p ]              = 1;
					$bases[ basename( $p ) ] = 1;
				}
			}
		}
	}

	/**
	 * Maakt van een URL of bijlagepad een vergelijkbaar pad relatief aan uploads:
	 * kleine letters, zonder formaat (-300x200), -scaled, een extra .webp of het
	 * multisite-voorvoegsel sites/N/.
	 */
	public static function normalize_path( $s ) {
		$s   = strtolower( rawurldecode( (string) $s ) );
		$s   = preg_replace( '/[?#].*$/', '', $s );
		$pos = false;
		$len = 0;
		foreach ( self::upload_anchors() as $a ) {
			$p = strrpos( $s, $a );
			if ( false !== $p && ( false === $pos || $p > $pos ) ) {
				$pos = $p;
				$len = strlen( $a );
			}
		}
		if ( false !== $pos ) {
			$s = substr( $s, $pos + $len );
		}
		$s = ltrim( $s, '/' );
		$s = preg_replace( '#^sites/\d+/#', '', $s );                        // multisite-subsite
		$s = preg_replace( '/\.(jpe?g|png|gif)\.webp$/', '.$1', $s );       // foto.jpg.webp → foto.jpg
		$s = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/', '', $s );          // -300x200
		$s = preg_replace( '/-scaled(?=\.[a-z0-9]+$)/', '', $s );           // -scaled
		return $s;
	}

	/**
	 * Snelle her-controle vlak voor het prullen: zit de afbeelding intussen
	 * tóch weer in een uitgelichte afbeelding, variatie-foto of galerij?
	 */
	private static function still_referenced( $id ) {
		global $wpdb;
		$id = (int) $id;

		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1", $id ) ) ) {
			return true;
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = 'woovr_image_id' AND meta_value = %d LIMIT 1", $id ) ) ) {
			return true;
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND FIND_IN_SET(%d, meta_value) LIMIT 1", $id ) ) ) {
			return true;
		}

		// Staat het pad (zonder extensie/-scaled) intussen ergens in content,
		// meta of opties? Een te ruime treffer (foto-pensioen ↔ foto-pensioen-2025)
		// laat hem alleen staan — de veilige kant.
		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		$stem = preg_replace( '/(-scaled)?\.[a-z0-9]+$/i', '', $file );
		if ( '' !== $stem && false !== strpos( $stem, '/' ) ) {
			$like = '%' . $wpdb->esc_like( $stem ) . '%';
			$esc  = '%' . $wpdb->esc_like( str_replace( '/', '\\/', $stem ) ) . '%'; // JSON-ge-escapet (page builders)
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type NOT IN ( 'attachment', 'revision' ) AND post_status NOT IN ( 'trash', 'auto-draft' ) AND ( post_content LIKE %s OR post_content LIKE %s ) LIMIT 1", $like, $esc ) ) ) {
				return true;
			}
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type NOT IN ( 'attachment', 'revision' ) AND pm.meta_key NOT LIKE %s AND ( pm.meta_value LIKE %s OR pm.meta_value LIKE %s ) LIMIT 1", $wpdb->esc_like( 'wpvivid' ) . '%', $like, $esc ) ) ) {
				return true;
			}
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_value LIKE %s LIMIT 1", $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( 'wpvivid' ) . '%', $wpdb->esc_like( 'mcm_' ) . '%', $like ) ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------
	 * Live-controle
	 * ------------------------------------------------------------- */

	/** Option met de voortgang van de live-controle. */
	const LIVE_OPT = 'mcm_media_live';

	/**
	 * De pagina's die de live-controle ophaalt: home + gepubliceerde berichten
	 * van publieke posttypes (pagina's eerst). Filter: mcm_optimizer_live_check_urls.
	 */
	private static function live_urls() {
		$types = array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] );
		usort( $types, static function ( $a, $b ) {
			return ( 'page' === $b ) <=> ( 'page' === $a );
		} );
		$limit = max( 10, (int) apply_filters( 'mcm_optimizer_live_check_limit', 150 ) );
		$urls  = [ home_url( '/' ) ];
		foreach ( $types as $t ) {
			$ids = get_posts( [ 'post_type' => $t, 'post_status' => 'publish', 'numberposts' => $limit, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC', 'has_password' => false ] );
			foreach ( $ids as $pid ) {
				$u = get_permalink( $pid );
				if ( $u ) {
					$urls[] = $u;
				}
			}
			if ( count( $urls ) >= $limit ) {
				break;
			}
		}
		$urls = array_slice( $urls, 0, $limit );
		// Een paar archiefpagina's (categorieën, productcategorieën): daar staan
		// categorie-afbeeldingen die op berichtpagina's niet voorkomen.
		$terms = get_terms( [ 'taxonomy' => get_taxonomies( [ 'public' => true ] ), 'hide_empty' => true, 'number' => 30 ] );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				$u = get_term_link( $t );
				if ( is_string( $u ) ) {
					$urls[] = $u;
				}
			}
		}
		// Alleen pagina's van de site zelf (geen externe adressen via filters of
		// redirects). De homepage blijft altijd de eerste: live_step() beoordeelt die apart.
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$urls = array_filter( (array) apply_filters( 'mcm_optimizer_live_check_urls', $urls ), static function ( $u ) use ( $home ) {
			return strtolower( (string) wp_parse_url( (string) $u, PHP_URL_HOST ) ) === $home;
		} );
		return array_values( array_unique( array_merge( [ home_url( '/' ) ], $urls ) ) );
	}

	/**
	 * Haalt één pagina van de eigen site op. Redirects alleen binnen dezelfde
	 * host (max. 2), zodat een redirect-plugin de server nooit naar buiten stuurt.
	 */
	private static function fetch_own_page( $url ) {
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$res = wp_remote_get(
				add_query_arg( 'mcm_live', '1', $url ), // query string: buiten de paginacache om, dus de actuele pagina.
				[
					'timeout'     => 10,
					'redirection' => 0,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
					'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128 Safari/537.36 MCM-Optimizer-LiveCheck',
				]
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$loc  = (string) wp_remote_retrieve_header( $res, 'location' );
			if ( $code >= 300 && $code < 400 && '' !== $loc ) {
				$loc = 0 === strpos( $loc, '/' ) ? home_url( $loc ) : $loc;
				if ( strtolower( (string) wp_parse_url( $loc, PHP_URL_HOST ) ) !== $home ) {
					return new WP_Error( 'external_redirect', 'Redirect naar een andere host.' );
				}
				$url = remove_query_arg( 'mcm_live', $loc );
				continue;
			}
			return $res;
		}
		return new WP_Error( 'too_many_redirects', 'Te veel redirects.' );
	}

	/** _wp_attached_file + origineel (original_image) voor een lijst bijlagen, in porties. */
	private static function files_for_ids( array $ids ) {
		global $wpdb;
		$out = [];
		foreach ( array_chunk( array_map( 'intval', $ids ), 200 ) as $chunk ) {
			$in = implode( ',', $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ( '_wp_attached_file', '_wp_attachment_metadata' )" ) as $r ) {
				$pid = (int) $r->post_id;
				if ( '_wp_attached_file' === $r->meta_key ) {
					$out[ $pid ]['file'] = (string) $r->meta_value;
				} elseif ( false !== strpos( (string) $r->meta_value, 'original_image' ) ) {
					$m                       = maybe_unserialize( $r->meta_value );
					$out[ $pid ]['original'] = is_array( $m ) ? (string) ( $m['original_image'] ?? '' ) : '';
				}
			}
		}
		return $out;
	}

	/**
	 * Haalt een portie pagina's op en noteert welke /uploads/-bestanden erop
	 * staan. Is alles bekeken, dan gaan wezen die zichtbaar zijn uit de lijst
	 * ("beschermd"). Het sterkste vangnet: werkt ongeacht wáár de afbeelding
	 * vandaan komt (opties, thema, page builder, widget...).
	 */
	private static function live_step() {
		$scan = get_option( self::SCAN_OPT, [] );
		if ( empty( $scan['time'] ) ) {
			return [ 'status' => 'failed', 'checked' => 0, 'total' => 0, 'errors' => 0, 'protected' => [], 'reason' => 'Geen scan gevonden — scan opnieuw.' ];
		}
		if ( empty( $scan['orphan_ids'] ) && 'todo' === ( $scan['live']['status'] ?? '' ) ) {
			$scan['live'] = [ 'status' => 'done', 'checked' => 0, 'total' => 0, 'errors' => 0, 'protected' => [] ];
			update_option( self::SCAN_OPT, $scan, false );
			return $scan['live'];
		}
		$state = get_option( self::LIVE_OPT, [] );
		if ( empty( $state ) && 'done' === ( $scan['live']['status'] ?? '' ) ) {
			return $scan['live']; // al afgerond voor deze scan.
		}
		if ( ( $state['scan_time'] ?? '' ) !== $scan['time'] || ! isset( $state['urls'] ) ) {
			$state = [ 'scan_time' => $scan['time'], 'urls' => self::live_urls(), 'pos' => 0, 'seen' => [], 'errors' => 0, 'empty' => 0, 'home_ok' => false ];
		}

		$budget = time() + max( 5, (int) apply_filters( 'mcm_optimizer_live_check_budget', 12 ) );
		$total  = count( $state['urls'] );
		while ( $state['pos'] < $total && time() < $budget ) {
			$url = $state['urls'][ $state['pos'] ];
			$state['pos']++;
			$res = self::fetch_own_page( $url );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				$state['errors']++;
				continue;
			}
			$paths = [];
			$bases = [];
			self::collect_upload_urls( wp_remote_retrieve_body( $res ), $paths, $bases );
			if ( ! $paths ) {
				$state['empty']++; // 200 zonder één upload: onderhoudspagina, challenge, of echt zonder afbeeldingen.
			}
			if ( 1 === $state['pos'] ) {
				$state['home_ok'] = (bool) $paths;
			}
			$state['seen'] += $paths;
			// Tussentijds opslaan: valt het verzoek om (time-out), dan gaat het hier verder.
			update_option( self::LIVE_OPT, $state, false );
		}

		$live = [ 'status' => 'running', 'checked' => $state['pos'], 'total' => $total, 'errors' => $state['errors'], 'empty' => $state['empty'], 'protected' => [] ];
		if ( $state['pos'] >= $total ) {
			$keep      = [];
			$protected = [];
			$files     = self::files_for_ids( (array) ( $scan['orphan_ids'] ?? [] ) );
			foreach ( (array) ( $scan['orphan_ids'] ?? [] ) as $oid ) {
				$file = (string) ( $files[ (int) $oid ]['file'] ?? '' );
				$hit  = false;
				foreach ( self::attachment_keys( $file, (string) ( $files[ (int) $oid ]['original'] ?? '' ) ) as $k ) {
					if ( isset( $state['seen'][ $k ] ) ) {
						$hit = true;
						break;
					}
				}
				if ( $hit ) {
					$protected[] = [ 'id' => (int) $oid, 'file' => $file ];
				} else {
					$keep[] = (int) $oid;
				}
			}
			// Alleen "gelukt" als de homepage afbeeldingen liet zien en minstens
			// 80% van de pagina's echt bekeken kon worden. Anders (onderhoudsmodus,
			// bot-challenge, onbereikbaar) mag de prullenbak alleen na een extra bevestiging.
			$ok             = $total > 0 && $state['home_ok'] && ( $total - $state['errors'] ) >= 0.8 * $total;
			$live['status'] = $ok ? 'done' : 'failed';
			if ( ! $ok ) {
				$live['reason'] = ! $state['home_ok'] ? 'De homepage gaf geen afbeeldingen terug (onderhoudsmodus, beveiligingscontrole of niet bereikbaar).' : 'Te veel pagina\'s waren niet bereikbaar.';
			}
			$live['protected']  = $protected;
			$scan['orphan_ids'] = $keep;
			$scan['orphans']    = count( $keep );
			$scan['referenced'] = (int) $scan['total'] - count( $keep );
			if ( $protected ) {
				$buckets = [];
				foreach ( $keep as $kid ) {
					$f               = (string) ( $files[ $kid ]['file'] ?? '' );
					$seg             = ( false !== strpos( $f, '/' ) ) ? substr( $f, 0, strpos( $f, '/' ) ) : '(root)';
					$buckets[ $seg ] = ( $buckets[ $seg ] ?? 0 ) + 1;
				}
				arsort( $buckets );
				$scan['buckets'] = $buckets;
				$scan['sample']  = self::sample( $keep );
			}
			delete_option( self::LIVE_OPT );
		} else {
			update_option( self::LIVE_OPT, $state, false );
		}
		$scan['live'] = $live;
		update_option( self::SCAN_OPT, $scan, false );
		return $live;
	}

	/** Aantal afbeeldingen dat nu door deze tool in de prullenbak staat. */
	private static function count_trashed() {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 WHERE p.post_type = 'attachment' AND p.post_status = 'trash'",
				self::TRASH_META
			)
		);
	}

	/* ---------------------------------------------------------------
	 * Scan
	 * ------------------------------------------------------------- */

	private static function scan() {
		global $wpdb;

		$atts = $wpdb->get_results(
			"SELECT p.ID, pm.meta_value AS file
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type LIKE 'image/%'
			   AND p.post_status != 'trash'"
		);

		$ref     = self::build_reference_index();
		$orphans = [];
		$buckets = [];
		$missing = [];

		$upload  = wp_upload_dir();
		$basedir = untrailingslashit( $upload['basedir'] ?? '' );

		// Originele bestandsnamen (WordPress maakt bij grote uploads een -scaled
		// versie; het origineel staat in de metadata). In één query, zodat er
		// niet per bijlage metadata geladen hoeft te worden.
		$originals = [];
		$last      = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s AND meta_id > %d ORDER BY meta_id LIMIT 500", '%original_image%', $last ) );
			foreach ( $rows as $row ) {
				$last = (int) $row->meta_id;
				$m    = maybe_unserialize( $row->meta_value );
				if ( is_array( $m ) && ! empty( $m['original_image'] ) ) {
					$originals[ (int) $row->post_id ] = (string) $m['original_image'];
				}
			}
		} while ( count( $rows ) === 500 );

		// Bestandsnamen die bij meer dan één bijlage horen (dubbele uploads):
		// daar beslist alleen het pad, niet de naam.
		$name_count = [];
		$keys       = [];
		foreach ( $atts as $a ) {
			$keys[ (int) $a->ID ] = self::attachment_keys( (string) $a->file, $originals[ (int) $a->ID ] ?? '' );
			$b                    = basename( $keys[ (int) $a->ID ][0] ?? '' );
			if ( '' !== $b ) {
				$name_count[ $b ] = ( $name_count[ $b ] ?? 0 ) + 1;
			}
		}
		$dupes = [];

		foreach ( $atts as $a ) {
			$id   = (int) $a->ID;
			$file = (string) $a->file;
			$k    = $keys[ $id ];
			$base = basename( $k[0] ?? '' );

			// Ontbrekend bestand: het record staat nog in de database maar het
			// bestand is van schijf verdwenen. Dit staat LOS van "ongebruikt" —
			// een gebruikte afbeelding kan ook een dood record zijn, en dan
			// blijven optimalisatie-plugins er eindeloos op stuklopen. Zo liep
			// WPvivid Imgoptim maandenlang in een lus op één zo'n record.
			if ( '' !== $basedir && ( '' === $file || ! file_exists( $basedir . '/' . $file ) ) ) {
				$missing[] = $id;
			}

			$is_dupe = '' !== $base && ( $name_count[ $base ] ?? 0 ) > 1;
			$used    = isset( $ref['ids'][ $id ] );
			foreach ( $k as $key ) {
				if ( isset( $ref['paths'][ $key ] ) ) {
					$used = true;
					break;
				}
			}
			// Vangnet: verwijzing met een afwijkend pad (oud domein, CDN, andere
			// uploadmap) — alleen als de bestandsnaam uniek is.
			if ( ! $used && ! $is_dupe && '' !== $base && isset( $ref['bases'][ $base ] ) ) {
				$used = true;
			}
			if ( $is_dupe ) {
				$dupes[ $base ][] = [ 'id' => $id, 'file' => $file, 'used' => $used ];
			}
			if ( $used ) {
				continue;
			}

			$orphans[] = $id;
			$seg = ( false !== strpos( $file, '/' ) ) ? substr( $file, 0, strpos( $file, '/' ) ) : '(root)';
			$buckets[ $seg ] = ( $buckets[ $seg ] ?? 0 ) + 1;
		}

		arsort( $buckets );
		$sample = self::sample( $orphans );

		// Dubbele uploads: groepen waarvan minstens één kopie ongebruikt is.
		$dupe_groups = [];
		foreach ( $dupes as $name => $items ) {
			if ( count( array_filter( $items, static function ( $i ) { return ! $i['used']; } ) ) ) {
				$dupe_groups[] = [ 'name' => $name, 'items' => $items ];
			}
		}

		return [
			'time'        => current_time( 'mysql' ),
			'total'       => count( $atts ),
			'referenced'  => count( $atts ) - count( $orphans ),
			'orphans'     => count( $orphans ),
			'orphan_ids'  => $orphans,
			'buckets'     => $buckets,
			'sample'      => $sample,
			'missing'     => count( $missing ),
			'missing_ids' => array_slice( $missing, 0, 200 ),
			'dupes'       => count( $dupe_groups ),
			'dupe_groups' => array_slice( $dupe_groups, 0, 30 ),
			'live'        => [ 'status' => 'todo', 'checked' => 0, 'total' => 0, 'protected' => [] ],
		];
	}

	/** Steekproef (24 miniaturen) uit een lijst wezen. */
	private static function sample( array $ids ) {
		$sample = [];
		foreach ( array_slice( $ids, 0, 24 ) as $sid ) {
			$url = wp_get_attachment_image_url( $sid, 'thumbnail' );
			if ( ! $url ) {
				$url = wp_get_attachment_image_url( $sid, 'full' );
			}
			$sample[] = [ 'id' => (int) $sid, 'url' => $url ?: '' ];
		}
		return $sample;
	}

	/**
	 * Vergelijk-sleutels van een bijlage: het genormaliseerde pad van het
	 * bestand, plus het originele bestand als WordPress een -scaled/-e…-versie
	 * aanmaakte (metadata original_image).
	 */
	private static function attachment_keys( $file, $original = '' ) {
		$keys = [];
		if ( '' !== $file ) {
			$keys[] = self::normalize_path( $file );
			if ( '' !== $original ) {
				$dir    = dirname( $file );
				$keys[] = self::normalize_path( ( '.' === $dir ? '' : $dir . '/' ) . $original );
			}
		}
		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/* ---------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------- */

	public function ajax_scan() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		$data = self::scan();
		update_option( self::SCAN_OPT, $data, false );

		// Stuur niet de volledige ID-lijst mee terug (kan groot zijn).
		$response = $data;
		unset( $response['orphan_ids'] );
		$response['trashed'] = self::count_trashed();

		wp_send_json_success( $response );
	}

	public function ajax_live() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}
		$live = self::live_step();
		$scan = get_option( self::SCAN_OPT, [] );
		wp_send_json_success( [
			'live'       => $live,
			'total'      => (int) ( $scan['total'] ?? 0 ),
			'referenced' => (int) ( $scan['referenced'] ?? 0 ),
			'orphans'    => (int) ( $scan['orphans'] ?? 0 ),
			'buckets'    => $scan['buckets'] ?? [],
			'sample'     => $scan['sample'] ?? [],
		] );
	}

	public function ajax_trash() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		$scan = get_option( self::SCAN_OPT, [] );
		$ids  = ( isset( $scan['orphan_ids'] ) && is_array( $scan['orphan_ids'] ) ) ? $scan['orphan_ids'] : [];

		// Eerst de live-controle. Alleen als die niet kon (alle pagina's
		// onbereikbaar) mag het na een expliciete bevestiging zonder.
		$live_status = $scan['live']['status'] ?? 'todo';
		if ( 'done' !== $live_status && ! ( 'failed' === $live_status && ! empty( $_POST['skip_live'] ) ) ) {
			wp_send_json_error( 'Voer eerst de live-controle uit (scan opnieuw).' );
		}

		// Zonder prullenbak (EMPTY_TRASH_DAYS = 0) wist wp_trash_post() direct
		// en definitief. Dan doen we niets: deze knop moet terug te draaien zijn.
		if ( ! EMPTY_TRASH_DAYS ) {
			wp_send_json_error( 'De prullenbak staat uit op deze site (EMPTY_TRASH_DAYS = 0): verplaatsen zou direct definitief wissen. Zet EMPTY_TRASH_DAYS in wp-config op bijvoorbeeld 30.' );
		}

		if ( empty( $ids ) ) {
			wp_send_json_success( [ 'done' => true, 'processed' => 0, 'remaining' => 0, 'trashed' => self::count_trashed() ] );
		}

		$budget    = microtime( true ) + 15;
		$processed = 0;
		$skipped   = 0;

		while ( $ids && microtime( true ) < $budget ) {
			$id  = (int) array_shift( $ids );
			$att = get_post( $id );
			// Alleen gewone bijlagen (inherit/private); al in de prullenbak of iets anders → overslaan.
			if ( ! $att || 'attachment' !== $att->post_type || ! in_array( $att->post_status, [ 'inherit', 'private' ], true ) ) {
				$skipped++;
			} elseif ( self::still_referenced( $id ) ) {
				$skipped++; // intussen tóch in gebruik.
			} elseif ( wp_trash_post( $id ) ) {
				// Niet automatisch na EMPTY_TRASH_DAYS laten wissen (wp_scheduled_delete
				// kijkt naar _wp_trash_meta_time): definitief wissen gaat alleen via
				// de knop hieronder. _wp_trash_meta_status blijft, voor terugzetten.
				delete_post_meta( $id, '_wp_trash_meta_time' );
				update_post_meta( $id, self::TRASH_META, current_time( 'mysql' ) );
				$processed++;
			} else {
				$skipped++;
			}
			// Na elk item opslaan: valt het verzoek om, dan begint de volgende ronde niet opnieuw.
			$scan['orphan_ids'] = array_values( $ids );
			update_option( self::SCAN_OPT, $scan, false );
		}

		wp_send_json_success( [
			'done'      => empty( $ids ),
			'processed' => $processed,
			'skipped'   => $skipped,
			'remaining' => count( $ids ),
			'trashed'   => self::count_trashed(),
		] );
	}

	public function ajax_restore() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 WHERE p.post_type = 'attachment' AND p.post_status = 'trash'
				 LIMIT %d",
				self::TRASH_META,
				self::BATCH
			)
		);

		$processed = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			wp_untrash_post( $id );
			delete_post_meta( $id, self::TRASH_META );
			$processed++;
		}

		$remaining = self::count_trashed();
		wp_send_json_success( [ 'done' => 0 === $remaining, 'processed' => $processed, 'remaining' => $remaining ] );
	}

	public function ajax_purge() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 WHERE p.post_type = 'attachment' AND p.post_status = 'trash'
				 LIMIT %d",
				self::TRASH_META,
				self::BATCH
			)
		);

		$processed = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_attachment( (int) $id, true ) ) {
				$processed++;
			}
		}

		$remaining = self::count_trashed();
		wp_send_json_success( [ 'done' => 0 === $remaining, 'processed' => $processed, 'remaining' => $remaining ] );
	}

	/* ---------------------------------------------------------------
	 * UI
	 * ------------------------------------------------------------- */

	public function render_card() {
		$trashed = self::count_trashed();
		?>
		<div class="mcm-opt-card">
			<div class="mcm-opt-card-header">
				<span class="dashicons dashicons-format-image"></span>
				<h2>Ongebruikte media</h2>
				<button type="button" id="mcm-media-scan" class="button mcm-opt-btn-primary" style="margin-left:auto;">
					<span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
					Scan Starten
				</button>
			</div>
			<div class="mcm-opt-card-body">
				<p class="description" style="margin-top:0;">
					Zoekt afbeeldingen die nergens gebruikt worden (uitgelichte afbeelding, WooCommerce-galerij,
					variatie-foto's, content, logo, Avada-opties en pagina-opties, widgets, menu's).
					Daarna een <strong>live-controle</strong>: afbeeldingen die op de pagina's van de site staan, worden beschermd.
					Wees-afbeeldingen gaan naar de <strong>prullenbak</strong> —
					terugzetbaar; bestanden blijven op schijf tot je ze hieronder definitief verwijdert
					(WordPress leegt deze prullenbak niet automatisch).
				</p>
				<?php if ( ! EMPTY_TRASH_DAYS ) : ?>
					<div class="mcm-opt-alert mcm-opt-alert-warn"><span class="dashicons dashicons-warning"></span>
						De prullenbak staat uit op deze site (<code>EMPTY_TRASH_DAYS</code> = 0). Scannen kan, maar verplaatsen naar de
						prullenbak is uitgeschakeld: WordPress zou de afbeeldingen dan direct definitief wissen.
					</div>
				<?php endif; ?>

				<div id="mcm-media-loading" style="display:none;">
					<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
					Bezig met scannen...
				</div>

				<div id="mcm-media-results"></div>

				<div id="mcm-media-progress" style="display:none;margin-top:14px;">
					<div style="background:#e2e4e7;border-radius:6px;height:22px;overflow:hidden;">
						<div id="mcm-media-bar" style="background:var(--mcm-primary);height:100%;width:0;transition:width .3s;"></div>
					</div>
					<p id="mcm-media-status" style="font-size:13px;margin:6px 0 0;"></p>
				</div>

				<div id="mcm-media-trash-box" style="<?php echo $trashed > 0 ? '' : 'display:none;'; ?>margin-top:16px;padding-top:14px;border-top:1px solid var(--mcm-border);">
					<strong>In prullenbak:</strong> <span id="mcm-media-trash-count"><?php echo (int) $trashed; ?></span> afbeeldingen.
					<div style="margin-top:8px;">
						<button type="button" id="mcm-media-restore" class="button">Alles terugzetten</button>
						<button type="button" id="mcm-media-purge" class="button mcm-opt-btn-clean" style="color:var(--mcm-terracotta);">Definitief verwijderen</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function assets( $hook ) {
		if ( 'tools_page_mcm-tools' !== $hook ) {
			return;
		}
		wp_add_inline_script( 'jquery', $this->get_js() );
	}

	private function get_js() {
		return <<<'JS'
jQuery(document).ready(function($) {

	function fmt(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }

	function setBar(done, total) {
		var pct = total > 0 ? Math.min(100, done / total * 100) : 100;
		$('#mcm-media-bar').css('width', pct.toFixed(1) + '%');
	}

	/* ---- Scan ---- */
	$('#mcm-media-scan').on('click', function() {
		var btn = $(this);
		btn.prop('disabled', true);
		$('#mcm-media-loading').show();
		$('#mcm-media-results').html('');
		$('#mcm-media-progress').hide();

		$.post(mcmOptimizer.ajaxUrl, {
			action: 'mcm_media_scan',
			nonce: mcmOptimizer.nonce
		}, function(res) {
			btn.prop('disabled', false);
			$('#mcm-media-loading').hide();

			if (!res.success) {
				$('#mcm-media-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Scan mislukt.</div>');
				return;
			}

			scanData = res.data;
			if (scanData.orphans === 0) {
				render(scanData, { status: 'done', protected: [] });
				return;
			}
			// Eerst de live-controle; pas daarna komt de prullenbak-knop.
			render(scanData, null);
			liveLoop();
		}).fail(function() {
			btn.prop('disabled', false);
			$('#mcm-media-loading').hide();
			$('#mcm-media-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Verbindingsfout.</div>');
		});
	});

	var scanData = null;

	function esc(s) { return $('<div/>').text(String(s)).html(); }

	function render(d, live) {
		var html = '';
		html += '<div class="mcm-opt-db-size">';
		html += 'Afbeeldingen: <strong>' + fmt(d.total) + '</strong> &middot; ';
		html += 'in gebruik: <strong>' + fmt(d.referenced) + '</strong> &middot; ';
		html += 'ongebruikt: <strong style="color:var(--mcm-terracotta);">' + fmt(d.orphans) + '</strong>';
		html += '</div>';

		if (d.missing > 0) {
			html += '<div class="mcm-opt-alert mcm-opt-alert-warn"><span class="dashicons dashicons-warning"></span> ';
			html += '<strong>' + fmt(d.missing) + '</strong> mediarecord(s) verwijzen naar een bestand dat niet meer op schijf staat. ';
			html += 'Dat zijn dode records: optimalisatie-plugins kunnen erop blijven hangen. Los van "ongebruikt" hierboven.';
			html += '</div>';
		}

		if (live && live.protected && live.protected.length) {
			html += '<div class="mcm-opt-alert mcm-opt-alert-safe"><span class="dashicons dashicons-shield"></span> ';
			html += '<strong>' + fmt(live.protected.length) + '</strong> afbeelding(en) leken ongebruikt maar staan zichtbaar op de site — beschermd en uit de lijst gehaald: ';
			html += live.protected.slice(0, 8).map(function(p) { return esc(p.file); }).join(', ') + (live.protected.length > 8 ? ' …' : '') + '</div>';
		}

		if (d.dupes > 0 && d.dupe_groups) {
			html += '<details style="margin:8px 0;"><summary style="cursor:pointer;font-size:13px;"><strong>' + fmt(d.dupes) + '</strong> dubbele upload(s): zelfde bestandsnaam in meerdere mappen</summary>';
			html += '<p class="description" style="margin:6px 0;">De ongebruikte kopie staat bij de wezen; de gebruikte blijft staan.</p><ul style="margin:0 0 0 18px;font-size:12px;">';
			d.dupe_groups.forEach(function(g) {
				html += '<li>' + esc(g.name) + ': ' + g.items.map(function(i) {
					return esc(i.file) + (i.used ? ' <span style="color:#1a5c5e;">(gebruikt)</span>' : ' <span style="color:var(--mcm-terracotta);">(ongebruikt)</span>');
				}).join(' · ') + '</li>';
			});
			html += '</ul></details>';
		}

		if (d.orphans === 0) {
			html += '<div class="mcm-opt-alert mcm-opt-alert-safe"><span class="dashicons dashicons-yes-alt"></span> Geen ongebruikte afbeeldingen gevonden.</div>';
			$('#mcm-media-results').html(html);
			return;
		}

		var b = d.buckets || {};
		html += '<p style="font-size:13px;margin:4px 0 8px;"><strong>Ongebruikt per map:</strong> ';
		var parts = [];
		for (var k in b) { parts.push(esc(k) + ': ' + fmt(b[k])); }
		html += parts.join(' &middot; ') + '</p>';

		if (d.sample && d.sample.length) {
			html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0;">';
			for (var i = 0; i < d.sample.length; i++) {
				if (d.sample[i].url) {
					html += '<img src="' + esc(d.sample[i].url) + '" title="#' + d.sample[i].id + '" style="width:64px;height:64px;object-fit:cover;border:1px solid var(--mcm-border);border-radius:4px;">';
				}
			}
			html += '</div>';
			html += '<p class="description">Steekproef — controleer dat dit inderdaad ongebruikte afbeeldingen zijn.</p>';
		}

		if (!live) {
			html += '<div class="mcm-opt-alert mcm-opt-alert-warn"><span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span> ' +
				'Live-controle: de pagina\'s van de site worden opgehaald om te zien of deze afbeeldingen tóch zichtbaar zijn… <span id="mcm-media-live-status"></span></div>';
		} else if (live.status === 'failed') {
			html += '<div class="mcm-opt-alert mcm-opt-alert-danger"><span class="dashicons dashicons-warning"></span> ' +
				'De live-controle is niet gelukt' + (live.reason ? ': ' + esc(live.reason) : '.') + ' Controleer de steekproef extra goed.</div>';
		} else {
			html += '<p class="description">Live-controle: ' + fmt(live.checked) + ' pagina\'s bekeken' + (live.errors ? ', ' + fmt(live.errors) + ' niet bereikbaar' : '') + (live.empty ? ', ' + fmt(live.empty) + ' zonder afbeeldingen' : '') + '.</p>';
		}

		if (live) {
			html += '<div class="mcm-opt-bulk-actions" style="border-top:none;padding-top:8px;">';
			html += '<button type="button" id="mcm-media-trash" class="button mcm-opt-btn-primary mcm-opt-btn-large" data-total="' + d.orphans + '" data-skip-live="' + (live.status === 'failed' ? 1 : 0) + '">';
			html += '<span class="dashicons dashicons-trash" style="vertical-align:middle;margin-top:-2px;"></span> ';
			html += fmt(d.orphans) + ' afbeeldingen naar prullenbak';
			html += '</button></div>';
		}

		$('#mcm-media-results').html(html);
	}

	function liveLoop() {
		$.post(mcmOptimizer.ajaxUrl, { action: 'mcm_media_live', nonce: mcmOptimizer.nonce }, function(res) {
			if (!res.success) {
				render(scanData, { status: 'failed', protected: [], checked: 0, errors: 0, reason: typeof res.data === 'string' ? res.data : '' });
				return;
			}
			var r = res.data, l = r.live;
			if (l.status === 'running') {
				$('#mcm-media-live-status').text(fmt(l.checked) + ' van ' + fmt(l.total) + ' pagina\'s');
				liveLoop();
				return;
			}
			scanData.total = r.total; scanData.referenced = r.referenced; scanData.orphans = r.orphans;
			scanData.buckets = r.buckets; scanData.sample = r.sample;
			render(scanData, l);
		}).fail(function() {
			render(scanData, { status: 'failed', protected: [], checked: 0, errors: 0 });
		});
	}

	/* ---- Generieke batch-runner ---- */
	function runBatch(action, total, label, onDone, extra) {
		$('#mcm-media-progress').show();
		var done = 0, skipped = 0;

		function next() {
			$.post(mcmOptimizer.ajaxUrl, $.extend({ action: action, nonce: mcmOptimizer.nonce }, extra || {}), function(res) {
				if (!res.success) {
					var why = typeof res.data === 'string' ? res.data : 'ververs de pagina en probeer opnieuw.';
					$('#mcm-media-status').html('<strong style="color:var(--mcm-terracotta);">Fout — ' + esc(why) + '</strong>');
					return;
				}
				var d = res.data;
				done += d.processed;
				skipped += d.skipped || 0;
				setBar(done + skipped, total);
				$('#mcm-media-status').text(label + ': ' + fmt(done) + ' verwerkt' + (skipped ? ', ' + fmt(skipped) + ' overgeslagen (intussen in gebruik)' : '') + ', ' + fmt(d.remaining) + ' te gaan.');
				if (typeof d.trashed !== 'undefined') {
					$('#mcm-media-trash-count').text(d.trashed);
				}
				if (d.done) {
					$('#mcm-media-status').html('<strong>Klaar — ' + fmt(done) + ' verwerkt' + (skipped ? ', ' + fmt(skipped) + ' overgeslagen' : '') + '.</strong>');
					if (onDone) { onDone(d); }
				} else {
					next();
				}
			}).fail(function() {
				$('#mcm-media-status').html('<strong style="color:var(--mcm-terracotta);">Verbindingsfout — probeer opnieuw.</strong>');
			});
		}
		next();
	}

	/* ---- Naar prullenbak ---- */
	$(document).on('click', '#mcm-media-trash', function() {
		var total = parseInt($(this).data('total'), 10) || 0;
		var skipLive = parseInt($(this).data('skip-live'), 10) === 1;
		var msg = 'Weet je zeker dat je ' + fmt(total) + ' ongebruikte afbeeldingen naar de prullenbak verplaatst?\n\nDit is terugzetbaar.';
		if (skipLive) {
			msg = 'LET OP: de live-controle is niet gelukt.\n\n' + msg;
		}
		if (!confirm(msg)) {
			return;
		}
		$(this).prop('disabled', true);
		runBatch('mcm_media_trash', total, 'Naar prullenbak', function() {
			$('#mcm-media-trash-box').show();
		}, skipLive ? { skip_live: 1 } : {});
	});

	/* ---- Terugzetten ---- */
	$(document).on('click', '#mcm-media-restore', function() {
		var total = parseInt($('#mcm-media-trash-count').text(), 10) || 0;
		if (!confirm('Alle ' + fmt(total) + ' afbeeldingen uit de prullenbak terugzetten?')) { return; }
		$(this).prop('disabled', true);
		runBatch('mcm_media_restore', total, 'Terugzetten', function() {
			$('#mcm-media-restore, #mcm-media-purge').prop('disabled', true);
		});
	});

	/* ---- Definitief verwijderen ---- */
	$(document).on('click', '#mcm-media-purge', function() {
		var total = parseInt($('#mcm-media-trash-count').text(), 10) || 0;
		if (!confirm('LET OP: ' + fmt(total) + ' afbeeldingen definitief verwijderen (incl. bestanden op schijf)?\n\nDit kan NIET ongedaan worden gemaakt.')) {
			return;
		}
		$(this).prop('disabled', true);
		runBatch('mcm_media_purge', total, 'Definitief verwijderen', function() {
			$('#mcm-media-restore, #mcm-media-purge').prop('disabled', true);
		});
	});
});
JS;
	}
}

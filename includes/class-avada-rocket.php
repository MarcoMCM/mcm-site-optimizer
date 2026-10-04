<?php
/**
 * Avada ↔ WP Rocket: laat beide plugins elk hun eigen taak doen.
 *
 * Aanleiding — pensioenfonds-sagittarius.nl, 3/4 oktober 2026 (lokaal nagemeten):
 *  - WP Rocket "Remove Unused CSS" definieert FUSION_DISABLE_COMPILERS
 *    (wp-rocket/inc/ThirdParty/Themes/Avada.php). Avada zet dan zijn volledige
 *    dynamische CSS inline in élke pagina: ±750 kB, 50–60 losse JS-bestanden.
 *  - Taakverdeling die getest is: WP Rocket = paginacache + JS defer/delay +
 *    lazy load; Avada = CSS/JS bundelen (CSS naar bestand, JS-compiler aan).
 *    Resultaat: HTML 841–883 kB → 86–128 kB, gecachete pagina 0,01 s.
 *  - WP Rocket "Delay JS" hield de Avada-animaties (o.a. de homepage-header,
 *    visibility:hidden tot de animatie-JS draait) tegen tot de bezoeker
 *    bewoog. Fix: jQuery + de Avada-scriptbundel uitzonderen.
 *  - Element Manager (Avada → Performance → Avada Elements): staat een element
 *    uit dat wél in de content gebruikt wordt, dan verschijnt de shortcode als
 *    losse tekst — bij een uitgeschakelde honeypot faalt zelfs elke inzending.
 *
 * Deze klasse levert alleen aanbevelingen en voert ze uit via een changeset,
 * zodat MCM_Performance elke toepassing kan vastleggen en terugdraaien.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Avada_Rocket {

	/** Keys die deze klasse kan toepassen. */
	const KEYS = [
		'avada_compilers',
		'avada_rucss_off',
		'avada_minify_off',
		'avada_delay_exclusions',
		'avada_lazyload',
		'avada_conflicts',
		'avada_revisions',
		'avada_webp',
		'rocket_config',
		'avada_elements_missing',
		'avada_elements_dupes',
	];

	/* ---------------------------------------------------------------
	 * Detectie
	 * ------------------------------------------------------------- */

	public static function is_avada() {
		$theme = wp_get_theme();
		return in_array( 'Avada', [ $theme->get( 'Name' ), $theme->get_template() ], true ) || class_exists( 'Avada' );
	}

	private static function fusion_options() {
		$o = get_option( 'fusion_options', [] );
		return is_array( $o ) ? $o : [];
	}

	private static function on( $v ) {
		return in_array( $v, [ 1, '1', true, 'on', 'yes' ], true );
	}

	/** Staan de Avada-compilers aan volgens de opties (los van geforceerd uit)? */
	private static function compilers_state( array $f ) {
		$css_file = ( 'file' === ( $f['css_cache_method'] ?? 'file' ) );
		// Avada behandelt een ontbrekende js_compiler als "aan" (options/performance.php).
		$js_on = ! isset( $f['js_compiler'] ) || self::on( $f['js_compiler'] );
		return [ $css_file, $js_on ];
	}

	/** Omgevingsinfo voor de kaart. */
	public static function env( $rocket ) {
		if ( ! self::is_avada() ) {
			return [ 'is_avada' => false ];
		}
		$f                    = self::fusion_options();
		list( $css, $js )     = self::compilers_state( $f );
		$rucss                = is_array( $rocket ) && 1 === (int) ( $rocket['remove_unused_css'] ?? 0 );
		$forced               = $rucss || ( defined( 'FUSION_DISABLE_COMPILERS' ) && FUSION_DISABLE_COMPILERS );
		return [
			'is_avada'        => true,
			'avada_version'   => defined( 'AVADA_VERSION' ) ? AVADA_VERSION : '',
			'css_mode'        => $css ? 'bestand' : ( $f['css_cache_method'] ?? '?' ),
			'js_compiler'     => $js,
			'compilers_forced_off' => $forced,
			'forced_by'       => $rucss ? 'WP Rocket RUCSS' : ( $forced ? 'FUSION_DISABLE_COMPILERS' : '' ),
			'lazy_load'       => $f['lazy_load'] ?? 'none',
		];
	}

	/* ---------------------------------------------------------------
	 * Aanbevelingen
	 * ------------------------------------------------------------- */

	/**
	 * Avada-specifieke aanbevelingen. $s = WP Rocket-instellingen (array) of false.
	 */
	public static function recommendations( $s ) {
		if ( ! self::is_avada() ) {
			return [];
		}
		$f    = self::fusion_options();
		$recs = [];
		list( $css_file, $js_on ) = self::compilers_state( $f );
		$rocket = is_array( $s );
		$rucss  = $rocket && 1 === (int) ( $s['remove_unused_css'] ?? 0 );

		// --- Avada-compilers ------------------------------------------------
		$recs[] = [
			'key'     => 'avada_compilers',
			'label'   => 'Avada: CSS naar bestand + JS-compiler',
			'tier'    => 'aanbevolen',
			'ok'      => $css_file && $js_on && ! $rucss,
			'current' => $rucss ? 'uitgezet door WP Rocket RUCSS'
				: ( ( $css_file ? 'CSS bestand' : 'CSS ' . ( $f['css_cache_method'] ?? '?' ) ) . ', JS ' . ( $js_on ? 'aan' : 'uit' ) ),
			'advised' => 'CSS bestand, JS aan',
			'detail'  => $rucss
				? 'Zolang WP Rocket "Ongebruikte CSS verwijderen" aan staat, zet WP Rocket de Avada-compilers geforceerd uit en komt alle Avada-CSS (±750 kB) inline in elke pagina. Zet eerst RUCSS uit.'
				: 'Avada bundelt CSS in één cachebaar bestand (uploads/fusion-styles) en JS in één bundel. Gemeten: pagina\'s van ±850 kB naar ±100 kB. Daarna de Avada-caches resetten — dat doet de knop.',
		];

		if ( $rocket ) {
			// --- RUCSS (Avada-variant: uit is de veilige kant) ------------------
			$recs[] = [
				'key'     => 'avada_rucss_off',
				'label'   => 'WP Rocket: Ongebruikte CSS verwijderen (RUCSS)',
				'tier'    => 'aanbevolen',
				'ok'      => ! $rucss,
				'current' => $rucss ? 'aan' : 'uit',
				'advised' => 'uit op Avada',
				'detail'  => 'Op Avada dwingt RUCSS de Avada-compilers uit (FUSION_DISABLE_COMPILERS): ±750 kB CSS inline in elke niet-gecachete pagina en veel werk in de WP Rocket-bewerkingsstap. Bovendien loopt RUCSS via de servers van WP Rocket. Met Avada\'s eigen CSS-bestand is het niet nodig. Negeer de gele RUCSS-banner van WP Rocket.',
			];

			// --- Dubbel verkleinen/combineren -----------------------------------
			$min_on = 1 === (int) ( $s['minify_css'] ?? 0 ) || 1 === (int) ( $s['minify_concatenate_css'] ?? 0 )
				|| 1 === (int) ( $s['minify_js'] ?? 0 ) || 1 === (int) ( $s['minify_concatenate_js'] ?? 0 );
			$parts  = [];
			foreach ( [ 'minify_css' => 'CSS verkleinen', 'minify_concatenate_css' => 'CSS combineren', 'minify_js' => 'JS verkleinen', 'minify_concatenate_js' => 'JS combineren' ] as $k => $l ) {
				if ( 1 === (int) ( $s[ $k ] ?? 0 ) ) {
					$parts[] = $l;
				}
			}
			$recs[] = [
				'key'     => 'avada_minify_off',
				'label'   => 'WP Rocket: CSS/JS verkleinen en combineren',
				'tier'    => 'aanbevolen',
				'ok'      => ! $min_on,
				'current' => $parts ? 'aan: ' . implode( ', ', $parts ) : 'uit',
				'advised' => 'uit (Avada bundelt al)',
				'detail'  => 'Avada levert zijn CSS en JS al gebundeld en verkleind (.min). WP Rocket nog eens laten verkleinen/combineren is dubbel werk per pagina en een extra bron van fouten. "JS uitgesteld laden" en "Delay JS" blijven wél bij WP Rocket.',
			];

			// --- Delay JS: Avada-scripts uitzonderen ----------------------------
			$delay = 1 === (int) ( $s['delay_js'] ?? 0 );
			if ( $delay ) {
				$need    = self::delay_patterns();
				$have    = array_map( 'trim', (array) ( $s['delay_js_exclusions'] ?? [] ) );
				$missing = array_values( array_diff( $need, $have ) );
				$recs[]  = [
					'key'     => 'avada_delay_exclusions',
					'label'   => 'WP Rocket Delay JS: Avada-scripts uitzonderen',
					'tier'    => 'veilig',
					'ok'      => $js_on && ! $missing,
					'current' => ! $js_on ? 'Avada JS-compiler uit' : ( $missing ? count( $missing ) . ' uitzondering(en) ontbreken' : 'aanwezig' ),
					'advised' => 'jQuery + Avada-bundel uitgezonderd',
					'detail'  => ! $js_on
						? 'Zet eerst de Avada JS-compiler aan (regel hierboven); deze uitzonderingen zijn getest met de gebundelde Avada-JS.'
						: 'Zonder deze uitzonderingen houdt Delay JS de Avada-animaties tegen: elementen met een in-animatie (vaak de header op de homepage) blijven onzichtbaar tot de bezoeker scrolt of beweegt. Externe scripts (Analytics e.d.) blijven uitgesteld. Toe te voegen: ' . esc_html( implode( '  |  ', $missing ? $missing : $need ) ),
				];
			}

			// --- Lazy load: precies één van beide -------------------------------
			$rl  = 1 === (int) ( $s['lazyload'] ?? 0 );
			$al  = $f['lazy_load'] ?? 'none';
			$one = ( $rl && 'none' === $al ) || ( ! $rl && 'none' !== $al );
			$recs[] = [
				'key'     => 'avada_lazyload',
				'label'   => 'Lazy load: precies één van WP Rocket of Avada',
				'tier'    => 'veilig',
				'ok'      => $one,
				'current' => 'WP Rocket ' . ( $rl ? 'aan' : 'uit' ) . ', Avada ' . $al,
				'advised' => 'WP Rocket aan, Avada none',
				'detail'  => 'Twee lazy-loaders tegelijk (of geen) geeft dubbele scripts of trage pagina\'s. WP Rocket doet het al voor gecachete pagina\'s; Avada\'s optie blijft dan op "Geen".',
			];

			// --- Avada-opties die met WP Rocket botsen --------------------------
			$conf = self::conflicts( $f, $s );
			$recs[] = [
				'key'     => 'avada_conflicts',
				'label'   => 'Avada-opties die met WP Rocket botsen',
				'tier'    => 'aanbevolen',
				'ok'      => ! $conf,
				'current' => $conf ? implode( ', ', array_values( $conf ) ) : 'geen',
				'advised' => 'uit in Avada',
				'detail'  => 'Avada Critical CSS naast WP Rocket\'s CSS-levering/RUCSS, en Avada "Load jQuery In Footer" naast WP Rocket defer/delay, doen hetzelfde werk dubbel en kunnen scripts breken. Klik in de Avada Performance-wizard ook nooit op "Apply All" naast WP Rocket.',
			];

			// --- WP Rocket-config voor dit domein (na verhuizing/import) --------
			$cfg = self::rocket_config_files();
			if ( null !== $cfg ) {
				$recs[] = [
					'key'     => 'rocket_config',
					'label'   => 'WP Rocket-config voor dit domein',
					'tier'    => 'veilig',
					'ok'      => ! $cfg['missing'],
					'current' => $cfg['missing'] ? 'ontbreekt: ' . implode( ', ', array_map( 'basename', $cfg['missing'] ) ) : 'aanwezig',
					'advised' => 'aanwezig',
					'detail'  => 'Zonder config-bestand voor het huidige domein (wp-content/wp-rocket-config/) cachet WP Rocket niets. Gebeurt na verhuizen of importeren naar een ander domein.',
				];
			}
		}

		// --- Revisies --------------------------------------------------------
		$limit     = (int) ( $f['post_revisions_limit'] ?? -1 );
		$const     = defined( 'WP_POST_REVISIONS' ) && is_int( WP_POST_REVISIONS ) && WP_POST_REVISIONS >= 0 ? WP_POST_REVISIONS : null;
		$effective = $limit >= 0 ? $limit : $const;
		$recs[] = [
			'key'     => 'avada_revisions',
			'label'   => 'Revisies per pagina beperken',
			'tier'    => 'veilig',
			'ok'      => null !== $effective && $effective <= 10,
			'current' => null === $effective ? 'onbeperkt' : (string) $effective,
			'advised' => '5',
			'detail'  => 'Avada-pagina\'s maken grote revisies; onbeperkt bewaren laat de database groeien (Sagittarius: 311 revisies, posts-tabel 25 MB). Bestaande revisies opruimen gaat via de database-opschoning hierboven.',
		];

		// --- WebP voor nieuwe uploads ----------------------------------------
		$webp_ok = function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] );
		$fmt     = $f['upload_image_format'] ?? 'default';
		$recs[]  = [
			'key'     => 'avada_webp',
			'label'   => 'Nieuwe uploads als WebP (Avada)',
			'tier'    => 'aanbevolen',
			'ok'      => 'default' !== $fmt || ! $webp_ok,
			'current' => $webp_ok ? $fmt : 'server ondersteunt geen WebP',
			'advised' => 'webp',
			'detail'  => 'Avada zet nieuwe uploads om naar WebP met GD/Imagick van de server — geen extra plugin of cron nodig. Geldt alleen voor nieuwe uploads. Bestaande afbeeldingen NIET in bulk omzetten via Avada → Status: dat vervangt bestanden en past de links in de pagina\'s niet aan.',
		];

		// --- Element Manager -------------------------------------------------
		$el = self::element_report();
		if ( $el['allowlist'] ) {
			$miss = $el['missing'];
			$recs[] = [
				'key'     => 'avada_elements_missing',
				'label'   => 'Element Manager: gebruikte elementen uitgeschakeld',
				'tier'    => 'veilig',
				'ok'      => ! $miss,
				'current' => $miss ? implode( ', ', array_keys( $miss ) ) : 'alles wat gebruikt wordt staat aan',
				'advised' => 'aan',
				'detail'  => $miss
					? 'Deze elementen staan in de content (' . esc_html( self::where_used_text( $miss ) ) . ') maar zijn uitgeschakeld in Avada → Performance → Avada Elements. Ze verschijnen als losse shortcode-tekst; een uitgeschakelde honeypot laat elke formulier-inzending mislukken. De knop zet alleen deze elementen aan.'
					: 'Elementen die niet gebruikt worden mogen uit blijven (scheelt geheugen per paginaweergave).',
			];
			$recs[] = [
				'key'     => 'avada_elements_dupes',
				'label'   => 'Element Manager: dubbele regels',
				'tier'    => 'veilig',
				'ok'      => 0 === $el['duplicates'],
				'current' => $el['duplicates'] ? $el['total'] . ' regels, ' . $el['unique'] . ' uniek' : $el['unique'] . ' elementen',
				'advised' => 'ontdubbeld',
				'detail'  => 'Door herhaald opslaan kan de lijst toegestane elementen dezelfde namen vele keren bevatten. Ontdubbelen verandert niets aan wat aan of uit staat.',
			];
		}

		return $recs;
	}

	/** Patronen voor Delay JS (getest met Avada JS-compiler aan). */
	private static function delay_patterns() {
		$uploads = wp_parse_url( (string) ( wp_upload_dir()['baseurl'] ?? '' ), PHP_URL_PATH );
		$uploads = $uploads ? untrailingslashit( $uploads ) : '/wp-content/uploads';
		return [
			'/jquery-?[0-9.](.*)(.min|.slim|.slim.min)?.js',
			'/wp-includes/js/jquery/',
			$uploads . '/fusion-scripts/',
		];
	}

	/** Avada-opties die dubbel werk doen naast WP Rocket. [optie => label]. */
	private static function conflicts( array $f, array $s ) {
		$out = [];
		$rocket_css = 1 === (int) ( $s['async_css'] ?? 0 ) || 1 === (int) ( $s['remove_unused_css'] ?? 0 );
		$rocket_js  = 1 === (int) ( $s['delay_js'] ?? 0 ) || 1 === (int) ( $s['defer_all_js'] ?? 0 );
		if ( $rocket_css && self::on( $f['critical_css'] ?? '0' ) ) {
			$out['critical_css'] = 'Avada Critical CSS';
		}
		if ( $rocket_js && self::on( $f['defer_jquery'] ?? '0' ) ) {
			$out['defer_jquery'] = 'Avada jQuery in footer';
		}
		return $out;
	}

	/** Config-bestanden van WP Rocket voor dit domein, of null als WP Rocket niet geladen is. */
	private static function rocket_config_files() {
		if ( ! function_exists( 'get_rocket_config_file' ) ) {
			return null;
		}
		list( $paths ) = get_rocket_config_file();
		$missing = [];
		foreach ( (array) $paths as $p ) {
			if ( ! file_exists( $p ) ) {
				$missing[] = $p;
			}
		}
		return [ 'paths' => (array) $paths, 'missing' => $missing ];
	}

	/* ---------------------------------------------------------------
	 * Element Manager
	 * ------------------------------------------------------------- */

	/**
	 * Welke Avada-elementen worden in de content gebruikt maar staan uit?
	 *
	 * Werkwijze (licht, zonder de element-maps van Fusion Builder op te bouwen):
	 *  1. Lees de "poorten" uit de element-bestanden van Fusion Builder/Core:
	 *     elk bestand begint met fusion_is_element_enabled( 'naam' ) en registreert
	 *     daarbinnen zijn shortcode(s) — ook kind-shortcodes (fusion_li_item → fusion_checklist).
	 *  2. Zoek alle [fusion_…]-shortcodes in de content van de builder-posttypes
	 *     (zoals Avada's eigen element-scan in de Performance-wizard).
	 *  3. Gebruikte shortcode → poort; staat die poort niet in de allowlist → ontbreekt.
	 *
	 * @return array allowlist(bool), total, unique, duplicates, missing [poort => [tags, posts]]
	 */
	public static function element_report() {
		$settings = get_option( 'fusion_builder_settings', [] );
		$list     = ( is_array( $settings ) && isset( $settings['fusion_elements'] ) && is_array( $settings['fusion_elements'] ) ) ? $settings['fusion_elements'] : null;
		$report   = [ 'allowlist' => null !== $list && ! empty( $list ), 'total' => 0, 'unique' => 0, 'duplicates' => 0, 'missing' => [] ];
		if ( ! $report['allowlist'] ) {
			return $report; // Geen lijst = alles staat aan (Avada-standaard).
		}
		$unique               = array_values( array_unique( $list ) );
		$report['total']      = count( $list );
		$report['unique']     = count( $unique );
		$report['duplicates'] = $report['total'] - $report['unique'];

		$map  = self::gate_map();
		$used = self::used_shortcodes();
		foreach ( $used as $tag => $posts ) {
			$gate = $map[ $tag ] ?? null;
			if ( null === $gate || in_array( $gate, $unique, true ) ) {
				continue; // Onbekend (layout/core) of staat aan.
			}
			$report['missing'][ $gate ]['tags'][ $tag ] = true;
			foreach ( $posts as $pid => $_ ) {
				$report['missing'][ $gate ]['posts'][ $pid ] = true;
			}
		}
		return $report;
	}

	/** Leesbare samenvatting waar ontbrekende elementen gebruikt worden. */
	private static function where_used_text( array $missing ) {
		$posts = [];
		foreach ( $missing as $m ) {
			foreach ( array_keys( $m['posts'] ?? [] ) as $pid ) {
				$posts[ $pid ] = true;
			}
		}
		$labels = [];
		foreach ( array_slice( array_keys( $posts ), 0, 5 ) as $pid ) {
			$labels[] = get_the_title( $pid ) . ' (' . get_post_type( $pid ) . ' #' . $pid . ')';
		}
		return implode( ', ', $labels ) . ( count( $posts ) > 5 ? ' en ' . ( count( $posts ) - 5 ) . ' meer' : '' );
	}

	/**
	 * Shortcode → poortnaam, uit de element-bestanden. Gecachet per Fusion Builder-versie.
	 */
	private static function gate_map() {
		$version = defined( 'FUSION_BUILDER_VERSION' ) ? FUSION_BUILDER_VERSION : 'x';
		$cache   = get_transient( 'mcm_avada_gate_map' );
		if ( is_array( $cache ) && ( $cache['v'] ?? '' ) === $version ) {
			return $cache['map'];
		}
		$dirs = [];
		if ( defined( 'FUSION_BUILDER_PLUGIN_DIR' ) ) {
			$dirs[] = FUSION_BUILDER_PLUGIN_DIR . 'shortcodes/';
			$dirs[] = FUSION_BUILDER_PLUGIN_DIR . 'shortcodes/form/';
		}
		if ( defined( 'FUSION_CORE_PATH' ) ) {
			$dirs[] = trailingslashit( FUSION_CORE_PATH ) . 'shortcodes/';
		}
		$map = [];
		foreach ( $dirs as $dir ) {
			foreach ( (array) glob( $dir . '*.php' ) as $file ) {
				$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( ! preg_match_all( "/fusion_is_element_enabled\(\s*'([a-z0-9_]+)'\s*\)/", $src, $g ) ) {
					continue;
				}
				$gates = array_unique( $g[1] );
				if ( 1 !== count( $gates ) ) {
					continue; // Twijfel: geen gok.
				}
				$gate         = $gates[0];
				$map[ $gate ] = $gate;
				if ( preg_match_all( "/add_shortcode\(\s*'([a-z0-9_]+)'/", $src, $t ) ) {
					foreach ( $t[1] as $tag ) {
						$map[ $tag ] = $gate;
					}
				}
				if ( preg_match_all( "/parent::__construct\(\s*'([a-z0-9_]+)'/", $src, $t ) ) {
					foreach ( $t[1] as $tag ) {
						$map[ $tag ] = $gate;
					}
				}
			}
		}
		set_transient( 'mcm_avada_gate_map', [ 'v' => $version, 'map' => $map ], WEEK_IN_SECONDS );
		return $map;
	}

	/**
	 * Gebruikte [fusion_…]-shortcodes per post (builder-posttypes, geen prullenbak/revisies).
	 *
	 * @return array [tag => [post_id => true]]
	 */
	private static function used_shortcodes() {
		global $wpdb;
		$types = class_exists( 'FusionBuilder' ) && method_exists( 'FusionBuilder', 'allowed_post_types' )
			? array_filter( (array) FusionBuilder::allowed_post_types() )
			: [ 'post', 'page', 'avada_portfolio', 'avada_faq', 'fusion_template', 'fusion_tb_section', 'fusion_form', 'fusion_element', 'awb_off_canvas' ];
		if ( ! $types ) {
			return [];
		}
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$used  = [];
		$last  = 0;
		$batch = 200;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d", array_merge( $types, [ '%[fusion_%', $last, $batch ] ) ) );
			foreach ( $rows as $r ) {
				$last = (int) $r->ID;
				if ( preg_match_all( '/\[(fusion_[a-z0-9_]+)/', (string) $r->post_content, $m ) ) {
					foreach ( array_unique( $m[1] ) as $tag ) {
						$used[ $tag ][ $last ] = true;
					}
				}
			}
			$n = count( $rows );
			unset( $rows );
		} while ( $n === $batch );
		return $used;
	}

	/* ---------------------------------------------------------------
	 * Toepassen
	 * ------------------------------------------------------------- */

	/**
	 * Voert een aanbeveling uit in de changeset. Geeft true of WP_Error.
	 */
	public static function apply( $key, MCM_Perf_Changeset $cs ) {
		$f = self::fusion_options();
		$s = get_option( 'wp_rocket_settings' );
		$s = is_array( $s ) ? $s : null;

		switch ( $key ) {
			case 'avada_compilers':
				if ( $s && 1 === (int) ( $s['remove_unused_css'] ?? 0 ) ) {
					return new WP_Error( 'rucss', 'Zet eerst WP Rocket "Ongebruikte CSS verwijderen" uit — anders blijven de compilers geforceerd uit.' );
				}
				$cs->set( 'fusion_options', 'css_cache_method', 'file' );
				$cs->set( 'fusion_options', 'js_compiler', '1' );
				return true;

			case 'avada_rucss_off':
				if ( ! $s ) {
					return new WP_Error( 'norocket', 'WP Rocket-instellingen niet gevonden.' );
				}
				$cs->set( 'wp_rocket_settings', 'remove_unused_css', 0 );
				return true;

			case 'avada_minify_off':
				if ( ! $s ) {
					return new WP_Error( 'norocket', 'WP Rocket-instellingen niet gevonden.' );
				}
				foreach ( [ 'minify_css', 'minify_concatenate_css', 'minify_js', 'minify_concatenate_js' ] as $k ) {
					$cs->set( 'wp_rocket_settings', $k, 0 );
				}
				return true;

			case 'avada_delay_exclusions':
				if ( ! $s ) {
					return new WP_Error( 'norocket', 'WP Rocket-instellingen niet gevonden.' );
				}
				list( , $js_on ) = self::compilers_state( $f );
				if ( ! $js_on ) {
					return new WP_Error( 'js', 'Zet eerst de Avada JS-compiler aan.' );
				}
				$have = array_values( array_filter( array_map( 'trim', (array) ( $s['delay_js_exclusions'] ?? [] ) ) ) );
				$cs->set( 'wp_rocket_settings', 'delay_js_exclusions', array_values( array_unique( array_merge( $have, self::delay_patterns() ) ) ) );
				return true;

			case 'avada_lazyload':
				if ( ! $s ) {
					return new WP_Error( 'norocket', 'WP Rocket-instellingen niet gevonden.' );
				}
				$cs->set( 'wp_rocket_settings', 'lazyload', 1 );
				$cs->set( 'fusion_options', 'lazy_load', 'none' );
				return true;

			case 'avada_conflicts':
				if ( ! $s ) {
					return new WP_Error( 'norocket', 'WP Rocket-instellingen niet gevonden.' );
				}
				foreach ( array_keys( self::conflicts( $f, $s ) ) as $opt ) {
					$cs->set( 'fusion_options', $opt, '0' );
				}
				return true;

			case 'avada_revisions':
				$cs->set( 'fusion_options', 'post_revisions_limit', '5' );
				return true;

			case 'avada_webp':
				if ( ! wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ) {
					return new WP_Error( 'webp', 'De server ondersteunt geen WebP.' );
				}
				$cs->set( 'fusion_options', 'upload_image_format', 'webp' );
				return true;

			case 'rocket_config':
				if ( ! function_exists( 'rocket_generate_config_file' ) ) {
					return new WP_Error( 'norocket', 'WP Rocket is niet actief.' );
				}
				rocket_generate_config_file(); // Geen optie-wijziging: niets om terug te draaien.
				return true;

			case 'avada_elements_missing':
			case 'avada_elements_dupes':
				$settings = get_option( 'fusion_builder_settings', [] );
				if ( ! is_array( $settings ) || empty( $settings['fusion_elements'] ) || ! is_array( $settings['fusion_elements'] ) ) {
					return new WP_Error( 'none', 'Geen beperkte elementenlijst actief — alles staat al aan.' );
				}
				$list = array_values( array_unique( $settings['fusion_elements'] ) );
				if ( 'avada_elements_missing' === $key ) {
					foreach ( array_keys( self::element_report()['missing'] ) as $gate ) {
						$list[] = $gate;
					}
					$list = array_values( array_unique( $list ) );
				}
				$cs->set( 'fusion_builder_settings', 'fusion_elements', $list );
				return true;
		}
		return new WP_Error( 'unknown', 'Onbekende aanbeveling.' );
	}
}

/**
 * Verzamelt optie-wijzigingen (per sub-sleutel), schrijft ze in één keer en
 * onthoudt de oude waarden zodat ze terug te zetten zijn.
 */
class MCM_Perf_Changeset {

	private $opts    = [];
	private $changes = [];

	public function set( $option, $sub, $value ) {
		if ( ! array_key_exists( $option, $this->opts ) ) {
			$cur                   = get_option( $option, [] );
			$this->opts[ $option ] = is_array( $cur ) ? $cur : [];
		}
		$existed = array_key_exists( $sub, $this->opts[ $option ] );
		$old     = $existed ? $this->opts[ $option ][ $sub ] : null;
		if ( $existed && self::same( $old, $value ) ) {
			return;
		}
		$this->changes[]               = [ 'option' => $option, 'sub' => $sub, 'existed' => $existed, 'old' => $old, 'new' => $value ];
		$this->opts[ $option ][ $sub ] = $value;
	}

	private static function same( $a, $b ) {
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return (string) $a === (string) $b;
		}
		return $a === $b;
	}

	/** Schrijft de gewijzigde opties weg en geeft de wijzigingen terug. */
	public function commit() {
		foreach ( array_unique( array_column( $this->changes, 'option' ) ) as $option ) {
			update_option( $option, $this->opts[ $option ] );
		}
		return $this->changes;
	}

	/** Zet een eerder vastgelegde lijst wijzigingen terug (in omgekeerde volgorde). */
	public static function revert( array $changes ) {
		$touched = [];
		foreach ( array_reverse( $changes ) as $c ) {
			$opt = $c['option'];
			if ( ! isset( $touched[ $opt ] ) ) {
				$cur             = get_option( $opt, [] );
				$touched[ $opt ] = is_array( $cur ) ? $cur : [];
			}
			if ( ! empty( $c['existed'] ) ) {
				$touched[ $opt ][ $c['sub'] ] = $c['old'];
			} else {
				unset( $touched[ $opt ][ $c['sub'] ] );
			}
		}
		foreach ( $touched as $opt => $val ) {
			update_option( $opt, $val );
		}
		return array_keys( $touched );
	}
}

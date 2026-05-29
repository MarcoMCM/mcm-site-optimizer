<?php
/**
 * Nep-/botaccount Scanner — FASE 1: alleen detecteren + preview.
 *
 * Verwijdert NIETS. Toont een lijst customer-accounts met per account de
 * flag(s), zodat de beheerder zelf kan filteren en bewust kan beslissen.
 * Verwijderen komt in een latere versie (Fase 2) met dubbele bevestiging.
 *
 * Scope:
 *   - rol 'customer'
 *   - alle accounts, of alleen geregistreerd op/na een cutoff-datum (keuze in UI)
 *
 * Niets wordt vooraf uitgesloten — iedereen komt in de lijst, met flags. Filteren
 * gebeurt in het resultaat (tri-state per flag: alle / moet wel / moet niet).
 *
 * Flags — twee soorten:
 *   HARDE FEITEN (objectief uit de data):
 *     - geen-aankopen : nooit een WooCommerce-order (HPOS + legacy)
 *     - heeft-content : heeft posts of comments (= echte gebruiker)
 *   ZACHTE HEURISTIEKEN (signalen, kunnen vals-positief zijn):
 *     - gmail-dot-dup : Gmail dot/plus-trick wijst naar dezelfde inbox als ander account
 *     - bulk-registratie : geregistreerd in een piek-uur (mogelijk bot-golf of import)
 *     - spam-tld : e-mail op een verdachte TLD (.ru, .cn, ...)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Nepaccount_Scanner {

	const SCAN_OPT              = 'mcm_nepaccount_scan';
	const DEFAULT_ROLE         = 'customer';
	const BULK_THRESHOLD       = 10;  // 10+ registraties in 1 uur = piek
	const DEFAULT_CUTOFF_MONTHS = 12;

	/** Vaste flag-volgorde voor weergave + totalen. */
	private static function flag_keys() {
		return [ 'geen-aankopen', 'geen-naam', 'heeft-content', 'heeft-adres', 'heeft-contactformulier', 'gmail-dot-dup', 'bulk-registratie', 'spam-tld' ];
	}

	public function __construct() {
		add_action( 'wp_ajax_mcm_nepaccount_scan',   [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_mcm_nepaccount_export', [ $this, 'ajax_export' ] );
		add_action( 'wp_ajax_mcm_nepaccount_delete', [ $this, 'ajax_delete' ] );
		add_action( 'mcm_optimizer_render_cards',    [ $this, 'render_card' ] );
		add_action( 'admin_enqueue_scripts',         [ $this, 'assets' ] );
	}

	/* ---------------------------------------------------------------
	 * Detectie-helpers
	 * ------------------------------------------------------------- */

	/**
	 * Alle user-IDs die ooit een order hebben (HPOS + legacy postmeta).
	 *
	 * @return array<int,bool> map user_id => true
	 */
	private static function users_with_orders() {
		global $wpdb;
		$uids = [];

		// HPOS — High-Performance Order Storage (moderne WooCommerce).
		$hpos = $wpdb->prefix . 'wc_orders';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos ) ) === $hpos ) {
			foreach ( $wpdb->get_col( "SELECT DISTINCT customer_id FROM {$hpos} WHERE customer_id > 0" ) as $v ) {
				$uids[ (int) $v ] = true;
			}
		}

		// Legacy — shop_order posts + _customer_user meta.
		$rows = $wpdb->get_col(
			"SELECT DISTINCT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_customer_user'
			   AND p.post_type IN ('shop_order','shop_order_refund')
			   AND pm.meta_value > 0"
		);
		foreach ( $rows as $v ) {
			$uids[ (int) $v ] = true;
		}

		return $uids;
	}

	/**
	 * User-IDs die posts of comments bezitten (= echte activiteit).
	 *
	 * @param array<int> $ids kandidaat-IDs om te checken.
	 * @return array<int,bool>
	 */
	private static function users_with_content( array $ids ) {
		global $wpdb;
		if ( empty( $ids ) ) {
			return [];
		}
		$in  = implode( ',', array_map( 'absint', $ids ) );
		$out = [];

		// Alleen ECHT geschreven content. NIET shop_order (WooCommerce zet daar de klant
		// als auteur op) en niet flamingo_contact/bijlagen e.d.
		foreach ( $wpdb->get_col( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_author IN ($in) AND post_type IN ('post','page')" ) as $v ) {
			$out[ (int) $v ] = true;
		}
		foreach ( $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->comments} WHERE user_id IN ($in)" ) as $v ) {
			$out[ (int) $v ] = true;
		}
		return $out;
	}

	/**
	 * User-IDs die een contactformulier insturen (Flamingo). Eigen flag, geen
	 * verwijder-bescherming — alleen ter info/filtering.
	 *
	 * @param array<int> $ids
	 * @return array<int,bool>
	 */
	private static function users_with_contactform( array $ids ) {
		global $wpdb;
		if ( empty( $ids ) ) {
			return [];
		}
		$in  = implode( ',', array_map( 'absint', $ids ) );
		$out = [];
		foreach ( $wpdb->get_col( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_author IN ($in) AND post_type IN ('flamingo_contact','flamingo_inbound')" ) as $v ) {
			$out[ (int) $v ] = true;
		}
		return $out;
	}

	/**
	 * User-IDs ZONDER ingevulde naam (geen voor- én geen achternaam).
	 *
	 * @param array<int> $ids
	 * @return array<int,bool>
	 */
	private static function users_without_name( array $ids ) {
		global $wpdb;
		if ( empty( $ids ) ) {
			return [];
		}
		$in  = implode( ',', array_map( 'absint', $ids ) );
		$has = [];
		$rows = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta}
			 WHERE user_id IN ($in) AND meta_key IN ('first_name','last_name')"
		);
		foreach ( $rows as $r ) {
			if ( '' !== trim( (string) $r->meta_value ) ) {
				$has[ (int) $r->user_id ] = true;
			}
		}
		$out = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! isset( $has[ $id ] ) ) {
				$out[ $id ] = true;
			}
		}
		return $out;
	}

	/**
	 * User-IDs MÉT een ingevuld factuuradres (straat, postcode of plaats).
	 *
	 * @param array<int> $ids
	 * @return array<int,bool>
	 */
	private static function users_with_address( array $ids ) {
		global $wpdb;
		if ( empty( $ids ) ) {
			return [];
		}
		$in  = implode( ',', array_map( 'absint', $ids ) );
		$out = [];
		$rows = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta}
			 WHERE user_id IN ($in) AND meta_key IN ('billing_address_1','billing_postcode','billing_city')"
		);
		foreach ( $rows as $r ) {
			if ( '' !== trim( (string) $r->meta_value ) ) {
				$out[ (int) $r->user_id ] = true;
			}
		}
		return $out;
	}

	/**
	 * Normaliseer e-mail voor Gmail dot/plus-trick.
	 * m.ia.l+x@gmail.com  ->  mialaura@gmail.com
	 */
	private static function normalize_email( $email ) {
		$email = strtolower( trim( $email ) );
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return $email;
		}
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );

		if ( in_array( $domain, [ 'gmail.com', 'googlemail.com' ], true ) ) {
			// Alles na '+' weg.
			$plus = strpos( $local, '+' );
			if ( false !== $plus ) {
				$local = substr( $local, 0, $plus );
			}
			// Puntjes weg.
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com'; // googlemail = gmail.
		}
		return $local . '@' . $domain;
	}

	/** Verdachte TLD's (heuristiek — alleen als flag, niet als verwijdergrond). */
	private static function spam_tlds() {
		return apply_filters( 'mcm_nepaccount_spam_tlds', [
			'ru', 'su', 'cn', 'tk', 'ml', 'ga', 'cf', 'gq', 'top', 'xyz', 'click', 'work',
		] );
	}

	private static function email_tld( $email ) {
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return '';
		}
		$domain = substr( $email, $at + 1 );
		$dot    = strrpos( $domain, '.' );
		return $dot === false ? '' : strtolower( substr( $domain, $dot + 1 ) );
	}

	/* ---------------------------------------------------------------
	 * Hoofdscan
	 * ------------------------------------------------------------- */

	/**
	 * Scan alle customers (optioneel vanaf een cutoff-datum). Niets wordt
	 * uitgesloten; alles wordt als flag getoond.
	 *
	 * @param string $cutoff  Leeg = alle accounts. Anders MySQL datetime 'Y-m-d H:i:s'.
	 * @return array{candidates: array, summary: array}
	 */
	public static function scan( $cutoff = '' ) {
		global $wpdb;

		$cap_key = $wpdb->prefix . 'capabilities';

		// Customers ophalen (optioneel vanaf cutoff).
		$params = [ $cap_key, '%"' . self::DEFAULT_ROLE . '"%' ];
		$where_date = '';
		if ( $cutoff ) {
			$where_date = ' AND u.user_registered >= %s';
			$params[]   = $cutoff;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_login, u.user_email, u.user_registered
				 FROM {$wpdb->users} u
				 JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = %s
				 WHERE um.meta_value LIKE %s" . $where_date . "
				 ORDER BY u.user_registered ASC",
				$params
			)
		);

		$total = count( $rows );
		if ( empty( $rows ) ) {
			$s = self::empty_summary( $cutoff );
			update_option( self::SCAN_OPT, [ 'summary' => $s, 'candidates' => [] ], false );
			return [ 'candidates' => [], 'summary' => $s ];
		}

		// Harde feiten.
		$ids_all      = wp_list_pluck( $rows, 'ID' );
		$order_uids   = self::users_with_orders();           // globaal
		$content_uids = self::users_with_content( $ids_all ); // posts/comments
		$noname_uids  = self::users_without_name( $ids_all );   // geen naam
		$addr_uids    = self::users_with_address( $ids_all );   // factuuradres
		$contact_uids = self::users_with_contactform( $ids_all ); // contactformulier

		// Heuristiek-voorwerk: tel genormaliseerde e-mails en registratie-uren.
		$norm_count = [];
		$hour_count = [];
		foreach ( $rows as $r ) {
			$n = self::normalize_email( $r->user_email );
			$norm_count[ $n ] = ( $norm_count[ $n ] ?? 0 ) + 1;
			$h = substr( $r->user_registered, 0, 13 ); // 'YYYY-MM-DD HH'
			$hour_count[ $h ] = ( $hour_count[ $h ] ?? 0 ) + 1;
		}

		$spam_tlds = array_flip( self::spam_tlds() );

		// Bouw lijst + flags.
		$candidates  = [];
		$flag_totals = array_fill_keys( self::flag_keys(), 0 );

		foreach ( $rows as $r ) {
			$id    = (int) $r->ID;
			$flags = [];

			// Harde feiten.
			if ( ! isset( $order_uids[ $id ] ) ) {
				$flags[] = 'geen-aankopen';
				$flag_totals['geen-aankopen']++;
			}
			if ( isset( $noname_uids[ $id ] ) ) {
				$flags[] = 'geen-naam';
				$flag_totals['geen-naam']++;
			}
			if ( isset( $content_uids[ $id ] ) ) {
				$flags[] = 'heeft-content';
				$flag_totals['heeft-content']++;
			}
			if ( isset( $addr_uids[ $id ] ) ) {
				$flags[] = 'heeft-adres';
				$flag_totals['heeft-adres']++;
			}
			if ( isset( $contact_uids[ $id ] ) ) {
				$flags[] = 'heeft-contactformulier';
				$flag_totals['heeft-contactformulier']++;
			}

			// Zachte heuristieken.
			$n = self::normalize_email( $r->user_email );
			if ( ( $norm_count[ $n ] ?? 0 ) > 1 ) {
				$flags[] = 'gmail-dot-dup';
				$flag_totals['gmail-dot-dup']++;
			}
			$h = substr( $r->user_registered, 0, 13 );
			if ( ( $hour_count[ $h ] ?? 0 ) >= self::BULK_THRESHOLD ) {
				$flags[] = 'bulk-registratie';
				$flag_totals['bulk-registratie']++;
			}
			if ( isset( $spam_tlds[ self::email_tld( $r->user_email ) ] ) ) {
				$flags[] = 'spam-tld';
				$flag_totals['spam-tld']++;
			}

			$candidates[] = [
				'id'         => $id,
				'login'      => $r->user_login,
				'email'      => $r->user_email,
				'registered' => $r->user_registered,
				'flags'      => $flags,
			];
		}

		$summary = [
			'scope'       => $cutoff ? 'date' : 'all',
			'cutoff'      => $cutoff,
			'total'       => $total,
			'flag_totals' => $flag_totals,
			'scanned_at'  => current_time( 'mysql' ),
		];

		update_option( self::SCAN_OPT, [ 'summary' => $summary, 'candidates' => $candidates ], false );

		return [ 'candidates' => $candidates, 'summary' => $summary ];
	}

	private static function empty_summary( $cutoff ) {
		return [
			'scope'       => $cutoff ? 'date' : 'all',
			'cutoff'      => $cutoff,
			'total'       => 0,
			'flag_totals' => array_fill_keys( self::flag_keys(), 0 ),
			'scanned_at'  => current_time( 'mysql' ),
		];
	}

	/* ---------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------- */

	public function ajax_scan() {
		check_ajax_referer( 'mcm_nepaccount', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		$scope  = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
		$cutoff = '';
		if ( 'date' === $scope ) {
			$raw    = isset( $_POST['cutoff'] ) ? sanitize_text_field( wp_unslash( $_POST['cutoff'] ) ) : '';
			$cutoff = self::sanitize_cutoff( $raw );
		}

		$result = self::scan( $cutoff );
		wp_send_json_success( $result );
	}

	public function ajax_export() {
		check_ajax_referer( 'mcm_nepaccount', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Geen toegang.' );
		}
		$data = get_option( self::SCAN_OPT );
		if ( empty( $data['candidates'] ) ) {
			wp_die( 'Geen scan-resultaat om te exporteren. Draai eerst een scan.' );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=accounts-' . gmdate( 'Y-m-d-Hi' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'ID', 'user_login', 'user_email', 'user_registered', 'flags' ] );
		foreach ( $data['candidates'] as $c ) {
			fputcsv( $out, [ $c['id'], $c['login'], $c['email'], $c['registered'], implode( '|', $c['flags'] ) ] );
		}
		fclose( $out );
		exit;
	}

	/* ---------------------------------------------------------------
	 * Verwijderen (Fase 2) — alleen via expliciete bevestiging in de UI.
	 * ------------------------------------------------------------- */

	public function ajax_delete() {
		check_ajax_referer( 'mcm_nepaccount', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}
		$ids = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : [];
		$ids = array_map( 'absint', $ids );

		$result = self::delete_users( $ids );
		wp_send_json_success( $result );
	}

	/**
	 * Verwijder accounts — met een hard veiligheidsslot dat ONAFHANKELIJK van de
	 * UI opnieuw controleert. Verwijdert ALLEEN een account dat:
	 *   - bestaat, niet ID <= 1, niet de huidige gebruiker, geen super admin;
	 *   - uitsluitend de rol 'customer' heeft (geen extra/privileged rollen);
	 *   - geen WooCommerce-order heeft (HPOS + legacy);
	 *   - geen posts/comments heeft.
	 * Alles wat afvalt wordt overgeslagen mét reden. Vóór verwijderen wordt een
	 * CSV-back-up weggeschreven in een afgeschermde uploads-map.
	 *
	 * @param array<int> $ids
	 * @return array{deleted: array<int>, skipped: array<array{id:int,reason:string}>, log: string}
	 */
	public static function delete_users( array $ids ) {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$ids = array_values( array_filter( array_unique( array_map( 'absint', $ids ) ) ) );
		$deleted = [];
		$skipped = [];
		if ( empty( $ids ) ) {
			return [ 'deleted' => [], 'skipped' => [], 'log' => '' ];
		}

		$order_uids   = self::users_with_orders();
		$content_uids = self::users_with_content( $ids );
		$addr_uids    = self::users_with_address( $ids );
		$current      = get_current_user_id();

		$to_delete = [];
		$to_log    = [];
		foreach ( $ids as $id ) {
			if ( $id <= 1 ) {
				$skipped[] = [ 'id' => $id, 'reason' => 'beschermd account (ID <= 1)' ];
				continue;
			}
			if ( $id === $current ) {
				$skipped[] = [ 'id' => $id, 'reason' => 'huidige gebruiker' ];
				continue;
			}
			$u = get_userdata( $id );
			if ( ! $u ) {
				$skipped[] = [ 'id' => $id, 'reason' => 'bestaat niet (al verwijderd?)' ];
				continue;
			}
			$roles = array_values( (array) $u->roles );
			if ( 1 !== count( $roles ) || ! in_array( 'customer', $roles, true ) ) {
				$skipped[] = [ 'id' => $id, 'reason' => 'rol niet uitsluitend customer (' . implode( ',', $roles ) . ')' ];
				continue;
			}
			if ( is_super_admin( $id ) ) {
				$skipped[] = [ 'id' => $id, 'reason' => 'super admin' ];
				continue;
			}
			if ( isset( $order_uids[ $id ] ) ) {
				$skipped[] = [ 'id' => $id, 'reason' => 'heeft een order' ];
				continue;
			}
			if ( isset( $content_uids[ $id ] ) ) {
				$skipped[] = [ 'id' => $id, 'reason' => 'heeft posts/comments' ];
				continue;
			}
			if ( isset( $addr_uids[ $id ] ) ) {
				$skipped[] = [ 'id' => $id, 'reason' => 'heeft een factuuradres' ];
				continue;
			}
			$to_delete[] = $id;
			$to_log[]    = [ $id, $u->user_login, $u->user_email, $u->user_registered ];
		}

		// Back-up wegschrijven VOOR verwijderen.
		$log_path = '';
		if ( $to_log ) {
			$log_path = self::append_delete_log( $to_log );
		}

		foreach ( $to_delete as $id ) {
			if ( wp_delete_user( $id ) ) {
				$deleted[] = $id;
			} else {
				$skipped[] = [ 'id' => $id, 'reason' => 'verwijderen mislukt' ];
			}
		}

		return [ 'deleted' => $deleted, 'skipped' => $skipped, 'log' => $log_path ];
	}

	/**
	 * Schrijf verwijderde accounts naar een afgeschermd CSV-logbestand.
	 *
	 * @param array<array{0:int,1:string,2:string,3:string}> $rows
	 * @return string Pad naar het logbestand (of '' bij falen).
	 */
	private static function append_delete_log( array $rows ) {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'mcm-nepaccount-logs';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// Webtoegang blokkeren (bevat e-mailadressen).
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			@file_put_contents( $dir . '/index.html', '' );
		}
		$file = trailingslashit( $dir ) . 'verwijderd-' . gmdate( 'Y-m-d' ) . '.csv';
		$new  = ! file_exists( $file );
		$fh   = fopen( $file, 'a' );
		if ( ! $fh ) {
			return '';
		}
		if ( $new ) {
			fputcsv( $fh, [ 'deleted_at_utc', 'ID', 'user_login', 'user_email', 'user_registered' ] );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $rows as $r ) {
			fputcsv( $fh, array_merge( [ $now ], $r ) );
		}
		fclose( $fh );
		return $file;
	}

	private static function sanitize_cutoff( $val ) {
		// Verwacht 'Y-m-d' of 'Y-m-d H:i:s'. Anders default: X maanden terug.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $val ) ) {
			return strlen( $val ) === 10 ? $val . ' 00:00:00' : $val;
		}
		return gmdate( 'Y-m-d 00:00:00', strtotime( '-' . self::DEFAULT_CUTOFF_MONTHS . ' months' ) );
	}

	/* ---------------------------------------------------------------
	 * UI
	 * ------------------------------------------------------------- */

	public function assets( $hook ) {
		if ( 'tools_page_mcm-tools' !== $hook ) {
			return;
		}
		wp_register_script( 'mcm-nepaccount', '', [ 'jquery' ], '1.4.0', true );
		wp_enqueue_script( 'mcm-nepaccount' );
		wp_localize_script( 'mcm-nepaccount', 'MCM_NEP', [
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'mcm_nepaccount' ),
			'export' => wp_nonce_url( admin_url( 'admin-ajax.php?action=mcm_nepaccount_export' ), 'mcm_nepaccount', 'nonce' ),
		] );
		wp_add_inline_script( 'mcm-nepaccount', self::inline_js() );
	}

	public function render_card() {
		$default_cutoff = gmdate( 'Y-m-d', strtotime( '-' . self::DEFAULT_CUTOFF_MONTHS . ' months' ) );
		?>
		<div class="mcm-opt-card">
			<div class="mcm-opt-card-header">
				<span class="dashicons dashicons-groups"></span>
				<h2>Nep-/botaccounts</h2>
			</div>
			<div class="mcm-opt-card-body">
				<p class="description" style="margin-top:0;">
					Scant <strong>customer</strong>-accounts en toont per account flags. Niets wordt
					vooraf weggelaten — je filtert zelf in het resultaat. <strong>Deze scan verwijdert niets.</strong>
				</p>

				<p style="margin:10px 0;">
					<label style="margin-right:16px;">
						<input type="radio" name="mcm-nep-scope" value="all" checked /> Alle klanten
					</label>
					<label>
						<input type="radio" name="mcm-nep-scope" value="date" /> Alleen vanaf:
						<input type="date" id="mcm-nep-cutoff" value="<?php echo esc_attr( $default_cutoff ); ?>" />
					</label>
					<button type="button" id="mcm-nep-scan" class="button mcm-opt-btn-primary" style="margin-left:8px;">
						<span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
						Scan starten
					</button>
				</p>

				<div id="mcm-nep-loading" style="display:none;">
					<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span> Bezig met scannen...
				</div>

				<div id="mcm-nep-summary" style="display:none;"></div>
				<div id="mcm-nep-filter" style="display:none;"></div>
				<div id="mcm-nep-results"></div>
				<div id="mcm-nep-delete" style="margin-top:12px;"></div>
			</div>
		</div>
		<?php
	}

	private static function inline_js() {
		return <<<'JS'
(function($){
	var FLAGS = [
		{key:'geen-aankopen',    label:'Geen aankopen',    color:'#9d2d2d', desc:'Heeft nooit een WooCommerce-order geplaatst (HPOS en legacy gecontroleerd).'},
		{key:'geen-naam',        label:'Geen naam',        color:'#9d2d2d', desc:'Geen voor- en achternaam ingevuld — wie echt wil kopen vult dit meestal wel in.'},
		{key:'heeft-content',    label:'Heeft content',    color:'#3c6e47', desc:'Heeft posts of comments — bijvoorbeeld een productreview. Dit is een echte gebruiker; meestal NIET verwijderen.'},
		{key:'heeft-adres',      label:'Heeft adres',      color:'#3c6e47', desc:'Heeft een factuuradres ingevuld — vrijwel zeker een echte (intentionele) klant; wordt NIET verwijderd.'},
		{key:'heeft-contactformulier', label:'Contactformulier', color:'#5b7a9d', desc:'Heeft een contactformulier ingestuurd (Flamingo) — teken van een echt mens, maar beschermt NIET automatisch tegen verwijderen.'},
		{key:'gmail-dot-dup',    label:'Gmail dot-dup',    color:'#b95e41', desc:'Gmail dot/plus-truc: marco+1@gmail.com en ma.rco@gmail.com wijzen naar dezelfde inbox als een ander account.'},
		{key:'bulk-registratie', label:'Bulk-registratie', color:'#824131', desc:'10 of meer accounts in hetzelfde uur aangemaakt — mogelijk een bot-golf of een import.'},
		{key:'spam-tld',         label:'Spam-TLD',         color:'#9d2d2d', desc:'E-mail op een verdacht top-level domein (.ru, .cn, .tk, .xyz, ...).'}
	];
	var labelOf = {}, colorOf = {}, descOf = {};
	FLAGS.forEach(function(f){ labelOf[f.key]=f.label; colorOf[f.key]=f.color; descOf[f.key]=f.desc; });

	// Filterstatus per flag: 0 = alle, 1 = moet wel, 2 = moet niet.
	var filterState = {};
	FLAGS.forEach(function(f){ filterState[f.key]=0; });

	function badge(f){
		return '<span title="'+(descOf[f]||'')+'" style="display:inline-block;padding:1px 7px;margin:1px 3px 1px 0;border-radius:3px;cursor:help;'
			+'font-size:10px;font-weight:600;color:#fff;background:'+(colorOf[f]||'#666')+'">'
			+(labelOf[f]||f)+'</span>';
	}

	function esc(t){ return $('<i>').text(t==null?'':t).html(); }

	function stateLabel(s){ return s===1 ? 'moet wél' : (s===2 ? 'moet níét' : 'alle'); }
	function stateStyle(s){
		if(s===1){ return 'background:#3c6e47;color:#fff;border-color:#2f5638;'; }
		if(s===2){ return 'background:#9d2d2d;color:#fff;border-color:#7d2222;'; }
		return 'background:#f0f0f1;color:#50575e;border-color:#c3c4c7;';
	}

	var FBTN_BASE='cursor:pointer;margin:6px 6px 0 0;padding:3px 9px;border:1px solid;border-radius:3px;font-size:11px;font-weight:600;';
	function btnText(key,count,s){ return labelOf[key]+' ('+count+'): '+stateLabel(s); }

	function renderFilterBar(totals){
		var html = '<div style="background:#fff;border:1px solid #e4e6e8;border-radius:6px;padding:10px 12px;margin:6px 0 10px;">'
			+'<strong style="font-size:12px;">Filter op flags</strong> '
			+'<span style="font-size:11px;color:#646970;">(klik: alle &rarr; moet wél &rarr; moet níét &middot; hover voor uitleg)</span><br>';
		html += '<div style="margin:8px 0;"><input type="search" id="mcm-nep-search" placeholder="Zoek op login, e-mail of ID..." style="width:300px;max-width:100%;padding:3px 8px;" /></div>';
		FLAGS.forEach(function(f){
			var s = filterState[f.key], cnt = totals[f.key]||0;
			html += '<button type="button" class="mcm-nep-fbtn" data-flag="'+f.key+'" data-count="'+cnt+'" '
				+'title="'+(descOf[f.key]||'')+'" '
				+'style="'+FBTN_BASE+stateStyle(s)+'">'+btnText(f.key,cnt,s)+'</button>';
		});
		html += ' <button type="button" id="mcm-nep-reset" class="button button-small" style="margin-top:6px;">Reset filter</button>';
		html += '<div id="mcm-nep-count" style="margin-top:8px;font-size:12px;color:#646970;"></div>';
		html += '<div style="margin-top:8px;"><a href="#" id="mcm-nep-legend-toggle" style="font-size:11px;text-decoration:none;">&#9656; Wat betekenen de flags?</a></div>';
		html += '<div id="mcm-nep-legend" style="display:none;margin-top:6px;font-size:12px;color:#3c434a;">';
		FLAGS.forEach(function(f){
			html += '<div style="margin:4px 0;line-height:1.5;">'+badge(f.key)+' '+descOf[f.key]+'</div>';
		});
		html += '</div>';
		html += '</div>';
		return html;
	}

	function applyFilter(){
		var req=[], exc=[];
		FLAGS.forEach(function(f){
			if(filterState[f.key]===1){ req.push(f.key); }
			else if(filterState[f.key]===2){ exc.push(f.key); }
		});
		var term = ($('#mcm-nep-search').val()||'').toLowerCase().trim();
		var shown=0, total=0;
		$('#mcm-nep-results tbody tr').each(function(){
			total++;
			var raw = ($(this).attr('data-flags')||'');
			var flags = raw.length ? raw.split(' ') : [];
			var ok = req.every(function(f){ return flags.indexOf(f)>=0; })
				&& exc.every(function(f){ return flags.indexOf(f)<0; });
			if(ok && term){ ok = (($(this).attr('data-search')||'').indexOf(term) >= 0); }
			this.style.display = ok ? '' : 'none';
			if(ok){ shown++; }
		});
		$('#mcm-nep-count').html('<strong>'+shown+'</strong> van '+total+' accounts getoond.');
		updateDeleteCount();
	}

	function checkedIds(){
		var ids=[];
		$('#mcm-nep-results tbody tr').each(function(){
			if($(this).find('.mcm-nep-cb').is(':checked')){
				var v=$(this).attr('data-id');
				if(v){ ids.push(parseInt(v,10)); }
			}
		});
		return ids;
	}

	function updateDeleteCount(){
		var n = $('#mcm-nep-results tbody tr .mcm-nep-cb:checked').length;
		$('#mcm-nep-del-count').text(n);
		$('#mcm-nep-del-start').prop('disabled', n===0);
	}

	function renderDeleteControl(){
		var html = '<div style="border:1px solid #f0c0c0;background:#fff6f6;border-radius:6px;padding:12px 14px;">'
			+'<p style="margin:0 0 8px;font-size:12px;color:#646970;">Vink de accounts aan die je wilt verwijderen. Tip: filter/zoek eerst, gebruik dan "Selecteer alle zichtbare".</p>'
			+'<button type="button" id="mcm-nep-sel-all" class="button button-small">Selecteer alle zichtbare</button> '
			+'<button type="button" id="mcm-nep-desel-all" class="button button-small">Deselecteer alle</button><br><br>'
			+'<button type="button" id="mcm-nep-del-start" class="button" style="background:#9d2d2d;border-color:#7d2222;color:#fff;">'
			+'Verwijder de aangevinkte accounts (<span id="mcm-nep-del-count">0</span>)</button>'
			+'<div id="mcm-nep-del-confirm" style="display:none;margin-top:10px;">'
			+'<p style="margin:.4em 0;color:#9d2d2d;"><strong>Let op: verwijderen is onomkeerbaar.</strong> Alleen accounts met uitsluitend de rol customer, zónder order en zónder posts/comments worden verwijderd; de rest wordt automatisch overgeslagen. Er wordt vóór verwijderen een CSV-back-up op de server weggeschreven.</p>'
			+'<label style="display:block;margin:.4em 0;"><input type="checkbox" id="mcm-nep-del-backup"/> Ik heb een database-backup gemaakt.</label>'
			+'<label style="display:block;margin:.4em 0;">Typ <strong>VERWIJDER</strong> om te bevestigen: <input type="text" id="mcm-nep-del-word" autocomplete="off" style="width:150px;"/></label>'
			+'<button type="button" id="mcm-nep-del-go" class="button" disabled style="background:#9d2d2d;border-color:#7d2222;color:#fff;">Definitief verwijderen</button> '
			+'<button type="button" id="mcm-nep-del-cancel" class="button">Annuleer</button>'
			+'</div>'
			+'<div id="mcm-nep-del-progress" style="margin-top:8px;font-size:13px;"></div>'
			+'</div>';
		$('#mcm-nep-delete').html(html);
		updateDeleteCount();
	}

	function delGoState(){
		var ok = ($('#mcm-nep-del-word').val()==='VERWIJDER') && $('#mcm-nep-del-backup').is(':checked');
		$('#mcm-nep-del-go').prop('disabled', !ok);
	}

	function doDelete(ids){
		var CHUNK=100, idx=0, deleted=0, skipped=[], logPath='';
		var $prog=$('#mcm-nep-del-progress');
		function finish(){
			var msg='<strong>'+deleted+' accounts verwijderd.</strong>';
			if(logPath){ msg+='<br><span style="font-size:12px;color:#646970;">Back-up: '+esc(logPath)+'</span>'; }
			if(skipped.length){
				msg+='<br>'+skipped.length+' overgeslagen door het veiligheidsslot:<br><ul style="margin:4px 0 0 18px;">';
				skipped.slice(0,50).forEach(function(s){ msg+='<li>#'+s.id+' — '+esc(s.reason)+'</li>'; });
				if(skipped.length>50){ msg+='<li>... en '+(skipped.length-50)+' meer</li>'; }
				msg+='</ul>';
			}
			msg+='<br><em>Draai een nieuwe scan om de bijgewerkte lijst te zien.</em>';
			$prog.html(msg);
		}
		function next(){
			if(idx>=ids.length){ finish(); return; }
			var batch=ids.slice(idx, idx+CHUNK);
			$.post(MCM_NEP.ajax, { action:'mcm_nepaccount_delete', nonce:MCM_NEP.nonce, ids:batch })
			.done(function(res){
				if(res && res.success){
					deleted += (res.data.deleted||[]).length;
					skipped = skipped.concat(res.data.skipped||[]);
					if(res.data.log){ logPath=res.data.log; }
					idx += CHUNK;
					$prog.text('Bezig... '+deleted+' verwijderd van max '+ids.length+'.');
					next();
				} else {
					$prog.html('<span style="color:#9d2d2d;">Fout bij verwijderen. Gestopt na '+deleted+'.</span>');
				}
			})
			.fail(function(){ $prog.html('<span style="color:#9d2d2d;">Serverfout. Gestopt na '+deleted+'.</span>'); });
		}
		$prog.text('Bezig met verwijderen...');
		next();
	}

	$(document).on('click','#mcm-nep-scan',function(){
		var scope = $('input[name=mcm-nep-scope]:checked').val() || 'all';
		var cutoff = $('#mcm-nep-cutoff').val();
		$('#mcm-nep-loading').show();
		$('#mcm-nep-summary').hide().empty();
		$('#mcm-nep-filter').hide().empty();
		$('#mcm-nep-results').empty();
		$('#mcm-nep-delete').empty();
		$.post(MCM_NEP.ajax, { action:'mcm_nepaccount_scan', nonce:MCM_NEP.nonce, scope:scope, cutoff:cutoff })
		.done(function(res){
			$('#mcm-nep-loading').hide();
			if(!res || !res.success){ $('#mcm-nep-results').html('<p style="color:#9d2d2d;">Scan mislukt.</p>'); return; }
			var s = res.data.summary, c = res.data.candidates || [];
			var ft = s.flag_totals || {};
			var scopeText = s.scope==='date' ? ('vanaf '+(s.cutoff||'').substring(0,10)) : 'alle klanten';

			$('#mcm-nep-summary').show().html(
				'<div style="background:#f6f7f7;border:1px solid #e4e6e8;border-radius:6px;padding:12px 14px;margin:6px 0 10px;">'
				+'<strong>'+c.length+' customer-accounts</strong> gescand ('+scopeText+').<br>'
				+'<span style="font-size:12px;color:#646970;">'
				+'Geen aankopen '+(ft['geen-aankopen']||0)+' &middot; '
				+'Heeft content '+(ft['heeft-content']||0)+' &middot; '
				+'Gmail dot-dup '+(ft['gmail-dot-dup']||0)+' &middot; '
				+'Bulk-registratie '+(ft['bulk-registratie']||0)+' &middot; '
				+'Spam-TLD '+(ft['spam-tld']||0)
				+'</span></div>'
				+ (c.length ? '<p><a href="'+MCM_NEP.export+'" class="button">Exporteer CSV (volledige lijst)</a></p>' : '')
			);

			if(!c.length){ return; }

			// Filterstatus resetten bij nieuwe scan.
			FLAGS.forEach(function(f){ filterState[f.key]=0; });
			$('#mcm-nep-filter').show().html(renderFilterBar(ft));

			var rows = '';
			c.forEach(function(x){
				var fl = (x.flags||[]).map(badge).join('') || '<span style="color:#999;font-size:11px;">&mdash;</span>';
				var srch = (x.id+' '+(x.login||'')+' '+(x.email||'')).toLowerCase().replace(/"/g,'');
				rows += '<tr data-id="'+x.id+'" data-flags="'+(x.flags||[]).join(' ')+'" data-search="'+srch+'">'
					+'<td style="text-align:center;"><input type="checkbox" class="mcm-nep-cb" /></td>'
					+'<td>'+x.id+'</td><td>'+esc(x.login)+'</td><td>'+esc(x.email)+'</td>'
					+'<td>'+esc(x.registered)+'</td><td>'+fl+'</td></tr>';
			});
			$('#mcm-nep-results').html(
				'<table class="widefat striped" style="margin-top:4px;"><thead><tr>'
				+'<th style="width:28px;text-align:center;"><input type="checkbox" id="mcm-nep-cb-all" title="Alle zichtbare aan/uit" /></th>'
				+'<th>ID</th><th>Login</th><th>E-mail</th><th>Geregistreerd</th><th>Flags</th>'
				+'</tr></thead><tbody>'+rows+'</tbody></table>'
				+'<p class="description" style="margin-top:8px;">Filter de lijst tot precies de accounts die je weg wilt. De verwijder-knop hieronder werkt op exact wat zichtbaar is.</p>'
			);
			applyFilter();
			renderDeleteControl();
		})
		.fail(function(){ $('#mcm-nep-loading').hide(); $('#mcm-nep-results').html('<p style="color:#9d2d2d;">Serverfout bij scannen.</p>'); });
	});

	// Tri-state flag-knoppen: alle -> moet wel -> moet niet -> alle.
	$(document).on('click','.mcm-nep-fbtn',function(){
		var key = $(this).data('flag');
		var cnt = $(this).data('count');
		filterState[key] = (filterState[key]+1) % 3;
		$(this).attr('style', FBTN_BASE+stateStyle(filterState[key])).text(btnText(key,cnt,filterState[key]));
		applyFilter();
	});

	$(document).on('click','#mcm-nep-reset',function(){
		$('.mcm-nep-fbtn').each(function(){
			var key=$(this).data('flag'), cnt=$(this).data('count');
			filterState[key]=0;
			$(this).attr('style', FBTN_BASE+stateStyle(0)).text(btnText(key,cnt,0));
		});
		applyFilter();
	});

	$(document).on('click','#mcm-nep-legend-toggle',function(e){
		e.preventDefault();
		var $l = $('#mcm-nep-legend');
		$l.toggle();
		$(this).html(($l.is(':visible')?'&#9662; ':'&#9656; ')+'Wat betekenen de flags?');
	});

	// Verwijderen: bevestigingsflow.
	$(document).on('click','#mcm-nep-del-start',function(){
		$('#mcm-nep-del-confirm').show();
		$('#mcm-nep-del-word').val(''); $('#mcm-nep-del-backup').prop('checked',false);
		delGoState();
	});
	$(document).on('click','#mcm-nep-del-cancel',function(){
		$('#mcm-nep-del-confirm').hide();
	});
	$(document).on('input','#mcm-nep-del-word',delGoState);
	$(document).on('change','#mcm-nep-del-backup',delGoState);
	$(document).on('click','#mcm-nep-del-go',function(){
		var ids=checkedIds();
		if(!ids.length){ return; }
		if($('#mcm-nep-del-word').val()!=='VERWIJDER' || !$('#mcm-nep-del-backup').is(':checked')){ return; }
		$('#mcm-nep-del-go,#mcm-nep-del-cancel,#mcm-nep-del-start').prop('disabled',true);
		$('#mcm-nep-del-word,#mcm-nep-del-backup').prop('disabled',true);
		doDelete(ids);
	});

	// Aanvinken / selecteren.
	$(document).on('change','.mcm-nep-cb', updateDeleteCount);
	$(document).on('change','#mcm-nep-cb-all', function(){
		var c=this.checked;
		$('#mcm-nep-results tbody tr:visible').find('.mcm-nep-cb').prop('checked', c);
		updateDeleteCount();
	});
	$(document).on('click','#mcm-nep-sel-all', function(){
		$('#mcm-nep-results tbody tr:visible').find('.mcm-nep-cb').prop('checked', true);
		updateDeleteCount();
	});
	$(document).on('click','#mcm-nep-desel-all', function(){
		$('#mcm-nep-results .mcm-nep-cb').prop('checked', false);
		$('#mcm-nep-cb-all').prop('checked', false);
		updateDeleteCount();
	});
	$(document).on('input','#mcm-nep-search', applyFilter);
})(jQuery);
JS;
	}
}

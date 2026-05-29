<?php
/**
 * Nep-/botaccount Scanner — FASE 1: alleen detecteren + preview.
 *
 * Verwijdert NIETS. Toont een lijst verdachte accounts met per account de
 * reden(en) waarom het verdacht is, zodat de beheerder bewust kan beslissen.
 * Verwijderen komt in een latere versie (Fase 2) met dubbele bevestiging.
 *
 * Hoofdfilter (een account is alleen kandidaat als ALLE waar zijn):
 *   - rol 'customer'
 *   - NOOIT een WooCommerce-order (alle statussen) — HPOS + legacy
 *   - geregistreerd op/na de cutoff-datum
 *
 * Harde uitsluiting (komt NOOIT in de lijst, ongeacht flags):
 *   - heeft ooit een order geplaatst
 *   - heeft posts (auteur)
 *   - heeft comments
 *
 * Flags (verdacht-signalen, NIET zelfstandig een verwijdergrond):
 *   - gmail-dot-dup : Gmail dot/plus-trick wijst naar zelfde inbox als ander account
 *   - bulk-registratie : geregistreerd in een piek-uur (mogelijk bot-golf of import)
 *   - spam-tld : e-mail op een verdachte TLD (.ru, .cn, ...)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Nepaccount_Scanner {

	const SCAN_OPT          = 'mcm_nepaccount_scan';
	const DEFAULT_ROLE      = 'customer';
	const BULK_THRESHOLD    = 10;  // 10+ registraties in 1 uur = piek
	const DEFAULT_CUTOFF_MONTHS = 12;

	public function __construct() {
		add_action( 'wp_ajax_mcm_nepaccount_scan',   [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_mcm_nepaccount_export', [ $this, 'ajax_export' ] );
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

		foreach ( $wpdb->get_col( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_author IN ($in)" ) as $v ) {
			$out[ (int) $v ] = true;
		}
		foreach ( $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->comments} WHERE user_id IN ($in)" ) as $v ) {
			$out[ (int) $v ] = true;
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
	 * @param string $cutoff  MySQL datetime 'Y-m-d H:i:s'
	 * @return array{candidates: array, summary: array}
	 */
	public static function scan( $cutoff ) {
		global $wpdb;

		$cap_key = $wpdb->prefix . 'capabilities';

		// 1. Customers geregistreerd op/na cutoff.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_login, u.user_email, u.user_registered
				 FROM {$wpdb->users} u
				 JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = %s
				 WHERE um.meta_value LIKE %s
				   AND u.user_registered >= %s
				 ORDER BY u.user_registered ASC",
				$cap_key,
				'%"' . self::DEFAULT_ROLE . '"%',
				$cutoff
			)
		);

		$total_recent_customers = count( $rows );
		if ( empty( $rows ) ) {
			return [
				'candidates' => [],
				'summary'    => self::empty_summary( $cutoff ),
			];
		}

		// 2. Order-bezitters uitsluiten (harde regel).
		$order_uids = self::users_with_orders();

		$pre = [];
		foreach ( $rows as $r ) {
			if ( isset( $order_uids[ (int) $r->ID ] ) ) {
				continue; // ooit gekocht -> nooit verdacht.
			}
			$pre[ (int) $r->ID ] = $r;
		}

		// 3. Content-bezitters uitsluiten (harde regel).
		$content_uids = self::users_with_content( array_keys( $pre ) );
		foreach ( $content_uids as $uid => $_ ) {
			unset( $pre[ $uid ] );
		}

		if ( empty( $pre ) ) {
			$s = self::empty_summary( $cutoff );
			$s['recent_customers'] = $total_recent_customers;
			return [ 'candidates' => [], 'summary' => $s ];
		}

		// 4a. Gmail dot/plus duplicaten — normaliseer + tel.
		$norm_count = [];
		foreach ( $pre as $r ) {
			$n = self::normalize_email( $r->user_email );
			$norm_count[ $n ] = ( $norm_count[ $n ] ?? 0 ) + 1;
		}

		// 4b. Bulk-registratie — tel per uur.
		$hour_count = [];
		foreach ( $pre as $r ) {
			$h = substr( $r->user_registered, 0, 13 ); // 'YYYY-MM-DD HH'
			$hour_count[ $h ] = ( $hour_count[ $h ] ?? 0 ) + 1;
		}

		$spam_tlds = array_flip( self::spam_tlds() );

		// 5. Bouw kandidaten + flags.
		$candidates = [];
		$flag_totals = [ 'gmail-dot-dup' => 0, 'bulk-registratie' => 0, 'spam-tld' => 0 ];

		foreach ( $pre as $r ) {
			$flags = [];

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
				'id'         => (int) $r->ID,
				'login'      => $r->user_login,
				'email'      => $r->user_email,
				'registered' => $r->user_registered,
				'flags'      => $flags,
			];
		}

		$summary = [
			'cutoff'           => $cutoff,
			'recent_customers' => $total_recent_customers,
			'with_order'       => $total_recent_customers - count( $pre ) - count( $content_uids ),
			'with_content'     => count( $content_uids ),
			'candidates'       => count( $candidates ),
			'flag_totals'      => $flag_totals,
			'scanned_at'       => current_time( 'mysql' ),
		];

		update_option( self::SCAN_OPT, [ 'summary' => $summary, 'candidates' => $candidates ], false );

		return [ 'candidates' => $candidates, 'summary' => $summary ];
	}

	private static function empty_summary( $cutoff ) {
		return [
			'cutoff'           => $cutoff,
			'recent_customers' => 0,
			'with_order'       => 0,
			'with_content'     => 0,
			'candidates'       => 0,
			'flag_totals'      => [ 'gmail-dot-dup' => 0, 'bulk-registratie' => 0, 'spam-tld' => 0 ],
			'scanned_at'       => current_time( 'mysql' ),
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
		$cutoff = isset( $_POST['cutoff'] ) ? sanitize_text_field( wp_unslash( $_POST['cutoff'] ) ) : '';
		$cutoff = self::sanitize_cutoff( $cutoff );

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
		header( 'Content-Disposition: attachment; filename=nepaccounts-' . gmdate( 'Y-m-d-Hi' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'ID', 'user_login', 'user_email', 'user_registered', 'flags' ] );
		foreach ( $data['candidates'] as $c ) {
			fputcsv( $out, [ $c['id'], $c['login'], $c['email'], $c['registered'], implode( '|', $c['flags'] ) ] );
		}
		fclose( $out );
		exit;
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
		// Alleen op de optimizer-pagina (zelfde guard als andere modules gebruiken).
		if ( false === strpos( (string) $hook, 'mcm' ) && 'settings_page_mcm-site-optimizer' !== $hook && 'tools_page_mcm-site-optimizer' !== $hook ) {
			// Laat toe; we enqueuen inline dus brede match is OK. Geen harde return.
		}
		wp_register_script( 'mcm-nepaccount', '', [ 'jquery' ], '1.4.0', true );
		wp_enqueue_script( 'mcm-nepaccount' );
		wp_localize_script( 'mcm-nepaccount', 'MCM_NEP', [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'mcm_nepaccount' ),
			'export'=> wp_nonce_url( admin_url( 'admin-ajax.php?action=mcm_nepaccount_export' ), 'mcm_nepaccount', 'nonce' ),
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
					Zoekt verdachte <strong>customer</strong>-accounts: geregistreerd na de datum hieronder,
					met <strong>nooit een order</strong> en geen posts/comments. Wie ooit kocht of content heeft,
					wordt altijd uitgesloten. <strong>Deze scan verwijdert niets</strong> — je krijgt alleen een lijst.
				</p>

				<p style="margin:10px 0;">
					<label>Geregistreerd op of na:
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
				<div id="mcm-nep-results"></div>
			</div>
		</div>
		<?php
	}

	private static function inline_js() {
		return <<<'JS'
(function($){
	function badge(f){
		var labels = {
			'gmail-dot-dup':'Gmail dot-dup',
			'bulk-registratie':'Bulk-registratie',
			'spam-tld':'Spam-TLD'
		};
		var colors = {
			'gmail-dot-dup':'#b95e41',
			'bulk-registratie':'#824131',
			'spam-tld':'#9d2d2d'
		};
		return '<span style="display:inline-block;padding:1px 7px;margin:1px 3px 1px 0;border-radius:3px;font-size:10px;font-weight:600;color:#fff;background:'+(colors[f]||'#666')+'">'+(labels[f]||f)+'</span>';
	}
	$(document).on('click','#mcm-nep-scan',function(){
		var cutoff = $('#mcm-nep-cutoff').val();
		$('#mcm-nep-loading').show();
		$('#mcm-nep-summary').hide().empty();
		$('#mcm-nep-results').empty();
		$.post(MCM_NEP.ajax, { action:'mcm_nepaccount_scan', nonce:MCM_NEP.nonce, cutoff:cutoff })
		.done(function(res){
			$('#mcm-nep-loading').hide();
			if(!res || !res.success){ $('#mcm-nep-results').html('<p style="color:#9d2d2d;">Scan mislukt.</p>'); return; }
			var s = res.data.summary, c = res.data.candidates || [];
			var ft = s.flag_totals || {};
			$('#mcm-nep-summary').show().html(
				'<div style="background:#f6f7f7;border:1px solid #e4e6e8;border-radius:6px;padding:12px 14px;margin:6px 0 12px;">'
				+'<strong>'+c.length+' verdachte accounts</strong> gevonden.<br>'
				+'<span style="font-size:12px;color:#646970;">'
				+'Recente customers gescand: '+s.recent_customers+' &middot; '
				+'uitgesloten (had order): '+s.with_order+' &middot; '
				+'uitgesloten (content): '+s.with_content+'<br>'
				+'Flags: Gmail dot-dup '+(ft['gmail-dot-dup']||0)+' &middot; '
				+'Bulk-registratie '+(ft['bulk-registratie']||0)+' &middot; '
				+'Spam-TLD '+(ft['spam-tld']||0)
				+'</span></div>'
				+ (c.length ? '<p><a href="'+MCM_NEP.export+'" class="button">Exporteer CSV</a></p>' : '')
			);
			if(!c.length){ return; }
			var rows = '';
			c.forEach(function(x){
				var fl = (x.flags||[]).map(badge).join('') || '<span style="color:#999;font-size:11px;">—</span>';
				rows += '<tr><td>'+x.id+'</td><td>'+$('<i>').text(x.login).html()+'</td><td>'
					+ $('<i>').text(x.email).html()+'</td><td>'+x.registered+'</td><td>'+fl+'</td></tr>';
			});
			$('#mcm-nep-results').html(
				'<table class="widefat striped" style="margin-top:8px;"><thead><tr>'
				+'<th>ID</th><th>Login</th><th>E-mail</th><th>Geregistreerd</th><th>Flags</th>'
				+'</tr></thead><tbody>'+rows+'</tbody></table>'
				+'<p class="description" style="margin-top:8px;">Dit is alleen een overzicht. Verwijderen zit (nog) niet in deze versie — eerst controleren of deze lijst klopt.</p>'
			);
		})
		.fail(function(){ $('#mcm-nep-loading').hide(); $('#mcm-nep-results').html('<p style="color:#9d2d2d;">Serverfout bij scannen.</p>'); });
	});
})(jQuery);
JS;
	}
}

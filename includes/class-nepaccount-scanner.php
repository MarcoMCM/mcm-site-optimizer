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
		return [ 'geen-aankopen', 'heeft-content', 'gmail-dot-dup', 'bulk-registratie', 'spam-tld' ];
	}

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

		// Harde feiten: order-bezitters (globaal) + content-bezitters (van deze set).
		$order_uids   = self::users_with_orders();
		$content_uids = self::users_with_content( wp_list_pluck( $rows, 'ID' ) );

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
			if ( isset( $content_uids[ $id ] ) ) {
				$flags[] = 'heeft-content';
				$flag_totals['heeft-content']++;
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
			</div>
		</div>
		<?php
	}

	private static function inline_js() {
		return <<<'JS'
(function($){
	var FLAGS = [
		{key:'geen-aankopen',    label:'Geen aankopen',    color:'#9d2d2d', desc:'Heeft nooit een WooCommerce-order geplaatst (HPOS en legacy gecontroleerd).'},
		{key:'heeft-content',    label:'Heeft content',    color:'#3c6e47', desc:'Heeft posts of comments — bijvoorbeeld een productreview. Dit is een echte gebruiker; meestal NIET verwijderen.'},
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
		var shown=0, total=0;
		$('#mcm-nep-results tbody tr').each(function(){
			total++;
			var raw = ($(this).attr('data-flags')||'');
			var flags = raw.length ? raw.split(' ') : [];
			var ok = req.every(function(f){ return flags.indexOf(f)>=0; })
				&& exc.every(function(f){ return flags.indexOf(f)<0; });
			this.style.display = ok ? '' : 'none';
			if(ok){ shown++; }
		});
		$('#mcm-nep-count').html('<strong>'+shown+'</strong> van '+total+' accounts getoond.');
	}

	$(document).on('click','#mcm-nep-scan',function(){
		var scope = $('input[name=mcm-nep-scope]:checked').val() || 'all';
		var cutoff = $('#mcm-nep-cutoff').val();
		$('#mcm-nep-loading').show();
		$('#mcm-nep-summary').hide().empty();
		$('#mcm-nep-filter').hide().empty();
		$('#mcm-nep-results').empty();
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
				rows += '<tr data-flags="'+(x.flags||[]).join(' ')+'">'
					+'<td>'+x.id+'</td><td>'+esc(x.login)+'</td><td>'+esc(x.email)+'</td>'
					+'<td>'+esc(x.registered)+'</td><td>'+fl+'</td></tr>';
			});
			$('#mcm-nep-results').html(
				'<table class="widefat striped" style="margin-top:4px;"><thead><tr>'
				+'<th>ID</th><th>Login</th><th>E-mail</th><th>Geregistreerd</th><th>Flags</th>'
				+'</tr></thead><tbody>'+rows+'</tbody></table>'
				+'<p class="description" style="margin-top:8px;">Dit is alleen een overzicht. Verwijderen zit (nog) niet in deze versie — eerst controleren of de selectie klopt.</p>'
			);
			applyFilter();
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
})(jQuery);
JS;
	}
}

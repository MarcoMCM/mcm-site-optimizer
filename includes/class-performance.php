<?php
/**
 * Performance: detecteert de omgeving (host, Varnish, thema, caching, optimizers)
 * en geeft WP Rocket-aanbevelingen in drie niveaus:
 *
 *  - veilig       : overal toepasbaar, met één klik.
 *  - aanbevolen   : laag risico, individueel toepasbaar.
 *  - risico       : ALLEEN advies — nooit automatisch. Host-/thema-bewust
 *                   (geen preload-advies op Xel; harde RUCSS-waarschuwing op Avada).
 *
 * De module verandert nooit eigenhandig een risico-instelling.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Performance {

	public function __construct() {
		add_action( 'wp_ajax_mcm_perf_scan',  [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_mcm_perf_apply', [ $this, 'ajax_apply' ] );

		add_action( 'mcm_optimizer_render_cards', [ $this, 'render_card' ] );
		add_action( 'admin_enqueue_scripts',      [ $this, 'assets' ] );
	}

	/* ---------------------------------------------------------------
	 * Detectie
	 * ------------------------------------------------------------- */

	private static function detect() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active     = (array) get_option( 'active_plugins', [] );
		$active_str = strtolower( implode( '|', $active ) );

		$is_xel = ( false !== strpos( $active_str, 'xel' ) )
			|| ( false !== stripos( (string) gethostname(), 'xel' ) );

		$varnish = ( false !== strpos( $active_str, 'varnish' ) );

		$theme    = wp_get_theme();
		$is_avada = in_array( 'Avada', [ $theme->get( 'Name' ), $theme->get_template() ], true )
			|| function_exists( 'Avada' ) || class_exists( 'Avada' );

		$wp_rocket = defined( 'WP_ROCKET_VERSION' )
			? WP_ROCKET_VERSION
			: ( false !== strpos( $active_str, 'wp-rocket' ) ? 'actief' : false );

		$optim_map = [
			'imagify'          => 'Imagify',
			'ewww'             => 'EWWW',
			'shortpixel'       => 'ShortPixel',
			'wpvivid-imgoptim' => 'WPVivid ImgOptim',
			'optimole'         => 'Optimole',
			'webp-express'     => 'WebP Express',
			'converter-for-media' => 'Converter for Media',
		];
		$optimizers = [];
		foreach ( $optim_map as $slug => $name ) {
			if ( false !== strpos( $active_str, $slug ) ) {
				$optimizers[] = $name;
			}
		}

		return [
			'host'         => $is_xel ? 'Xel' : 'onbekend',
			'is_xel'       => $is_xel,
			'varnish'      => $varnish,
			'is_avada'     => $is_avada,
			'theme'        => $theme->get( 'Name' ),
			'wp_rocket'    => $wp_rocket,
			'optimizers'   => $optimizers,
			'object_cache' => wp_using_ext_object_cache(),
		];
	}

	/** Huidige WP Rocket-instellingen, of false. */
	private static function rocket_settings() {
		$s = get_option( 'wp_rocket_settings' );
		return is_array( $s ) ? $s : false;
	}

	/**
	 * Cache-levensduur in seconden uit de WP Rocket-instellingen.
	 */
	private static function lifespan_seconds( $s ) {
		$interval = (int) ( $s['purge_cron_interval'] ?? 0 );
		if ( $interval <= 0 ) {
			return 0; // geen tijdgebonden purge.
		}
		$unit = $s['purge_cron_unit'] ?? 'HOUR_IN_SECONDS';
		$mult = ( 'DAY_IN_SECONDS' === $unit ) ? DAY_IN_SECONDS
			: ( ( 'WEEK_IN_SECONDS' === $unit ) ? WEEK_IN_SECONDS
			: ( ( 'MINUTE_IN_SECONDS' === $unit ) ? MINUTE_IN_SECONDS : HOUR_IN_SECONDS ) );
		return $interval * $mult;
	}

	/* ---------------------------------------------------------------
	 * Aanbevelingen
	 * ------------------------------------------------------------- */

	/**
	 * Bouwt de lijst aanbevelingen op basis van detectie + WP Rocket-instellingen.
	 */
	private static function recommendations( $env ) {
		$s    = self::rocket_settings();
		$recs = [];

		// Object cache — los van WP Rocket.
		$recs[] = [
			'key'    => 'object_cache',
			'label'  => 'Persistente object cache',
			'tier'   => 'risico',
			'ok'     => (bool) $env['object_cache'],
			'current' => $env['object_cache'] ? 'actief' : 'geen',
			'advised' => 'Redis/Memcached',
			'detail' => $env['object_cache']
				? 'Object cache is actief — goed.'
				: 'Geen persistente object cache. Maakt ongecachte renders sneller. Niet via deze tool aan te zetten — vraag de host (bv. Xel) om Redis te provisionen.',
		];

		if ( false === $s ) {
			$recs[] = [
				'key' => 'wp_rocket', 'label' => 'WP Rocket', 'tier' => 'risico', 'ok' => false,
				'current' => 'niet gevonden', 'advised' => '—',
				'detail' => 'WP Rocket-instellingen niet gevonden. De caching-aanbevelingen zijn overgeslagen.',
			];
			return $recs;
		}

		// --- VEILIG ---
		$hb_ok = ( 1 === (int) ( $s['control_heartbeat'] ?? 0 ) );
		$recs[] = [
			'key' => 'heartbeat', 'label' => 'Heartbeat temmen', 'tier' => 'veilig', 'ok' => $hb_ok,
			'current' => $hb_ok ? 'aan' : 'uit', 'advised' => 'aan (reduce)',
			'detail' => 'Beperkt WP Heartbeat-verkeer. Risicoloos.',
		];

		$ll_ok = ( 1 === (int) ( $s['lazyload'] ?? 0 ) );
		$recs[] = [
			'key' => 'lazyload', 'label' => 'Lazy-load afbeeldingen', 'tier' => 'veilig', 'ok' => $ll_ok,
			'current' => $ll_ok ? 'aan' : 'uit', 'advised' => 'aan',
			'detail' => 'Laadt afbeeldingen pas bij scrollen. Risicoloos.',
		];

		$id_ok = ( 1 === (int) ( $s['image_dimensions'] ?? 0 ) );
		$recs[] = [
			'key' => 'image_dimensions', 'label' => 'Afmetingen toevoegen aan afbeeldingen', 'tier' => 'veilig', 'ok' => $id_ok,
			'current' => $id_ok ? 'aan' : 'uit', 'advised' => 'aan',
			'detail' => 'Voorkomt layout-shift (CLS). Risicoloos.',
		];

		$life = self::lifespan_seconds( $s );
		$life_ok = ( 0 === $life || $life >= 2 * DAY_IN_SECONDS );
		$recs[] = [
			'key' => 'cache_lifespan', 'label' => 'Cache-levensduur', 'tier' => 'veilig', 'ok' => $life_ok,
			'current' => 0 === $life ? 'geen tijdpurge' : round( $life / HOUR_IN_SECONDS ) . ' uur',
			'advised' => '7 dagen',
			'detail' => 'Een korte levensduur (bv. 10 uur) leegt de cache steeds → terugkerende koude renders. 7 dagen houdt pagina\'s warm; versheid blijft via wijzig-purges. Lost de koude render op zonder preload.',
		];

		// --- AANBEVOLEN ---
		$mcss_ok = ( 1 === (int) ( $s['minify_css'] ?? 0 ) );
		$recs[] = [
			'key' => 'minify_css', 'label' => 'CSS minificeren', 'tier' => 'aanbevolen', 'ok' => $mcss_ok,
			'current' => $mcss_ok ? 'aan' : 'uit', 'advised' => 'aan',
			'detail' => 'Meestal veilig. Controleer na toepassen de opmaak.',
		];

		$mjs_ok = ( 1 === (int) ( $s['minify_js'] ?? 0 ) );
		$recs[] = [
			'key' => 'minify_js', 'label' => 'JS minificeren', 'tier' => 'aanbevolen', 'ok' => $mjs_ok,
			'current' => $mjs_ok ? 'aan' : 'uit', 'advised' => 'aan',
			'detail' => 'Meestal veilig. Controleer na toepassen de functionaliteit.',
		];

		$fonts_ok = ( 1 === (int) ( $s['host_fonts_locally'] ?? 0 ) );
		$recs[] = [
			'key' => 'host_fonts_locally', 'label' => 'Lettertypes lokaal hosten', 'tier' => 'aanbevolen', 'ok' => $fonts_ok,
			'current' => $fonts_ok ? 'aan' : 'uit', 'advised' => 'aan',
			'detail' => 'Haalt Google Fonts naar je eigen domein — sneller en AVG-vriendelijker. Laag risico; controleer de fonts na toepassen.',
		];

		// --- RISICO (alleen advies) ---
		$preload_on = ( 1 === (int) ( $s['manual_preload'] ?? 0 ) );
		$recs[] = [
			'key' => 'preload', 'label' => 'Cache preload', 'tier' => 'risico', 'ok' => true,
			'current' => $preload_on ? 'aan' : 'uit', 'advised' => 'handmatig beslissen',
			'detail' => $env['is_xel']
				? '⚠ Xel-hosting gedetecteerd — preload AFGERADEN. De preload-crawl veroorzaakt load-pieken op Xel. Gebruik in plaats daarvan een langere cache-levensduur (zie veilig).'
				: 'Kan koude renders verminderen, maar de crawl belast de server. Test op de host voor je dit aanzet. Niet automatisch toegepast.',
		];

		$rucss_on = ( 1 === (int) ( $s['remove_unused_css'] ?? 0 ) );
		$recs[] = [
			'key' => 'remove_unused_css', 'label' => 'Ongebruikte CSS verwijderen (RUCSS)', 'tier' => 'risico', 'ok' => true,
			'current' => $rucss_on ? 'aan' : 'uit', 'advised' => 'handmatig + testen',
			'detail' => $env['is_avada']
				? '⚠ Avada/Fusion Builder actief — RUCSS breekt vaak de layout. Alleen aanzetten met testtijd: pagina voor pagina nalopen en kapotte elementen safelisten. Niet automatisch toegepast.'
				: 'Grootste winst voor render-blocking CSS, maar kan opmaak breken. Aanzetten + alle paginatypes testen. Niet automatisch toegepast.',
		];

		$cdn_on    = ( 1 === (int) ( $s['cdn'] ?? 0 ) );
		$cdn_names = array_filter( (array) ( $s['cdn_cnames'] ?? [] ) );
		$recs[] = [
			'key' => 'cdn', 'label' => 'CDN', 'tier' => 'risico', 'ok' => $cdn_on || empty( $cdn_names ),
			'current' => $cdn_on ? 'aan' : ( $cdn_names ? 'geconfigureerd, uit' : 'niet ingesteld' ),
			'advised' => 'verifiëren',
			'detail' => ( ! $cdn_on && $cdn_names )
				? 'Er is een CDN-CNAME ingesteld (' . esc_html( implode( ', ', $cdn_names ) ) . ') maar de CDN staat uit. Verifieer eerst of dat endpoint werkt voor je het aanzet.'
				: 'Geen actie nodig of geen CDN ingesteld.',
		];

		return $recs;
	}

	/** Welke keys mag de tool daadwerkelijk toepassen. */
	private static function applyable() {
		return [ 'heartbeat', 'lazyload', 'image_dimensions', 'cache_lifespan', 'minify_css', 'minify_js', 'host_fonts_locally' ];
	}

	/* ---------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------- */

	public function ajax_scan() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}
		$env = self::detect();
		wp_send_json_success( [
			'env'  => $env,
			'recs' => self::recommendations( $env ),
		] );
	}

	public function ajax_apply() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		$key = sanitize_key( $_POST['key'] ?? '' );
		if ( ! in_array( $key, self::applyable(), true ) ) {
			wp_send_json_error( 'Deze instelling kan niet automatisch worden toegepast.' );
		}

		$s = self::rocket_settings();
		if ( false === $s ) {
			wp_send_json_error( 'WP Rocket-instellingen niet gevonden.' );
		}

		switch ( $key ) {
			case 'heartbeat':
				$s['control_heartbeat']        = 1;
				$s['heartbeat_admin_behavior']  = 'reduce_periodicity';
				$s['heartbeat_editor_behavior'] = 'reduce_periodicity';
				$s['heartbeat_site_behavior']   = 'reduce_periodicity';
				break;
			case 'lazyload':
				$s['lazyload'] = 1;
				break;
			case 'image_dimensions':
				$s['image_dimensions'] = 1;
				break;
			case 'cache_lifespan':
				$s['purge_cron_interval'] = 7;
				$s['purge_cron_unit']     = 'DAY_IN_SECONDS';
				break;
			case 'minify_css':
				$s['minify_css'] = 1;
				break;
			case 'minify_js':
				$s['minify_js'] = 1;
				break;
			case 'host_fonts_locally':
				$s['host_fonts_locally'] = 1;
				break;
		}

		update_option( 'wp_rocket_settings', $s );

		// Cache legen zodat de wijziging direct effect heeft.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// Log de actie in de bestaande optimizer-log.
		if ( class_exists( 'MCM_Database_Cleaner' ) && method_exists( 'MCM_Database_Cleaner', 'log_action' ) ) {
			MCM_Database_Cleaner::log_action( 'performance:' . $key, [ 'applied' => true ] );
		}

		wp_send_json_success( [ 'key' => $key ] );
	}

	/* ---------------------------------------------------------------
	 * UI
	 * ------------------------------------------------------------- */

	public function render_card() {
		?>
		<div class="mcm-opt-card">
			<div class="mcm-opt-card-header">
				<span class="dashicons dashicons-performance"></span>
				<h2>Performance</h2>
				<button type="button" id="mcm-perf-scan" class="button mcm-opt-btn-primary" style="margin-left:auto;">
					<span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
					Analyseren
				</button>
			</div>
			<div class="mcm-opt-card-body">
				<p class="description" style="margin-top:0;">
					Detecteert host, Varnish, thema en caching-plugins, en geeft WP Rocket-aanbevelingen.
					<strong>Veilige</strong> instellingen pas je met één klik toe; <strong>risico</strong>-instellingen
					(preload, RUCSS, CDN) krijg je alléén als advies — host- en thema-bewust.
				</p>
				<div id="mcm-perf-loading" style="display:none;">
					<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span> Bezig met analyseren...
				</div>
				<div id="mcm-perf-results"></div>
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

	var tierInfo = {
		'veilig':    { label: 'Veilig',    color: '#9DD0D2' },
		'aanbevolen':{ label: 'Aanbevolen', color: '#E78E46' },
		'risico':    { label: 'Risico',    color: '#AE432B' }
	};

	function envRow(label, value, good) {
		var color = good === true ? '#1a5c5e' : (good === false ? '#AE432B' : '#6b5d52');
		return '<span style="display:inline-block;margin:0 14px 6px 0;font-size:13px;">' +
			'<strong>' + label + ':</strong> <span style="color:' + color + ';">' + value + '</span></span>';
	}

	$('#mcm-perf-scan').on('click', function() {
		var btn = $(this);
		btn.prop('disabled', true);
		$('#mcm-perf-loading').show();
		$('#mcm-perf-results').html('');

		$.post(mcmOptimizer.ajaxUrl, { action: 'mcm_perf_scan', nonce: mcmOptimizer.nonce }, function(res) {
			btn.prop('disabled', false);
			$('#mcm-perf-loading').hide();
			if (!res.success) {
				$('#mcm-perf-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Analyse mislukt.</div>');
				return;
			}
			var e = res.data.env, recs = res.data.recs, html = '';

			// Detectie.
			html += '<div class="mcm-opt-db-size">';
			html += envRow('Host', e.host, e.is_xel ? null : null);
			html += envRow('Varnish', e.varnish ? 'ja' : 'nee', null);
			html += envRow('Thema', e.theme + (e.is_avada ? ' (Avada)' : ''), null);
			html += envRow('WP Rocket', e.wp_rocket ? e.wp_rocket : 'niet actief', !!e.wp_rocket);
			html += envRow('Object cache', e.object_cache ? 'actief' : 'geen', e.object_cache);
			html += envRow('Image-optimizers', e.optimizers.length ? e.optimizers.join(', ') : 'geen', e.optimizers.length > 1 ? false : null);
			html += '</div>';

			if (e.optimizers.length > 1) {
				html += '<div class="mcm-opt-alert mcm-opt-alert-warning"><span class="dashicons dashicons-warning"></span> ' +
					'Meerdere image-optimizers actief (' + e.optimizers.join(', ') + ') — kies er één om conflicten te voorkomen.</div>';
			}

			// Aanbevelingen per niveau.
			['veilig','aanbevolen','risico'].forEach(function(tier) {
				var items = recs.filter(function(r) { return r.tier === tier; });
				if (!items.length) return;
				var t = tierInfo[tier];
				html += '<h3 style="margin:16px 0 6px;color:var(--mcm-brown);">' +
					'<span class="mcm-opt-risk-badge" style="background:' + t.color + ';">' + t.label + '</span></h3>';
				items.forEach(function(r) {
					html += '<div class="mcm-perf-rec" data-key="' + r.key + '">';
					html += '<div style="display:flex;align-items:center;gap:8px;">';
					html += r.ok
						? '<span class="dashicons dashicons-yes-alt" style="color:#1a5c5e;"></span>'
						: '<span class="dashicons dashicons-info" style="color:' + t.color + ';"></span>';
					html += '<strong>' + r.label + '</strong>';
					html += '<span style="color:#6b5d52;font-size:12px;">(' + r.current + ' → ' + r.advised + ')</span>';
					var canApply = (tier === 'veilig' || tier === 'aanbevolen');
					if (!r.ok && canApply) {
						html += '<button class="button mcm-opt-btn-clean mcm-perf-apply" data-key="' + r.key + '" style="margin-left:auto;">Toepassen</button>';
					} else if (r.ok) {
						html += '<span class="mcm-opt-clean-ok" style="margin-left:auto;">✓ in orde</span>';
					} else {
						html += '<span class="mcm-opt-risk-badge" style="background:' + t.color + ';margin-left:auto;">Handmatig</span>';
					}
					html += '</div>';
					html += '<div style="font-size:12px;color:#6b5d52;margin:4px 0 0 26px;">' + r.detail + '</div>';
					html += '</div>';
				});
			});

			$('#mcm-perf-results').html(html);
		}).fail(function() {
			btn.prop('disabled', false);
			$('#mcm-perf-loading').hide();
			$('#mcm-perf-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Verbindingsfout.</div>');
		});
	});

	$(document).on('click', '.mcm-perf-apply', function() {
		var btn = $(this), key = btn.data('key');
		if (!confirm('Deze WP Rocket-instelling toepassen? De cache wordt daarna geleegd.')) return;
		btn.prop('disabled', true).text('Bezig...');
		$.post(mcmOptimizer.ajaxUrl, { action: 'mcm_perf_apply', nonce: mcmOptimizer.nonce, key: key }, function(res) {
			if (res.success) {
				btn.replaceWith('<span class="mcm-opt-clean-done" style="margin-left:auto;">✓ toegepast</span>');
			} else {
				btn.prop('disabled', false).text('Toepassen');
				alert('Toepassen mislukt: ' + (res.data || 'onbekende fout'));
			}
		}).fail(function() {
			btn.prop('disabled', false).text('Opnieuw');
		});
	});
});
JS;
	}
}

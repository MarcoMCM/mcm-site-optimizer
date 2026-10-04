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

	/** Option met de laatste toepassingen (voor "Ongedaan maken"). */
	const UNDO_OPT = 'mcm_perf_undo';

	public function __construct() {
		add_action( 'wp_ajax_mcm_perf_scan',  [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_mcm_perf_apply', [ $this, 'ajax_apply' ] );
		add_action( 'wp_ajax_mcm_perf_undo',  [ $this, 'ajax_undo' ] );

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
			'avada'        => MCM_Avada_Rocket::env( self::rocket_settings() ),
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

		// Avada-sites: Avada-bewuste regels (taakverdeling Avada ↔ WP Rocket). Die
		// vervangen hieronder het algemene advies voor lazy load, minify en RUCSS.
		$avada_recs = MCM_Avada_Rocket::recommendations( $s );
		$is_avada   = ! empty( $avada_recs );

		if ( false === $s ) {
			$recs[] = [
				'key' => 'wp_rocket', 'label' => 'WP Rocket', 'tier' => 'risico', 'ok' => false,
				'current' => 'niet gevonden', 'advised' => '—',
				'detail' => 'WP Rocket-instellingen niet gevonden. De caching-aanbevelingen zijn overgeslagen.',
			];
			return array_merge( $recs, $avada_recs );
		}

		$recs = array_merge( $recs, self::rocket_recommendations( $s, $env, $is_avada ) );
		return array_merge( $recs, $avada_recs );
	}

	/**
	 * Algemene WP Rocket-aanbevelingen. Op Avada worden lazy load, minify en
	 * RUCSS overgeslagen: daar gelden de regels uit MCM_Avada_Rocket.
	 */
	private static function rocket_recommendations( $s, $env, $is_avada ) {
		$recs = [];

		// --- VEILIG ---
		$hb_ok = ( 1 === (int) ( $s['control_heartbeat'] ?? 0 ) );
		$recs[] = [
			'key' => 'heartbeat', 'label' => 'Heartbeat temmen', 'tier' => 'veilig', 'ok' => $hb_ok,
			'current' => $hb_ok ? 'aan' : 'uit', 'advised' => 'aan (reduce)',
			'detail' => 'Beperkt WP Heartbeat-verkeer. Risicoloos.',
		];

		if ( ! $is_avada ) {
			$ll_ok = ( 1 === (int) ( $s['lazyload'] ?? 0 ) );
			$recs[] = [
				'key' => 'lazyload', 'label' => 'Lazy-load afbeeldingen', 'tier' => 'veilig', 'ok' => $ll_ok,
				'current' => $ll_ok ? 'aan' : 'uit', 'advised' => 'aan',
				'detail' => 'Laadt afbeeldingen pas bij scrollen. Risicoloos.',
			];
		}

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
		// Op Avada NIET: Avada bundelt en verkleint zelf (zie MCM_Avada_Rocket, avada_minify_off).
		if ( ! $is_avada ) {
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
		}

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

		if ( ! $is_avada ) {
			$rucss_on = ( 1 === (int) ( $s['remove_unused_css'] ?? 0 ) );
			$recs[] = [
				'key' => 'remove_unused_css', 'label' => 'Ongebruikte CSS verwijderen (RUCSS)', 'tier' => 'risico', 'ok' => true,
				'current' => $rucss_on ? 'aan' : 'uit', 'advised' => 'handmatig + testen',
				'detail' => 'Grootste winst voor render-blocking CSS, maar kan opmaak breken. Aanzetten + alle paginatypes testen. Niet automatisch toegepast.',
			];
		}

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
		return array_merge(
			[ 'heartbeat', 'lazyload', 'image_dimensions', 'cache_lifespan', 'minify_css', 'minify_js', 'host_fonts_locally' ],
			MCM_Avada_Rocket::KEYS
		);
	}

	/** Leesbare naam van een key, voor log en "Ongedaan maken". */
	private static function key_label( $key ) {
		$labels = [
			'heartbeat'              => 'Heartbeat temmen',
			'lazyload'               => 'Lazy-load afbeeldingen',
			'image_dimensions'       => 'Afmetingen toevoegen aan afbeeldingen',
			'cache_lifespan'         => 'Cache-levensduur 7 dagen',
			'minify_css'             => 'CSS minificeren',
			'minify_js'              => 'JS minificeren',
			'host_fonts_locally'     => 'Lettertypes lokaal hosten',
			'avada_compilers'        => 'Avada: CSS naar bestand + JS-compiler',
			'avada_rucss_off'        => 'WP Rocket RUCSS uit',
			'avada_minify_off'       => 'WP Rocket CSS/JS verkleinen + combineren uit',
			'avada_delay_exclusions' => 'Delay JS: Avada-scripts uitgezonderd',
			'avada_lazyload'         => 'Lazy load: WP Rocket aan, Avada uit',
			'avada_conflicts'        => 'Botsende Avada-opties uit',
			'avada_revisions'        => 'Revisies beperkt tot 5',
			'avada_webp'             => 'Nieuwe uploads als WebP',
			'rocket_config'          => 'WP Rocket-config aangemaakt',
			'avada_elements_missing' => 'Gebruikte Avada-elementen aangezet',
			'avada_elements_dupes'   => 'Element Manager ontdubbeld',
		];
		return $labels[ $key ] ?? $key;
	}

	/** Laatste toepassingen die terug te zetten zijn (zonder de oude waarden). */
	private static function undo_list() {
		$out = [];
		foreach ( array_reverse( (array) get_option( self::UNDO_OPT, [] ) ) as $e ) {
			$out[] = [ 'id' => $e['id'], 'time' => $e['time'], 'label' => $e['label'], 'count' => count( $e['changes'] ) ];
		}
		return $out;
	}

	/** Caches legen na een wijziging aan Avada- of WP Rocket-opties. */
	private static function clear_caches( array $options ) {
		if ( array_intersect( $options, [ 'fusion_options', 'fusion_builder_settings' ] ) && function_exists( 'fusion_reset_all_caches' ) ) {
			fusion_reset_all_caches();
		}
		if ( function_exists( 'rocket_clean_minify' ) ) {
			rocket_clean_minify();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
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
			'undo' => self::undo_list(),
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

		// Momentopname voor de health-check (vóór/na), hooguit één per uur.
		$snap = get_option( 'mcm_optimizer_pre_snapshot', [] );
		if ( empty( $snap['time'] ) || strtotime( $snap['time'] ) < current_time( 'timestamp' ) - HOUR_IN_SECONDS ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			MCM_Health_Check::save_pre_snapshot();
		}

		$cs = new MCM_Perf_Changeset();
		if ( in_array( $key, MCM_Avada_Rocket::KEYS, true ) ) {
			$res = MCM_Avada_Rocket::apply( $key, $cs );
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( $res->get_error_message() );
			}
		} else {
			if ( false === self::rocket_settings() ) {
				wp_send_json_error( 'WP Rocket-instellingen niet gevonden.' );
			}
			$set = [
				'heartbeat'          => [ 'control_heartbeat' => 1, 'heartbeat_admin_behavior' => 'reduce_periodicity', 'heartbeat_editor_behavior' => 'reduce_periodicity', 'heartbeat_site_behavior' => 'reduce_periodicity' ],
				'lazyload'           => [ 'lazyload' => 1 ],
				'image_dimensions'   => [ 'image_dimensions' => 1 ],
				'cache_lifespan'     => [ 'purge_cron_interval' => 7, 'purge_cron_unit' => 'DAY_IN_SECONDS' ],
				'minify_css'         => [ 'minify_css' => 1 ],
				'minify_js'          => [ 'minify_js' => 1 ],
				'host_fonts_locally' => [ 'host_fonts_locally' => 1 ],
			];
			foreach ( $set[ $key ] as $sub => $val ) {
				$cs->set( 'wp_rocket_settings', $sub, $val );
			}
		}

		$changes = $cs->commit();
		self::clear_caches( array_unique( array_column( $changes, 'option' ) ) );

		// Vastleggen zodat het terug te zetten is (laatste 15).
		if ( $changes ) {
			$undo   = (array) get_option( self::UNDO_OPT, [] );
			$undo[] = [
				'id'      => strtolower( wp_generate_password( 12, false ) ), // sanitize_key() maakt kleine letters.
				'time'    => current_time( 'mysql' ),
				'key'     => $key,
				'label'   => self::key_label( $key ),
				'changes' => $changes,
			];
			update_option( self::UNDO_OPT, array_slice( $undo, -15 ), false );
		}

		if ( class_exists( 'MCM_Database_Cleaner' ) && method_exists( 'MCM_Database_Cleaner', 'log_action' ) ) {
			MCM_Database_Cleaner::log_action( 'performance:' . $key, [ 'applied' => true, 'changes' => count( $changes ) ] );
		}

		wp_send_json_success( [ 'key' => $key, 'changes' => count( $changes ), 'undo' => self::undo_list() ] );
	}

	/** Zet een eerdere toepassing terug naar de oude waarden. */
	public function ajax_undo() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}
		$id   = sanitize_key( $_POST['id'] ?? '' );
		$undo = (array) get_option( self::UNDO_OPT, [] );
		foreach ( $undo as $i => $e ) {
			if ( '' === $id || strtolower( (string) ( $e['id'] ?? '' ) ) !== $id ) {
				continue;
			}
			$options = MCM_Perf_Changeset::revert( (array) $e['changes'] );
			self::clear_caches( $options );
			unset( $undo[ $i ] );
			update_option( self::UNDO_OPT, array_values( $undo ), false );
			if ( class_exists( 'MCM_Database_Cleaner' ) && method_exists( 'MCM_Database_Cleaner', 'log_action' ) ) {
				MCM_Database_Cleaner::log_action( 'performance:undo:' . ( $e['key'] ?? '' ), [ 'reverted' => count( (array) $e['changes'] ) ] );
			}
			wp_send_json_success( [ 'undo' => self::undo_list() ] );
		}
		wp_send_json_error( 'Deze wijziging is niet (meer) terug te zetten.' );
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
					Op Avada-sites: taakverdeling Avada ↔ WP Rocket en de Element Manager.
					Elke toepassing is terug te zetten via <em>Ongedaan maken</em>.
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
			if (e.avada && e.avada.is_avada) {
				var a = e.avada;
				html += envRow('Avada-compilers', a.compilers_forced_off
					? 'uitgezet door ' + a.forced_by
					: ('CSS ' + a.css_mode + ', JS ' + (a.js_compiler ? 'aan' : 'uit')),
					a.compilers_forced_off ? false : (a.css_mode === 'bestand' && a.js_compiler));
			}
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

			html += '<div id="mcm-perf-undo"></div>';
			$('#mcm-perf-results').html(html);
			renderUndo(res.data.undo || []);
		}).fail(function() {
			btn.prop('disabled', false);
			$('#mcm-perf-loading').hide();
			$('#mcm-perf-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Verbindingsfout.</div>');
		});
	});

	function esc(s) { return $('<div/>').text(String(s)).html(); }

	function renderUndo(list) {
		if (!list.length) { $('#mcm-perf-undo').html(''); return; }
		var h = '<h3 style="margin:18px 0 6px;color:var(--mcm-brown);">Recent toegepast</h3>';
		h += '<p class="description" style="margin:0 0 6px;">Ongedaan maken zet de instellingen terug naar de waarde van vóór die toepassing en leegt de caches.</p>';
		list.forEach(function(u) {
			h += '<div class="mcm-perf-rec" style="display:flex;align-items:center;gap:8px;">' +
				'<span class="dashicons dashicons-backup" style="color:#6b5d52;"></span>' +
				'<strong>' + esc(u.label) + '</strong>' +
				'<span style="color:#6b5d52;font-size:12px;">' + esc(u.time) + ' · ' + u.count + ' instelling(en)</span>' +
				'<button class="button mcm-perf-undo" data-id="' + esc(u.id) + '" style="margin-left:auto;">Ongedaan maken</button></div>';
		});
		$('#mcm-perf-undo').html(h);
	}

	$(document).on('click', '.mcm-perf-apply', function() {
		var btn = $(this), key = btn.data('key');
		if (!confirm('Deze instelling toepassen? Er wordt eerst een momentopname voor de health-check gemaakt; daarna worden de caches geleegd. Je kunt het terugzetten met "Ongedaan maken".')) return;
		btn.prop('disabled', true).text('Bezig...');
		$.post(mcmOptimizer.ajaxUrl, { action: 'mcm_perf_apply', nonce: mcmOptimizer.nonce, key: key }, function(res) {
			if (res.success) {
				// Opnieuw analyseren: regels hangen van elkaar af (bv. RUCSS uit → compilers mogelijk).
				$('#mcm-perf-scan').trigger('click');
			} else {
				btn.prop('disabled', false).text('Toepassen');
				alert('Toepassen mislukt: ' + (res.data || 'onbekende fout'));
			}
		}).fail(function() {
			btn.prop('disabled', false).text('Opnieuw');
		});
	});

	$(document).on('click', '.mcm-perf-undo', function() {
		var btn = $(this);
		if (!confirm('Deze toepassing ongedaan maken?')) return;
		btn.prop('disabled', true).text('Bezig...');
		$.post(mcmOptimizer.ajaxUrl, { action: 'mcm_perf_undo', nonce: mcmOptimizer.nonce, id: btn.data('id') }, function(res) {
			if (res.success) {
				$('#mcm-perf-scan').trigger('click');
			} else {
				btn.prop('disabled', false).text('Ongedaan maken');
				alert(res.data || 'Ongedaan maken mislukt.');
			}
		}).fail(function() {
			btn.prop('disabled', false).text('Opnieuw');
		});
	});
});
JS;
	}
}

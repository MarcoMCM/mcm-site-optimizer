<?php
/**
 * Admin pagina voor MCM Site Optimizer.
 * SecuPress-achtige UI met MCM huisstijl kleuren.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Optimizer_Admin_Page {

	const NONCE     = 'mcm_optimizer_nonce';
	const MCM_OWNER = 'MarcoMCM';

	/**
	 * Check of de huidige gebruiker de MCM eigenaar is.
	 */
	public static function is_mcm_owner() {
		$current_user = wp_get_current_user();
		return $current_user->user_login === self::MCM_OWNER;
	}

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init', [ $this, 'handle_settings' ] );

		// AJAX handlers.
		add_action( 'wp_ajax_mcm_optimizer_scan', [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_mcm_optimizer_clean', [ $this, 'ajax_clean' ] );
		add_action( 'wp_ajax_mcm_optimizer_health', [ $this, 'ajax_health_check' ] );
		add_action( 'wp_ajax_mcm_optimizer_cron_dubbel', [ $this, 'ajax_cron_dubbel' ] );

		// Health ping voor admin-ajax check.
		add_action( 'wp_ajax_mcm_optimizer_health_ping', [ $this, 'ajax_health_ping' ] );
		add_action( 'wp_ajax_nopriv_mcm_optimizer_health_ping', [ $this, 'ajax_health_ping' ] );
	}

	public function add_menu() {
		add_submenu_page(
			'tools.php',
			'MCM Site Optimizer',
			'MCM Optimizer',
			'manage_options',
			'mcm-tools',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'tools_page_mcm-tools' !== $hook ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', $this->get_css() );
		wp_add_inline_script( 'jquery', $this->get_js() );
	}

	/**
	 * Pakket instellingen opslaan — alleen MCM eigenaar.
	 */
	public function handle_settings() {
		if ( ! isset( $_POST['mcm_optimizer_save_settings'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || ! self::is_mcm_owner() ) {
			wp_die( 'Alleen de MCM beheerder kan instellingen wijzigen.' );
		}
		if ( ! wp_verify_nonce( $_POST[ self::NONCE ] ?? '', 'mcm_optimizer_save' ) ) {
			wp_die( 'Ongeldige nonce.' );
		}

		$settings = get_option( 'mcm_optimizer_settings', MCM_Site_Optimizer::get_defaults() );

		$settings['package_level']         = sanitize_key( $_POST['package_level'] ?? 'all' );
		$settings['revisions_keep']        = absint( $_POST['revisions_keep'] ?? 5 );
		$settings['transient_blacklist']   = sanitize_textarea_field( $_POST['transient_blacklist'] ?? '' );
		$settings['action_scheduler_days'] = absint( $_POST['action_scheduler_days'] ?? 30 );

		update_option( 'mcm_optimizer_settings', $settings );

		wp_safe_redirect( add_query_arg( 'mcm-status', 'saved', admin_url( 'admin.php?page=mcm-tools' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------
	 * AJAX Handlers
	 * ------------------------------------------------------------- */

	public function ajax_scan() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		$results = MCM_Scanner::full_scan();
		wp_send_json_success( $results );
	}

	public function ajax_clean() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		$module   = sanitize_key( $_POST['module'] ?? '' );
		$settings = get_option( 'mcm_optimizer_settings', MCM_Site_Optimizer::get_defaults() );

		// Opschoning in delen: 'vanaf' = waar de vorige ronde bleef, 'eerder' =
		// wat die rondes samen al verwijderden (alleen voor de log).
		$vervolg = ! empty( $_POST['vervolg'] );
		$vanaf   = absint( $_POST['vanaf'] ?? 0 );
		$eerder  = absint( $_POST['eerder'] ?? 0 );

		// Check of module beschikbaar is voor dit pakket.
		if ( ! MCM_Site_Optimizer::module_available( $module ) ) {
			wp_send_json_error( 'Module niet beschikbaar voor dit pakket.' );
		}

		// "Alles Opschonen" mag alleen de modules uit bulk_modules() raken. Ook
		// hier gecontroleerd, niet alleen in de knop.
		if ( ! empty( $_POST['bulk'] ) && ! in_array( $module, MCM_Database_Cleaner::bulk_modules(), true ) ) {
			wp_send_json_error( 'Deze module valt buiten "Alles Opschonen". Schoon hem per stuk op.' );
		}

		wp_raise_memory_limit( 'admin' );
		@set_time_limit( 120 );

		// Pre-snapshot opslaan — alleen aan het begin van een opschoonronde,
		// anders is "vóór" bij de vergelijking de stand van halverwege.
		if ( ! $vervolg && '0' !== ( $_POST['snapshot'] ?? '1' ) ) {
			MCM_Health_Check::save_pre_snapshot();
		}

		$result = [];

		switch ( $module ) {
			case 'expired_transients':
				$result = MCM_Database_Cleaner::clean_expired_transients();
				break;

			case 'active_transients':
				$blacklist = array_filter( array_map( 'trim', explode( "\n", $settings['transient_blacklist'] ?? '' ) ) );
				$result    = MCM_Database_Cleaner::clean_active_transients( $blacklist );
				break;

			case 'revisions':
				$keep   = intval( $settings['revisions_keep'] ?? 5 );
				$result = MCM_Database_Cleaner::clean_revisions( $keep );
				break;

			case 'auto_drafts':
				$result = MCM_Database_Cleaner::clean_auto_drafts( $vanaf );
				break;

			case 'spam_comments':
				$result = MCM_Database_Cleaner::clean_spam_comments();
				break;

			case 'trash_comments':
				$result = MCM_Database_Cleaner::clean_trash_comments();
				break;

			case 'trashed_posts':
				$result = MCM_Database_Cleaner::clean_trashed_posts( $vanaf );
				break;

			case 'orphaned_postmeta':
				$result = MCM_Database_Cleaner::clean_orphaned_postmeta( $vanaf );
				break;

			case 'orphaned_commentmeta':
				$result = MCM_Database_Cleaner::clean_orphaned_commentmeta( $vanaf );
				break;

			case 'duplicate_postmeta':
				$result = MCM_Database_Cleaner::clean_duplicate_postmeta();
				break;

			case 'action_scheduler':
				$days   = intval( $settings['action_scheduler_days'] ?? 30 );
				$result = MCM_Database_Cleaner::clean_action_scheduler( $days, $vanaf );
				break;

			case 'orphaned_plugin_options':
				$result = MCM_Database_Cleaner::clean_orphaned_plugin_options();
				break;

			default:
				wp_send_json_error( 'Onbekende module: ' . $module );
		}

		// Log de actie; bij een vervolgronde met het totaal van alle rondes.
		$log_result = $result;
		if ( isset( $log_result['deleted'] ) ) {
			$log_result['deleted'] = $eerder + intval( $log_result['deleted'] );
		}
		MCM_Database_Cleaner::log_action( $module, $log_result, $vervolg );

		wp_send_json_success( [
			'module' => $module,
			'result' => $result,
		] );
	}

	public function ajax_health_check() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		$results = MCM_Health_Check::run_post_checks();
		wp_send_json_success( $results );
	}

	/**
	 * Knop "Ruim op" bij dubbel ingeplande cron-taken (Health Check). Haalt
	 * alleen de extra kopieën weg; elke taak blijft één keer ingepland.
	 */
	public function ajax_cron_dubbel() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		$result = MCM_Health_Check::cron_dubbel_opruimen();
		MCM_Database_Cleaner::log_action( 'cron_dubbel', $result );

		wp_send_json_success( [
			'result' => $result,
			'check'  => MCM_Health_Check::check_cron(),
		] );
	}

	public function ajax_health_ping() {
		wp_send_json_success( [ 'pong' => true ] );
	}

	/* ---------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------- */

	public function render_page() {
		$settings = get_option( 'mcm_optimizer_settings', MCM_Site_Optimizer::get_defaults() );
		$status   = isset( $_GET['mcm-status'] ) ? sanitize_key( $_GET['mcm-status'] ) : '';
		$backup   = MCM_Backup_Check::is_safe_to_proceed();
		?>
		<div class="wrap mcm-opt-wrap">
			<div class="mcm-opt-header">
				<h1>MCM Site Optimizer</h1>
				<span class="mcm-opt-version">v<?php echo MCM_OPTIMIZER_VERSION; ?></span>
			</div>

			<?php if ( 'saved' === $status ) : ?>
				<div class="notice notice-success is-dismissible"><p>Instellingen opgeslagen.</p></div>
			<?php endif; ?>

			<!-- BACKUP STATUS -->
			<div class="mcm-opt-card mcm-opt-backup-card">
				<div class="mcm-opt-card-header">
					<span class="dashicons dashicons-backup"></span>
					<h2>Backup Status</h2>
				</div>
				<div class="mcm-opt-card-body">
					<?php if ( ! $backup['safe'] ) : ?>
						<div class="mcm-opt-alert mcm-opt-alert-danger">
							<span class="dashicons dashicons-warning"></span>
							<?php echo esc_html( $backup['message'] ); ?>
						</div>
					<?php elseif ( ! empty( $backup['warning'] ) ) : ?>
						<div class="mcm-opt-alert mcm-opt-alert-warning">
							<span class="dashicons dashicons-info"></span>
							<?php echo esc_html( $backup['message'] ); ?>
						</div>
					<?php else : ?>
						<div class="mcm-opt-alert mcm-opt-alert-safe">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php echo esc_html( $backup['message'] ); ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- INSTELLINGEN — alleen zichtbaar voor MCM eigenaar -->
			<?php if ( self::is_mcm_owner() ) : ?>
			<div class="mcm-opt-card">
				<div class="mcm-opt-card-header">
					<span class="dashicons dashicons-admin-generic"></span>
					<h2>Instellingen</h2>
					<span class="mcm-opt-owner-badge" style="margin-left:auto;">MarcoMCM</span>
				</div>
				<div class="mcm-opt-card-body">
					<form method="post">
						<?php wp_nonce_field( 'mcm_optimizer_save', self::NONCE ); ?>

						<div class="mcm-opt-field-row">
							<label for="package_level">Pakket niveau</label>
							<select name="package_level" id="package_level">
								<option value="basis" <?php selected( $settings['package_level'], 'basis' ); ?>>Basis (€250/jaar)</option>
								<option value="actief" <?php selected( $settings['package_level'], 'actief' ); ?>>Actief (€360/jaar)</option>
								<option value="webshop" <?php selected( $settings['package_level'], 'webshop' ); ?>>Webshop (€500/jaar)</option>
								<option value="all" <?php selected( $settings['package_level'], 'all' ); ?>>Alles (eenmalig/MCM)</option>
							</select>
							<p class="description">Bepaalt welke modules beschikbaar zijn.</p>
						</div>

						<div class="mcm-opt-field-row">
							<label for="revisions_keep">Revisies behouden per post</label>
							<input type="number" name="revisions_keep" id="revisions_keep"
								value="<?php echo esc_attr( $settings['revisions_keep'] ); ?>"
								min="0" max="50" style="width:80px;" />
						</div>

						<?php if ( MCM_Site_Optimizer::module_available( 'active_transients' ) ) : ?>
						<div class="mcm-opt-field-row">
							<label for="transient_blacklist">Transient blacklist (prefixes)</label>
							<textarea name="transient_blacklist" id="transient_blacklist" rows="3" class="large-text code"
								placeholder="wc_&#10;jetpack_"><?php echo esc_textarea( $settings['transient_blacklist'] ); ?></textarea>
							<p class="description">Eén prefix per regel. Transients die hiermee beginnen worden nooit verwijderd.</p>
						</div>
						<?php endif; ?>

						<?php if ( MCM_Site_Optimizer::module_available( 'action_scheduler' ) ) : ?>
						<div class="mcm-opt-field-row">
							<label for="action_scheduler_days">Action Scheduler: verwijder taken ouder dan</label>
							<input type="number" name="action_scheduler_days" id="action_scheduler_days"
								value="<?php echo esc_attr( $settings['action_scheduler_days'] ); ?>"
								min="7" max="365" style="width:80px;" /> dagen
						</div>
						<?php endif; ?>

						<button type="submit" name="mcm_optimizer_save_settings" value="1" class="button mcm-opt-btn-primary">
							Instellingen Opslaan
						</button>
					</form>
				</div>
			</div>
			<?php else : ?>
			<div class="mcm-opt-card">
				<div class="mcm-opt-card-header">
					<span class="dashicons dashicons-lock"></span>
					<h2>Pakket: <?php echo esc_html( ucfirst( $settings['package_level'] ) ); ?></h2>
				</div>
				<div class="mcm-opt-card-body">
					<div class="mcm-opt-alert mcm-opt-alert-warning">
						<span class="dashicons dashicons-info"></span>
						Instellingen kunnen alleen gewijzigd worden door de MCM beheerder.
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- SCAN & RESULTATEN -->
			<div class="mcm-opt-card">
				<div class="mcm-opt-card-header">
					<span class="dashicons dashicons-search"></span>
					<h2>Database Scan</h2>
					<button type="button" id="mcm-run-scan" class="button mcm-opt-btn-primary" style="margin-left:auto;">
						<span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
						Scan Starten
					</button>
				</div>
				<div class="mcm-opt-card-body">
					<div id="mcm-scan-loading" style="display:none;">
						<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
						Bezig met scannen...
					</div>
					<div id="mcm-scan-results"></div>
				</div>
			</div>

			<!-- HEALTH CHECK RESULTATEN -->
			<div class="mcm-opt-card" id="mcm-health-card" style="display:none;">
				<div class="mcm-opt-card-header">
					<span class="dashicons dashicons-heart"></span>
					<h2>Health Check</h2>
				</div>
				<div class="mcm-opt-card-body">
					<div id="mcm-health-results"></div>
				</div>
			</div>

			<!-- ACTIE LOG -->
			<?php $this->render_log(); ?>

			<?php
			/**
			 * Render-punt voor losse modules (media scanner, performance, ...).
			 * Modules haken hierop in om hun eigen kaart te tonen.
			 */
			do_action( 'mcm_optimizer_render_cards' );
			?>

		</div>

		<script>
		var mcmOptimizer = {
			ajaxUrl: '<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>',
			nonce: '<?php echo wp_create_nonce( "mcm_optimizer_ajax" ); ?>',
			packageLevel: '<?php echo esc_js( $settings['package_level'] ); ?>'
		};
		</script>
		<?php
	}

	/**
	 * Render de actie log.
	 */
	private function render_log() {
		$log = get_option( 'mcm_optimizer_log', [] );
		$log = array_reverse( $log ); // Nieuwste eerst.
		$log = array_slice( $log, 0, 20 ); // Laatste 20.

		if ( empty( $log ) ) {
			return;
		}

		$module_labels = self::get_module_labels();
		?>
		<div class="mcm-opt-card">
			<div class="mcm-opt-card-header">
				<span class="dashicons dashicons-list-view"></span>
				<h2>Actie Log</h2>
			</div>
			<div class="mcm-opt-card-body">
				<table class="mcm-opt-log-table">
					<thead>
						<tr>
							<th>Tijdstip</th>
							<th>Module</th>
							<th>Resultaat</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry['time'] ); ?></td>
							<td><?php echo esc_html( $module_labels[ $entry['module'] ] ?? $entry['module'] ); ?></td>
							<td>
								<?php
								if ( isset( $entry['result']['deleted'] ) ) {
									$tekst = $entry['result']['deleted'] . ' items verwijderd';
									if ( ! empty( $entry['result']['more'] ) ) {
										$tekst .= ' (niet afgemaakt)';
									}
									if ( ! empty( $entry['result']['error'] ) ) {
										$tekst .= ' — ' . $entry['result']['error'];
									}
									echo esc_html( $tekst );
								} else {
									echo esc_html( wp_json_encode( $entry['result'] ) );
								}
								?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Module labels (Nederlands).
	 */
	public static function get_module_labels() {
		return [
			'expired_transients'    => 'Verlopen Transients',
			'active_transients'     => 'Actieve Transients',
			'revisions'             => 'Post Revisies',
			'auto_drafts'           => 'Auto-drafts',
			'spam_comments'         => 'Spam Reacties',
			'trash_comments'        => 'Prullenbak Reacties',
			'trashed_posts'         => 'Prullenbak Berichten',
			'orphaned_postmeta'     => 'Orphaned Postmeta',
			'orphaned_commentmeta'  => 'Orphaned Commentmeta',
			'duplicate_postmeta'    => 'Dubbele Postmeta',
			'autoloaded_options'    => 'Autoloaded Options',
			'action_scheduler'      => 'Action Scheduler',
			'orphaned_plugin_options' => 'Verweesde plugin-opties',
			'cron_dubbel'           => 'Dubbele cron-taken',
		];
	}

	/**
	 * Waarschuwing bij de modules die alleen per stuk mogen (risico 'danger',
	 * buiten "Alles Opschonen"). Staat op de kaart en in de bevestiging.
	 */
	public static function get_module_warnings() {
		return [
			'active_transients'  => 'Deze tijdelijke opslag is nog geldig. Plugins bewaren hier soms gegevens van een lopend proces (import, betaling, koppeling); weghalen kan dat proces laten mislukken. Alleen doen met een reden, en niet tijdens een import of drukte.',
			'duplicate_postmeta' => 'Sommige plugins slaan bewust dezelfde waarde vaker op. WooCommerce telt zo het gebruik van een coupon (_used_by); die rijen blijven staan. Van andere plugins weet je het niet zeker. Van wat weggaat wordt eerst een backup gemaakt.',
			'trashed_posts'      => sprintf(
				'De prullenbak is de \'ongedaan maken\' van WordPress: hij leegt zichzelf na %d dagen. Wat je nu weghaalt, is niet meer terug te zetten. Bestellingen worden overgeslagen; die beheert WooCommerce.',
				defined( 'EMPTY_TRASH_DAYS' ) ? (int) EMPTY_TRASH_DAYS : 30
			),
		];
	}

	/**
	 * Risiconiveau info.
	 */
	public static function get_risk_info() {
		return [
			'safe'    => [ 'label' => 'Veilig',       'color' => '#9DD0D2', 'icon' => 'yes-alt' ],
			'warning' => [ 'label' => 'Controleren',   'color' => '#E78E46', 'icon' => 'info' ],
			'danger'  => [ 'label' => 'Let op',        'color' => '#AE432B', 'icon' => 'warning' ],
		];
	}

	/* ---------------------------------------------------------------
	 * JavaScript
	 * ------------------------------------------------------------- */

	private function get_js() {
		$labels   = wp_json_encode( self::get_module_labels() );
		$risk     = wp_json_encode( self::get_risk_info() );
		$bulk     = wp_json_encode( MCM_Database_Cleaner::bulk_modules() );
		$warnings = wp_json_encode( self::get_module_warnings() );

		return <<<JS
jQuery(document).ready(function($) {

	var labels = {$labels};
	var riskInfo = {$risk};
	// Alleen deze modules gaan mee met "Alles Opschonen"; de rest per stuk.
	var bulkModules = {$bulk};
	var warnings = {$warnings};

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function(c) {
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
		});
	}

	// Welke modules beschikbaar zijn per pakket.
	var packageModules = {
		'basis':   ['expired_transients','auto_drafts','spam_comments','trash_comments','trashed_posts','revisions','orphaned_postmeta','orphaned_commentmeta','autoloaded_options','orphaned_plugin_options'],
		'actief':  ['expired_transients','auto_drafts','spam_comments','trash_comments','trashed_posts','revisions','orphaned_postmeta','orphaned_commentmeta','duplicate_postmeta','autoloaded_options','active_transients','orphaned_plugin_options'],
		'webshop': ['expired_transients','auto_drafts','spam_comments','trash_comments','trashed_posts','revisions','orphaned_postmeta','orphaned_commentmeta','duplicate_postmeta','autoloaded_options','active_transients','action_scheduler','orphaned_plugin_options'],
		'all':     ['expired_transients','auto_drafts','spam_comments','trash_comments','trashed_posts','revisions','orphaned_postmeta','orphaned_commentmeta','duplicate_postmeta','autoloaded_options','active_transients','action_scheduler','orphaned_plugin_options']
	};

	var currentPackage = mcmOptimizer.packageLevel;

	function isModuleAvailable(mod) {
		return (packageModules[currentPackage] || []).indexOf(mod) !== -1;
	}

	function formatSize(kb) {
		if (kb >= 1024) return (kb / 1024).toFixed(2) + ' MB';
		return kb.toFixed(1) + ' KB';
	}

	function riskBadge(level) {
		var r = riskInfo[level] || riskInfo['warning'];
		return '<span class="mcm-opt-risk-badge" style="background:' + r.color + ';">' +
			'<span class="dashicons dashicons-' + r.icon + '"></span> ' + r.label + '</span>';
	}

	function moduleCard(key, data) {
		if (!isModuleAvailable(key)) return '';

		var count = 0;
		var extra = '';
		var sub = []; // uitleg op een kleine regel onder het getal
		var risk = data.risk || 'warning';

		switch(key) {
			case 'expired_transients':
				count = data.count;
				extra = ' (' + formatSize(data.size_kb) + ')';
				break;
			case 'active_transients':
				count = data.total_count;
				extra = ' (' + formatSize(data.total_size_kb) + ')';
				if (Object.keys(data.has_images || {}).length > 0) {
					extra += ' <span class="mcm-opt-img-warn">⚠ Bevat image-referenties</span>';
				}
				break;
			case 'revisions':
				count = data.count;
				extra = ' (' + formatSize(data.size_kb) + ')';
				break;
			case 'duplicate_postmeta':
				count = data.excess_rows;
				extra = ' (' + data.groups + ' groepen)';
				if (data.skipped_rows > 0) {
					sub.push(data.skipped_rows + ' bewust dubbel (' + esc((data.skip_keys || []).join(', ')) + ') blijven staan');
				}
				break;
			case 'auto_drafts':
				count = data.count;
				if (data.recent > 0) {
					sub.push(data.recent + ' jonger dan ' + data.days + ' dagen blijven staan');
				}
				break;
			case 'trashed_posts':
				count = data.count;
				var tTypes = [];
				for (var ti = 0; ti < (data.types || []).length; ti++) {
					tTypes.push(data.types[ti].count + ' ' + esc(data.types[ti].label));
				}
				if (tTypes.length) sub.push(tTypes.join(', '));
				if (data.orders_skipped > 0) {
					sub.push(data.orders_skipped + ' bestellingen overgeslagen (beheert WooCommerce)');
				}
				break;
			case 'autoloaded_options':
				count = data.total_size_kb;
				extra = ' KB totaal autoloaded';
				risk = data.risk;
				break;
			case 'action_scheduler':
				if (!data.available) return '';
				count = data.completed + data.failed;
				extra = ' (' + data.completed + ' voltooid, ' + data.failed + ' mislukt)';
				break;
			default:
				count = data.count || 0;
		}

		if (key === 'autoloaded_options') {
			return '<div class="mcm-opt-module-card">' +
				'<div class="mcm-opt-module-header">' +
					'<span class="mcm-opt-module-label">' + labels[key] + '</span>' +
					riskBadge(risk) +
				'</div>' +
				'<div class="mcm-opt-module-count">' + formatSize(count) + '</div>' +
				'<div class="mcm-opt-module-extra">' + extra + '</div>' +
				'<div class="mcm-opt-module-info">Bekijk de grootste autoloaded options in de log.</div>' +
			'</div>';
		}

		if (key === 'orphaned_plugin_options') {
			var oGroups = data.groups || [];
			if (!oGroups.length) {
				return '<div class="mcm-opt-module-card">' +
					'<div class="mcm-opt-module-header">' +
						'<span class="mcm-opt-module-label">' + labels[key] + '</span>' +
						riskBadge('safe') +
					'</div>' +
					'<div class="mcm-opt-module-count"><span class="mcm-opt-clean-ok">✓ Geen verweesde plugin-opties</span></div>' +
				'</div>';
			}
			var oList = '';
			var oPlugins = [];
			for (var oi = 0; oi < oGroups.length; oi++) {
				var g = oGroups[oi];
				oPlugins.push(g.label);
				oList += '<li>' + g.label + ': <strong>' + g.count + '</strong> opties (' + formatSize(g.size_kb) + ')' +
					(g.autoload ? ' <span class="mcm-opt-img-warn">autoload</span>' : '') + '</li>';
			}
			return '<div class="mcm-opt-module-card">' +
				'<div class="mcm-opt-module-header">' +
					'<span class="mcm-opt-module-label">' + labels[key] + '</span>' +
					riskBadge('warning') +
				'</div>' +
				'<div class="mcm-opt-module-count">' + data.count + ' opties (' + formatSize(data.size_kb) + ')</div>' +
				'<ul class="mcm-opt-orphan-list">' + oList + '</ul>' +
				'<div class="mcm-opt-module-info">Van verwijderde plugins. Er wordt eerst een terugzetbare backup gemaakt.</div>' +
				'<div class="mcm-opt-module-actions"><button class="button mcm-opt-btn-clean-orphaned" data-module="orphaned_plugin_options" data-plugins="' + oPlugins.join(', ').replace(/"/g,'') + '">Opschonen</button></div>' +
			'</div>';
		}

		var perStuk = bulkModules.indexOf(key) === -1;
		var cleanBtn = count > 0
			? '<button class="button mcm-opt-btn-clean" data-module="' + key + '" data-risk="' + risk + '">' + (perStuk ? 'Opschonen (los)' : 'Opschonen') + '</button>'
			: '<span class="mcm-opt-clean-ok">✓ Schoon</span>';
		var info = (count > 0 && warnings[key])
			? '<div class="mcm-opt-module-info mcm-opt-module-warning">' + esc(warnings[key]) + '</div>'
			: '';

		return '<div class="mcm-opt-module-card">' +
			'<div class="mcm-opt-module-header">' +
				'<span class="mcm-opt-module-label">' + labels[key] + '</span>' +
				riskBadge(risk) +
			'</div>' +
			'<div class="mcm-opt-module-count">' + count + ' items' + extra + '</div>' +
			(sub.length ? '<div class="mcm-opt-module-extra">' + sub.join(' · ') + '</div>' : '') +
			info +
			'<div class="mcm-opt-module-actions">' + cleanBtn + '</div>' +
		'</div>';
	}

	// Voer één module uit. Grote opschoningen lopen in delen: de server stopt
	// na een tijdsbudget met 'more' en 'vanaf', en wij gaan verder waar hij bleef.
	// opts: bulk (bool), snapshot (bool, standaard true), progress(totaal).
	// done(ok, totaal, resultaat-of-foutmelding).
	function runClean(mod, opts, done) {
		var totaal = 0;
		var rondes = 0;

		function stap(vanaf) {
			$.post(mcmOptimizer.ajaxUrl, {
				action: 'mcm_optimizer_clean',
				nonce: mcmOptimizer.nonce,
				module: mod,
				bulk: opts.bulk ? 1 : 0,
				snapshot: opts.snapshot === false ? 0 : 1,
				vervolg: rondes > 0 ? 1 : 0,
				vanaf: vanaf,
				eerder: totaal
			}, function(response) {
				if (!response.success) {
					done(false, totaal, response.data || 'Onbekende fout');
					return;
				}
				var r = response.data.result || {};
				var del = parseInt(r.deleted, 10) || 0;
				var volgende = parseInt(r.vanaf, 10) || 0;
				totaal += del;
				rondes++;
				if (r.error) {
					done(false, totaal, r.error);
					return;
				}
				if (opts.progress) opts.progress(totaal);
				// Verder zolang er meer is én de vorige ronde iets opschoot —
				// vangnet tegen een eindeloze lus.
				if (r.more && (del > 0 || volgende > vanaf) && rondes < 500) {
					stap(volgende);
					return;
				}
				done(true, totaal, r);
			}).fail(function(xhr) {
				done(false, totaal, 'Verbindingsfout (HTTP ' + (xhr.status || '?') + ')');
			});
		}

		stap(0);
	}

	function klaarTekst(totaal, r) {
		var t = '✓ ' + totaal + ' verwijderd';
		if (r && r.more) t += ' (niet afgemaakt — klik opnieuw op Scan)';
		if (r && r.backup) t += ' (backup opgeslagen)';
		return t;
	}

	// SCAN
	$('#mcm-run-scan').on('click', function() {
		var btn = $(this);
		btn.prop('disabled', true);
		$('#mcm-scan-loading').show();
		$('#mcm-scan-results').html('');

		$.post(mcmOptimizer.ajaxUrl, {
			action: 'mcm_optimizer_scan',
			nonce: mcmOptimizer.nonce
		}, function(response) {
			btn.prop('disabled', false);
			$('#mcm-scan-loading').hide();

			if (!response.success) {
				$('#mcm-scan-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Scan mislukt.</div>');
				return;
			}

			var d = response.data;
			var html = '';

			// DB grootte header.
			html += '<div class="mcm-opt-db-size">Database grootte: <strong>' + d.db_size.label + '</strong></div>';

			// Module grid.
			html += '<div class="mcm-opt-module-grid">';

			var order = [
				'expired_transients','active_transients','revisions','auto_drafts',
				'spam_comments','trash_comments','trashed_posts',
				'orphaned_postmeta','orphaned_commentmeta','duplicate_postmeta',
				'autoloaded_options','orphaned_plugin_options','action_scheduler'
			];

			for (var i = 0; i < order.length; i++) {
				var key = order[i];
				if (d[key]) {
					html += moduleCard(key, d[key]);
				}
			}

			html += '</div>';

			// Wat "Alles Opschonen" bewust laat liggen (alleen per stuk).
			var losLabels = [];
			for (var j = 0; j < order.length; j++) {
				var lk = order[j];
				if (d[lk] && isModuleAvailable(lk) && bulkModules.indexOf(lk) === -1 && warnings[lk]) {
					losLabels.push(labels[lk]);
				}
			}

			// Alles opschonen knop.
			html += '<div class="mcm-opt-bulk-actions">';
			html += '<button type="button" id="mcm-clean-all" class="button mcm-opt-btn-primary mcm-opt-btn-large">';
			html += '<span class="dashicons dashicons-trash" style="vertical-align:middle;margin-top:-2px;"></span> ';
			html += 'Alles Opschonen (Veilig + Controleren)';
			html += '</button>';
			if (losLabels.length) {
				html += '<p class="mcm-opt-bulk-note">Niet meegenomen: ' + losLabels.join(', ') + '. ' +
					'Die kunnen gegevens bevatten die nog nodig zijn; schoon ze alleen los op, met een reden.</p>';
			}
			html += '</div>';

			$('#mcm-scan-results').html(html);
		}).fail(function() {
			btn.prop('disabled', false);
			$('#mcm-scan-loading').hide();
			$('#mcm-scan-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Verbindingsfout.</div>');
		});
	});

	// CLEAN individuele module.
	$(document).on('click', '.mcm-opt-btn-clean', function() {
		var btn = $(this);
		var mod = btn.data('module');
		var risk = btn.data('risk');

		var msg = 'Weet je zeker dat je "' + labels[mod] + '" wilt opschonen?';
		if (warnings[mod]) {
			msg += '\\n\\n' + warnings[mod];
		}
		if (risk === 'warning' || risk === 'danger') {
			msg += '\\n\\nControleer of je een recente backup hebt.';
		}

		if (!confirm(msg)) return;

		btn.prop('disabled', true).text('Bezig...');

		runClean(mod, {
			progress: function(totaal) { btn.text('Bezig... ' + totaal + ' verwijderd'); }
		}, function(ok, totaal, r) {
			if (ok) {
				btn.replaceWith('<span class="mcm-opt-clean-done">' + klaarTekst(totaal, r) + '</span>');
			} else {
				btn.prop('disabled', false).text('Opnieuw');
				alert('Opschonen mislukt: ' + r + (totaal ? '\\n\\nWel al verwijderd: ' + totaal + '.' : ''));
			}
		});
	});

	// CLEAN verweesde plugin-opties (aparte knop; valt bewust buiten "Alles Opschonen").
	$(document).on('click', '.mcm-opt-btn-clean-orphaned', function() {
		var btn = $(this);
		var plugins = btn.data('plugins') || '';
		var msg = 'Verwijder de achtergebleven opties van verwijderde plugins?';
		if (plugins) { msg += '\\n\\nHet gaat om: ' + plugins + '.'; }
		msg += '\\n\\nEr wordt eerst een terugzetbare backup gemaakt.';
		if (!confirm(msg)) return;

		btn.prop('disabled', true).text('Bezig...');

		$.post(mcmOptimizer.ajaxUrl, {
			action: 'mcm_optimizer_clean',
			nonce: mcmOptimizer.nonce,
			module: 'orphaned_plugin_options'
		}, function(response) {
			if (response.success) {
				var del = response.data.result.deleted || 0;
				var bk = response.data.result.backup ? ' (backup opgeslagen)' : '';
				btn.replaceWith('<span class="mcm-opt-clean-done">✓ ' + del + ' verwijderd' + bk + '</span>');
			} else {
				btn.prop('disabled', false).text('Fout!');
				alert('Opschonen mislukt: ' + (response.data || 'Onbekende fout'));
			}
		}).fail(function() {
			btn.prop('disabled', false).text('Opnieuw');
		});
	});

	// CLEAN ALL — alleen de modules uit bulkModules. Actieve transients, dubbele
	// postmeta en de prullenbak blijven liggen; die gaan alleen per stuk.
	$(document).on('click', '#mcm-clean-all', function() {
		var modules = [];
		var overgeslagen = [];
		$('.mcm-opt-btn-clean').each(function() {
			var m = $(this).data('module');
			if (bulkModules.indexOf(m) !== -1) {
				modules.push(m);
			} else {
				overgeslagen.push(labels[m]);
			}
		});

		if (!modules.length) {
			alert('Er is niets dat "Alles Opschonen" mag meenemen.' +
				(overgeslagen.length ? '\\n\\nLos op te schonen: ' + overgeslagen.join(', ') + '.' : ''));
			return;
		}

		var msg = 'Deze onderdelen worden opgeschoond:\\n- ' +
			modules.map(function(m) { return labels[m]; }).join('\\n- ');
		if (overgeslagen.length) {
			msg += '\\n\\nNiet meegenomen (alleen los): ' + overgeslagen.join(', ') + '.';
		}
		msg += '\\n\\nEr wordt eerst een health check snapshot gemaakt.';
		if (!confirm(msg)) {
			return;
		}

		var btn = $(this);
		btn.prop('disabled', true).text('Bezig met opschonen...');

		var idx = 0;

		function cleanNext() {
			if (idx >= modules.length) {
				btn.text('Klaar! Health check uitvoeren...');
				runHealthCheck();
				return;
			}

			var mod = modules[idx];
			var modBtn = $('.mcm-opt-btn-clean[data-module="' + mod + '"]');
			modBtn.prop('disabled', true).text('Bezig...');

			runClean(mod, {
				bulk: true,
				// Eén snapshot vóór de eerste module, zodat "vóór" in de vergelijking klopt.
				snapshot: idx === 0,
				progress: function(totaal) { modBtn.text('Bezig... ' + totaal + ' verwijderd'); }
			}, function(ok, totaal, r) {
				if (ok) {
					modBtn.replaceWith('<span class="mcm-opt-clean-done">' + klaarTekst(totaal, r) + '</span>');
				} else {
					modBtn.prop('disabled', false).text('Mislukt: ' + r);
				}
				idx++;
				cleanNext();
			});
		}

		cleanNext();
	});

	// HEALTH CHECK.
	function runHealthCheck() {
		$('#mcm-health-card').show();
		$('#mcm-health-results').html('<span class="spinner is-active" style="float:none;"></span> Health check uitvoeren...');

		$.post(mcmOptimizer.ajaxUrl, {
			action: 'mcm_optimizer_health',
			nonce: mcmOptimizer.nonce
		}, function(response) {
			if (!response.success) {
				$('#mcm-health-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Health check mislukt.</div>');
				return;
			}

			var d = response.data;
			var html = '';
			var aandacht = 0;

			// Checks. 'warn' = aandachtspunt (site draait gewoon), met evt. een knop.
			for (var key in d.checks) {
				var check = d.checks[key];
				if (check.passed && check.warn) aandacht++;
				html += healthRij(key, check.label, check.passed, check.warn, check.detail, check.actie);
			}

			// Vergelijking.
			if (d.comparison) {
				html += '<div class="mcm-opt-health-comparison">';
				html += '<strong>Database:</strong> ';
				html += d.comparison.db_before + ' MB → ' + d.comparison.db_after + ' MB ';
				if (d.comparison.db_saved > 0) {
					html += '(<span style="color:#155724;">-' + d.comparison.db_saved + ' MB bespaard</span>)';
				}
				html += '</div>';
			}

			// Overall status.
			if (d.all_passed) {
				html += '<div class="mcm-opt-alert mcm-opt-alert-safe mcm-opt-health-totaal" style="margin-top:15px;">';
				html += '<span class="dashicons dashicons-yes-alt"></span> ';
				html += '<span class="mcm-opt-health-totaal-tekst">' + (aandacht
					? 'De site draait correct. Hierboven staat iets dat je kunt opruimen.'
					: 'Alle checks geslaagd — de site draait correct!') + '</span>';
				html += '</div>';
			} else {
				html += '<div class="mcm-opt-alert mcm-opt-alert-danger" style="margin-top:15px;">';
				html += '<span class="dashicons dashicons-warning"></span> ';
				html += 'Eén of meer checks zijn mislukt. Controleer de details hierboven.';
				html += '</div>';
			}

			$('#mcm-health-results').html(html);
			$('#mcm-clean-all').text('Opschoning voltooid').prop('disabled', true);
		});
	}

	function healthRij(key, label, passed, warn, detail, actie) {
		var stand = !passed ? 'fail' : (warn ? 'warn' : 'pass');
		var icon = { fail: 'warning', warn: 'info', pass: 'yes-alt' }[stand];
		return '<div class="mcm-opt-health-row mcm-opt-health-' + stand + '" data-check="' + esc(key) + '">' +
			'<span class="dashicons dashicons-' + icon + '"></span> ' +
			'<span class="mcm-opt-health-tekst"><strong>' + esc(label) + '</strong>: ' + esc(detail) + '</span>' +
			(actie ? '<button type="button" class="button mcm-opt-health-actie" data-actie="' + esc(actie.type) + '">' + esc(actie.label) + '</button>' : '') +
		'</div>';
	}

	// "Ruim op" bij dubbel ingeplande cron-taken: per taak blijft er één over.
	$(document).on('click', '.mcm-opt-health-actie[data-actie="cron_dubbel"]', function() {
		var btn = $(this);
		var rij = btn.closest('.mcm-opt-health-row');
		btn.prop('disabled', true).text('Bezig...');

		$.post(mcmOptimizer.ajaxUrl, {
			action: 'mcm_optimizer_cron_dubbel',
			nonce: mcmOptimizer.nonce
		}, function(response) {
			if (!response.success) {
				btn.prop('disabled', false).text('Opnieuw');
				alert('Opruimen mislukt: ' + (response.data || 'Onbekende fout'));
				return;
			}
			var n = parseInt(response.data.result.deleted, 10) || 0;
			var c = response.data.check;
			var tekst = (n === 1 ? '1 dubbele inplanning' : n + ' dubbele inplanningen') + ' weggehaald. ' + c.message;
			rij.replaceWith(healthRij('cron', 'Cron-taken', c.ok, c.warn, tekst, c.actie));
			if (!$('#mcm-health-results .mcm-opt-health-warn').length) {
				$('.mcm-opt-health-totaal-tekst').text('Alle checks geslaagd — de site draait correct!');
			}
		}).fail(function(xhr) {
			btn.prop('disabled', false).text('Opnieuw');
			alert('Verbindingsfout (HTTP ' + (xhr.status || '?') + ').');
		});
	});
});
JS;
	}

	/* ---------------------------------------------------------------
	 * CSS — MCM Huisstijl (SecuPress-achtige layout)
	 * ------------------------------------------------------------- */

	private function get_css() {
		return <<<CSS
/* MCM Site Optimizer — Huisstijl */
:root {
	--mcm-primary: #E78E46;
	--mcm-primary-dark: #B95E41;
	--mcm-teal: #9DD0D2;
	--mcm-teal-dark: #7ab8ba;
	--mcm-terracotta: #AE432B;
	--mcm-brown: #875742;
	--mcm-brown-light: #A27D4B;
	--mcm-bg: #f0ede8;
	--mcm-white: #ffffff;
	--mcm-text: #3c3228;
	--mcm-text-light: #6b5d52;
	--mcm-border: #d4cdc4;
}

.mcm-opt-wrap {
	max-width: 960px;
	padding-top: 10px;
}

/* Header */
.mcm-opt-header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 20px;
}
.mcm-opt-header h1 {
	color: var(--mcm-brown);
	font-size: 26px;
	margin: 0;
}
.mcm-opt-version {
	background: var(--mcm-primary);
	color: #fff;
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 10px;
	font-weight: 600;
}
.mcm-opt-owner-badge {
	background: rgba(255,255,255,0.2);
	color: #fff;
	font-size: 11px;
	padding: 2px 10px;
	border-radius: 10px;
	font-weight: 600;
	letter-spacing: 0.5px;
}

/* Cards */
.mcm-opt-card {
	background: var(--mcm-white);
	border: 1px solid var(--mcm-border);
	border-radius: 8px;
	margin-bottom: 20px;
	overflow: hidden;
}
.mcm-opt-card-header {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 14px 20px;
	background: linear-gradient(135deg, var(--mcm-brown) 0%, var(--mcm-primary-dark) 100%);
	color: #fff;
}
.mcm-opt-card-header h2 {
	margin: 0;
	font-size: 16px;
	color: #fff;
}
.mcm-opt-card-header .dashicons {
	font-size: 20px;
	width: 20px;
	height: 20px;
}
.mcm-opt-card-body {
	padding: 20px;
}

/* Alerts */
.mcm-opt-alert {
	padding: 12px 16px;
	border-radius: 6px;
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
}
.mcm-opt-alert .dashicons {
	font-size: 18px;
	width: 18px;
	height: 18px;
}
.mcm-opt-alert-safe {
	background: rgba(157, 208, 210, 0.2);
	border: 1px solid var(--mcm-teal);
	color: #1a5c5e;
}
.mcm-opt-alert-warning {
	background: rgba(231, 142, 70, 0.15);
	border: 1px solid var(--mcm-primary);
	color: #7a4a1a;
}
.mcm-opt-alert-danger {
	background: rgba(174, 67, 43, 0.12);
	border: 1px solid var(--mcm-terracotta);
	color: var(--mcm-terracotta);
}

/* Form fields */
.mcm-opt-field-row {
	margin-bottom: 16px;
}
.mcm-opt-field-row label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
	color: var(--mcm-text);
}
.mcm-opt-field-row select,
.mcm-opt-field-row input[type="number"] {
	border: 1px solid var(--mcm-border);
	border-radius: 4px;
	padding: 6px 10px;
}
.mcm-opt-field-row .description {
	color: var(--mcm-text-light);
	font-size: 12px;
	margin-top: 4px;
}

/* Buttons */
.mcm-opt-btn-primary {
	background: var(--mcm-primary) !important;
	border-color: var(--mcm-primary-dark) !important;
	color: #fff !important;
	font-weight: 600;
	border-radius: 4px !important;
	transition: background 0.2s;
}
.mcm-opt-btn-primary:hover,
.mcm-opt-btn-primary:focus {
	background: var(--mcm-primary-dark) !important;
	border-color: var(--mcm-brown) !important;
	color: #fff !important;
}
.mcm-opt-btn-large {
	padding: 8px 24px !important;
	font-size: 14px !important;
	height: auto !important;
}

/* DB size bar */
.mcm-opt-db-size {
	font-size: 15px;
	color: var(--mcm-text);
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--mcm-border);
}

/* Module grid */
.mcm-opt-module-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 12px;
	margin-bottom: 20px;
}
.mcm-opt-module-card {
	background: var(--mcm-bg);
	border: 1px solid var(--mcm-border);
	border-radius: 6px;
	padding: 14px 16px;
}
.mcm-opt-module-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}
.mcm-opt-module-label {
	font-weight: 600;
	font-size: 13px;
	color: var(--mcm-text);
}
.mcm-opt-module-count {
	font-size: 20px;
	font-weight: 700;
	color: var(--mcm-brown);
	margin-bottom: 4px;
}
.mcm-opt-module-extra {
	font-size: 12px;
	color: var(--mcm-text-light);
	margin-bottom: 10px;
}
.mcm-opt-module-info {
	font-size: 12px;
	color: var(--mcm-text-light);
	font-style: italic;
}
.mcm-opt-module-actions {
	margin-top: 8px;
}

/* Risk badges */
.mcm-opt-risk-badge {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	color: #fff;
}
.mcm-opt-risk-badge .dashicons {
	font-size: 14px;
	width: 14px;
	height: 14px;
}

/* Image warning */
.mcm-opt-img-warn {
	color: var(--mcm-terracotta);
	font-weight: 600;
}

/* Clean buttons */
.mcm-opt-btn-clean {
	font-size: 12px !important;
	padding: 2px 12px !important;
	border-radius: 4px !important;
}
.mcm-opt-clean-ok {
	color: var(--mcm-teal-dark);
	font-weight: 600;
	font-size: 13px;
}
.mcm-opt-clean-done {
	color: #155724;
	font-weight: 600;
	font-size: 13px;
}

/* Bulk actions */
.mcm-opt-bulk-actions {
	text-align: center;
	padding: 16px 0 0;
	border-top: 1px solid var(--mcm-border);
}
.mcm-opt-bulk-note {
	max-width: 560px;
	margin: 10px auto 0;
	font-size: 12px;
	color: var(--mcm-text-light);
}

/* Waarschuwing op een kaart die alleen los mag */
.mcm-opt-module-warning {
	font-style: normal;
	color: var(--mcm-terracotta);
	margin: 6px 0 2px;
	line-height: 1.45;
}

/* Health check */
.mcm-opt-health-row {
	padding: 8px 12px;
	margin-bottom: 6px;
	border-radius: 4px;
	font-size: 13px;
	display: flex;
	align-items: center;
	gap: 6px;
}
.mcm-opt-health-pass {
	background: rgba(157, 208, 210, 0.15);
}
.mcm-opt-health-pass .dashicons {
	color: #1a5c5e;
}
.mcm-opt-health-fail {
	background: rgba(174, 67, 43, 0.1);
}
.mcm-opt-health-fail .dashicons {
	color: var(--mcm-terracotta);
}
.mcm-opt-health-warn {
	background: rgba(231, 142, 70, 0.12);
}
.mcm-opt-health-warn .dashicons {
	color: var(--mcm-primary-dark);
}
.mcm-opt-health-row .dashicons {
	flex: none;
}
.mcm-opt-health-tekst {
	flex: 1;
	line-height: 1.45;
}
.mcm-opt-health-actie {
	flex: none;
	font-size: 12px !important;
}
.mcm-opt-health-comparison {
	padding: 12px;
	margin-top: 10px;
	background: var(--mcm-bg);
	border-radius: 4px;
	font-size: 14px;
}

/* Log table */
.mcm-opt-log-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}
.mcm-opt-log-table th,
.mcm-opt-log-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--mcm-border);
}
.mcm-opt-log-table th {
	background: var(--mcm-bg);
	color: var(--mcm-text);
	font-weight: 600;
}
.mcm-opt-log-table tr:hover td {
	background: rgba(231, 142, 70, 0.05);
}
CSS;
	}
}

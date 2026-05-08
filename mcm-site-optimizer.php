<?php
/**
 * Plugin Name: MCM Site Optimizer
 * Plugin URI:  https://github.com/MarcoMCM/mcm-site-optimizer
 * Description: Site optimalisatie tool voor MCM Websites klanten. Database opschoning, ongebruikte media detectie, image sizes beheer en meer.
 * Version: 1.2.2
 * Author: MCM Websites
 * Author URI: https://mcmwebsites.nl
 * Update URI: https://github.com/MarcoMCM/mcm-site-optimizer
 * Text Domain: mcm-site-optimizer
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCM_OPTIMIZER_VERSION', '1.2.2' );
define( 'MCM_OPTIMIZER_FILE', __FILE__ );
define( 'MCM_OPTIMIZER_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCM_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );

// Self-update via publieke GitHub repo. Pikt nieuwste GitHub-release op
// en biedt 'm aan via WP's normale update-flow (en dus ook MainWP).
require_once MCM_OPTIMIZER_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
$mcm_optimizer_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/MarcoMCM/mcm-site-optimizer/',
	MCM_OPTIMIZER_FILE,
	'mcm-site-optimizer'
);
$mcm_optimizer_update_checker->setBranch( 'main' );

require_once MCM_OPTIMIZER_DIR . 'includes/class-backup-check.php';
require_once MCM_OPTIMIZER_DIR . 'includes/class-scanner.php';
require_once MCM_OPTIMIZER_DIR . 'includes/class-database-cleaner.php';
require_once MCM_OPTIMIZER_DIR . 'includes/class-health-check.php';
require_once MCM_OPTIMIZER_DIR . 'includes/class-admin-page.php';
require_once MCM_OPTIMIZER_DIR . 'includes/class-client-role.php';

/**
 * Main plugin class.
 */
final class MCM_Site_Optimizer {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( MCM_OPTIMIZER_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( MCM_OPTIMIZER_FILE, [ $this, 'deactivate' ] );

		if ( is_admin() ) {
			new MCM_Optimizer_Admin_Page();
		}
	}

	/**
	 * On activation: set defaults.
	 */
	public function activate() {
		if ( ! get_option( 'mcm_optimizer_settings' ) ) {
			update_option( 'mcm_optimizer_settings', self::get_defaults() );
		}
		if ( ! get_option( 'mcm_optimizer_log' ) ) {
			update_option( 'mcm_optimizer_log', [] );
		}

		// Installeer / werk de MCM Klant rol bij.
		if ( class_exists( 'MCM_Optimizer_Client_Role' ) ) {
			MCM_Optimizer_Client_Role::install_role();
			if ( false === get_option( 'mcm_client_role_enabled', false ) ) {
				update_option( 'mcm_client_role_enabled', true );
			}
		}
	}

	/**
	 * On deactivation: cleanup.
	 */
	public function deactivate() {
		// Bewaar instellingen en logs voor heractivatie.
	}

	/**
	 * Default settings.
	 */
	public static function get_defaults() {
		return [
			'package_level'          => 'all', // basis, actief, webshop, all
			'revisions_keep'         => 5,
			'transient_blacklist'    => '',
			'action_scheduler_days'  => 30,
		];
	}

	/**
	 * Pakket configuratie: welke modules per niveau.
	 */
	public static function get_package_modules() {
		return [
			'basis'   => [
				'expired_transients',
				'auto_drafts',
				'spam_comments',
				'trash_comments',
				'trashed_posts',
				'revisions',
				'orphaned_postmeta',
				'orphaned_commentmeta',
				'autoloaded_options',
			],
			'actief'  => [
				'expired_transients',
				'auto_drafts',
				'spam_comments',
				'trash_comments',
				'trashed_posts',
				'revisions',
				'orphaned_postmeta',
				'orphaned_commentmeta',
				'duplicate_postmeta',
				'autoloaded_options',
				'active_transients',
			],
			'webshop' => [
				'expired_transients',
				'auto_drafts',
				'spam_comments',
				'trash_comments',
				'trashed_posts',
				'revisions',
				'orphaned_postmeta',
				'orphaned_commentmeta',
				'duplicate_postmeta',
				'autoloaded_options',
				'active_transients',
				'action_scheduler',
			],
			'all'     => [
				'expired_transients',
				'auto_drafts',
				'spam_comments',
				'trash_comments',
				'trashed_posts',
				'revisions',
				'orphaned_postmeta',
				'orphaned_commentmeta',
				'duplicate_postmeta',
				'autoloaded_options',
				'active_transients',
				'action_scheduler',
			],
		];
	}

	/**
	 * Check of een module beschikbaar is voor het huidige pakket.
	 */
	public static function module_available( $module ) {
		$settings = get_option( 'mcm_optimizer_settings', self::get_defaults() );
		$level    = $settings['package_level'] ?? 'all';
		$modules  = self::get_package_modules();

		return in_array( $module, $modules[ $level ] ?? [], true );
	}
}

MCM_Site_Optimizer::get_instance();

<?php
/**
 * Scanner: dry-run analyses zonder data te wijzigen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Scanner {

	/**
	 * Volledige scan van alle modules.
	 */
	public static function full_scan() {
		global $wpdb;

		$results = [];

		// Database grootte.
		$results['db_size'] = self::get_database_size();

		// Expired transients.
		$results['expired_transients'] = self::count_expired_transients();

		// Actieve transients (gegroepeerd per prefix).
		$results['active_transients'] = self::scan_active_transients();

		// Revisies.
		$results['revisions'] = self::count_revisions();

		// Auto-drafts.
		$results['auto_drafts'] = self::count_auto_drafts();

		// Spam comments.
		$results['spam_comments'] = self::count_spam_comments();

		// Trash comments.
		$results['trash_comments'] = self::count_trash_comments();

		// Trashed posts.
		$results['trashed_posts'] = self::count_trashed_posts();

		// Orphaned postmeta.
		$results['orphaned_postmeta'] = self::count_orphaned_postmeta();

		// Orphaned commentmeta.
		$results['orphaned_commentmeta'] = self::count_orphaned_commentmeta();

		// Dubbele postmeta.
		$results['duplicate_postmeta'] = self::count_duplicate_postmeta();

		// Autoloaded options.
		$results['autoloaded_options'] = self::scan_autoloaded_options();

		// Action Scheduler.
		$results['action_scheduler'] = self::count_action_scheduler();

		return $results;
	}

	/**
	 * Database grootte in MB.
	 */
	public static function get_database_size() {
		global $wpdb;

		$size = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
				 FROM information_schema.TABLES
				 WHERE table_schema = %s",
				DB_NAME
			)
		);

		return [
			'size_mb' => floatval( $size ),
			'label'   => sprintf( '%s MB', $size ),
		];
	}

	/**
	 * Tel expired transients.
	 */
	public static function count_expired_transients() {
		global $wpdb;

		$time = time();

		$count = $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_timeout_%'
			 AND option_value < {$time}"
		);

		// Bereken de grootte.
		$size = $wpdb->get_var(
			"SELECT ROUND(SUM(LENGTH(option_value)) / 1024, 2)
			 FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_%'
			 AND option_name NOT LIKE '_transient_timeout_%'
			 AND REPLACE(option_name, '_transient_', '_transient_timeout_') IN (
				SELECT option_name FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_timeout_%'
				AND option_value < {$time}
			 )"
		);

		return [
			'count'  => intval( $count ),
			'size_kb' => floatval( $size ?: 0 ),
			'risk'   => 'safe',
		];
	}

	/**
	 * Scan actieve transients, gegroepeerd per prefix.
	 */
	public static function scan_active_transients() {
		global $wpdb;

		$time = time();

		$transients = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) as size
			 FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_%'
			 AND option_name NOT LIKE '_transient_timeout_%'
			 AND (
				REPLACE(option_name, '_transient_', '_transient_timeout_') NOT IN (
					SELECT option_name FROM {$wpdb->options}
					WHERE option_name LIKE '_transient_timeout_%'
				)
				OR REPLACE(option_name, '_transient_', '_transient_timeout_') IN (
					SELECT option_name FROM {$wpdb->options}
					WHERE option_name LIKE '_transient_timeout_%'
					AND option_value >= {$time}
				)
			 )"
		);

		// Groepeer per prefix (eerste deel van de transient naam).
		$groups         = [];
		$has_images     = [];
		$total_count    = 0;
		$total_size     = 0;

		foreach ( $transients as $t ) {
			$name = str_replace( '_transient_', '', $t->option_name );

			// Bepaal prefix: eerste deel voor underscore of eerste 20 tekens.
			$parts  = explode( '_', $name );
			$prefix = $parts[0];
			if ( strlen( $prefix ) < 3 && isset( $parts[1] ) ) {
				$prefix = $parts[0] . '_' . $parts[1];
			}

			if ( ! isset( $groups[ $prefix ] ) ) {
				$groups[ $prefix ] = [ 'count' => 0, 'size' => 0 ];
			}
			$groups[ $prefix ]['count']++;
			$groups[ $prefix ]['size'] += $t->size;
			$total_count++;
			$total_size += $t->size;
		}

		// Check of transients image-URLs bevatten (steekproef).
		$sample = $wpdb->get_results(
			"SELECT option_name, option_value
			 FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_%'
			 AND option_name NOT LIKE '_transient_timeout_%'
			 AND (option_value LIKE '%<img%' OR option_value LIKE '%.jpg%' OR option_value LIKE '%.png%' OR option_value LIKE '%.webp%')
			 LIMIT 50"
		);

		foreach ( $sample as $s ) {
			$name   = str_replace( '_transient_', '', $s->option_name );
			$parts  = explode( '_', $name );
			$prefix = $parts[0];
			if ( strlen( $prefix ) < 3 && isset( $parts[1] ) ) {
				$prefix = $parts[0] . '_' . $parts[1];
			}
			$has_images[ $prefix ] = true;
		}

		// Sorteer op grootte (grootste eerst).
		arsort( $groups );

		return [
			'total_count' => $total_count,
			'total_size_kb' => round( $total_size / 1024, 2 ),
			'groups'      => $groups,
			'has_images'  => $has_images,
			'risk'        => 'warning',
		];
	}

	/**
	 * Tel revisies.
	 */
	public static function count_revisions() {
		global $wpdb;

		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
		);

		$size = $wpdb->get_var(
			"SELECT ROUND(SUM(LENGTH(post_content)) / 1024, 2)
			 FROM {$wpdb->posts}
			 WHERE post_type = 'revision'"
		);

		return [
			'count'   => intval( $count ),
			'size_kb' => floatval( $size ?: 0 ),
			'risk'    => 'warning',
		];
	}

	/**
	 * Tel auto-drafts.
	 */
	public static function count_auto_drafts() {
		global $wpdb;

		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
		);

		return [
			'count' => intval( $count ),
			'risk'  => 'safe',
		];
	}

	/**
	 * Tel spam comments.
	 */
	public static function count_spam_comments() {
		global $wpdb;

		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
		);

		return [
			'count' => intval( $count ),
			'risk'  => 'safe',
		];
	}

	/**
	 * Tel trash comments.
	 */
	public static function count_trash_comments() {
		global $wpdb;

		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
		);

		return [
			'count' => intval( $count ),
			'risk'  => 'safe',
		];
	}

	/**
	 * Tel trashed posts.
	 */
	public static function count_trashed_posts() {
		global $wpdb;

		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
		);

		return [
			'count' => intval( $count ),
			'risk'  => 'safe',
		];
	}

	/**
	 * Tel orphaned postmeta (postmeta waar de post niet meer bestaat).
	 */
	public static function count_orphaned_postmeta() {
		global $wpdb;

		$count = $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE p.ID IS NULL"
		);

		return [
			'count' => intval( $count ),
			'risk'  => 'warning',
		];
	}

	/**
	 * Tel orphaned commentmeta.
	 */
	public static function count_orphaned_commentmeta() {
		global $wpdb;

		$count = $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->commentmeta} cm
			 LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
			 WHERE c.comment_ID IS NULL"
		);

		return [
			'count' => intval( $count ),
			'risk'  => 'warning',
		];
	}

	/**
	 * Tel dubbele postmeta (zelfde post_id + meta_key + meta_value).
	 */
	public static function count_duplicate_postmeta() {
		global $wpdb;

		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT post_id, meta_key, meta_value, COUNT(*) as cnt
				FROM {$wpdb->postmeta}
				GROUP BY post_id, meta_key, meta_value
				HAVING cnt > 1
			) as dupes"
		);

		// Tel het totaal aan overtollige rijen (totaal - unieke).
		$excess = $wpdb->get_var(
			"SELECT SUM(cnt - 1) FROM (
				SELECT post_id, meta_key, meta_value, COUNT(*) as cnt
				FROM {$wpdb->postmeta}
				GROUP BY post_id, meta_key, meta_value
				HAVING cnt > 1
			) as dupes"
		);

		return [
			'groups'       => intval( $count ),
			'excess_rows'  => intval( $excess ?: 0 ),
			'risk'         => 'warning',
		];
	}

	/**
	 * Scan autoloaded options: vind grote of orphaned autoloaded options.
	 */
	public static function scan_autoloaded_options() {
		global $wpdb;

		// Totale autoload grootte.
		$total_size = $wpdb->get_var(
			"SELECT ROUND(SUM(LENGTH(option_value)) / 1024, 2)
			 FROM {$wpdb->options}
			 WHERE autoload = 'yes'"
		);

		// Top 20 grootste autoloaded options.
		$biggest = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) as size
			 FROM {$wpdb->options}
			 WHERE autoload = 'yes'
			 ORDER BY size DESC
			 LIMIT 20"
		);

		// Detecteer options van gedeactiveerde plugins.
		$active_plugins = get_option( 'active_plugins', [] );
		$orphaned       = [];

		// Haal alle bekende plugin prefixes op.
		$all_options = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) as size
			 FROM {$wpdb->options}
			 WHERE autoload = 'yes'
			 AND option_name NOT LIKE 'widget_%'
			 AND option_name NOT LIKE 'theme_mods_%'
			 AND option_name NOT LIKE '_transient_%'
			 AND option_name NOT LIKE '_site_transient_%'
			 AND option_name NOT IN (
				'siteurl','home','blogname','blogdescription','users_can_register',
				'admin_email','start_of_week','use_balanceTags','use_smilies',
				'require_name_email','comments_notify','posts_per_rss','rss_use_excerpt',
				'mailserver_url','mailserver_login','mailserver_pass','mailserver_port',
				'default_category','default_link_category','default_email_category',
				'template','stylesheet','comment_moderation','moderation_notify',
				'permalink_structure','rewrite_rules','active_plugins','current_theme',
				'sidebars_widgets','cron','wp_user_roles'
			 )
			 ORDER BY size DESC"
		);

		return [
			'total_size_kb' => floatval( $total_size ?: 0 ),
			'biggest'       => $biggest,
			'risk'          => floatval( $total_size ) > 1024 ? 'danger' : 'warning',
		];
	}

	/**
	 * Tel Action Scheduler voltooide taken.
	 */
	public static function count_action_scheduler() {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';

		// Check of de tabel bestaat.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s",
				DB_NAME,
				$table
			)
		);

		if ( ! $exists ) {
			return [
				'available' => false,
				'message'   => 'Action Scheduler tabel niet gevonden.',
			];
		}

		$completed = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'complete'"
		);

		$failed = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'failed'"
		);

		$total = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}"
		);

		return [
			'available'  => true,
			'completed'  => intval( $completed ),
			'failed'     => intval( $failed ),
			'total'      => intval( $total ),
			'risk'       => 'warning',
		];
	}
}

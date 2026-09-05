<?php
/**
 * Database Cleaner: voert de daadwerkelijke opschoning uit.
 * Elke methode retourneert het aantal verwijderde rijen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Database_Cleaner {

	/**
	 * Verwijder expired transients.
	 */
	public static function clean_expired_transients() {
		global $wpdb;

		$time = time();

		// Haal de expired timeout option names op.
		$expired = $wpdb->get_col(
			"SELECT option_name
			 FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_timeout_%'
			 AND option_value < {$time}"
		);

		if ( empty( $expired ) ) {
			return [ 'deleted' => 0 ];
		}

		$count = 0;

		foreach ( $expired as $timeout_name ) {
			// Van _transient_timeout_xxx naar _transient_xxx.
			$transient_name = str_replace( '_transient_timeout_', '_transient_', $timeout_name );

			$wpdb->delete( $wpdb->options, [ 'option_name' => $timeout_name ] );
			$wpdb->delete( $wpdb->options, [ 'option_name' => $transient_name ] );
			$count++;
		}

		// Doe hetzelfde voor site transients.
		$expired_site = $wpdb->get_col(
			"SELECT option_name
			 FROM {$wpdb->options}
			 WHERE option_name LIKE '_site_transient_timeout_%'
			 AND option_value < {$time}"
		);

		foreach ( $expired_site as $timeout_name ) {
			$transient_name = str_replace( '_site_transient_timeout_', '_site_transient_', $timeout_name );

			$wpdb->delete( $wpdb->options, [ 'option_name' => $timeout_name ] );
			$wpdb->delete( $wpdb->options, [ 'option_name' => $transient_name ] );
			$count++;
		}

		return [ 'deleted' => $count ];
	}

	/**
	 * Verwijder actieve transients (met blacklist check).
	 */
	public static function clean_active_transients( $blacklist = [] ) {
		global $wpdb;

		$time = time();

		// Haal niet-expired, niet-permanente transients op.
		$transients = $wpdb->get_results(
			"SELECT option_name
			 FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_%'
			 AND option_name NOT LIKE '_transient_timeout_%'"
		);

		$count   = 0;
		$skipped = 0;

		foreach ( $transients as $t ) {
			$name = $t->option_name;

			// Check blacklist.
			$is_blacklisted = false;
			foreach ( $blacklist as $bl ) {
				$bl = trim( $bl );
				if ( ! empty( $bl ) && false !== strpos( $name, $bl ) ) {
					$is_blacklisted = true;
					break;
				}
			}

			if ( $is_blacklisted ) {
				$skipped++;
				continue;
			}

			// Verwijder transient + timeout.
			$timeout_name = str_replace( '_transient_', '_transient_timeout_', $name );
			$wpdb->delete( $wpdb->options, [ 'option_name' => $name ] );
			$wpdb->delete( $wpdb->options, [ 'option_name' => $timeout_name ] );
			$count++;
		}

		return [
			'deleted' => $count,
			'skipped' => $skipped,
		];
	}

	/**
	 * Verwijder revisies met behoud van X per post.
	 */
	public static function clean_revisions( $keep = 5 ) {
		global $wpdb;

		// Vind alle posts met revisies.
		$parents = $wpdb->get_col(
			"SELECT DISTINCT post_parent
			 FROM {$wpdb->posts}
			 WHERE post_type = 'revision'
			 AND post_parent > 0"
		);

		$total_deleted = 0;

		foreach ( $parents as $parent_id ) {
			// Haal revisies op, gesorteerd op datum (nieuwste eerst).
			$revisions = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_type = 'revision'
					 AND post_parent = %d
					 ORDER BY post_date DESC",
					$parent_id
				)
			);

			// Sla de eerste $keep over.
			$to_delete = array_slice( $revisions, $keep );

			foreach ( $to_delete as $rev_id ) {
				// Verwijder bijbehorende postmeta.
				$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $rev_id ] );
				// Verwijder de revisie.
				$wpdb->delete( $wpdb->posts, [ 'ID' => $rev_id ] );
				$total_deleted++;
			}
		}

		return [ 'deleted' => $total_deleted ];
	}

	/**
	 * Verwijder auto-drafts.
	 */
	public static function clean_auto_drafts() {
		global $wpdb;

		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
		);

		foreach ( $ids as $id ) {
			$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $id ] );
			$wpdb->delete( $wpdb->posts, [ 'ID' => $id ] );
		}

		return [ 'deleted' => count( $ids ) ];
	}

	/**
	 * Verwijder spam comments.
	 */
	public static function clean_spam_comments() {
		global $wpdb;

		$ids = $wpdb->get_col(
			"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
		);

		foreach ( $ids as $id ) {
			$wpdb->delete( $wpdb->commentmeta, [ 'comment_id' => $id ] );
			$wpdb->delete( $wpdb->comments, [ 'comment_ID' => $id ] );
		}

		return [ 'deleted' => count( $ids ) ];
	}

	/**
	 * Verwijder trash comments.
	 */
	public static function clean_trash_comments() {
		global $wpdb;

		$ids = $wpdb->get_col(
			"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
		);

		foreach ( $ids as $id ) {
			$wpdb->delete( $wpdb->commentmeta, [ 'comment_id' => $id ] );
			$wpdb->delete( $wpdb->comments, [ 'comment_ID' => $id ] );
		}

		return [ 'deleted' => count( $ids ) ];
	}

	/**
	 * Verwijder trashed posts.
	 */
	public static function clean_trashed_posts() {
		global $wpdb;

		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'"
		);

		foreach ( $ids as $id ) {
			$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $id ] );
			$wpdb->delete( $wpdb->posts, [ 'ID' => $id ] );
		}

		return [ 'deleted' => count( $ids ) ];
	}

	/**
	 * Verwijder orphaned postmeta.
	 */
	public static function clean_orphaned_postmeta() {
		global $wpdb;

		$deleted = $wpdb->query(
			"DELETE pm FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE p.ID IS NULL"
		);

		return [ 'deleted' => intval( $deleted ) ];
	}

	/**
	 * Verwijder orphaned commentmeta.
	 */
	public static function clean_orphaned_commentmeta() {
		global $wpdb;

		$deleted = $wpdb->query(
			"DELETE cm FROM {$wpdb->commentmeta} cm
			 LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
			 WHERE c.comment_ID IS NULL"
		);

		return [ 'deleted' => intval( $deleted ) ];
	}

	/**
	 * Verwijder dubbele postmeta (behoud de eerste per groep).
	 */
	public static function clean_duplicate_postmeta() {
		global $wpdb;

		$deleted = $wpdb->query(
			"DELETE pm1 FROM {$wpdb->postmeta} pm1
			 INNER JOIN {$wpdb->postmeta} pm2
			 WHERE pm1.meta_id > pm2.meta_id
			 AND pm1.post_id = pm2.post_id
			 AND pm1.meta_key = pm2.meta_key
			 AND pm1.meta_value = pm2.meta_value"
		);

		return [ 'deleted' => intval( $deleted ) ];
	}

	/**
	 * Verwijder Action Scheduler voltooide taken ouder dan X dagen.
	 */
	public static function clean_action_scheduler( $days = 30 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';
		$log_table = $wpdb->prefix . 'actionscheduler_logs';

		// Check of tabellen bestaan.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s",
				DB_NAME,
				$table
			)
		);

		if ( ! $exists ) {
			return [ 'deleted' => 0, 'message' => 'Tabel niet gevonden.' ];
		}

		$cutoff = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Verwijder eerst de logs.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE al FROM {$log_table} al
				 INNER JOIN {$table} aa ON al.action_id = aa.action_id
				 WHERE aa.status IN ('complete', 'failed', 'canceled')
				 AND aa.last_attempt_gmt < %s",
				$cutoff
			)
		);

		// Verwijder de acties.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE status IN ('complete', 'failed', 'canceled')
				 AND last_attempt_gmt < %s",
				$cutoff
			)
		);

		return [ 'deleted' => intval( $deleted ) ];
	}

	/**
	 * Gecureerde registry: optie-prefix → plugin(s).
	 *
	 * Een prefix wordt ALLEEN als "verweesd" behandeld als geen van de
	 * bijbehorende plugin-mappen nog in wp-content/plugins/ staat. Zo raken we
	 * nooit opties aan van een plugin die er nog is (actief óf inactief). Blind
	 * op naam wissen kan niet — opties dragen geen plugin-referentie — dus de
	 * lijst is bewust gecureerd. Per site uit te breiden via het filter
	 * 'mcm_optimizer_orphaned_option_prefixes'.
	 *
	 * @return array<string,array{label:string,folders:array<int,string>}>
	 */
	public static function orphaned_option_registry() {
		$registry = [
			'secupress' => [ 'label' => 'SecuPress',                       'folders' => [ 'secupress', 'secupress-pro' ] ],
			'itsec'     => [ 'label' => 'iThemes / Solid Security',        'folders' => [ 'better-wp-security', 'ithemes-security-pro', 'better-wp-security-pro' ] ],
			'bwps'      => [ 'label' => 'iThemes (Better WP Security, oud)', 'folders' => [ 'better-wp-security', 'ithemes-security-pro' ] ],
			'wordfence' => [ 'label' => 'Wordfence',                       'folders' => [ 'wordfence' ] ],
			'wfls_'     => [ 'label' => 'Wordfence Login Security',        'folders' => [ 'wordfence-login-security', 'wordfence' ] ],
			'sucuri'    => [ 'label' => 'Sucuri Security',                 'folders' => [ 'sucuri-scanner' ] ],
			'aiowps_'   => [ 'label' => 'All In One WP Security',          'folders' => [ 'all-in-one-wp-security-and-firewall' ] ],
			'wpcf-'     => [ 'label' => 'Toolset Types',                   'folders' => [ 'types', 'wp-types', 'toolset-types' ] ],
			'toolset'   => [ 'label' => 'Toolset',                         'folders' => [ 'toolset-common', 'toolset-blocks', 'types', 'wp-views', 'cred-frontend-editor', 'toolset-maps' ] ],
			'__CRED'    => [ 'label' => 'Toolset CRED',                    'folders' => [ 'cred-frontend-editor', 'toolset-cred-commerce' ] ],
		];

		return apply_filters( 'mcm_optimizer_orphaned_option_prefixes', $registry );
	}

	/**
	 * Staat minstens één van deze plugin-mappen nog in wp-content/plugins/?
	 */
	protected static function any_plugin_installed( array $folders ) {
		foreach ( $folders as $folder ) {
			$folder = trim( (string) $folder );
			if ( '' !== $folder && is_dir( WP_PLUGIN_DIR . '/' . $folder ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scan verweesde plugin-opties (dry-run): per verwijderde plugin het aantal
	 * achtergebleven opties, de omvang en of ze autoloaded zijn.
	 *
	 * @return array{count:int,size_kb:float,groups:array<int,array>,risk:string}
	 */
	public static function scan_orphaned_plugin_options() {
		global $wpdb;

		$registry    = self::orphaned_option_registry();
		$groups      = [];
		$total_count = 0;
		$total_bytes = 0;

		foreach ( $registry as $prefix => $info ) {
			// Plugin nog aanwezig? Dan met rust laten.
			if ( self::any_plugin_installed( (array) ( $info['folders'] ?? [] ) ) ) {
				continue;
			}

			$like = $wpdb->esc_like( (string) $prefix ) . '%';
			$row  = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS cnt,
					        COALESCE(SUM(LENGTH(option_value)),0) AS bytes,
					        COALESCE(SUM(autoload IN ('yes','on','auto','auto-on')),0) AS autoload_cnt
					 FROM {$wpdb->options}
					 WHERE option_name LIKE %s",
					$like
				)
			);

			$cnt = intval( $row->cnt ?? 0 );
			if ( $cnt < 1 ) {
				continue;
			}

			$bytes         = intval( $row->bytes ?? 0 );
			$groups[]      = [
				'prefix'   => (string) $prefix,
				'label'    => $info['label'] ?? (string) $prefix,
				'count'    => $cnt,
				'size_kb'  => round( $bytes / 1024, 1 ),
				'autoload' => intval( $row->autoload_cnt ?? 0 ) > 0,
			];
			$total_count  += $cnt;
			$total_bytes  += $bytes;
		}

		return [
			'count'   => $total_count,
			'size_kb' => round( $total_bytes / 1024, 1 ),
			'groups'  => $groups,
			'risk'    => 'warning',
		];
	}

	/**
	 * Verwijder verweesde plugin-opties — mét terugzetbare backup vooraf.
	 * Verwijdert alleen wat scan_orphaned_plugin_options() aandraagt (dus enkel
	 * prefixes waarvan de plugin niet meer geïnstalleerd is).
	 */
	public static function clean_orphaned_plugin_options() {
		global $wpdb;

		$scan = self::scan_orphaned_plugin_options();
		if ( empty( $scan['groups'] ) ) {
			return [ 'deleted' => 0 ];
		}

		// Verzamel de exacte rijen (voor backup én verwijderen).
		$rows = [];
		foreach ( $scan['groups'] as $g ) {
			$like  = $wpdb->esc_like( $g['prefix'] ) . '%';
			$found = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like
				),
				ARRAY_A
			);
			foreach ( $found as $r ) {
				$rows[] = $r;
			}
		}

		if ( empty( $rows ) ) {
			return [ 'deleted' => 0 ];
		}

		// Backup vóór verwijderen — geen backup, geen delete.
		$backup = self::backup_options_rows( $rows, 'orphaned-plugin-options' );
		if ( empty( $backup['ok'] ) ) {
			return [
				'deleted' => 0,
				'error'   => 'Backup mislukt — er is niets verwijderd. (' . ( $backup['error'] ?? 'onbekend' ) . ')',
			];
		}

		$deleted = 0;
		foreach ( $rows as $r ) {
			$deleted += (int) $wpdb->delete( $wpdb->options, [ 'option_name' => $r['option_name'] ] );
		}

		return [
			'deleted' => $deleted,
			'groups'  => count( $scan['groups'] ),
			'backup'  => $backup['file'] ?? '',
		];
	}

	/**
	 * Schrijf een set option-rijen naar een terugzetbaar JSON-bestand in een
	 * beschermde map onder uploads. Retourneert ['ok'=>bool, 'file'=>relpad, ...].
	 */
	protected static function backup_options_rows( array $rows, $slug ) {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return [ 'ok' => false, 'error' => $upload['error'] ];
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'mcm-optimizer-backups';
		if ( ! wp_mkdir_p( $dir ) ) {
			return [ 'ok' => false, 'error' => 'Kan backup-map niet aanmaken.' ];
		}

		// Afschermen tegen publieke toegang (opties kunnen config bevatten).
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}

		$file    = $dir . '/' . sanitize_file_name( $slug ) . '-' . gmdate( 'Ymd-His' ) . '.json';
		$payload = wp_json_encode(
			[
				'created' => current_time( 'mysql' ),
				'table'   => $GLOBALS['wpdb']->options,
				'note'    => 'MCM Site Optimizer — terugzetbare backup van verwijderde opties.',
				'rows'    => $rows,
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		$written = @file_put_contents( $file, $payload );
		if ( false === $written ) {
			return [ 'ok' => false, 'error' => 'Schrijven van backup mislukt.' ];
		}

		return [
			'ok'    => true,
			'file'  => ltrim( str_replace( ABSPATH, '', $file ), '/' ),
			'bytes' => $written,
			'rows'  => count( $rows ),
		];
	}

	/**
	 * Log een opschoningsactie.
	 */
	public static function log_action( $module, $result ) {
		$log = get_option( 'mcm_optimizer_log', [] );

		$log[] = [
			'time'    => current_time( 'mysql' ),
			'module'  => $module,
			'result'  => $result,
			'user'    => get_current_user_id(),
		];

		// Bewaar maximaal 100 log entries.
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}

		update_option( 'mcm_optimizer_log', $log );
	}
}

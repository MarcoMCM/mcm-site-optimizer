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

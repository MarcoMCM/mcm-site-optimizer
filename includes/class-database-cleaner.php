<?php
/**
 * Database Cleaner: voert de daadwerkelijke opschoning uit.
 * Elke methode retourneert het aantal verwijderde rijen.
 *
 * Grote opschoningen lopen in delen: een methode stopt na een tijdsbudget en
 * geeft dan 'more' => true terug, plus 'vanaf' (de laatst bekeken ID) waar de
 * volgende aanroep verder kan. Verwijderen gaat altijd op primaire sleutel, in
 * korte batches — nooit één lange DELETE over een hele tabel. Op een webshop
 * staan in posts/postmeta ook de bestellingen, en een checkout die op een lock
 * wacht, mislukt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Database_Cleaner {

	/**
	 * WordPress ruimt auto-drafts zelf op na 7 dagen (wp_delete_auto_drafts).
	 * Een jongere auto-draft kan van iemand zijn die nú een pagina aanmaakt.
	 */
	const AUTO_DRAFT_DAYS = 7;

	/**
	 * Modules die "Alles Opschonen" mag meenemen. Dit is de enige bron: de knop
	 * krijgt de lijst mee en ajax_clean() weigert bij een bulk-verzoek alles
	 * wat er niet in staat.
	 *
	 * Bewust NIET in de lijst, alleen per stuk:
	 * - actieve transients: plugins bewaren er soms gegevens van een lopend
	 *   proces in (import, betaling, koppeling);
	 * - dubbele postmeta: soms bewust dubbel (WooCommerce `_used_by`);
	 * - de prullenbak: de 'ongedaan maken' van WordPress;
	 * - verweesde plugin-opties: eigen knop, met backup.
	 */
	public static function bulk_modules() {
		return [
			'expired_transients',
			'revisions',
			'auto_drafts',
			'spam_comments',
			'trash_comments',
			'orphaned_postmeta',
			'orphaned_commentmeta',
			'action_scheduler',
		];
	}

	/**
	 * Aantal rijen per DELETE. Filter: mcm_optimizer_delete_batch_size.
	 */
	protected static function batch_size() {
		$size = (int) apply_filters( 'mcm_optimizer_delete_batch_size', 500 );
		return max( 50, min( 2000, $size ) );
	}

	/**
	 * Tijdstip waarop een opschoning stopt en 'more' teruggeeft. Ruim binnen
	 * de timeout van PHP en van een reverse proxy (Varnish).
	 * Filter: mcm_optimizer_clean_time_budget (seconden).
	 */
	protected static function deadline() {
		$budget = (float) apply_filters( 'mcm_optimizer_clean_time_budget', 15 );
		return microtime( true ) + max( 2, $budget );
	}

	/**
	 * Verwijder rijen op primaire sleutel, in batches. Elke batch is een eigen
	 * kort statement met een korte pauze erna, zodat andere verzoeken (een
	 * checkout) tussendoor aan de beurt komen.
	 */
	protected static function delete_by_ids( $table, $pk, array $ids ) {
		global $wpdb;

		$ids     = array_values( array_filter( array_map( 'absint', $ids ) ) );
		$deleted = 0;

		foreach ( array_chunk( $ids, self::batch_size() ) as $chunk ) {
			$deleted += (int) $wpdb->query(
				"DELETE FROM {$table} WHERE {$pk} IN (" . implode( ',', $chunk ) . ')'
			);
			usleep( 50000 );
		}

		return $deleted;
	}

	/**
	 * Post types van WooCommerce-bestellingen. Die slaat de prullenbak-opschoning
	 * over: bestellingen vallen onder de fiscale bewaarplicht, en met HPOS zijn
	 * de rijen in posts placeholders of spiegels van wc_orders — rechtstreeks
	 * verwijderen laat die twee uit de pas lopen. WooCommerce beheert ze zelf.
	 */
	public static function order_post_types() {
		$types = [ 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription' ];

		if ( function_exists( 'wc_get_order_types' ) ) {
			$types = array_merge( $types, (array) wc_get_order_types() );
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );
	}

	/**
	 * Meta-keys die bewust dezelfde waarde vaker op één post hebben en dus
	 * nooit als "dubbel" gelden. WooCommerce slaat per couponsgebruik een rij
	 * `_used_by` op (user-ID of e-mail); weghalen verlaagt de teller en breekt
	 * de limiet per klant. Filter: mcm_optimizer_duplicate_postmeta_skip_keys.
	 */
	public static function duplicate_postmeta_skip_keys() {
		$keys = (array) apply_filters( 'mcm_optimizer_duplicate_postmeta_skip_keys', [ '_used_by' ] );
		return array_values( array_unique( array_filter( array_map( 'strval', $keys ), 'strlen' ) ) );
	}

	/**
	 * SQL-voorwaarde "deze meta_key wordt overgeslagen". Gedeeld door de scan
	 * en de opschoning, zodat de telling en het resultaat altijd overeenkomen.
	 */
	public static function duplicate_postmeta_skip_sql() {
		global $wpdb;

		$keys = self::duplicate_postmeta_skip_keys();
		if ( empty( $keys ) ) {
			return '0';
		}

		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		return $wpdb->prepare( "meta_key IN ({$placeholders})", $keys );
	}

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
	 * Verwijder auto-drafts ouder dan AUTO_DRAFT_DAYS — dezelfde regel als
	 * WordPress zelf (wp_delete_auto_drafts). Een jongere auto-draft kan van
	 * iemand zijn die op dit moment een nieuwe pagina aanmaakt.
	 */
	public static function clean_auto_drafts( $vanaf = 0 ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'auto-draft'
				 AND DATE_SUB( NOW(), INTERVAL %d DAY ) > post_date
				 AND ID > %d
				 ORDER BY ID
				 LIMIT %d",
				self::AUTO_DRAFT_DAYS,
				absint( $vanaf ),
				self::batch_size()
			)
		);

		return self::delete_posts( $ids );
	}

	/**
	 * Verwijder posts permanent via wp_delete_post(), zoals WordPress' eigen
	 * "Definitief verwijderen": met meta, reacties, termkoppelingen en revisies,
	 * en met de hooks waarmee plugins (WooCommerce) hun eigen tabellen
	 * opruimen. Stopt na het tijdsbudget.
	 *
	 * @param array $ids Oplopend gesorteerd, hooguit batch_size() stuks.
	 */
	protected static function delete_posts( array $ids ) {
		$deadline = self::deadline();
		$deleted  = 0;
		$vanaf    = 0;
		$more     = count( $ids ) >= self::batch_size();

		foreach ( $ids as $id ) {
			$vanaf = (int) $id;
			if ( wp_delete_post( $vanaf, true ) ) {
				$deleted++;
			}
			if ( microtime( true ) >= $deadline ) {
				$more = true;
				break;
			}
		}

		return [
			'deleted' => $deleted,
			'more'    => $more,
			'vanaf'   => $vanaf,
		];
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
	 * Leeg de prullenbak (berichten, pagina's, producten, ...).
	 *
	 * Alleen per stuk, nooit via "Alles Opschonen": de prullenbak is de
	 * 'ongedaan maken' van WordPress. Via wp_delete_post(), zodat ook reacties,
	 * termkoppelingen en revisies meegaan — voorheen bleven die als wezen
	 * achter. Bestellingen worden overgeslagen, zie order_post_types().
	 */
	public static function clean_trashed_posts( $vanaf = 0 ) {
		global $wpdb;

		$skip         = self::order_post_types();
		$placeholders = implode( ',', array_fill( 0, count( $skip ), '%s' ) );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'trash'
				 AND post_type NOT IN ({$placeholders})
				 AND ID > %d
				 ORDER BY ID
				 LIMIT %d",
				array_merge( $skip, [ absint( $vanaf ), self::batch_size() ] )
			)
		);

		return self::delete_posts( $ids );
	}

	/**
	 * Verwijder orphaned postmeta: eerst de meta_id's zoeken (een lezende
	 * query, zonder locks), dan op primaire sleutel verwijderen in batches.
	 */
	public static function clean_orphaned_postmeta( $vanaf = 0 ) {
		global $wpdb;

		return self::delete_orphans(
			"SELECT pm.meta_id FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.ID IS NULL AND pm.meta_id > %d
			 ORDER BY pm.meta_id
			 LIMIT %d",
			$wpdb->postmeta,
			'meta_id',
			$vanaf
		);
	}

	/**
	 * Verwijder orphaned commentmeta, op dezelfde manier als orphaned postmeta.
	 */
	public static function clean_orphaned_commentmeta( $vanaf = 0 ) {
		global $wpdb;

		return self::delete_orphans(
			"SELECT cm.meta_id FROM {$wpdb->commentmeta} cm
			 LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
			 WHERE c.comment_ID IS NULL AND cm.meta_id > %d
			 ORDER BY cm.meta_id
			 LIMIT %d",
			$wpdb->commentmeta,
			'meta_id',
			$vanaf
		);
	}

	/**
	 * Loop met een cursor door de wezen: batch zoeken (vanaf de laatst
	 * bekeken ID), batch verwijderen op primaire sleutel, tot er niets meer is
	 * of het tijdsbudget op is.
	 *
	 * @param string $select_sql Query met %d voor de cursor en %d voor de limiet.
	 */
	protected static function delete_orphans( $select_sql, $table, $pk, $vanaf ) {
		global $wpdb;

		$deadline = self::deadline();
		$batch    = self::batch_size();
		$cursor   = absint( $vanaf );
		$deleted  = 0;
		$more     = false;

		while ( true ) {
			$ids = $wpdb->get_col( $wpdb->prepare( $select_sql, $cursor, $batch ) );
			if ( empty( $ids ) ) {
				break;
			}

			$cursor   = (int) end( $ids );
			$deleted += self::delete_by_ids( $table, $pk, $ids );

			if ( count( $ids ) < $batch ) {
				break;
			}
			if ( microtime( true ) >= $deadline ) {
				$more = true;
				break;
			}
		}

		return [
			'deleted' => $deleted,
			'more'    => $more,
			'vanaf'   => $cursor,
		];
	}

	/**
	 * Verwijder dubbele postmeta: rijen met exact dezelfde post_id, meta_key
	 * en meta_value. De oudste rij (laagste meta_id) blijft staan.
	 *
	 * Alleen per stuk. Meta-keys uit duplicate_postmeta_skip_keys() blijven
	 * altijd staan (WooCommerce `_used_by`). Van wat weggaat wordt eerst een
	 * terugzetbare backup gemaakt — geen backup, geen delete.
	 *
	 * Voorheen was dit één self-join-DELETE over de hele postmeta (1,7 miljoen
	 * rijen op een grote shop): minutenlang locks. Nu: groepen zoeken via
	 * MD5 (lezend), per groep exact nagaan met een binaire vergelijking — de
	 * gewone = negeert hoofdletters en spaties aan het eind — en verwijderen
	 * op meta_id in batches.
	 */
	public static function clean_duplicate_postmeta() {
		global $wpdb;

		$deadline   = self::deadline();
		$max_groups = 2000;
		$skip_sql   = self::duplicate_postmeta_skip_sql();

		$keep_ids = $wpdb->get_col(
			"SELECT MIN(meta_id) FROM {$wpdb->postmeta}
			 WHERE meta_key IS NOT NULL
			 AND meta_value IS NOT NULL
			 AND NOT ( {$skip_sql} )
			 GROUP BY post_id, meta_key, MD5(meta_value)
			 HAVING COUNT(*) > 1
			 LIMIT {$max_groups}"
		);

		if ( empty( $keep_ids ) ) {
			return [ 'deleted' => 0 ];
		}

		$more = count( $keep_ids ) >= $max_groups;
		$rows = [];

		foreach ( $keep_ids as $keep_id ) {
			$dupes = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value
					 FROM {$wpdb->postmeta} k
					 INNER JOIN {$wpdb->postmeta} pm
					   ON pm.post_id = k.post_id AND pm.meta_key = k.meta_key
					 WHERE k.meta_id = %d
					 AND pm.meta_id > k.meta_id
					 AND CAST(pm.meta_value AS BINARY) = CAST(k.meta_value AS BINARY)",
					$keep_id
				),
				ARRAY_A
			);

			foreach ( $dupes as $d ) {
				$rows[] = $d;
			}

			if ( microtime( true ) >= $deadline ) {
				$more = true;
				break;
			}
		}

		if ( empty( $rows ) ) {
			return [ 'deleted' => 0 ];
		}

		$backup = self::backup_rows( $rows, 'duplicate-postmeta', $wpdb->postmeta );
		if ( empty( $backup['ok'] ) ) {
			return [
				'deleted' => 0,
				'error'   => 'Backup mislukt — er is niets verwijderd. (' . ( $backup['error'] ?? 'onbekend' ) . ')',
			];
		}

		return [
			'deleted' => self::delete_by_ids( $wpdb->postmeta, 'meta_id', wp_list_pluck( $rows, 'meta_id' ) ),
			'more'    => $more,
			'backup'  => $backup['file'] ?? '',
		];
	}

	/**
	 * Verwijder Action Scheduler voltooide taken ouder dan X dagen: eerst de
	 * action_id's zoeken, dan logs en acties op action_id verwijderen in
	 * batches. Op een drukke shop staan hier honderdduizenden rijen.
	 */
	public static function clean_action_scheduler( $days = 30, $vanaf = 0 ) {
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

		$log_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s",
				DB_NAME,
				$log_table
			)
		);

		$cutoff   = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$deadline = self::deadline();
		$batch    = self::batch_size();
		$cursor   = absint( $vanaf );
		$deleted  = 0;
		$more     = false;

		while ( true ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT action_id FROM {$table}
					 WHERE status IN ('complete', 'failed', 'canceled')
					 AND last_attempt_gmt < %s
					 AND action_id > %d
					 ORDER BY action_id
					 LIMIT %d",
					$cutoff,
					$cursor,
					$batch
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			$cursor = (int) end( $ids );

			// Eerst de logs, dan de acties zelf.
			if ( $log_exists ) {
				self::delete_by_ids( $log_table, 'action_id', $ids );
			}
			$deleted += self::delete_by_ids( $table, 'action_id', $ids );

			if ( count( $ids ) < $batch ) {
				break;
			}
			if ( microtime( true ) >= $deadline ) {
				$more = true;
				break;
			}
		}

		return [
			'deleted' => $deleted,
			'more'    => $more,
			'vanaf'   => $cursor,
		];
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
		$backup = self::backup_rows( $rows, 'orphaned-plugin-options', $wpdb->options );
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
	 * Schrijf een set rijen (opties, postmeta) naar een terugzetbaar JSON-bestand
	 * in een beschermde map onder uploads. Retourneert ['ok'=>bool, 'file'=>relpad, ...].
	 * Een leeg of half geschreven bestand telt als mislukt.
	 */
	protected static function backup_rows( array $rows, $slug, $table ) {
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

		// Uniek: een vervolgronde binnen dezelfde seconde overschrijft anders de vorige backup.
		$file    = $dir . '/' . wp_unique_filename( $dir, sanitize_file_name( $slug ) . '-' . gmdate( 'Ymd-His' ) . '.json' );
		$payload = wp_json_encode(
			[
				'created' => current_time( 'mysql' ),
				'table'   => $table,
				'note'    => 'MCM Site Optimizer — terugzetbare backup van verwijderde rijen.',
				'rows'    => $rows,
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( false === $payload || '' === $payload ) {
			return [ 'ok' => false, 'error' => 'Backup kon niet als JSON worden opgebouwd.' ];
		}

		$written = @file_put_contents( $file, $payload );
		if ( false === $written || $written !== strlen( $payload ) ) {
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
	 *
	 * @param bool $vervolg Vervolgronde van een opschoning in delen: dan wordt
	 *                      de vorige regel van dezelfde module bijgewerkt in
	 *                      plaats van een nieuwe regel per ronde.
	 */
	public static function log_action( $module, $result, $vervolg = false ) {
		$log   = get_option( 'mcm_optimizer_log', [] );
		$entry = [
			'time'    => current_time( 'mysql' ),
			'module'  => $module,
			'result'  => $result,
			'user'    => get_current_user_id(),
		];

		$last = empty( $log ) ? null : $log[ count( $log ) - 1 ];
		if ( $vervolg && $last
			&& ( $last['module'] ?? '' ) === $module
			&& (int) ( $last['user'] ?? 0 ) === $entry['user']
			&& ! empty( $last['result']['more'] ) ) {
			$log[ count( $log ) - 1 ] = $entry;
		} else {
			$log[] = $entry;
		}

		// Bewaar maximaal 100 log entries.
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}

		update_option( 'mcm_optimizer_log', $log );
	}
}

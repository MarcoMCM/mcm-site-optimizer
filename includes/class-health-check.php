<?php
/**
 * Health Check: controleert of de site nog correct draait na optimalisatie.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Health_Check {

	/**
	 * Sla een snapshot op van de huidige staat (vóór optimalisatie).
	 */
	public static function save_pre_snapshot() {
		global $wpdb;

		$snapshot = [
			'time'         => current_time( 'mysql' ),
			'db_size'      => MCM_Scanner::get_database_size(),
			'homepage_ok'  => self::check_frontend(),
			'admin_ok'     => true, // We zijn al in admin, dus dat werkt.
			'db_ok'        => self::check_database(),
		];

		// Voeg WooCommerce check toe als het actief is.
		if ( class_exists( 'WooCommerce' ) ) {
			$snapshot['woo_ok'] = self::check_woocommerce();
		}

		update_option( 'mcm_optimizer_pre_snapshot', $snapshot );

		return $snapshot;
	}

	/**
	 * Voer alle health checks uit (ná optimalisatie).
	 */
	public static function run_post_checks() {
		$pre_snapshot = get_option( 'mcm_optimizer_pre_snapshot', [] );

		$results = [
			'time'       => current_time( 'mysql' ),
			'checks'     => [],
			'all_passed' => true,
		];

		// 1. Frontend check.
		$frontend = self::check_frontend();
		$results['checks']['frontend'] = [
			'label'  => 'Frontend (homepage)',
			'passed' => $frontend['ok'],
			'detail' => $frontend['message'],
		];
		if ( ! $frontend['ok'] ) {
			$results['all_passed'] = false;
		}

		// 2. Admin/AJAX check.
		$admin = self::check_admin_ajax();
		$results['checks']['admin'] = [
			'label'  => 'Admin (AJAX)',
			'passed' => $admin['ok'],
			'detail' => $admin['message'],
		];
		if ( ! $admin['ok'] ) {
			$results['all_passed'] = false;
		}

		// 3. Database check.
		$db = self::check_database();
		$results['checks']['database'] = [
			'label'  => 'Database',
			'passed' => $db['ok'],
			'detail' => $db['message'],
		];
		if ( ! $db['ok'] ) {
			$results['all_passed'] = false;
		}

		// 3b. Autoload-omvang. Autoloaded options worden bij ELKE pageload
		//     ingelezen; loopt dat op, dan betaalt elke bezoeker mee.
		$autoload = self::check_autoload();
		$results['checks']['autoload'] = [
			'label'  => 'Autoload-omvang',
			'passed' => $autoload['ok'],
			'detail' => $autoload['message'],
		];
		if ( ! $autoload['ok'] ) {
			$results['all_passed'] = false;
		}

		// 3c. Cron. Een vastgelopen of dubbel ingeplande taak vreet stilletjes
		//     capaciteit; dat zie je nergens terug behalve in de cron-array.
		$cron = self::check_cron();
		$results['checks']['cron'] = [
			'label'  => 'Cron-taken',
			'passed' => $cron['ok'],
			'detail' => $cron['message'],
		];
		if ( ! $cron['ok'] ) {
			$results['all_passed'] = false;
		}

		// 4. WooCommerce check (als beschikbaar).
		if ( class_exists( 'WooCommerce' ) ) {
			$woo = self::check_woocommerce();
			$results['checks']['woocommerce'] = [
				'label'  => 'WooCommerce',
				'passed' => $woo['ok'],
				'detail' => $woo['message'],
			];
			if ( ! $woo['ok'] ) {
				$results['all_passed'] = false;
			}
		}

		// 5. Vergelijking met pre-snapshot.
		if ( ! empty( $pre_snapshot ) ) {
			$post_db = MCM_Scanner::get_database_size();
			$saved   = round( ( $pre_snapshot['db_size']['size_mb'] ?? 0 ) - $post_db['size_mb'], 2 );

			$results['comparison'] = [
				'db_before' => $pre_snapshot['db_size']['size_mb'] ?? 0,
				'db_after'  => $post_db['size_mb'],
				'db_saved'  => $saved,
			];
		}

		// Sla resultaat op.
		update_option( 'mcm_optimizer_post_check', $results );

		return $results;
	}

	/**
	 * Check of de frontend bereikbaar is.
	 */
	public static function check_frontend() {
		$home_url = home_url( '/' );

		$response = wp_remote_get( $home_url, [
			'timeout'   => 15,
			'sslverify' => false,
			'headers'   => [
				'Cache-Control' => 'no-cache',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'message' => 'Frontend onbereikbaar: ' . $response->get_error_message(),
			];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			return [
				'ok'      => false,
				'message' => sprintf( 'Frontend gaf HTTP %d terug (verwacht 200).', $code ),
			];
		}

		// Check of er daadwerkelijk HTML in zit.
		if ( stripos( $body, '</html>' ) === false && stripos( $body, '</body>' ) === false ) {
			return [
				'ok'      => false,
				'message' => 'Frontend geeft geen geldige HTML terug.',
			];
		}

		// Check op PHP errors in de output.
		if ( preg_match( '/(Fatal error|Parse error|Warning:|Notice:)/i', $body ) ) {
			return [
				'ok'      => false,
				'message' => 'Frontend bevat PHP foutmeldingen.',
			];
		}

		return [
			'ok'      => true,
			'message' => sprintf( 'Homepage OK (HTTP %d).', $code ),
		];
	}

	/**
	 * Check of admin-ajax.php bereikbaar is.
	 */
	public static function check_admin_ajax() {
		$ajax_url = admin_url( 'admin-ajax.php' );

		$response = wp_remote_post( $ajax_url, [
			'timeout'   => 10,
			'sslverify' => false,
			'body'      => [
				'action' => 'mcm_optimizer_health_ping',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'message' => 'Admin AJAX onbereikbaar: ' . $response->get_error_message(),
			];
		}

		$code = wp_remote_retrieve_response_code( $response );

		// admin-ajax.php geeft 400 terug bij een onbekende action, maar dat is OK.
		// Het betekent dat PHP + WordPress draait.
		if ( $code === 200 || $code === 400 ) {
			return [
				'ok'      => true,
				'message' => 'Admin AJAX bereikbaar.',
			];
		}

		return [
			'ok'      => false,
			'message' => sprintf( 'Admin AJAX gaf HTTP %d terug.', $code ),
		];
	}

	/**
	 * Check of de database bereikbaar is.
	 */
	public static function check_database() {
		global $wpdb;

		$result = $wpdb->get_var( "SELECT 1" );

		if ( $result === null ) {
			return [
				'ok'      => false,
				'message' => 'Database query mislukt.',
			];
		}

		// Extra check: kunnen we options lezen?
		$siteurl = $wpdb->get_var(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl' LIMIT 1"
		);

		if ( empty( $siteurl ) ) {
			return [
				'ok'      => false,
				'message' => 'Database bereikbaar maar core options niet leesbaar.',
			];
		}

		return [
			'ok'      => true,
			'message' => 'Database OK.',
		];
	}

	/**
	 * Drempel voor autoloaded options. WordPress' eigen Site Health slaat rond
	 * 800 KB alarm; wij houden 1 MB aan als "nog acceptabel".
	 */
	public static function max_autoload_bytes() {
		return (int) apply_filters( 'mcm_optimizer_max_autoload_bytes', 1024 * 1024 );
	}

	/**
	 * Autoload-omvang meten. Aanleiding: op een klantsite stond 1,58 MB aan
	 * opties van een allang verwijderde security-plugin nog op autoload — die
	 * werd dus bij elke pageload ingelezen zonder dat iets ze nog gebruikte.
	 */
	public static function check_autoload() {
		global $wpdb;

		$bytes = (int) $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options}
			 WHERE autoload IN ('yes','on','auto','auto-on')"
		);

		$max = self::max_autoload_bytes();

		if ( $bytes > $max ) {
			$grootste = $wpdb->get_results(
				"SELECT option_name, LENGTH(option_value) AS len FROM {$wpdb->options}
				 WHERE autoload IN ('yes','on','auto','auto-on')
				 ORDER BY len DESC LIMIT 3"
			);
			$namen = [];
			foreach ( (array) $grootste as $g ) {
				$namen[] = $g->option_name . ' (' . size_format( (int) $g->len ) . ')';
			}

			return [
				'ok'      => false,
				'message' => sprintf(
					'Autoload is %s (drempel %s). Grootste: %s.',
					size_format( $bytes ),
					size_format( $max ),
					implode( ', ', $namen )
				),
			];
		}

		return [
			'ok'      => true,
			'message' => sprintf( 'Autoload is %s. OK.', size_format( $bytes ) ),
		];
	}

	/**
	 * Cron-taken controleren op drie signalen:
	 *  1. taken die ver over tijd zijn  → cron draait niet
	 *  2. dezelfde hook meerdere keren ingepland → dubbele planning
	 *  3. buitensporig veel taken → opeenstapeling
	 *
	 * Aanleiding: een beeldoptimalisatie-plugin die maandenlang elke twee
	 * minuten dezelfde (niet-bestaande) afbeelding "optimaliseerde".
	 */
	public static function check_cron() {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return [ 'ok' => true, 'message' => 'Geen cron-array gevonden.' ];
		}

		$nu        = time();
		$te_laat   = [];
		$per_hook  = [];
		$totaal    = 0;
		$marge     = (int) apply_filters( 'mcm_optimizer_cron_late_seconds', HOUR_IN_SECONDS );

		foreach ( $crons as $ts => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				$aantal            = is_array( $events ) ? count( $events ) : 1;
				$totaal           += $aantal;
				$per_hook[ $hook ] = ( $per_hook[ $hook ] ?? 0 ) + $aantal;

				if ( $ts < ( $nu - $marge ) ) {
					$te_laat[ $hook ] = $nu - (int) $ts;
				}
			}
		}

		$problemen = [];

		if ( ! empty( $te_laat ) ) {
			arsort( $te_laat );
			$eerste = array_key_first( $te_laat );
			$problemen[] = sprintf(
				'%d taak/taken over tijd, langst: %s (%s te laat)',
				count( $te_laat ),
				$eerste,
				human_time_diff( $nu - $te_laat[ $eerste ], $nu )
			);
		}

		$dubbel = array_filter( $per_hook, function ( $n ) { return $n > 1; } );
		if ( ! empty( $dubbel ) ) {
			arsort( $dubbel );
			$namen = [];
			foreach ( array_slice( $dubbel, 0, 3, true ) as $h => $n ) {
				$namen[] = $h . ' (' . $n . 'x)';
			}
			$problemen[] = 'dubbel ingepland: ' . implode( ', ', $namen );
		}

		$max_taken = (int) apply_filters( 'mcm_optimizer_max_cron_events', 150 );
		if ( $totaal > $max_taken ) {
			$problemen[] = sprintf( '%d taken in totaal (drempel %d)', $totaal, $max_taken );
		}

		if ( ! empty( $problemen ) ) {
			return [
				'ok'      => false,
				'message' => ucfirst( implode( '; ', $problemen ) ) . '.',
			];
		}

		return [
			'ok'      => true,
			'message' => sprintf( '%d cron-taken, niets over tijd. OK.', $totaal ),
		];
	}

	/**
	 * Check WooCommerce pagina's.
	 */
	public static function check_woocommerce() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [
				'ok'      => true,
				'message' => 'WooCommerce niet actief (overgeslagen).',
			];
		}

		$shop_page_id = wc_get_page_id( 'shop' );
		if ( $shop_page_id <= 0 ) {
			return [
				'ok'      => false,
				'message' => 'WooCommerce shop pagina niet geconfigureerd.',
			];
		}

		$shop_url = get_permalink( $shop_page_id );
		$response = wp_remote_get( $shop_url, [
			'timeout'   => 15,
			'sslverify' => false,
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'message' => 'Shop pagina onbereikbaar: ' . $response->get_error_message(),
			];
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code !== 200 ) {
			return [
				'ok'      => false,
				'message' => sprintf( 'Shop pagina gaf HTTP %d terug.', $code ),
			];
		}

		return [
			'ok'      => true,
			'message' => 'WooCommerce shop pagina OK.',
		];
	}
}

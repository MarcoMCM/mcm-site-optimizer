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
			// Een aandachtspunt, geen storing: de site draait gewoon. Met een
			// knop om het op te lossen (zie ajax_cron_dubbel in de admin-pagina).
			'warn'   => ! empty( $cron['warn'] ),
			'actie'  => $cron['actie'] ?? null,
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
	 *  2. dezelfde taak meerdere keren ingepland → dubbele planning
	 *  3. buitensporig veel taken → opeenstapeling
	 *
	 * Aanleiding: een beeldoptimalisatie-plugin die maandenlang elke twee
	 * minuten dezelfde (niet-bestaande) afbeelding "optimaliseerde".
	 *
	 * Een dubbele planning is een aandachtspunt ('warn'), geen storing: de
	 * site draait gewoon. De admin-pagina toont er een knop bij die het
	 * opruimt (cron_dubbel_opruimen()).
	 */
	public static function check_cron() {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return [ 'ok' => true, 'message' => 'Geen cron-array gevonden.' ];
		}

		$nu        = time();
		$te_laat   = [];
		$totaal    = 0;
		$marge     = (int) apply_filters( 'mcm_optimizer_cron_late_seconds', HOUR_IN_SECONDS );

		foreach ( $crons as $ts => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				$totaal += is_array( $events ) ? count( $events ) : 1;

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

		$max_taken = (int) apply_filters( 'mcm_optimizer_max_cron_events', 150 );
		if ( $totaal > $max_taken ) {
			$problemen[] = sprintf( '%d taken in totaal (drempel %d)', $totaal, $max_taken );
		}

		$dubbel = self::cron_dubbel();
		$actie  = $dubbel ? [ 'type' => 'cron_dubbel', 'label' => 'Ruim op' ] : null;

		if ( ! empty( $problemen ) ) {
			return [
				'ok'      => false,
				'message' => ucfirst( implode( '; ', $problemen ) ) . '.'
					. ( $dubbel ? ' ' . self::cron_dubbel_tekst( $dubbel ) : '' ),
				'actie'   => $actie,
			];
		}

		if ( $dubbel ) {
			return [
				'ok'      => true,
				'warn'    => true,
				'message' => self::cron_dubbel_tekst( $dubbel ),
				'actie'   => $actie,
			];
		}

		return [
			'ok'      => true,
			'message' => sprintf( '%d cron-taken, niets over tijd. OK.', $totaal ),
		];
	}

	/**
	 * Herhalende cron-taken die meer dan één keer zijn ingepland, met precies
	 * dezelfde argumenten en hetzelfde interval.
	 *
	 * wp_schedule_event() controleert dat zelf niet; een plugin moet eerst
	 * wp_next_scheduled() vragen. Gaat dat een keer mis (oude versie, twee
	 * verzoeken tegelijk), dan blijft de taak voortaan te vaak draaien: elke
	 * kopie plant zichzelf na afloop opnieuw in. Gezien op powair.nl: de
	 * dagelijkse plugin- en themacheck van MainWP Child stonden er elk twee
	 * keer in, twee minuten na elkaar.
	 *
	 * Telt bewust niet mee:
	 * - dezelfde hook met andere argumenten: dat zijn verschillende taken;
	 * - eenmalige taken op verschillende tijden: die kunnen zo bedoeld zijn.
	 *
	 * @return array<int,array{hook:string,args:array,schedule:string,tijden:int[]}>
	 */
	public static function cron_dubbel() {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return [];
		}

		$groepen = [];
		foreach ( $crons as $ts => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				// De sleutel is md5(serialize(args)): gelijk = dezelfde argumenten.
				foreach ( $events as $sleutel => $event ) {
					if ( empty( $event['schedule'] ) ) {
						continue;
					}
					$id = $hook . '|' . $sleutel . '|' . $event['schedule'];
					if ( ! isset( $groepen[ $id ] ) ) {
						$groepen[ $id ] = [
							'hook'     => (string) $hook,
							'args'     => (array) ( $event['args'] ?? [] ),
							'schedule' => (string) $event['schedule'],
							'tijden'   => [],
						];
					}
					$groepen[ $id ]['tijden'][] = (int) $ts;
				}
			}
		}

		$dubbel = [];
		foreach ( $groepen as $groep ) {
			if ( count( $groep['tijden'] ) > 1 ) {
				sort( $groep['tijden'] );
				$dubbel[] = $groep;
			}
		}

		return $dubbel;
	}

	/**
	 * Haal de extra kopieën weg. Per taak blijft de eerstvolgende staan, dus
	 * de taak zelf blijft gewoon draaien, alleen niet meer dubbel.
	 *
	 * @return array{deleted:int,hooks:string[]}
	 */
	public static function cron_dubbel_opruimen() {
		$weg   = 0;
		$hooks = [];

		foreach ( self::cron_dubbel() as $groep ) {
			foreach ( array_slice( $groep['tijden'], 1 ) as $ts ) {
				if ( true === wp_unschedule_event( $ts, $groep['hook'], $groep['args'] ) ) {
					$weg++;
					$hooks[ $groep['hook'] ] = true;
				}
			}
		}

		return [
			'deleted' => $weg,
			'hooks'   => array_keys( $hooks ),
		];
	}

	/**
	 * Uitleg bij dubbel ingeplande taken: welke, van welke plugin, wat het
	 * gevolg is en wat je eraan doet.
	 */
	protected static function cron_dubbel_tekst( array $dubbel ) {
		$bronnen = [];
		foreach ( $dubbel as $groep ) {
			$bron = self::hook_bron( $groep['hook'] );
			if ( '' !== $bron ) {
				$bronnen[ $bron ] = true;
			}
		}
		$van = $bronnen ? ' van ' . implode( ' en ', array_keys( $bronnen ) ) : '';

		if ( 1 === count( $dubbel ) ) {
			$groep  = $dubbel[0];
			$n      = count( $groep['tijden'] );
			$per    = [ 'hourly' => 'uur', 'daily' => 'dag', 'weekly' => 'week' ];
			$gevolg = isset( $per[ $groep['schedule'] ] )
				? sprintf( '%d× per %s in plaats van 1×', $n, $per[ $groep['schedule'] ] )
				: sprintf( '%d× zo vaak als bedoeld', $n );

			return sprintf(
				'De taak %s%s staat %d× ingepland en draait daardoor %s. Onschuldig, maar onnodig. Met "Ruim op" blijft er één over.',
				$groep['hook'],
				$van,
				$n,
				$gevolg
			);
		}

		return sprintf(
			'%d taken%s staan dubbel ingepland (%s) en draaien daardoor vaker dan bedoeld. Onschuldig, maar onnodig. Met "Ruim op" blijft er van elke taak één over.',
			count( $dubbel ),
			$van,
			implode( ', ', array_map( function ( $groep ) {
				return $groep['hook'] . ' ' . count( $groep['tijden'] ) . '×';
			}, $dubbel ) )
		);
	}

	/**
	 * Van welke plugin is deze hook? Eerst via het bestand van de callbacks
	 * die er nu aan hangen. Veel plugins hangen hun cron-callbacks alleen op
	 * tijdens een cron-run (MainWP Child bijvoorbeeld); dan valt hij terug op
	 * de naam: begint de hook met de mapnaam van een geïnstalleerde plugin?
	 * Leeg als het niet te zeggen is.
	 */
	protected static function hook_bron( $hook ) {
		global $wp_filter;

		if ( ! empty( $wp_filter[ $hook ] ) && $wp_filter[ $hook ] instanceof WP_Hook ) {
			$bron = self::hook_bron_uit_callbacks( $wp_filter[ $hook ] );
			if ( '' !== $bron ) {
				return $bron;
			}
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$beste = '';
		foreach ( array_keys( get_plugins() ) as $bestand ) {
			$map = dirname( $bestand );
			if ( '.' === $map ) {
				continue;
			}
			// mainwp-child → mainwp_child; te korte namen ("wp") matchen alles.
			$prefix = str_replace( '-', '_', strtolower( $map ) );
			if ( strlen( $prefix ) < 4 || strlen( $prefix ) <= strlen( $beste ) ) {
				continue;
			}
			if ( $hook === $prefix || 0 === strpos( $hook, $prefix . '_' ) ) {
				$beste = $map;
			}
		}

		return '' !== $beste ? self::plugin_naam( $beste ) : '';
	}

	/**
	 * Plugin-naam via het bestand waarin een callback van deze hook staat.
	 */
	protected static function hook_bron_uit_callbacks( WP_Hook $wp_hook ) {
		$plugin_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );

		foreach ( $wp_hook->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$functie = $callback['function'] ?? null;
				try {
					if ( is_array( $functie ) ) {
						$ref = new ReflectionMethod( $functie[0], $functie[1] );
					} elseif ( is_string( $functie ) && false !== strpos( $functie, '::' ) ) {
						$ref = new ReflectionMethod( $functie );
					} else {
						$ref = new ReflectionFunction( $functie );
					}
				} catch ( Throwable $e ) {
					continue;
				}

				$bestand = wp_normalize_path( (string) $ref->getFileName() );
				if ( 0 !== strpos( $bestand, $plugin_dir ) ) {
					continue;
				}

				$map = strtok( substr( $bestand, strlen( $plugin_dir ) ), '/' );
				return self::plugin_naam( $map );
			}
		}

		return '';
	}

	/**
	 * Leesbare naam van de plugin in deze map, anders de mapnaam.
	 */
	protected static function plugin_naam( $map ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins( '/' . $map ) as $data ) {
			if ( ! empty( $data['Name'] ) ) {
				return $data['Name'];
			}
		}

		return $map;
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

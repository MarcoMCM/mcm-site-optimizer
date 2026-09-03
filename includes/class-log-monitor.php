<?php
/**
 * MCM Log-monitor.
 *
 * Bewaakt wp-content/debug.log (en signaleert andere grote *.log-bestanden).
 * Wordt debug.log te groot, dan wordt het weggerotateerd naar een beschermde
 * archiefmap op de site zelf en krijgt Marco een mail. Het draaiende log begint
 * daarna weer schoon; het archief blijft op de site staan tot het handmatig naar
 * de Hetzner-schijf (werk/<klant>/logs/) gehaald wordt.
 *
 * BEWUST: de plugin heeft GEEN toegang tot de Hetzner Storagebox en krijgt die
 * ook niet — Storagebox-credentials op klant-sites zetten = veiligheidsrisico.
 * De kopie naar Hetzner gebeurt vanaf Marco's Mac ná de mail (handmatig).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Log_Monitor {

	/** Cron-hook voor de dagelijkse check. */
	const CRON_HOOK = 'mcm_log_monitor_check';

	/** Optie waarin we per pad de laatst-gemelde grootte bewaren (anti-spam). */
	const STATE_OPT = 'mcm_log_monitor_state';

	/** Ontvanger van de meldingen. */
	const ALERT_EMAIL = 'marco@mcmwebsites.nl';

	/**
	 * Init: hook registreren + dagelijkse cron plannen.
	 *
	 * De cron wordt hier gepland (niet in de activatie-hook), zodat het ook
	 * na een PUC-update vanzelf goed komt — de activatie-hook draait daar niet.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_check' ] );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Drempel in bytes waarboven een log "te groot" is. Default 50 MB,
	 * aan te passen via de filter 'mcm_log_monitor_max_bytes'.
	 */
	public static function max_bytes() {
		return (int) apply_filters( 'mcm_log_monitor_max_bytes', 50 * 1024 * 1024 );
	}

	/**
	 * Drempel voor een hele logMAP. Een map kan ver over de limiet gaan zonder
	 * dat één bestand groot is: op stadsfondshilversum.nl stonden 5.885 losse
	 * logjes van ~15 KB (samen 97 MB) in wp-content/wpvividbackups/wpvivid_log/.
	 * Per-bestand toetsen ziet daar niets van.
	 */
	public static function max_dir_bytes() {
		return (int) apply_filters( 'mcm_log_monitor_max_dir_bytes', 50 * 1024 * 1024 );
	}

	/**
	 * Drempel voor het AANTAL bestanden in een logmap. Duizenden kleine logjes
	 * zijn ook zonder omvang een signaal: meestal een plugin die per run een
	 * nieuw bestand wegschrijft en nooit opruimt.
	 */
	public static function max_dir_files() {
		return (int) apply_filters( 'mcm_log_monitor_max_dir_files', 1000 );
	}

	/** Beschermde archiefmap op de site (binnen uploads). */
	private static function archive_dir() {
		$up = wp_upload_dir();
		return untrailingslashit( $up['basedir'] ) . '/mcm-log-archives';
	}

	/**
	 * Zorg dat de archiefmap bestaat en web-afgeschermd is.
	 * Retourneert het pad, of false als de map niet schrijfbaar is.
	 */
	private static function ensure_archive_dir() {
		$dir = self::archive_dir();

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return false;
		}

		// Web-toegang blokkeren (Apache) + directory listing voorkomen. Op nginx
		// werkt .htaccess niet — daarom krijgen de archieven ook een onraadbaar
		// token in de bestandsnaam (zie rotate()).
		$ht = $dir . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			@file_put_contents( $ht, "Require all denied\nDeny from all\n" );
		}
		$idx = $dir . '/index.php';
		if ( ! file_exists( $idx ) ) {
			@file_put_contents( $idx, "<?php // Silence is golden.\n" );
		}

		return $dir;
	}

	/**
	 * Dagelijkse check: debug.log rotaten + andere grote logs signaleren.
	 */
	public static function run_check() {
		$max   = self::max_bytes();
		$lines = [];
		$debug = untrailingslashit( WP_CONTENT_DIR ) . '/debug.log';

		// 1. debug.log — dit rotaten we automatisch (WP's eigen log, veilig).
		if ( is_file( $debug ) ) {
			clearstatcache( true, $debug );
			$size = (int) filesize( $debug );
			if ( $size > $max ) {
				$archived = self::rotate( $debug );
				if ( $archived ) {
					$lines[] = sprintf(
						'debug.log was %s en is gearchiveerd naar: %s',
						size_format( $size ),
						$archived
					);
				} else {
					$lines[] = sprintf(
						'debug.log is %s (boven de drempel) maar kon NIET gearchiveerd worden — archiefmap niet schrijfbaar. Handmatig nakijken.',
						size_format( $size )
					);
				}
			}
		}

		// 2. Andere *.log onder wp-content — alleen signaleren, niet aanraken
		//    (kunnen van een plugin zijn). Anti-spam via state per pad.
		$state = get_option( self::STATE_OPT, [] );
		if ( ! is_array( $state ) ) {
			$state = [];
		}
		$seen = [];
		foreach ( self::find_other_logs( $debug ) as $path ) {
			clearstatcache( true, $path );
			$size          = (int) filesize( $path );
			$seen[ $path ] = true;

			if ( $size > $max ) {
				// Alleen melden als we dit pad nog niet gemeld hadden (pas weer
				// na een reset omdat het onder de drempel zakte).
				if ( empty( $state[ $path ] ) ) {
					$lines[] = sprintf(
						'%s is %s (boven de drempel) — NIET automatisch gearchiveerd (mogelijk van een plugin). Handmatig bekijken.',
						str_replace( untrailingslashit( WP_CONTENT_DIR ) . '/', 'wp-content/', $path ),
						size_format( $size )
					);
				}
				$state[ $path ] = $size;
			} else {
				// Weer onder de drempel → reset zodat het opnieuw kan melden.
				unset( $state[ $path ] );
			}
		}
		// 3. LogMAPPEN — plugins die per run een nieuw logje wegschrijven en
		//    nooit opruimen. Hier telt de SOM, niet het losse bestand.
		foreach ( self::find_log_dirs() as $dir ) {
			list( $count, $bytes ) = self::dir_stats( $dir );
			if ( 0 === $count ) {
				continue;
			}

			$key           = 'dir:' . $dir;
			$seen[ $key ]  = true;
			$te_groot      = ( $bytes > self::max_dir_bytes() );
			$te_veel       = ( $count > self::max_dir_files() );

			if ( $te_groot || $te_veel ) {
				if ( empty( $state[ $key ] ) ) {
					$lines[] = sprintf(
						'%s bevat %d bestanden (samen %s) — logmap van een plugin, NIET automatisch opgeruimd. Handmatig bekijken: draait die plugin in een lus?',
						str_replace( untrailingslashit( WP_CONTENT_DIR ) . '/', 'wp-content/', $dir ) . '/',
						$count,
						size_format( $bytes )
					);
				}
				$state[ $key ] = $bytes;
			} else {
				unset( $state[ $key ] );
			}
		}

		// State opschonen: verdwenen bestanden eruit.
		foreach ( array_keys( $state ) as $p ) {
			if ( empty( $seen[ $p ] ) ) {
				unset( $state[ $p ] );
			}
		}
		update_option( self::STATE_OPT, $state, false );

		if ( ! empty( $lines ) ) {
			self::send_mail( $lines );
		}
	}

	/**
	 * Rotate debug.log: hernoem het naar de archiefmap. Dat is atomisch en
	 * instant — ook bij een log van gigabytes, geen geheugen- of timeout-risico.
	 * WordPress maakt bij de volgende log-regel vanzelf een nieuw debug.log aan.
	 *
	 * @return string|false Archiefpad bij succes, anders false.
	 */
	private static function rotate( $src ) {
		$dir = self::ensure_archive_dir();
		if ( ! $dir ) {
			return false;
		}
		$name = 'debug-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false ) . '.log';
		$dest = $dir . '/' . $name;

		return @rename( $src, $dest ) ? $dest : false;
	}

	/**
	 * Zoek andere *.log-bestanden onder wp-content (tot 3 niveaus diep),
	 * exclusief debug.log en de archiefmap zelf.
	 */
	private static function find_other_logs( $exclude ) {
		$base    = untrailingslashit( WP_CONTENT_DIR );
		$archive = self::archive_dir();
		$found   = [];

		foreach ( [ '/*.log', '/*/*.log', '/*/*/*.log' ] as $pattern ) {
			foreach ( (array) glob( $base . $pattern ) as $f ) {
				if ( $f && $f !== $exclude && 0 !== strpos( $f, $archive ) ) {
					$found[ $f ] = true;
				}
			}
		}

		return array_keys( $found );
	}

	/**
	 * Zoek mappen onder wp-content die logs bevatten (tot 3 niveaus diep).
	 * We herkennen ze aan de mapnaam ('log', 'logs', 'wpvivid_log', ...) in
	 * plaats van aan bestandsextensie: pluginlogs heten lang niet altijd *.log
	 * (WPvivid schrijft *.txt), en op extensie zoeken zou elke readme.txt van
	 * elke plugin meetellen.
	 */
	private static function find_log_dirs() {
		$base    = untrailingslashit( WP_CONTENT_DIR );
		$archive = self::archive_dir();
		$found   = [];

		foreach ( [ '/*', '/*/*', '/*/*/*' ] as $pattern ) {
			foreach ( (array) glob( $base . $pattern, GLOB_ONLYDIR ) as $d ) {
				if ( ! $d || 0 === strpos( $d, $archive ) ) {
					continue;
				}
				if ( preg_match( '/(^|[^a-z])logs?$/i', basename( $d ) ) ) {
					$found[ $d ] = true;
				}
			}
		}

		return array_keys( apply_filters( 'mcm_log_monitor_log_dirs', $found ) );
	}

	/**
	 * Tel bestanden en bytes in een logmap (één niveau diep, inclusief *.txt —
	 * binnen een logmap is dat wél veilig).
	 *
	 * @return array [ aantal, bytes ]
	 */
	private static function dir_stats( $dir ) {
		$count = 0;
		$bytes = 0;

		foreach ( [ '/*.log', '/*.txt', '/*/*.log', '/*/*.txt' ] as $pattern ) {
			foreach ( (array) glob( $dir . $pattern ) as $f ) {
				if ( $f && is_file( $f ) ) {
					$count++;
					$bytes += (int) filesize( $f );
				}
			}
		}

		return [ $count, $bytes ];
	}

	/**
	 * Stuur één mail naar Marco met alle bevindingen + hoe op te halen.
	 */
	private static function send_mail( array $lines ) {
		$site = get_bloginfo( 'name' );
		$url  = home_url();
		$dir  = self::archive_dir();
		$n    = count( (array) glob( $dir . '/*.log' ) );

		$subject = sprintf( '[MCM Log-monitor] Groot log op %s', $site );

		$body  = "Hallo Marco,\n\n";
		$body .= sprintf( "De log-monitor vond te grote log(s) op %s (%s):\n\n", $site, $url );
		foreach ( $lines as $l ) {
			$body .= ' - ' . $l . "\n";
		}
		$body .= "\n";
		$body .= 'Archiefmap op de site: ' . $dir . "\n";
		$body .= sprintf( "Aantal wachtende archieven: %d\n\n", $n );
		$body .= "Het gearchiveerde log staat veilig op de site zelf en het draaiende log\n";
		$body .= "is weer schoon. Haal het archief wanneer je wilt naar je Hetzner-schijf\n";
		$body .= "(werk/<klant>/logs/) en verwijder het daarna van de site.\n\n";
		$body .= "— MCM Site Optimizer (Log-monitor)\n";

		wp_mail( self::ALERT_EMAIL, $subject, $body );
	}
}

MCM_Log_Monitor::init();

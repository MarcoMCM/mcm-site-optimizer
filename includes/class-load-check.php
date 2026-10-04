<?php
/**
 * Plugins & belasting: wat de server per verzoek of op de achtergrond extra
 * kost, en back-uprommel die op nginx publiek bereikbaar is.
 *
 * Aanleiding — pensioenfonds-sagittarius.nl, 3/4 oktober 2026:
 *  - WP Migrate DB Pro en WP Debug Toolkit Pro actief op productie (de toolkit
 *    zette SAVEQUERIES aan en hernoemde plugin-mappen naar *-dbtk-disabled).
 *  - Cron elke minuut (MainWP Child system monitor) en elke 2 minuten (WPvivid
 *    Imgoptim) — elke run laadt heel WordPress, op een server met 128M.
 *  - Gravity Forms voor één formulier: ±320 PHP-bestanden / ±10 MB opcache per
 *    paginaweergave. Vervangen door Avada Forms.
 *  - WPvivid Imgoptim maakte 1114 WebP-kopieën die via .htaccess-rewrite
 *    geserveerd moesten worden — op nginx nooit gebeurd.
 *  - wp-content/wpvividbackups/rollback/…/*.zip (262 MB oude plugin-versies,
 *    o.a. betaalde) gaf op nginx 200 application/zip: publiek downloadbaar.
 *
 * Alleen rapport + advies; deze kaart verandert niets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Load_Check {

	/** Mappen in wp-content waar plugins back-ups/archieven neerzetten. */
	const BACKUP_DIRS = [
		'wpvividbackups'     => 'WPvivid',
		'wpvivid_uploads'    => 'WPvivid',
		'updraft'            => 'UpdraftPlus',
		'ai1wm-backups'      => 'All-in-One WP Migration',
		'backups-dup-lite'   => 'Duplicator',
		'backups-dup-pro'    => 'Duplicator Pro',
		'backwpup-backups'   => 'BackWPup',
		'backup-guard'       => 'BackupGuard',
		'backups'            => 'onbekend',
	];

	public function __construct() {
		add_action( 'wp_ajax_mcm_load_scan',       [ $this, 'ajax_scan' ] );
		add_action( 'mcm_optimizer_render_cards',  [ $this, 'render_card' ] );
		add_action( 'admin_enqueue_scripts',       [ $this, 'assets' ] );
	}

	/* ---------------------------------------------------------------
	 * Feiten
	 * ------------------------------------------------------------- */

	public static function facts() {
		$active = (array) get_option( 'active_plugins', [] );
		return [
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'nginx'       => isset( $_SERVER['SERVER_SOFTWARE'] ) && false !== stripos( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ), 'nginx' ),
			'active'      => array_map( static function ( $p ) {
				return strtolower( dirname( $p ) );
			}, $active ),
			'mu_files'    => array_map( 'basename', (array) glob( WPMU_PLUGIN_DIR . '/*.php' ) ),
			'cron'        => self::frequent_cron(),
			'wp_cron_off' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'is_avada'    => class_exists( 'MCM_Avada_Rocket' ) && MCM_Avada_Rocket::is_avada(),
			'gf'          => self::gf_usage(),
			'wpvivid_img' => get_option( 'wpvivid_optimization_options', [] ),
			'wpvivid_set' => get_option( 'wpvivid_common_setting', [] ),
			'backups'     => self::backup_dirs(),
		];
	}

	/** Cron-events die vaker dan elke 5 minuten draaien. [hook => [interval, schedule]] */
	private static function frequent_cron() {
		$out = [];
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				foreach ( (array) $events as $e ) {
					$iv = (int) ( $e['interval'] ?? 0 );
					if ( $iv > 0 && $iv < 300 ) {
						$out[ $hook ] = [ 'interval' => $iv, 'schedule' => (string) ( $e['schedule'] ?? '' ) ];
					}
				}
			}
		}
		return $out;
	}

	/** Gravity Forms: hoeveel formulieren en op hoeveel pagina's. */
	private static function gf_usage() {
		global $wpdb;
		$table = $wpdb->prefix . 'gf_form';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return null;
		}
		return [
			'widget' => (bool) is_active_widget( false, false, 'gform_widget', true ),
			'forms'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_active = 1 AND is_trash = 0" ),
			'pages' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT ID) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','attachment') AND ( post_content LIKE '%[gravityform%' OR post_content LIKE '%wp:gravityforms/%' )" ),
		];
	}

	/**
	 * Back-upmappen in wp-content met archieven: aantal, grootte en een paar
	 * voorbeeldpaden (relatief aan wp-content) om bereikbaarheid te testen.
	 */
	private static function backup_dirs() {
		$out = [];
		foreach ( self::BACKUP_DIRS as $dir => $plugin ) {
			$base = WP_CONTENT_DIR . '/' . $dir;
			if ( ! is_dir( $base ) ) {
				continue;
			}
			$count = 0;
			$size  = 0;
			$items = [];
			try {
				// CATCH_GET_CHILD: een onleesbare submap slaan we over in plaats van te crashen.
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY,
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);
			} catch ( Exception $e ) {
				continue;
			}
			foreach ( $it as $file ) {
				if ( ! $file->isFile() || ! preg_match( '/\.(zip|gz|tgz|tar|sql|wpress|daf|bak|7z|rar)$/i', $file->getFilename() ) ) {
					continue;
				}
				$count++;
				$size += $file->getSize();
				if ( count( $items ) < 3 ) {
					$items[] = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( WP_CONTENT_DIR ) ) ), '/' );
				}
				if ( $count > 5000 ) {
					break;
				}
			}
			if ( $count ) {
				$out[ $dir ] = [
					'plugin'   => $plugin,
					'count'    => $count,
					'size_mb'  => round( $size / MB_IN_BYTES, 1 ),
					'examples' => $items,
					'htaccess' => file_exists( $base . '/.htaccess' ),
					'public'   => null,
				];
			}
		}
		return $out;
	}

	/**
	 * Test met een HEAD-verzoek (alleen kopregels, niets downloaden) of een
	 * voorbeeldbestand publiek bereikbaar is. true/false/null (niet te bepalen).
	 */
	public static function probe_public( $rel ) {
		// Elk padsegment coderen (spaties, # of ? in bestandsnamen).
		$rel = implode( '/', array_map( 'rawurlencode', explode( '/', (string) $rel ) ) );
		$res = wp_remote_head(
			content_url( $rel ),
			[
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128 Safari/537.36 MCM-Optimizer-Check',
			]
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$type = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
		if ( 200 === $code ) {
			// Een 200 met een HTML-pagina is geen archief (bv. een catch-all of
			// inlogpagina): dan weten we het niet zeker.
			return 0 === strpos( $type, 'text/html' ) ? null : true;
		}
		return in_array( $code, [ 401, 403, 404, 410 ], true ) ? false : null;
	}

	/* ---------------------------------------------------------------
	 * Beoordeling
	 * ------------------------------------------------------------- */

	public static function evaluate( array $f ) {
		$rows = [];
		$prod = 'production' === $f['environment'];
		$act  = $f['active'];

		// --- Ontwikkel- en risicotools op productie ---
		$dev = [
			'query-monitor'          => 'Query Monitor',
			'wp-migrate-db'          => 'WP Migrate (Lite)',
			'wp-migrate-db-pro'      => 'WP Migrate DB Pro',
			'wpdebugtoolkit'         => 'WP Debug Toolkit Pro',
			'debug-bar'              => 'Debug Bar',
			'duplicator'             => 'Duplicator',
			'duplicator-pro'         => 'Duplicator Pro',
			'all-in-one-wp-migration' => 'All-in-One WP Migration',
			'wp-file-manager'        => 'WP File Manager',
			'file-manager-advanced'  => 'File Manager Advanced',
		];
		$found = [];
		foreach ( $dev as $slug => $name ) {
			if ( in_array( $slug, $act, true ) ) {
				$found[] = $name;
			}
		}
		if ( in_array( 'wpdebugtoolkit-notifications.php', $f['mu_files'], true ) ) {
			$found[] = 'mu-plugin van WP Debug Toolkit';
		}
		$rows[] = [
			'Ontwikkel- en migratietools actief',
			$found ? ( $prod ? 'warn' : 'info' ) : 'ok',
			$found ? implode( ', ', $found ) : 'geen',
			'alleen aan als je ze gebruikt',
			$found ? 'Laden bij elke paginaweergave mee (geheugen/opcache) en vergroten het aanvalsoppervlak. Zet ze aan als je ze nodig hebt.'
				. ( array_intersect( [ 'wp-file-manager', 'file-manager-advanced' ], $act ) ? ' Bestandsbeheerders zijn een bekend inbraakdoel.' : '' )
				. ( in_array( 'wpdebugtoolkit', $act, true ) ? ' Let op: WP Debug Toolkit zet bij deactiveren zijn wp-config-back-up in z\'n geheel terug — controleer wp-config daarna.' : '' ) : '',
		];

		// --- Frequente cron ---
		$known = [
			'mainwp_child'         => 'MainWP Child',
			'wpvivid'              => 'WPvivid',
			'action_scheduler'     => 'Action Scheduler (WooCommerce/WP Rocket)',
			'wp_rocket'            => 'WP Rocket',
			'rocket'               => 'WP Rocket',
			'wordfence'            => 'Wordfence',
			'jetpack'              => 'Jetpack',
		];
		$list = [];
		$warn = false;
		foreach ( $f['cron'] as $hook => $c ) {
			$who = 'onbekend';
			foreach ( $known as $prefix => $name ) {
				if ( 0 === strpos( $hook, $prefix ) ) {
					$who = $name;
					break;
				}
			}
			// Action Scheduler-wachtrijen (ook die van WP Rocket, *_rucss) draaien
			// standaard elke minuut en horen bij die plugins.
			if ( 0 !== strpos( $hook, 'action_scheduler_run_queue' ) ) {
				$warn = true;
			}
			$list[] = $hook . ' (' . $who . ', elke ' . ( $c['interval'] < 60 ? $c['interval'] . ' s' : round( $c['interval'] / 60 ) . ' min' ) . ')';
		}
		$rows[] = [
			'Cron vaker dan elke 5 minuten',
			$warn ? 'warn' : 'ok',
			$list ? implode( '; ', $list ) : 'geen',
			$warn ? 'zo weinig mogelijk' : '—',
			$list ? ( $warn ? 'Elke cronrun start heel WordPress (op shared hosting met krap geheugen merkbaar). Zet de functie uit in de betreffende plugin als je hem niet gebruikt (bv. de system monitor van MainWP Child, automatische optimalisatie van WPvivid Imgoptim). Let op: events van een uitgeschakelde plugin blijven ingepland tot de plugin ze opruimt. ' : '' )
				. 'Action Scheduler-wachtrijen (action_scheduler_run_queue*) zijn normaal voor WooCommerce/WP Rocket.'
				. ( $f['wp_cron_off'] ? ' WP-cron is uitgeschakeld: een systeemcron bepaalt hoe vaak dit echt draait.' : '' ) : '',
		];

		// --- Gravity Forms op Avada ---
		if ( $f['is_avada'] && is_array( $f['gf'] ) && in_array( 'gravityforms', $act, true ) && $f['gf']['forms'] <= 2 ) {
			$unused = 0 === $f['gf']['pages'] && empty( $f['gf']['widget'] );
			$rows[] = [
				$unused ? 'Gravity Forms lijkt ongebruikt' : 'Gravity Forms voor ' . $f['gf']['forms'] . ' formulier(en)',
				$unused ? 'warn' : 'info',
				$f['gf']['forms'] . ' formulier(en) actief, op ' . $f['gf']['pages'] . ' pagina(\'s)' . ( empty( $f['gf']['widget'] ) ? '' : ' + widget' ),
				$unused ? 'deactiveren' : 'Avada Forms overwegen',
				'Gravity Forms laadt op élke paginaweergave ±320 PHP-bestanden (±10 MB opcache), ook waar geen formulier staat; de reCAPTCHA-add-on laadt Google op elke pagina. '
					. ( $unused
						? 'Er staat geen formulier in pagina\'s, berichten, Avada-layouts of widgets. Staat het alleen nog in een thema-sjabloon (PHP)? Controleer dat, en deactiveer dan Gravity Forms en zijn add-ons. Inzendingen blijven in de database staan.'
						: 'Avada Forms zit al in Fusion Builder. Bij overstappen: de gebruikte formulier-elementen (ook Honeypot) aanzetten in Avada → Performance → Avada Elements, Turnstile i.p.v. reCAPTCHA, en een bewaartermijn instellen.' ),
			];
		}

		// --- WebP via rewrite op nginx ---
		$img = is_array( $f['wpvivid_img'] ) ? $f['wpvivid_img'] : [];
		if ( in_array( 'wpvivid-imgoptim', $act, true ) && ! empty( $img['webp']['display_enable'] ) && 'rewrite' === ( $img['webp']['display'] ?? '' ) ) {
			$rows[] = [
				'WPvivid WebP via rewrite',
				$f['nginx'] ? 'warn' : 'info',
				'weergave: rewrite' . ( $f['nginx'] ? ' op nginx' : '' ),
				$f['nginx'] ? 'werkt niet op nginx' : '—',
				$f['nginx'] ? 'nginx leest de .htaccess-regels niet: de WebP-kopieën (*.jpg.webp) worden nooit geserveerd en kosten alleen schijfruimte. Gebruik voor nieuwe uploads Avada\'s WebP-optie, of de <picture>-methode van de plugin.' : 'Werkt alleen zolang Apache de .htaccess-regels leest.',
			];
		}

		// --- WPvivid: lokale kopie bewaren ---
		$set = is_array( $f['wpvivid_set'] ) ? $f['wpvivid_set'] : [];
		if ( isset( $set['retain_local'] ) && '1' === (string) $set['retain_local'] ) {
			$rows[] = [
				'WPvivid bewaart lokale kopie na upload',
				$f['nginx'] ? 'warn' : 'info',
				'aan',
				'uit',
				'Back-ups blijven na het uploaden ook in wp-content staan. Op nginx zijn die archieven (inclusief database) publiek op te vragen als iemand de naam raadt. Zet "Keep storing the backups in localhost after uploading to remote storage" uit.',
			];
		}

		// --- Back-upmappen ---
		foreach ( $f['backups'] as $dir => $b ) {
			$public = $b['public'];
			// Zonder .htaccess is de map op Apache óók open; op nginx telt .htaccess nooit.
			$open   = $f['nginx'] || empty( $b['htaccess'] );
			// "Afgeschermd" geldt alleen voor deze test vanaf de server zelf; de archieven
			// staan er nog steeds en horen niet in de webroot. Daarom nooit "ok".
			$status = true === $public ? 'bad' : ( $open ? 'warn' : 'info' );
			$rows[] = [
				'Archieven in wp-content/' . $dir,
				$status,
				$b['count'] . ' bestand(en), ' . $b['size_mb'] . ' MB (' . $b['plugin'] . ')' . ( true === $public ? ' — PUBLIEK BEREIKBAAR' : ( false === $public ? ' — bij deze test geweigerd' : '' ) ),
				'opruimen of verplaatsen',
				( true === $public
					? 'Getest met een HEAD-verzoek (niets gedownload): ' . $b['examples'][0] . ' geeft 200. Iedereen die het pad raadt, kan dit downloaden — bij betaalde plugins of database-back-ups een echt lek. '
					: ( $f['nginx'] ? 'Op nginx beschermt een .htaccess in deze map niets. ' : ( empty( $b['htaccess'] ) ? 'Deze map heeft geen .htaccess, dus ook op Apache is hij niet afgeschermd. ' : '' ) ) )
					. ( 'wpvividbackups' === $dir ? 'Bij WPvivid: zet de rollback-functie (oude plugin-versies bewaren) uit onder WPvivid Backup → Rollback en verwijder de map rollback/. ' : '' )
					. 'Voorbeeld: ' . implode( ', ', $b['examples'] ),
			];
		}

		return $rows;
	}

	/* ---------------------------------------------------------------
	 * AJAX + UI
	 * ------------------------------------------------------------- */

	public function ajax_scan() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}
		$f = self::facts();
		foreach ( $f['backups'] as $dir => $b ) {
			// Tot drie voorbeelden: één bereikbaar = open. Alle geweigerd = bij deze test afgeschermd.
			$res = null;
			foreach ( array_slice( (array) $b['examples'], 0, 3 ) as $ex ) {
				$p = self::probe_public( $ex );
				if ( true === $p ) {
					$res = true;
					break;
				}
				if ( false === $p ) {
					$res = false;
				}
			}
			$f['backups'][ $dir ]['public'] = $res;
		}
		wp_send_json_success( [ 'rows' => self::evaluate( $f ) ] );
	}

	public function render_card() {
		?>
		<div class="mcm-opt-card">
			<div class="mcm-opt-card-header">
				<span class="dashicons dashicons-plugins-checked"></span>
				<h2>Plugins &amp; belasting</h2>
				<button type="button" id="mcm-load-scan" class="button mcm-opt-btn-primary" style="margin-left:auto;">
					<span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
					Analyseren
				</button>
			</div>
			<div class="mcm-opt-card-body">
				<p class="description" style="margin-top:0;">
					Ontwikkeltools op productie, cron die vaker dan elke 5 minuten draait, zware plugins voor weinig werk,
					WebP die op nginx niet werkt, en back-uparchieven in wp-content (met een test of ze publiek bereikbaar zijn
					— alleen kopregels, er wordt niets gedownload). Alleen advies.
				</p>
				<div id="mcm-load-loading" style="display:none;">
					<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span> Bezig met analyseren...
				</div>
				<div id="mcm-load-results"></div>
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
	var icons = {
		ok:   '<span class="dashicons dashicons-yes-alt" style="color:#1a5c5e;"></span>',
		warn: '<span class="dashicons dashicons-warning" style="color:#E78E46;"></span>',
		bad:  '<span class="dashicons dashicons-dismiss" style="color:#AE432B;"></span>',
		info: '<span class="dashicons dashicons-info" style="color:#6b5d52;"></span>'
	};
	function esc(s) { return $('<div/>').text(String(s)).html(); }
	$('#mcm-load-scan').on('click', function() {
		var btn = $(this);
		btn.prop('disabled', true);
		$('#mcm-load-loading').show();
		$.post(mcmOptimizer.ajaxUrl, { action: 'mcm_load_scan', nonce: mcmOptimizer.nonce }, function(res) {
			btn.prop('disabled', false);
			$('#mcm-load-loading').hide();
			if (!res.success) { $('#mcm-load-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Analyse mislukt.</div>'); return; }
			var h = '';
			res.data.rows.forEach(function(r) {
				h += '<div class="mcm-perf-rec"><div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">' + (icons[r[1]] || '') +
					'<strong>' + esc(r[0]) + '</strong><span style="color:#6b5d52;font-size:12px;">' + esc(r[2]) + (r[3] ? ' → ' + esc(r[3]) : '') + '</span></div>';
				if (r[4]) { h += '<div style="font-size:12px;color:#6b5d52;margin:4px 0 0 26px;">' + esc(r[4]) + '</div>'; }
				h += '</div>';
			});
			$('#mcm-load-results').html(h);
		}).fail(function() {
			btn.prop('disabled', false);
			$('#mcm-load-loading').hide();
			$('#mcm-load-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Verbindingsfout.</div>');
		});
	});
});
JS;
	}
}

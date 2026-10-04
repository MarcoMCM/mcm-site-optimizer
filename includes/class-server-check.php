<?php
/**
 * Server & mail: wat de host bepaalt (PHP, geheugen, opcache, limieten,
 * database) en of de mail van de site goed aankomt (SPF/DMARC/afzender).
 *
 * Aanleiding — pensioenfonds-sagittarius.nl, 3/4 oktober 2026: site plat door
 * "Allowed memory size of 134217728 bytes exhausted". memory_limit zat bij de
 * host vast op 128M (WP_MEMORY_LIMIT, .user.ini en ini_set werkten niet) en de
 * gedeelde opcache (128 MB, interned strings 8 MB en vol) liep na grote updates
 * vol. Lokaal gemeten: WordPress + Avada laden kost 28 MB mét en 118 MB zónder
 * werkende opcache. Daarbij: MariaDB 10.3, max_execution_time 30, max_input_vars 1750.
 *
 * De kaart RAPPORTEERT en maakt een mailtekst voor de hostingpartij. Het enige
 * wat hij kan aanzetten is de (uitgeschakelde) afzender-correctie voor mail.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Server_Check {

	/** Optie: envelope sender gelijk aan From (alleen eigen domein). */
	const ENVELOPE_OPT = 'mcm_mail_envelope_fix';

	public function __construct() {
		add_action( 'wp_ajax_mcm_server_scan',     [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_mcm_server_envelope', [ $this, 'ajax_envelope' ] );
		add_action( 'mcm_optimizer_render_cards',  [ $this, 'render_card' ] );
		add_action( 'admin_enqueue_scripts',       [ $this, 'assets' ] );
	}

	/* ---------------------------------------------------------------
	 * Afzender-correctie (draait overal, ook bij formulier-inzendingen)
	 * ------------------------------------------------------------- */

	/**
	 * Zet het onzichtbare afzenderadres (envelope sender / Return-Path) gelijk
	 * aan het From-adres, maar alleen als dat op het eigen domein zit en er nog
	 * niets is ingesteld (SMTP-plugins regelen dit zelf). Dan wordt SPF tegen
	 * het eigen domein gecontroleerd en klopt DMARC, in plaats van tegen een
	 * systeemadres van de server.
	 */
	public static function envelope_sender( $phpmailer ) {
		// WordPress hergebruikt binnen één verzoek hetzelfde PHPMailer-object en
		// wist Sender niet tussen mails door. Eerst onze eigen waarde van een
		// vorige mail opruimen, anders krijgt een volgende mail (ander From-domein)
		// ons afzenderadres mee. Gevonden in de test op 4 okt 2026.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName
		if ( '' !== self::$ours && $phpmailer->Sender === self::$ours ) {
			$phpmailer->Sender = '';
		}
		self::$ours = '';
		if ( ! get_option( self::ENVELOPE_OPT ) || ! empty( $phpmailer->Sender ) ) {
			return;
		}
		$from = (string) $phpmailer->From;
		if ( is_email( $from ) && self::domain_of( $from ) === self::site_domain() ) {
			$phpmailer->Sender = $from;
			self::$ours        = $from;
		}
		// phpcs:enable
	}

	/** Het Sender-adres dat wij bij de vorige mail hebben gezet. */
	private static $ours = '';

	public static function site_domain() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return preg_replace( '/^www\./', '', $host );
	}

	private static function domain_of( $email ) {
		return strtolower( (string) substr( strrchr( (string) $email, '@' ), 1 ) );
	}

	/* ---------------------------------------------------------------
	 * Feiten verzamelen
	 * ------------------------------------------------------------- */

	/** Alles wat de kaart nodig heeft, los van de beoordeling (testbaar). */
	public static function facts() {
		global $wpdb;

		$f = [
			'php_version'     => PHP_VERSION,
			'sapi'            => PHP_SAPI,
			'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'hostname'        => function_exists( 'gethostname' ) ? (string) gethostname() : '',
			'is_avada'        => class_exists( 'MCM_Avada_Rocket' ) && MCM_Avada_Rocket::is_avada(),
			'memory_limit'    => (string) ini_get( 'memory_limit' ),
			'wp_memory_limit' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
			'memory_locked'   => self::memory_locked(),
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			'max_input_vars'  => (int) ini_get( 'max_input_vars' ),
			'upload_max'      => (string) ini_get( 'upload_max_filesize' ),
			'post_max'        => (string) ini_get( 'post_max_size' ),
			'files_loaded'    => count( get_included_files() ),
			'db_server'       => method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : (string) $wpdb->db_version(),
			'opcache'         => self::opcache_facts(),
			'mail'            => self::mail_facts(),
		];
		return $f;
	}

	/**
	 * Zit memory_limit vast? Probeer hem 1 MB te verhogen en zet hem terug.
	 * Bewezen werkwijze: op Gooiland bleef ini_set('memory_limit','512M') 128M.
	 */
	private static function memory_locked() {
		$old = (string) ini_get( 'memory_limit' );
		if ( '-1' === $old ) {
			return false;
		}
		$bytes = wp_convert_hr_to_bytes( $old );
		$try   = (string) ( (int) floor( $bytes / MB_IN_BYTES ) + 1 ) . 'M';
		$res   = @ini_set( 'memory_limit', $try ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet
		$now   = (string) ini_get( 'memory_limit' );
		if ( false !== $res && $now !== $old ) {
			@ini_set( 'memory_limit', $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet
			return false;
		}
		return true;
	}

	private static function opcache_facts() {
		$o = [
			'enabled'                 => (bool) ini_get( 'opcache.enable' ),
			'memory_consumption'      => (int) ini_get( 'opcache.memory_consumption' ),
			'interned_strings_buffer' => (int) ini_get( 'opcache.interned_strings_buffer' ),
			'max_accelerated_files'   => (int) ini_get( 'opcache.max_accelerated_files' ),
			'status'                  => null,
		];
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( function_exists( 'opcache_get_status' ) && ! in_array( 'opcache_get_status', $disabled, true ) ) {
			$s = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $s ) ) {
				$mem = $s['memory_usage'] ?? [];
				$int = $s['interned_strings_usage'] ?? [];
				$o['status'] = [
					'used_mb'        => round( ( $mem['used_memory'] ?? 0 ) / MB_IN_BYTES, 1 ),
					'free_mb'        => round( ( $mem['free_memory'] ?? 0 ) / MB_IN_BYTES, 1 ),
					'wasted_mb'      => round( ( $mem['wasted_memory'] ?? 0 ) / MB_IN_BYTES, 1 ),
					'interned_free'  => round( ( $int['free_memory'] ?? 0 ) / MB_IN_BYTES, 1 ),
					'cache_full'     => ! empty( $s['cache_full'] ),
					'scripts'        => (int) ( $s['opcache_statistics']['num_cached_scripts'] ?? 0 ),
					'oom_restarts'   => (int) ( $s['opcache_statistics']['oom_restarts'] ?? 0 ),
				];
			}
		}
		return $o;
	}

	/* ---------------------------------------------------------------
	 * Mail: DNS + afzenders
	 * ------------------------------------------------------------- */

	private static function mail_facts() {
		$domain = self::site_domain();
		$m      = [
			'domain'       => $domain,
			'dns'          => function_exists( 'dns_get_record' ),
			'mx'           => [],
			'spf'          => '',
			'dmarc'        => '',
			'server_ips'   => [],
			'spf_covers'   => null,
			'smtp_plugin'  => self::smtp_plugin(),
			'from_domains' => self::from_domains(),
			'envelope_fix' => (bool) get_option( self::ENVELOPE_OPT ),
		];
		if ( ! $m['dns'] ) {
			return $m;
		}
		foreach ( (array) @dns_get_record( $domain, DNS_MX ) as $r ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! empty( $r['target'] ) ) {
				$m['mx'][] = strtolower( $r['target'] );
			}
		}
		$m['spf']   = self::txt_starting( $domain, 'v=spf1' );
		$m['dmarc'] = self::txt_starting( '_dmarc.' . $domain, 'v=DMARC1' );

		$ips = [];
		if ( ! empty( $_SERVER['SERVER_ADDR'] ) && filter_var( wp_unslash( $_SERVER['SERVER_ADDR'] ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$ips[] = sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) );
		}
		foreach ( self::a_records( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) as $ip ) {
			$ips[] = $ip;
		}
		$m['server_ips'] = array_values( array_unique( $ips ) );
		if ( $m['spf'] && $m['server_ips'] ) {
			$lookups         = 0;
			$m['spf_covers'] = self::spf_covers( $domain, $m['server_ips'], 0, $lookups );
		}
		return $m;
	}

	private static function txt_starting( $name, $prefix ) {
		foreach ( (array) @dns_get_record( $name, DNS_TXT ) as $r ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$txt = isset( $r['entries'] ) ? implode( '', (array) $r['entries'] ) : (string) ( $r['txt'] ?? '' );
			if ( 0 === stripos( trim( $txt ), $prefix ) ) {
				return trim( $txt );
			}
		}
		return '';
	}

	private static function a_records( $host ) {
		$out = [];
		foreach ( (array) @dns_get_record( $host, DNS_A ) as $r ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! empty( $r['ip'] ) ) {
				$out[] = $r['ip'];
			}
		}
		return $out;
	}

	/**
	 * Valt een van de IP's van de webserver binnen de SPF van het domein?
	 * Volgt de SPF-volgorde: het eerste mechanisme dat matcht beslist, en alleen
	 * "+" (pass) telt als toegestaan. Ondersteunt ip4, ip6, a, mx (met host en
	 * /cidr), include, all en redirect=. exists, ptr en macro's (%{…}) zijn niet
	 * na te rekenen → null. Max. 10 DNS-lookups, diepte 4.
	 *
	 * @return bool|null true/false, of null als het niet te bepalen was.
	 */
	private static function spf_covers( $domain, array $ips, $depth, &$lookups ) {
		if ( $depth > 4 || $lookups > 10 ) {
			return null;
		}
		$spf = self::txt_starting( $domain, 'v=spf1' );
		if ( '' === $spf ) {
			return null;
		}
		$unknown  = false; // een eerder mechanisme was niet na te rekenen…
		$unk_neg  = false; // …en had een -/~/? kwalificatie (had dus kunnen weigeren).
		$redirect = '';
		foreach ( preg_split( '/\s+/', strtolower( $spf ) ) as $tok ) {
			if ( '' === $tok || 'v=spf1' === $tok ) {
				continue;
			}
			if ( 0 === strpos( $tok, 'redirect=' ) ) {
				$redirect = substr( $tok, 9 );
				continue;
			}
			if ( false !== strpos( $tok, '=' ) ) {
				continue; // andere modifier (exp=) — geen invloed.
			}
			$q = '+';
			if ( false !== strpos( '+-~?', $tok[0] ) ) {
				$q   = $tok[0];
				$tok = substr( $tok, 1 );
			}
			if ( false !== strpos( $tok, '%{' ) ) {
				$unknown = true; // macro: niet na te rekenen.
				$unk_neg = $unk_neg || '+' !== $q;
				continue;
			}

			$match = false;
			if ( 'all' === $tok ) {
				// Alles hierna telt niet meer; redirect= wordt dan genegeerd.
				return self::spf_result( $q, $unknown, $unk_neg );
			} elseif ( 0 === strpos( $tok, 'ip4:' ) ) {
				foreach ( $ips as $ip ) {
					if ( self::ip_in_cidr( $ip, substr( $tok, 4 ) ) ) {
						$match = true;
						break;
					}
				}
			} elseif ( 0 === strpos( $tok, 'ip6:' ) ) {
				continue; // de webserver-IP's zijn IPv4.
			} elseif ( preg_match( '#^(a|mx)(?::([^/]+))?(?:/(\d{1,2}))?(?://\d+)?$#', $tok, $mm ) ) {
				$lookups++;
				if ( $lookups > 10 ) {
					return null;
				}
				$host  = ( isset( $mm[2] ) && '' !== $mm[2] ) ? $mm[2] : $domain;
				$bits  = ( isset( $mm[3] ) && '' !== $mm[3] ) ? (int) $mm[3] : 32;
				$addrs = [];
				if ( 'a' === $mm[1] ) {
					$addrs = self::a_records( $host );
				} else {
					foreach ( (array) @dns_get_record( $host, DNS_MX ) as $r ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
						if ( ! empty( $r['target'] ) ) {
							$addrs = array_merge( $addrs, self::a_records( $r['target'] ) );
						}
					}
				}
				foreach ( $addrs as $addr ) {
					foreach ( $ips as $ip ) {
						if ( self::ip_in_cidr( $ip, $addr . '/' . $bits ) ) {
							$match = true;
							break 2;
						}
					}
				}
			} elseif ( 0 === strpos( $tok, 'include:' ) ) {
				$lookups++;
				$res = self::spf_covers( substr( $tok, 8 ), $ips, $depth + 1, $lookups );
				if ( null === $res ) {
					$unknown = true;
					$unk_neg = $unk_neg || '+' !== $q;
					continue;
				}
				$match = $res; // include matcht alleen bij een pass in het andere record.
			} else {
				// exists:, ptr of iets onbekends: we weten niet of dit matcht.
				$unknown = true;
				$unk_neg = $unk_neg || '+' !== $q;
				continue;
			}

			if ( $match ) {
				return self::spf_result( $q, $unknown, $unk_neg );
			}
		}

		if ( '' !== $redirect ) {
			$lookups++;
			$res = self::spf_covers( $redirect, $ips, $depth + 1, $lookups );
			return ( null === $res || ( false === $res && $unknown ) ) ? null : $res;
		}
		return $unknown ? null : false;
	}

	/** Uitkomst bij een match, rekening houdend met eerdere onbekende mechanismen. */
	private static function spf_result( $q, $unknown, $unk_neg ) {
		if ( '+' === $q ) {
			return $unk_neg ? null : true;
		}
		return $unknown ? null : false;
	}

	private static function ip_in_cidr( $ip, $cidr ) {
		if ( false === strpos( $cidr, '/' ) ) {
			return $ip === $cidr;
		}
		list( $net, $bits ) = explode( '/', $cidr, 2 );
		$bits = (int) $bits;
		$ipl  = ip2long( $ip );
		$netl = ip2long( $net );
		if ( false === $ipl || false === $netl || $bits < 0 || $bits > 32 ) {
			return false;
		}
		$mask = 0 === $bits ? 0 : ( ~0 << ( 32 - $bits ) ) & 0xFFFFFFFF;
		return ( $ipl & $mask ) === ( $netl & $mask );
	}

	/** Actieve plugin die mail via SMTP/API verstuurt, of ''. */
	private static function smtp_plugin() {
		$map    = [
			'wp-mail-smtp'   => 'WP Mail SMTP',
			'fluent-smtp'    => 'FluentSMTP',
			'post-smtp'      => 'Post SMTP',
			'easy-wp-smtp'   => 'Easy WP SMTP',
			'smtp-mailer'    => 'SMTP Mailer',
			'wp-ses'         => 'WP Offload SES',
			'mailgun'        => 'Mailgun',
			'sendgrid'       => 'SendGrid',
			'gosmtp'         => 'GoSMTP',
			'suremails'      => 'SureMail',
		];
		$active = strtolower( implode( '|', (array) get_option( 'active_plugins', [] ) ) );
		foreach ( $map as $slug => $name ) {
			if ( false !== strpos( $active, $slug . '/' ) ) {
				return $name;
			}
		}
		return '';
	}

	/** Afzenderdomeinen die de site gebruikt (Avada-formuliermeldingen + beheerdersadres). */
	private static function from_domains() {
		$domains = [];
		foreach ( get_posts( [ 'post_type' => 'fusion_form', 'post_status' => 'publish', 'numberposts' => 50, 'fields' => 'ids' ] ) as $fid ) {
			$meta = get_post_meta( $fid, '_fusion', true );
			foreach ( (array) ( $meta['notifications'] ?? [] ) as $n ) {
				$from = (string) ( $n['email_from_id'] ?? '' );
				if ( is_email( $from ) ) {
					$domains[ self::domain_of( $from ) ] = true;
				}
			}
		}
		$domains[ self::domain_of( get_option( 'admin_email' ) ) ] = true;
		return array_keys( array_filter( $domains ) );
	}

	/* ---------------------------------------------------------------
	 * Beoordeling
	 * ------------------------------------------------------------- */

	/**
	 * Zet feiten om in regels [label, status ok|warn|bad|info, current, advised, detail]
	 * plus de verzoeken voor de hostingpartij.
	 */
	public static function evaluate( array $f ) {
		$rows    = [];
		$request = [];
		$avada   = ! empty( $f['is_avada'] );

		// --- PHP-versie ---
		$v = $f['php_version'];
		if ( version_compare( $v, '8.2', '<' ) ) {
			$rows[]    = [ 'PHP-versie', 'bad', $v, '8.3 of nieuwer', 'Deze PHP-versie krijgt geen beveiligingsupdates meer (8.1: einde 31-12-2025). Overstappen kan meestal zelf in het hostingpaneel; test eerst.' ];
		} elseif ( version_compare( $v, '8.3', '<' ) ) {
			$rows[] = [ 'PHP-versie', 'warn', $v, '8.3 of nieuwer', 'PHP 8.2 krijgt beveiligingsupdates tot 31-12-2026. Overstappen naar 8.3 kan meestal zelf in het hostingpaneel. Op Sagittarius gaf 8.3 ook een verse opcache en ±2× snellere pagina\'s.' ];
		} else {
			$rows[] = [ 'PHP-versie', 'ok', $v, '8.3 of nieuwer', '' ];
		}

		// --- Webserver ---
		$soft = strtolower( $f['server_software'] );
		if ( false !== strpos( $soft, 'nginx' ) ) {
			$rows[] = [ 'Webserver', 'info', $f['server_software'] . ' (' . $f['sapi'] . ')', '—', 'nginx leest geen .htaccess: plugins die WebP of mapbeveiliging via .htaccess regelen, werken hier niet. Back-up- en rollback-zips in wp-content zijn dan publiek op te vragen; test met alleen headers (curl -I).' ];
		} else {
			$rows[] = [ 'Webserver', 'info', ( $f['server_software'] ? $f['server_software'] : 'onbekend' ) . ' (' . $f['sapi'] . ')', '—', '' ];
		}

		// --- Geheugen ---
		$mem      = '-1' === $f['memory_limit'] ? PHP_INT_MAX : wp_convert_hr_to_bytes( $f['memory_limit'] );
		$want_min = $avada ? 256 * MB_IN_BYTES : 128 * MB_IN_BYTES;
		$want_req = $avada ? '512M' : '256M';
		$locked   = ! empty( $f['memory_locked'] );
		$status   = $mem >= $want_min ? 'ok' : ( $locked ? 'bad' : 'warn' );
		$detail   = $locked
			? 'Vastgezet door de host: WP_MEMORY_LIMIT' . ( $f['wp_memory_limit'] ? ' (' . $f['wp_memory_limit'] . ')' : '' ) . ', .user.ini en ini_set() hebben geen effect. Alleen de hostingpartij kan dit verhogen.'
			: 'Te verhogen via de PHP-instellingen van het hostingpaneel of WP_MEMORY_LIMIT in wp-config.php.';
		$rows[] = [ 'PHP-geheugen (memory_limit)', $status, $f['memory_limit'] . ( $locked ? ' (vastgezet)' : '' ), $avada ? '≥ 256M (Avada), liever 512M' : '≥ 128M', 'ok' === $status ? '' : $detail ];
		if ( 'ok' !== $status && $locked ) {
			$request[] = "memory_limit: {$f['memory_limit']} → {$want_req}";
		}

		// --- Opcache ---
		$o = $f['opcache'];
		if ( empty( $o['enabled'] ) ) {
			$rows[]    = [ 'Opcache', 'bad', 'uit', 'aan', 'Zonder opcache wordt alle PHP-code bij elk verzoek opnieuw omgezet: gemeten ±118 MB i.p.v. ±28 MB voor WordPress + Avada.' ];
			$request[] = 'opcache: aanzetten (opcache.enable=1)';
		} else {
			$probs = [];
			if ( $o['memory_consumption'] && $o['memory_consumption'] < 256 ) {
				$probs[]   = 'memory_consumption ' . $o['memory_consumption'] . ' MB';
				$request[] = "opcache.memory_consumption: {$o['memory_consumption']} → 512";
			}
			if ( $o['interned_strings_buffer'] && $o['interned_strings_buffer'] < 16 ) {
				$probs[]   = 'interned_strings_buffer ' . $o['interned_strings_buffer'] . ' MB';
				$request[] = "opcache.interned_strings_buffer: {$o['interned_strings_buffer']} → 32";
			}
			if ( $o['max_accelerated_files'] && $o['max_accelerated_files'] < 20000 ) {
				$probs[]   = 'max_accelerated_files ' . $o['max_accelerated_files'];
				$request[] = "opcache.max_accelerated_files: {$o['max_accelerated_files']} → 50000";
			}
			$st     = $o['status'];
			$status = $probs ? 'warn' : 'ok';
			$cur    = $o['memory_consumption'] . ' MB, strings ' . $o['interned_strings_buffer'] . ' MB, ' . $o['max_accelerated_files'] . ' bestanden';
			$det    = '';
			if ( is_array( $st ) ) {
				$cur .= ' — gebruikt ' . $st['used_mb'] . ' MB, vrij ' . $st['free_mb'] . ' MB, strings vrij ' . $st['interned_free'] . ' MB, ' . $st['scripts'] . ' scripts';
				if ( $st['cache_full'] || $st['interned_free'] <= 0 || $st['oom_restarts'] > 0 ) {
					$status = 'bad';
					$det   .= 'De opcache zit vol (of liep vol): nieuwe of bijgewerkte PHP-bestanden worden dan per verzoek opnieuw omgezet. ';
				}
			} else {
				$det .= 'Status niet uitleesbaar (opcache_get_status uitgeschakeld) — bekijk het blok "Zend OPcache" via phpinfo in het hostingpaneel. ';
			}
			if ( $probs ) {
				$det .= 'Op gedeelde hosting delen alle sites op dezelfde PHP-versie deze cache; één Avada-site gebruikt al ±60–90 MB en ±1.300–2.000 bestanden per paginaweergave. Te krap: ' . implode( ', ', $probs ) . '.';
			}
			$rows[] = [ 'Opcache', $status, $cur, '≥ 256 MB, strings ≥ 16 MB, ≥ 20.000 bestanden', trim( $det ) ];
		}

		// --- Limieten ---
		$met = (int) $f['max_execution_time'];
		$want_met = $avada ? 180 : 120;
		if ( $met > 0 && $met < 60 ) {
			$rows[]    = [ 'Maximale looptijd (max_execution_time)', 'warn', $met . ' s', '≥ ' . $want_met . ' s', 'Updates, imports en back-ups kunnen halverwege afbreken. set_time_limit() in wp-config werkt niet als de host dit vastzet.' ];
			$request[] = "max_execution_time: {$met} → {$want_met}";
		} else {
			$rows[] = [ 'Maximale looptijd (max_execution_time)', 'ok', 0 === $met ? 'onbeperkt' : $met . ' s', '≥ 60 s', '' ];
		}

		$miv = (int) $f['max_input_vars'];
		if ( $miv && $miv < 3000 ) {
			$rows[]    = [ 'max_input_vars', $avada ? 'warn' : 'info', (string) $miv, '≥ 3000', 'Bij het opslaan van grote menu\'s of thema-opties gaan anders stilletjes gegevens verloren. ini_set() werkt hier nooit (PHP_INI_PERDIR).' ];
			$request[] = "max_input_vars: {$miv} → 5000";
		} else {
			$rows[] = [ 'max_input_vars', 'ok', (string) $miv, '≥ 3000', '' ];
		}

		$up = min( wp_convert_hr_to_bytes( $f['upload_max'] ), wp_convert_hr_to_bytes( $f['post_max'] ) );
		if ( $up < 32 * MB_IN_BYTES ) {
			$rows[]    = [ 'Uploadgrootte', 'info', $f['upload_max'] . ' / post ' . $f['post_max'], '≥ 64M', 'Grotere pdf\'s of afbeeldingen kunnen niet worden geüpload.' ];
			$request[] = "upload_max_filesize en post_max_size: {$f['upload_max']} / {$f['post_max']} → 64M";
		} else {
			$rows[] = [ 'Uploadgrootte', 'ok', $f['upload_max'] . ' / post ' . $f['post_max'], '≥ 32M', '' ];
		}

		// --- Database ---
		$db = self::db_eval( $f['db_server'] );
		$rows[] = [ 'Database', $db['status'], $db['label'], 'MariaDB ≥ 10.11 of MySQL ≥ 8.4', $db['detail'] ];
		if ( 'ok' !== $db['status'] && $db['request'] ) {
			$request[] = $db['request'];
		}

		// --- Mail ---
		$mail = self::mail_eval( $f['mail'] );

		return [ 'rows' => $rows, 'mail' => $mail, 'request' => $request ];
	}

	/** Database-versie beoordelen op einddatum ondersteuning. */
	private static function db_eval( $info ) {
		$is_maria = false !== stripos( $info, 'mariadb' );
		$ver      = preg_match( '/(\d+\.\d+(?:\.\d+)?)/', (string) $info, $m ) ? $m[1] : '';
		// Sommige servers melden "5.5.5-10.3.35-MariaDB": pak dan het tweede nummer.
		if ( $is_maria && preg_match( '/5\.5\.5-(\d+\.\d+(?:\.\d+)?)/', (string) $info, $mm ) ) {
			$ver = $mm[1];
		}
		$label = ( $is_maria ? 'MariaDB ' : 'MySQL ' ) . ( $ver ? $ver : '?' );
		if ( '' === $ver ) {
			return [ 'status' => 'info', 'label' => $info, 'detail' => '', 'request' => '' ];
		}
		$eol = $is_maria
			? [ '10.3' => 'mei 2023', '10.4' => 'juni 2024', '10.5' => 'juni 2025', '10.6' => 'juli 2026' ]
			: [ '5.7' => 'oktober 2023', '8.0' => 'april 2026' ];
		$mm2 = implode( '.', array_slice( explode( '.', $ver ), 0, 2 ) );
		$old = $is_maria ? version_compare( $mm2, '10.11', '<' ) : version_compare( $mm2, '8.4', '<' );
		if ( ! $old ) {
			return [ 'status' => 'ok', 'label' => $label, 'detail' => '', 'request' => '' ];
		}
		$since = $eol[ $mm2 ] ?? 'enige tijd';
		return [
			'status'  => 'warn',
			'label'   => $label,
			'detail'  => "Deze versie krijgt sinds {$since} geen updates meer. Upgraden doet de hostingpartij.",
			'request' => "database: {$label} krijgt sinds {$since} geen updates meer — upgrade naar " . ( $is_maria ? 'MariaDB 10.11 of 11.4' : 'MySQL 8.4' ) . ' mogelijk?',
		];
	}

	/** Mailregels: SMTP, SPF, DMARC, afzender-correctie. */
	private static function mail_eval( array $m ) {
		$rows = [];
		$smtp = $m['smtp_plugin'];
		$m365 = (bool) array_filter( $m['mx'], static function ( $h ) {
			return false !== strpos( $h, 'outlook.com' );
		} );
		$own  = in_array( $m['domain'], $m['from_domains'], true );

		if ( ! $m['dns'] ) {
			return [ [ 'DNS-controle', 'info', 'niet beschikbaar', '—', 'dns_get_record() is niet beschikbaar op deze server.' ] ];
		}
		if ( preg_match( '/\.(local|test|localhost|invalid|example)$/', $m['domain'] ) ) {
			return [ [ 'Mail-domein', 'info', $m['domain'], '—', 'Lokale ontwikkelsite: DNS-controle (SPF/DMARC) overgeslagen.' ] ];
		}
		$rows[] = [ 'Mail-domein', 'info', $m['domain'], '—', $m['mx'] ? 'MX: ' . implode( ', ', $m['mx'] ) . ( $m365 ? ' (Microsoft 365)' : '' ) : 'Geen MX gevonden (lokale of nieuwe site?).' ];

		if ( $smtp ) {
			$rows[] = [ 'Verzending', 'ok', 'via ' . $smtp, 'SMTP/API', 'Mail gaat via een geauthenticeerde verbinding; controleer in de log van ' . $smtp . ' of het lukt.' ];
		} else {
			$rows[] = [ 'Verzending', 'info', 'PHP mail() van de webserver', 'SMTP/API of afzender-correctie', 'Geen SMTP-plugin actief: mail vertrekt vanaf de webserver zonder DKIM-handtekening.' ];
		}

		if ( '' === $m['spf'] ) {
			$rows[] = [ 'SPF', $smtp ? 'warn' : 'bad', 'geen', 'aanwezig', 'Zonder SPF belandt mail van dit domein snel in de spam. Toevoegen in de DNS.' ];
		} else {
			$cov = $m['spf_covers'];
			$rows[] = [
				'SPF',
				true === $cov || $smtp ? 'ok' : ( false === $cov ? 'warn' : 'info' ),
				$m['spf'],
				'webserver toegestaan',
				true === $cov
					? 'De webserver (' . implode( ', ', $m['server_ips'] ) . ') mag mailen namens ' . $m['domain'] . '.'
					: ( false === $cov ? 'De webserver (' . implode( ', ', $m['server_ips'] ) . ') staat niet in de SPF. Zonder SMTP-plugin is mail van de site dan "niet geautoriseerd".' : 'Niet volledig te bepalen (te veel of onbereikbare includes).' ),
			];
		}

		if ( '' === $m['dmarc'] ) {
			$rows[] = [ 'DMARC', 'warn', 'geen', 'p=none of strenger', 'Zonder DMARC weten ontvangers niet hoe ze vervalste mail moeten behandelen.' ];
		} else {
			$rows[] = [ 'DMARC', 'info', $m['dmarc'], '—', false !== stripos( $m['dmarc'], 'p=none' ) ? 'Alleen rapporteren: mislukte controles worden (nog) niet geweigerd.' : '' ];
		}

		if ( ! $smtp ) {
			$advise = true === $m['spf_covers'] && $own;
			$rows[] = [
				'Afzender-correctie',
				$m['envelope_fix'] ? 'ok' : ( $advise ? 'warn' : 'info' ),
				$m['envelope_fix'] ? 'aan' : 'uit',
				$advise ? 'aan' : '—',
				'Zet het onzichtbare afzenderadres (Return-Path) gelijk aan het From-adres op ' . $m['domain'] . '. Dan wordt SPF tegen het eigen domein gecontroleerd en klopt DMARC, in plaats van tegen een systeemadres van de server.'
					. ( $m365 && $own ? ' Belangrijk bij Microsoft 365: mail "van het eigen domein" die DMARC niet haalt, kan als vervalst in de ongewenste e-mail belanden — juist bij de eigen medewerkers.' : '' )
					. ( true !== $m['spf_covers'] ? ' Alleen zinvol als de webserver in de SPF staat.' : '' ),
				'envelope',
			];
		}
		return $rows;
	}

	/** Mailtekst voor de hostingpartij. */
	public static function host_mail( array $f, array $request ) {
		if ( ! $request ) {
			return '';
		}
		$domain = (string) ( $f['mail']['domain'] ?? self::site_domain() );
		$lines  = [];
		$extra  = [];
		foreach ( $request as $r ) {
			if ( 0 === strpos( $r, 'database:' ) ) {
				$extra[] = trim( substr( $r, 9 ) );
			} else {
				$lines[] = '- ' . $r;
			}
		}
		$txt  = "Onderwerp: Verzoek aanpassen serverinstellingen voor {$domain}\n\n";
		$txt .= "Beste hostingpartij,\n\n";
		$txt .= "Voor de website {$domain} lopen we tegen serverinstellingen aan die we niet zelf kunnen aanpassen";
		$txt .= ( ! empty( $f['memory_locked'] ) ? ' (verhogen via wp-config.php, .user.ini en ini_set() heeft geen effect)' : '' ) . ".\n";
		if ( $lines ) {
			$txt .= "Kunnen jullie deze voor dit domein aanpassen?\n\n" . implode( "\n", $lines ) . "\n";
		}
		if ( $extra ) {
			$txt .= "\nDaarnaast: " . implode( ' ', $extra ) . "\n";
		}
		$txt .= "\nDe opcache wordt op een gedeelde server door alle sites gebruikt; één WordPress-site met een uitgebreid thema laadt al zo'n 1.300–2.000 PHP-bestanden per paginaweergave. Loopt de cache vol (bijvoorbeeld na grote updates), dan wordt die code bij elk verzoek opnieuw omgezet en raakt het geheugen op.\n";
		$txt .= "\nMet vriendelijke groet,\n";
		return $txt;
	}

	/* ---------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------- */

	public function ajax_scan() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}
		$f   = self::facts();
		$ev  = self::evaluate( $f );
		wp_send_json_success( [
			'rows' => $ev['rows'],
			'mail' => $ev['mail'],
			'host_mail' => self::host_mail( $f, $ev['request'] ),
		] );
	}

	public function ajax_envelope() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}
		$on = ! empty( $_POST['on'] );
		update_option( self::ENVELOPE_OPT, $on ? 1 : 0, true );
		if ( class_exists( 'MCM_Database_Cleaner' ) && method_exists( 'MCM_Database_Cleaner', 'log_action' ) ) {
			MCM_Database_Cleaner::log_action( 'server:envelope_fix', [ 'on' => $on ] );
		}
		wp_send_json_success( [ 'on' => $on ] );
	}

	/* ---------------------------------------------------------------
	 * UI
	 * ------------------------------------------------------------- */

	public function render_card() {
		?>
		<div class="mcm-opt-card">
			<div class="mcm-opt-card-header">
				<span class="dashicons dashicons-cloud"></span>
				<h2>Server &amp; mail</h2>
				<button type="button" id="mcm-server-scan" class="button mcm-opt-btn-primary" style="margin-left:auto;">
					<span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
					Analyseren
				</button>
			</div>
			<div class="mcm-opt-card-body">
				<p class="description" style="margin-top:0;">
					Wat de hostingpartij bepaalt (PHP, geheugen, opcache, limieten, database) en of mail van de site goed
					aankomt (SPF, DMARC, afzender). Alleen een rapport — met een kant-en-klare mail voor de hostingpartij.
				</p>
				<div id="mcm-server-loading" style="display:none;">
					<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span> Bezig met analyseren...
				</div>
				<div id="mcm-server-results"></div>
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

	function row(r) {
		var h = '<div class="mcm-perf-rec"><div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">' + (icons[r[1]] || '') +
			'<strong>' + esc(r[0]) + '</strong><span style="color:#6b5d52;font-size:12px;">' + esc(r[2]) + (r[3] && r[3] !== '—' ? ' → ' + esc(r[3]) : '') + '</span>';
		if (r[5] === 'envelope') {
			var on = r[2] === 'aan';
			h += '<button class="button mcm-server-envelope" data-on="' + (on ? 0 : 1) + '" style="margin-left:auto;">' + (on ? 'Uitzetten' : 'Aanzetten') + '</button>';
		}
		h += '</div>';
		if (r[4]) { h += '<div style="font-size:12px;color:#6b5d52;margin:4px 0 0 26px;">' + esc(r[4]) + '</div>'; }
		return h + '</div>';
	}

	function scan() {
		var btn = $('#mcm-server-scan');
		btn.prop('disabled', true);
		$('#mcm-server-loading').show();
		$.post(mcmOptimizer.ajaxUrl, { action: 'mcm_server_scan', nonce: mcmOptimizer.nonce }, function(res) {
			btn.prop('disabled', false);
			$('#mcm-server-loading').hide();
			if (!res.success) { $('#mcm-server-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Analyse mislukt.</div>'); return; }
			var d = res.data, h = '<h3 style="margin:12px 0 6px;color:var(--mcm-brown);">Server</h3>';
			d.rows.forEach(function(r) { h += row(r); });
			h += '<h3 style="margin:16px 0 6px;color:var(--mcm-brown);">Mail</h3>';
			d.mail.forEach(function(r) { h += row(r); });
			if (d.host_mail) {
				h += '<h3 style="margin:16px 0 6px;color:var(--mcm-brown);">Mail voor de hostingpartij</h3>' +
					'<p class="description" style="margin:0 0 6px;">De mail vraagt bewust ruimer dan het minimum op de kaart (bv. opcache 512 MB i.p.v. ≥ 256 MB): de opcache wordt door alle sites op de server gedeeld, en zo hoef je niet na de volgende update opnieuw te mailen.</p>' +
					'<textarea id="mcm-server-hostmail" rows="14" style="width:100%;font-family:monospace;font-size:12px;">' + esc(d.host_mail) + '</textarea>' +
					'<p><button type="button" class="button" id="mcm-server-copy">Kopiëren</button></p>';
			} else {
				h += '<div class="mcm-opt-alert mcm-opt-alert-safe" style="margin-top:12px;"><span class="dashicons dashicons-yes-alt"></span> Niets om bij de hostingpartij aan te vragen.</div>';
			}
			$('#mcm-server-results').html(h);
		}).fail(function() {
			btn.prop('disabled', false);
			$('#mcm-server-loading').hide();
			$('#mcm-server-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Verbindingsfout.</div>');
		});
	}

	$('#mcm-server-scan').on('click', scan);

	$(document).on('click', '#mcm-server-copy', function() {
		var t = document.getElementById('mcm-server-hostmail');
		t.select();
		try { document.execCommand('copy'); $(this).text('Gekopieerd'); } catch (e) {}
	});

	$(document).on('click', '.mcm-server-envelope', function() {
		var btn = $(this), on = parseInt(btn.data('on'), 10) === 1;
		if (!confirm(on ? 'Afzender-correctie aanzetten? Mail van de site krijgt dan als onzichtbaar afzenderadres het From-adres op het eigen domein. Daarna een testmail sturen en de kopregels controleren (spf=pass, dmarc=pass).' : 'Afzender-correctie uitzetten?')) return;
		btn.prop('disabled', true);
		$.post(mcmOptimizer.ajaxUrl, { action: 'mcm_server_envelope', nonce: mcmOptimizer.nonce, on: on ? 1 : 0 }, function() { scan(); });
	});
});
JS;
	}
}

// Moet overal actief zijn (ook bij inzendingen via admin-ajax, cron en voorkant).
add_action( 'phpmailer_init', [ 'MCM_Server_Check', 'envelope_sender' ], 999 );

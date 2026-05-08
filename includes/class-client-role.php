<?php
/**
 * MCM Klant rol + backend opschoning.
 *
 * Registreert een custom WordPress rol "MCM Klant" met precies de rechten
 * die een klant nodig heeft om zelf content te beheren, zonder risico op
 * het per ongeluk slopen van thema, plugins, users of globale instellingen.
 *
 * Daarnaast wordt het WP Admin backend opgeschoond voor deze rol:
 * onnodige menus, dashboard widgets en admin bar items worden verborgen.
 *
 * Aan/uit te zetten via de optie 'mcm_client_role_enabled' (default: true).
 * De rol zelf wordt alleen bij plugin (de)activatie aan-/afgemaakt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Optimizer_Client_Role {

	/**
	 * Rol-slug zoals opgeslagen in wp_user_roles.
	 */
	const ROLE_SLUG = 'mcm_klant';

	/**
	 * Weergavenaam van de rol.
	 */
	const ROLE_NAME = 'MCM Klant';

	/**
	 * Init: registreer alle hooks direct.
	 *
	 * BELANGRIJK: admin_menu fires VÓÓR admin_init in WordPress. We mogen
	 * clean_admin_menu dus NIET via admin_init registreren — dan is het te
	 * laat. Elke callback checkt zelf of de huidige user een MCM Klant is.
	 */
	public static function init() {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'admin_menu',                 [ __CLASS__, 'clean_admin_menu' ], 99999 );
		add_action( 'wp_dashboard_setup',         [ __CLASS__, 'clean_dashboard' ], 99999 );
		add_action( 'wp_before_admin_bar_render', [ __CLASS__, 'clean_admin_bar' ], 99999 );
		add_filter( 'admin_footer_text',          [ __CLASS__, 'footer_text' ] );
		add_filter( 'update_footer',              [ __CLASS__, 'update_footer' ], 99999 );
		add_action( 'admin_head',                 [ __CLASS__, 'hide_update_notices' ] );
		add_action( 'admin_init',                 [ __CLASS__, 'remove_welcome_panel' ] );

		// Editor-detectie en omzet-melding (alleen voor admins).
		add_action( 'admin_notices',              [ __CLASS__, 'editor_conversion_notice' ] );
		add_action( 'wp_ajax_mcm_convert_editors',       [ __CLASS__, 'ajax_convert_editors' ] );
		add_action( 'wp_ajax_mcm_dismiss_editor_notice', [ __CLASS__, 'ajax_dismiss_notice' ] );

	}

	/**
	 * Verberg WP welcome panel voor MCM Klant.
	 */
	public static function remove_welcome_panel() {
		if ( ! self::current_user_is_client() ) {
			return;
		}
		remove_action( 'welcome_panel', 'wp_welcome_panel' );
	}

	/**
	 * Leeg de WP versie-footer voor MCM Klant (en laat 'm staan voor anderen).
	 */
	public static function update_footer( $content ) {
		if ( self::current_user_is_client() ) {
			return '';
		}
		return $content;
	}

	/**
	 * Is deze feature aangezet op deze site?
	 */
	public static function is_enabled() {
		return (bool) get_option( 'mcm_client_role_enabled', true );
	}

	/**
	 * Heeft de huidige user de MCM Klant rol?
	 */
	public static function current_user_is_client() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		return in_array( self::ROLE_SLUG, (array) $user->roles, true );
	}

	/* =======================================================================
	 * EDITOR-DETECTIE EN OMZETTING
	 * ======================================================================= */

	/**
	 * Toon admin-melding als er gebruikers met rol Editor bestaan
	 * die omgezet kunnen worden naar MCM Klant.
	 */
	public static function editor_conversion_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'mcm_editor_notice_dismissed' ) ) {
			return;
		}
		if ( ! get_role( self::ROLE_SLUG ) ) {
			return;
		}

		$editors = get_users( [ 'role' => 'editor' ] );
		if ( empty( $editors ) ) {
			return;
		}

		$nonce_convert = wp_create_nonce( 'mcm_convert' );
		$nonce_dismiss = wp_create_nonce( 'mcm_dismiss' );
		?>
		<div class="notice notice-info mcm-editor-notice" style="padding:15px;border-left-color:#c03a2b;">
			<h3 style="margin-top:0;">MCM Klant rol beschikbaar</h3>
			<p>De volgende gebruikers hebben de rol <strong>Editor</strong> en kunnen omgezet worden naar <strong>MCM Klant</strong> voor een veiliger en schoner dashboard:</p>
			<ul style="margin:0.5em 0 1em 1.5em;list-style:disc;">
				<?php foreach ( $editors as $user ) : ?>
					<li>
						<strong><?php echo esc_html( $user->display_name ); ?></strong>
						(<?php echo esc_html( $user->user_email ); ?>)
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<button class="button button-primary mcm-convert-btn">Omzetten naar MCM Klant</button>
				<button class="button mcm-dismiss-btn" style="margin-left:10px;">Niet meer tonen</button>
			</p>
			<script>
			jQuery(function($) {
				$('.mcm-convert-btn').on('click', function() {
					var $btn = $(this);
					$btn.prop('disabled', true).text('Bezig met omzetten...');
					$.post(ajaxurl, {
						action: 'mcm_convert_editors',
						_wpnonce: '<?php echo esc_js( $nonce_convert ); ?>'
					}, function(r) {
						if (r.success) {
							$('.mcm-editor-notice')
								.removeClass('notice-info').addClass('notice-success')
								.css('border-left-color', '#46b450')
								.html('<p><strong>\u2714 ' + r.data.count + ' gebruiker(s) omgezet naar MCM Klant.</strong> Ze zien het schone dashboard bij de volgende login.</p>');
						} else {
							$btn.prop('disabled', false).text('Omzetten naar MCM Klant');
							alert(r.data || 'Er ging iets mis.');
						}
					});
				});
				$('.mcm-dismiss-btn').on('click', function() {
					$.post(ajaxurl, {
						action: 'mcm_dismiss_editor_notice',
						_wpnonce: '<?php echo esc_js( $nonce_dismiss ); ?>'
					});
					$('.mcm-editor-notice').slideUp();
				});
			});
			</script>
		</div>
		<?php
	}

	/**
	 * AJAX: zet alle Editor-users om naar MCM Klant.
	 */
	public static function ajax_convert_editors() {
		check_ajax_referer( 'mcm_convert' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen rechten.' );
		}

		$editors = get_users( [ 'role' => 'editor' ] );
		$count   = 0;
		foreach ( $editors as $user ) {
			$user->set_role( self::ROLE_SLUG );
			$count++;
		}

		update_option( 'mcm_editor_notice_dismissed', true );
		wp_send_json_success( [ 'count' => $count ] );
	}

	/**
	 * AJAX: verberg de editor-melding permanent.
	 */
	public static function ajax_dismiss_notice() {
		check_ajax_referer( 'mcm_dismiss' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		update_option( 'mcm_editor_notice_dismissed', true );
		wp_send_json_success();
	}

	/* =======================================================================
	 * ROL REGISTRATIE — alleen aangeroepen op plugin activatie.
	 * ======================================================================= */

	/**
	 * Maak de rol aan (of werk capabilities bij als de rol al bestaat).
	 */
	public static function install_role() {
		// Start met de capabilities van een Editor als basis.
		$editor = get_role( 'editor' );
		if ( ! $editor ) {
			return false;
		}

		$caps = $editor->capabilities;

		// Capabilities die we NIET willen voor een klant.
		$remove = [
			'moderate_comments',  // Geen comment moderatie.
			'manage_categories',  // Geen taxonomie-rommel.
			'manage_links',       // Legacy.
			'unfiltered_html',    // Security: geen scripts in posts.
			'edit_theme_options', // Geen Appearance / Customizer / Avada Global Options.
		];
		foreach ( $remove as $cap ) {
			unset( $caps[ $cap ] );
		}

		// Capabilities die we expliciet wel willen voor Avada Portfolio.
		$add = [
			'read'                              => true,
			// Portfolio (avada_portfolio CPT).
			'edit_avada_portfolio'              => true,
			'edit_avada_portfolios'             => true,
			'edit_others_avada_portfolios'      => true,
			'edit_published_avada_portfolios'   => true,
			'publish_avada_portfolios'          => true,
			'delete_avada_portfolios'           => true,
			'delete_others_avada_portfolios'    => true,
			'delete_published_avada_portfolios' => true,
			'read_private_avada_portfolios'     => true,
		];
		$caps = array_merge( $caps, $add );

		// Verwijder eerst eventuele oude versie en herinstalleer schoon.
		remove_role( self::ROLE_SLUG );
		add_role( self::ROLE_SLUG, self::ROLE_NAME, $caps );

		return true;
	}

	/**
	 * Verwijder de rol (bij plugin deactivatie optioneel).
	 * Standaard laten we de rol staan zodat bestaande users hun rol behouden.
	 */
	public static function uninstall_role() {
		remove_role( self::ROLE_SLUG );
	}

	/* =======================================================================
	 * BACKEND OPSCHONING
	 * ======================================================================= */

	/**
	 * Verberg menu-items die een klant niet nodig heeft.
	 *
	 * Strategie:
	 * 1) remove_menu_page() voor nette verwijdering.
	 * 2) Direct $menu-array unsetten als belt-and-suspenders voor menus
	 *    die door plugins later dan onze priority geregistreerd worden.
	 * 3) Avada submenu: WHITELIST — alleen 'form'-gerelateerde items blijven.
	 */
	public static function clean_admin_menu() {
		if ( ! self::current_user_is_client() ) {
			return;
		}

		global $menu, $submenu;

		// 1. TOP-LEVEL menus die volledig weg moeten.
		$remove_top = [
			// WP core.
			'edit-comments.php',   // Reacties.
			'tools.php',           // Gereedschap.
			'themes.php',          // Weergave.
			'plugins.php',         // Plugins.
			'users.php',           // Gebruikers.
			'options-general.php', // Instellingen.
			'link-manager.php',    // Legacy Links.
			// Plugin menus die klant niet hoeft te zien.
			'wpvivid',
			'wpvivid_backup_restore_admin',
			'WPvivid',
			'itsec',
			'wp-migrate-db-pro',
			'wpvivid-imgoptim',
			'mcm-site-optimizer',
			'wpseo_dashboard', // Yoast SEO — mocht het ooit actief zijn.
			'rank-math',       // Rank Math.
		];
		foreach ( $remove_top as $slug ) {
			remove_menu_page( $slug );
		}

		// 2. Directe $menu manipulatie als fallback (laat ook 'tools.php' definitief weg).
		if ( is_array( $menu ) ) {
			foreach ( $menu as $key => $item ) {
				if ( ! isset( $item[2] ) ) {
					continue;
				}
				$slug = $item[2];
				if ( in_array( $slug, $remove_top, true ) ) {
					unset( $menu[ $key ] );
					continue;
				}
				// Defensief: Tools onder welke variant dan ook.
				if ( 'tools.php' === $slug || 0 === strpos( $slug, 'tools.php?' ) ) {
					unset( $menu[ $key ] );
				}
			}
		}

		// 3. AVADA/FUSION SUBMENU — WHITELIST: patroon-matching op parent key,
		// zodat we niet afhankelijk zijn van de exacte slug per Avada-versie.
		// Alleen submenu items met 'form' in hun slug blijven staan.
		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $parent_key => $items ) {
				if ( ! is_string( $parent_key ) ) {
					continue;
				}
				$is_avada = ( false !== stripos( $parent_key, 'avada' ) )
						 || ( false !== stripos( $parent_key, 'fusion' ) );
				if ( ! $is_avada ) {
					continue;
				}
				if ( ! is_array( $items ) ) {
					continue;
				}
				foreach ( $items as $key => $item ) {
					if ( ! isset( $item[2] ) ) {
						continue;
					}
					$slug = $item[2];
					if ( false === stripos( $slug, 'form' ) ) {
						unset( $submenu[ $parent_key ][ $key ] );
					}
				}
			}
		}
	}

	/**
	 * Ruim dashboard-widgets op en voeg een MCM welkomstblok toe.
	 *
	 * Strategie: loop door alle geregistreerde dashboard-widgets en verwijder
	 * alles behalve ons eigen mcm_welcome blok. Zo vangen we ook onbekende
	 * Avada/Fusion/Yoast/iThemes widgets die per versie kunnen veranderen.
	 */
	public static function clean_dashboard() {
		if ( ! self::current_user_is_client() ) {
			return;
		}

		global $wp_meta_boxes;

		if ( isset( $wp_meta_boxes['dashboard'] ) && is_array( $wp_meta_boxes['dashboard'] ) ) {
			foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
				if ( ! is_array( $priorities ) ) {
					continue;
				}
				foreach ( $priorities as $priority => $widgets ) {
					if ( ! is_array( $widgets ) ) {
						continue;
					}
					foreach ( $widgets as $widget_id => $widget ) {
						if ( 'mcm_welcome' === $widget_id ) {
							continue;
						}
						unset( $wp_meta_boxes['dashboard'][ $context ][ $priority ][ $widget_id ] );
					}
				}
			}
		}

		// Voeg MCM welkomst-widget toe in de brede (normal) kolom, bovenaan.
		wp_add_dashboard_widget(
			'mcm_welcome',
			'Welkom bij je website',
			[ __CLASS__, 'render_welcome_widget' ],
			null,
			null,
			'normal',
			'high'
		);
	}

	/**
	 * Inhoud van het MCM welkomst-widget — uitgebreid, gastvrij en gezellig.
	 */
	public static function render_welcome_widget() {
		$user        = wp_get_current_user();
		$first_name  = $user->first_name ? $user->first_name : $user->display_name;
		$site_name   = get_bloginfo( 'name' );
		$handleiding = home_url( '/handleiding/' );
		$mail        = 'marco@mcmwebsites.nl';
		$tel         = '06-28428785';
		$tel_link    = '+31628428785';
		?>
		<style>
			#mcm_welcome .inside { margin: 0; padding: 0; }
			#mcm_welcome .hndle { background: #fff; }
			.mcm-welcome { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #2c3338; }
			.mcm-welcome-hero {
				background: linear-gradient(135deg, #c03a2b 0%, #e55934 100%);
				color: #fff;
				padding: 26px 28px 24px;
			}
			.mcm-welcome-hero h2 {
				margin: 0 0 8px 0;
				font-size: 22px;
				line-height: 1.3;
				font-weight: 600;
				color: #fff;
				padding: 0;
			}
			.mcm-welcome-hero p {
				margin: 0;
				font-size: 14px;
				line-height: 1.5;
				color: rgba(255,255,255,0.94);
			}
			.mcm-welcome-hero strong { color: #fff; font-weight: 600; }
			.mcm-welcome-body { padding: 22px 24px 24px; background: #fff; }
			.mcm-section-title {
				margin: 0 0 12px 0;
				font-size: 12px;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: 0.6px;
				color: #646970;
				display: flex;
				align-items: center;
				gap: 8px;
			}
			.mcm-section-title .dashicons {
				color: #c03a2b;
				width: 18px;
				height: 18px;
				font-size: 18px;
			}
			.mcm-tiles {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
				gap: 10px;
				margin: 0 0 22px;
			}
			.mcm-tile {
				display: block;
				padding: 14px 14px 12px;
				background: #f6f7f7;
				border: 1px solid #e4e6e8;
				border-radius: 6px;
				text-decoration: none;
				color: #1d2327;
				transition: all 0.15s ease;
			}
			.mcm-tile:hover {
				background: #fff8f6;
				border-color: #c03a2b;
				transform: translateY(-1px);
				box-shadow: 0 3px 10px rgba(192, 58, 43, 0.1);
				color: #1d2327;
			}
			.mcm-tile .dashicons {
				display: block;
				color: #c03a2b;
				font-size: 26px;
				width: 26px;
				height: 26px;
				margin: 0 0 8px;
			}
			.mcm-tile strong {
				display: block;
				font-size: 13px;
				font-weight: 600;
				margin-bottom: 3px;
				color: #1d2327;
			}
			.mcm-tile .mcm-tile-sub {
				display: block;
				font-size: 11px;
				color: #646970;
				line-height: 1.45;
			}
			.mcm-info-blocks {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 12px;
				margin-bottom: 16px;
			}
			@media (max-width: 960px) {
				.mcm-info-blocks { grid-template-columns: 1fr; }
			}
			.mcm-info-block {
				padding: 14px 16px;
				border-radius: 6px;
				background: #fafafa;
				border-left: 3px solid #c03a2b;
			}
			.mcm-info-block h4 {
				margin: 0 0 6px 0;
				font-size: 13px;
				font-weight: 600;
				color: #1d2327;
				display: flex;
				align-items: center;
				gap: 6px;
			}
			.mcm-info-block h4 .dashicons {
				color: #c03a2b;
				font-size: 16px;
				width: 16px;
				height: 16px;
			}
			.mcm-info-block p {
				margin: 0;
				font-size: 12px;
				line-height: 1.55;
				color: #50575e;
			}
			.mcm-info-block a {
				color: #c03a2b;
				text-decoration: none;
				font-weight: 500;
			}
			.mcm-info-block a:hover { text-decoration: underline; }
			.mcm-tip {
				margin-top: 4px;
				padding: 12px 14px;
				background: #fff8e6;
				border-left: 3px solid #dba617;
				border-radius: 4px;
				font-size: 12px;
				color: #50575e;
				line-height: 1.55;
				display: flex;
				align-items: flex-start;
				gap: 10px;
			}
			.mcm-tip .dashicons {
				color: #dba617;
				font-size: 18px;
				width: 18px;
				height: 18px;
				flex-shrink: 0;
				margin-top: 1px;
			}
			.mcm-tip strong { color: #1d2327; font-weight: 600; }
			.mcm-tip em { font-style: normal; font-weight: 600; color: #1d2327; }
		</style>

		<div class="mcm-welcome">
			<div class="mcm-welcome-hero">
				<h2>Hallo <?php echo esc_html( $first_name ); ?>, fijn dat je er bent.</h2>
				<p>Dit is het beheer van <strong><?php echo esc_html( $site_name ); ?></strong>. Je bent hier veilig — ik heb alles zo ingericht dat je rustig kunt experimenteren zonder iets te kunnen slopen. Hieronder vind je de dingen die je het vaakst nodig hebt.</p>
			</div>

			<div class="mcm-welcome-body">
				<div class="mcm-info-blocks">
					<div class="mcm-info-block">
						<h4><span class="dashicons dashicons-book"></span> Eerste keer hier?</h4>
						<p>Lees de <a href="<?php echo esc_url( $handleiding ); ?>" target="_blank" rel="noopener">handleiding</a>. Daar leg ik rustig uit hoe je teksten aanpast, afbeeldingen vervangt en een nieuw bericht plaatst — geen technische kennis nodig. Alles in korte stapjes en met voorbeelden.</p>
					</div>
					<div class="mcm-info-block">
						<h4><span class="dashicons dashicons-phone"></span> Vastgelopen of een vraag?</h4>
						<p>Geen zorgen, ik help je graag. Bel <a href="tel:<?php echo esc_attr( $tel_link ); ?>"><?php echo esc_html( $tel ); ?></a> of mail <a href="mailto:<?php echo esc_attr( $mail ); ?>"><?php echo esc_html( $mail ); ?></a>. Ik ben bereikbaar op werkdagen van 09:00 tot 17:00.</p>
					</div>
				</div>

				<h3 class="mcm-section-title"><span class="dashicons dashicons-edit"></span> Snel aan de slag</h3>
				<div class="mcm-tiles">
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>" class="mcm-tile">
						<span class="dashicons dashicons-admin-page"></span>
						<strong>Pagina's</strong>
						<span class="mcm-tile-sub">Tekst en afbeeldingen aanpassen</span>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>" class="mcm-tile">
						<span class="dashicons dashicons-welcome-write-blog"></span>
						<strong>Nieuw bericht</strong>
						<span class="mcm-tile-sub">Nieuws of blog plaatsen</span>
					</a>
					<a href="<?php echo esc_url( $handleiding ); ?>" target="_blank" rel="noopener" class="mcm-tile">
						<span class="dashicons dashicons-book-alt"></span>
						<strong>Handleiding</strong>
						<span class="mcm-tile-sub">Stap-voor-stap uitleg</span>
					</a>
				</div>

				<div class="mcm-tip">
					<span class="dashicons dashicons-lightbulb"></span>
					<div>
						<strong>Wist je dat?</strong> Je kunt elke wijziging eerst bekijken met de <em>Voorvertoning</em>-knop voordat je op <em>Publiceren</em> klikt. Zo zie je precies hoe het er straks uit komt te zien. En mocht er toch iets niet kloppen: WordPress bewaart automatisch oude versies van je pagina's, dus je kunt altijd terug.
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Opschonen van de admin bar bovenaan.
	 */
	public static function clean_admin_bar() {
		if ( ! self::current_user_is_client() ) {
			return;
		}
		global $wp_admin_bar;

		$remove = [
			'wp-logo',          // WordPress logo.
			'comments',         // Comments bubble.
			'updates',          // Update notice.
			'customize',        // Customize link.
			'themes',           // Themes link.
			'wpseo-menu',       // Yoast menu als aanwezig.
			'fusion_menu',      // Avada admin bar.
			'tribe-events',     // Events Calendar als aanwezig.
		];
		foreach ( $remove as $id ) {
			$wp_admin_bar->remove_node( $id );
		}
	}

	/**
	 * Vervang WordPress footer tekst door MCM support-regel (alleen MCM Klant).
	 */
	public static function footer_text( $text = '' ) {
		if ( ! self::current_user_is_client() ) {
			return $text;
		}
		return 'Hulp nodig? Mail <a href="mailto:marco@mcmwebsites.nl">marco@mcmwebsites.nl</a> — MCM Websites';
	}

	/**
	 * Verberg update-notices in de admin voor deze rol.
	 */
	public static function hide_update_notices() {
		if ( ! self::current_user_is_client() ) {
			return;
		}
		echo '<style>.update-nag,.updated.notice,.notice-warning:not(.mcm-notice){display:none !important;}</style>';
	}
}

// Init op elke admin request.
MCM_Optimizer_Client_Role::init();

<?php
/**
 * MCM Token-link-guard.
 *
 * LAPMIDDEL — niet de oorzaak. De echte oorzaak ligt bij de hostingprovider
 * (Xel): hun Varnish geeft de cookie 'gflow_access_token' niet door aan de
 * server. Bewezen op stadsfondshilversum.nl (8 sep 2026): curl stuurt de
 * cookie aantoonbaar mee (geverifieerd met -v), maar PHP ziet
 * $_SERVER['HTTP_COOKIE'] leeg voor exact hetzelfde verzoek. Dat is geen
 * WordPress-, plugin- of Gravity Flow-probleem — die doen precies wat ze
 * moeten doen (token uitlezen → cookie zetten → doorsturen naar de kale URL).
 * Voor de blijvende oplossing: Xel vragen 'gflow_access_token' toe te voegen
 * aan de cookies die hun Varnish ongemoeid doorlaat (zoals al gebeurt voor
 * wordpress_logged_in_*).
 *
 * Tot die tijd: Gravity Flow's eigen gedrag (class-gravity-flow.php,
 * filter_wp() op de 'wp'-hook) haalt het token uit de URL, zet het als
 * cookie, en stuurt door naar de kale URL — die daarna afhankelijk is van
 * die cookie. Omdat de cookie niet aankomt, blijft de kale URL voor altijd
 * leeg. Deze klasse zet dat gedrag uit: het token blijft in de link staan
 * en wordt bij elk bezoek opnieuw uit de URL gelezen. Geen cookie nodig,
 * dus onafhankelijk van wat Varnish met cookies doet.
 *
 * Prijs: het token staat zichtbaar in de link i.p.v. verborgen in een
 * cookie. Geen extra risico t.o.v. voorheen — het stond toch al in de
 * gemailde link, en is per ontvanger en per pagina-scope beperkt en met
 * verloopdatum (zie Gravity_Flow::generate_access_token()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Token_Link_Guard {

	public static function init() {
		if ( ! apply_filters( 'mcm_optimizer_token_link_guard_enabled', true ) ) {
			return;
		}
		// Vóór Gravity_Flow::filter_wp() (die hangt op prioriteit 10 op 'wp').
		add_action( 'wp', [ __CLASS__, 'strip_gravityflow_redirect' ], 5 );
	}

	/**
	 * Haalt Gravity Flow's eigen 'wp'-hook weg zolang er een
	 * gflow_access_token in de URL staat, zodat de omzetting naar cookie +
	 * de daaropvolgende redirect niet plaatsvindt.
	 */
	public static function strip_gravityflow_redirect() {
		if ( empty( $_GET['gflow_access_token'] ) ) {
			return;
		}
		if ( ! function_exists( 'gravity_flow' ) ) {
			return;
		}

		$gf = gravity_flow();
		remove_action( 'wp', [ $gf, 'filter_wp' ] );
	}
}

MCM_Token_Link_Guard::init();

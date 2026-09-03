<?php
/**
 * MCM Cache-guard.
 *
 * Houdt de PAGINACACHE en de RESERVE-PROXY (Varnish) synchroon.
 *
 * Aanleiding — stadsfondshilversum.nl, 2 september 2026: WP Rocket had de
 * Gravity Flow-pagina's netjes in zijn "Nooit cachen"-lijst staan, maar Varnish
 * las die lijst niet en cachete ze alsnog. Gevolg: iedereen die op een
 * persoonlijke evaluatielink klikte, kreeg de opgeslagen kopie van iemand
 * anders te zien. Twee lijsten die handmatig gelijk gehouden moeten worden,
 * lopen vroeg of laat uit elkaar — meestal bij de eerstvolgende nieuwe pagina.
 *
 * Deze klasse leest WP Rocket's uitsluitingen en stuurt voor die URL's zelf
 * no-cache-headers. Varnish (en elke andere reverse proxy) luistert daarnaar,
 * dus je onderhoudt nog maar één lijst: die in de WP Rocket-interface.
 *
 * Werkt ook zonder WP Rocket — dan gebruik je alleen het eigen filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Cache_Guard {

	public static function init() {
		add_action( 'send_headers', [ __CLASS__, 'maybe_send_no_cache' ], 99 );
	}

	/**
	 * De URL-patronen die nooit gecachet mogen worden.
	 *
	 * Bron 1: WP Rocket's eigen "Nooit cachen"-lijst (cache_reject_uri).
	 * Bron 2: het filter 'mcm_optimizer_no_cache_uris', voor paden die je
	 *         wél buiten de proxy wilt houden maar niet in WP Rocket kwijt kunt.
	 */
	public static function patterns() {
		$patronen = [];

		$rocket = get_option( 'wp_rocket_settings', [] );
		if ( is_array( $rocket ) && ! empty( $rocket['cache_reject_uri'] ) && is_array( $rocket['cache_reject_uri'] ) ) {
			$patronen = $rocket['cache_reject_uri'];
		}

		return array_values( array_unique( (array) apply_filters( 'mcm_optimizer_no_cache_uris', $patronen ) ) );
	}

	/**
	 * Stuur no-cache-headers als de huidige URL op de lijst staat.
	 */
	public static function maybe_send_no_cache() {
		if ( is_admin() || wp_doing_ajax() || headers_sent() ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( '' === $uri ) {
			return;
		}

		if ( ! self::matches( $uri, self::patterns() ) ) {
			return;
		}

		nocache_headers();
		header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0', true );
		header( 'X-MCM-Cache-Guard: no-cache', true );
	}

	/**
	 * Past de URL op één van de patronen?
	 *
	 * WP Rocket's lijst bevat zowel kale paden ('/workflow-inbox/') als
	 * regex-achtige patronen ('/aanvraagformulier/(.*)/'). We proberen eerst
	 * een regex-match; is het patroon als regex ongeldig, dan vallen we terug
	 * op een gewone tekstvergelijking. Zo werkt beide soorten zonder dat je
	 * hoeft te weten welke je hebt ingevuld.
	 */
	public static function matches( $uri, $patronen ) {
		foreach ( (array) $patronen as $patroon ) {
			$patroon = trim( (string) $patroon );
			if ( '' === $patroon ) {
				continue;
			}

			$regex  = '@' . str_replace( '@', '\@', $patroon ) . '@i';
			$treffer = @preg_match( $regex, $uri );

			if ( 1 === $treffer ) {
				return true;
			}

			// Ongeldige regex → letterlijk vergelijken, zonder de regex-tekens.
			if ( false === $treffer ) {
				$kaal = str_replace( [ '(.*)', '.*', '\\' ], '', $patroon );
				if ( '' !== $kaal && false !== stripos( $uri, $kaal ) ) {
					return true;
				}
			}
		}

		return false;
	}
}

MCM_Cache_Guard::init();

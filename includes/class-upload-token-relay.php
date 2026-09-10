<?php
/**
 * MCM Upload-token-relay.
 *
 * Repareert bestandsuploads binnen een Gravity Flow-stap voor anonieme
 * token-bezoekers, wanneer class-token-link-guard.php actief is.
 *
 * Aanleiding — stadsfondshilversum.nl, 10 september 2026: een aanvrager kon
 * een evaluatieformulier met bijlage niet opslaan of versturen ("Your file
 * could not be opened" / "session has expired"). Reproductie op een lokale
 * kopie liet zien: Gravity Forms' eigen bestand-uploader (plupload) stuurt
 * zijn verzoek naar een LOSSE endpoint (?gf_page=...) met een vaste set
 * velden die NOOIT een gflow_access_token bevatten — die uploader vertrouwt
 * uitsluitend op de gflow_access_token-COOKIE om te weten wie er uploadt.
 *
 * Onze eigen token-link-guard (zie dat bestand) schakelt precies díe cookie
 * uit — nodig omdat Varnish 'm op live toch weggooit (zie de changelog van
 * 1.8.1). Zonder cookie kan Gravity Flow de bezoeker niet herkennen tijdens
 * de upload, faalt de nonce-controle, en meldt Gravity Forms "session has
 * expired" — ook al is er niets verlopen en komt het bestand gewoon aan.
 *
 * Deze klasse repareert dat gat: ze geeft de token mee als extra veld bij
 * de upload zelf (via het filter 'gform_plupload_settings', dat plupload's
 * eigen instellingen bepaalt), en herstelt 'm vlak vóór Gravity Forms de
 * nonce controleert (WordPress' 'wp'-hook, prioriteit vóór GFAsyncUpload's
 * prioriteit 9) tijdelijk in $_COOKIE — uitsluitend in het PHP-geheugen van
 * dát ene verzoek, nooit als een echte browsercookie. Daarmee blijft de
 * oplossing volledig buiten bereik van Varnish.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Upload_Token_Relay {

	const FIELD = 'mcm_gflow_token_relay';

	public static function init() {
		if ( ! function_exists( 'gravity_flow' ) ) {
			return;
		}
		add_filter( 'gform_plupload_settings', [ __CLASS__, 'inject_token_into_uploader' ], 10, 3 );
		// Prioriteit 5: moet vóór GFForms::maybe_process_form (prioriteit 9) draaien.
		add_action( 'wp', [ __CLASS__, 'relay_token_into_cookie' ], 5 );
	}

	/**
	 * Voegt de huidige token toe aan de multipart-parameters van elke
	 * bestand-uploadwidget op de pagina, zodat de browser 'm vanzelf
	 * meestuurt bij de losse ?gf_page=-uploadaanvraag.
	 */
	public static function inject_token_into_uploader( $plupload_init, $form_id, $field ) {
		if ( ! isset( $_COOKIE['gflow_access_token'] ) ) {
			$token = gravity_flow()->get_access_token();
			if ( ! empty( $token ) && isset( $plupload_init['multipart_params'] ) ) {
				$plupload_init['multipart_params'][ self::FIELD ] = $token;
			}
		}
		return $plupload_init;
	}

	/**
	 * Herstelt de token (indien meegestuurd) tijdelijk in $_COOKIE, zodat
	 * Gravity Flow's eigen bezoekersherkenning (die $_COOKIE als fallback
	 * gebruikt) de uploader correct herkent. Puur in-memory voor dit ene
	 * verzoek — er wordt geen browsercookie gezet.
	 *
	 * Bewust GEEN sanitize_text_field(): die strip de %3D-padding uit het
	 * base64-token (geverifieerd: 243 tekens werden er 240), waarna
	 * decode_access_token() het token niet meer kan lezen. De waarde wordt
	 * nooit uitgevoerd/getoond — alleen cryptografisch geverifieerd door
	 * Gravity Flow zelf — dus HTML-sanitizing is hier niet nodig.
	 */
	public static function relay_token_into_cookie( $wp ) {
		if ( isset( $_COOKIE['gflow_access_token'] ) || empty( $_REQUEST[ self::FIELD ] ) ) {
			return;
		}
		$_COOKIE['gflow_access_token'] = wp_unslash( $_REQUEST[ self::FIELD ] );
	}
}

MCM_Upload_Token_Relay::init();

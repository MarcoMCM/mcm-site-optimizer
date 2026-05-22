<?php
/**
 * Beeldoptimalisatie-advies: deze module optimaliseert zelf geen afbeeldingen
 * (dat is het werk van een gespecialiseerde plugin). Hij detecteert
 * conflicterende optimizers, bewaakt de juiste volgorde (eerst ongebruikte
 * media opruimen, dán optimaliseren) en geeft advies.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Image_Optim {

	public function __construct() {
		add_action( 'wp_ajax_mcm_imgopt_scan', [ $this, 'ajax_scan' ] );

		add_action( 'mcm_optimizer_render_cards', [ $this, 'render_card' ] );
		add_action( 'admin_enqueue_scripts',      [ $this, 'assets' ] );
	}

	/**
	 * Detecteert actieve image-optimizers (plugins die WebP/compressie doen).
	 */
	private static function detect_optimizers() {
		$active     = strtolower( implode( '|', (array) get_option( 'active_plugins', [] ) ) );
		$map        = [
			'imagify'             => 'Imagify',
			'ewww'                => 'EWWW Image Optimizer',
			'shortpixel'          => 'ShortPixel',
			'wpvivid-imgoptim'    => 'WPVivid Image Optimization',
			'optimole'            => 'Optimole',
			'webp-express'        => 'WebP Express',
			'converter-for-media' => 'Converter for Media',
		];
		$found = [];
		foreach ( $map as $slug => $name ) {
			if ( false !== strpos( $active, $slug ) ) {
				$found[] = $name;
			}
		}

		// Avada heeft een eigen ingebouwde beeldconversie.
		$theme    = wp_get_theme();
		$is_avada = in_array( 'Avada', [ $theme->get( 'Name' ), $theme->get_template() ], true );

		return [ 'plugins' => $found, 'avada' => $is_avada ];
	}

	private static function build_advice() {
		$opt    = self::detect_optimizers();
		$scan   = get_option( 'mcm_media_scan', [] );
		$orphans = isset( $scan['orphans'] ) ? (int) $scan['orphans'] : -1;

		$advice = [];

		// Conflict: meerdere optimizers.
		if ( count( $opt['plugins'] ) > 1 ) {
			$advice[] = [
				'type' => 'danger',
				'text' => 'Conflict: meerdere image-optimizers actief (' . esc_html( implode( ', ', $opt['plugins'] ) )
					. '). Laat er één optimaliseren — dubbel genereren geeft conflicten en dubbele bestanden.',
			];
		} elseif ( 1 === count( $opt['plugins'] ) ) {
			$advice[] = [
				'type' => 'safe',
				'text' => 'Eén optimizer actief (' . esc_html( $opt['plugins'][0] ) . ') — goed. Controleer wel of WebP daadwerkelijk aan bezoekers geserveerd wordt.',
			];
		} else {
			$advice[] = [
				'type' => 'warning',
				'text' => 'Geen image-optimizer actief. Overweeg er één (bv. WPVivid Image Optimization of Imagify) voor compressie + WebP.',
			];
		}

		// Avada-conversie waarschuwing.
		if ( $opt['avada'] ) {
			$advice[] = [
				'type' => 'warning',
				'text' => 'Avada heeft een ingebouwde "Convert Image Formats". Die vervángt originelen (onomkeerbaar). '
					. 'Een sidecar-optimizer (WPVivid/Imagify — origineel blijft) is veiliger. Gebruik niet beide.',
			];
		}

		// Volgorde-bewaking: eerst ongebruikte media opruimen.
		if ( $orphans > 0 ) {
			$advice[] = [
				'type' => 'warning',
				'text' => 'Er staan nog ' . number_format_i18n( $orphans ) . ' ongebruikte afbeeldingen. Ruim die eerst op '
					. 'via de module "Ongebruikte media" — anders optimaliseer je bestanden die je daarna weggooit.',
			];
		} elseif ( -1 === $orphans ) {
			$advice[] = [
				'type' => 'warning',
				'text' => 'Draai eerst een scan in "Ongebruikte media". Optimaliseer pas daarna — zo bewerk je geen weg-te-gooien afbeeldingen.',
			];
		} else {
			$advice[] = [
				'type' => 'safe',
				'text' => 'Geen ongebruikte afbeeldingen openstaand — je kunt veilig optimaliseren.',
			];
		}

		return [ 'optimizers' => $opt, 'orphans' => $orphans, 'advice' => $advice ];
	}

	public function ajax_scan() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}
		wp_send_json_success( self::build_advice() );
	}

	public function render_card() {
		?>
		<div class="mcm-opt-card">
			<div class="mcm-opt-card-header">
				<span class="dashicons dashicons-images-alt2"></span>
				<h2>Beeldoptimalisatie</h2>
				<button type="button" id="mcm-imgopt-scan" class="button mcm-opt-btn-primary" style="margin-left:auto;">
					<span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
					Controleren
				</button>
			</div>
			<div class="mcm-opt-card-body">
				<p class="description" style="margin-top:0;">
					Deze module optimaliseert zelf niets — dat is het werk van een gespecialiseerde plugin.
					Hij bewaakt dat je <strong>één</strong> optimizer gebruikt en in de juiste volgorde werkt
					(eerst ongebruikte media opruimen, dán optimaliseren).
				</p>
				<div id="mcm-imgopt-loading" style="display:none;">
					<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span> Bezig met controleren...
				</div>
				<div id="mcm-imgopt-results"></div>
			</div>
		</div>
		<?php
	}

	public function assets( $hook ) {
		if ( 'settings_page_mcm-tools' !== $hook ) {
			return;
		}
		wp_add_inline_script( 'jquery', $this->get_js() );
	}

	private function get_js() {
		return <<<'JS'
jQuery(document).ready(function($) {
	$('#mcm-imgopt-scan').on('click', function() {
		var btn = $(this);
		btn.prop('disabled', true);
		$('#mcm-imgopt-loading').show();
		$('#mcm-imgopt-results').html('');

		$.post(mcmOptimizer.ajaxUrl, { action: 'mcm_imgopt_scan', nonce: mcmOptimizer.nonce }, function(res) {
			btn.prop('disabled', false);
			$('#mcm-imgopt-loading').hide();
			if (!res.success) {
				$('#mcm-imgopt-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Controle mislukt.</div>');
				return;
			}
			var d = res.data, html = '';
			d.advice.forEach(function(a) {
				var cls = a.type === 'danger' ? 'mcm-opt-alert-danger'
					: (a.type === 'safe' ? 'mcm-opt-alert-safe' : 'mcm-opt-alert-warning');
				var icon = a.type === 'danger' ? 'warning' : (a.type === 'safe' ? 'yes-alt' : 'info');
				html += '<div class="mcm-opt-alert ' + cls + '" style="margin-bottom:8px;">' +
					'<span class="dashicons dashicons-' + icon + '"></span> ' + a.text + '</div>';
			});
			$('#mcm-imgopt-results').html(html);
		}).fail(function() {
			btn.prop('disabled', false);
			$('#mcm-imgopt-loading').hide();
			$('#mcm-imgopt-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Verbindingsfout.</div>');
		});
	});
});
JS;
	}
}

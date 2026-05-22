<?php
/**
 * Media Scanner: detecteert ongebruikte afbeeldingen in de mediabibliotheek
 * en verplaatst ze veilig (terugzetbaar) naar de prullenbak.
 *
 * Werkwijze: bouwt een referentie-index uit alle plekken waar een afbeelding
 * gebruikt kan worden (uitgelichte afbeelding, WooCommerce-galerij, WPClever
 * variatie-foto's, content-afbeeldingen via ID en URL, logo/site-icon/customizer).
 * Alles wat niet in die index zit is een wees. Verwijderen gebeurt via de
 * prullenbak (wp_trash_post) — bestanden blijven op schijf tot definitief wissen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Media_Scanner {

	/** Option waarin het laatste scanresultaat + wees-ID-lijst staat. */
	const SCAN_OPT = 'mcm_media_scan';

	/** Postmeta-markering op afbeeldingen die door deze tool zijn geprulld. */
	const TRASH_META = '_mcm_media_trashed';

	/** Aantal items per AJAX-batch. */
	const BATCH = 40;

	public function __construct() {
		add_action( 'wp_ajax_mcm_media_scan',    [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_mcm_media_trash',   [ $this, 'ajax_trash' ] );
		add_action( 'wp_ajax_mcm_media_restore', [ $this, 'ajax_restore' ] );
		add_action( 'wp_ajax_mcm_media_purge',   [ $this, 'ajax_purge' ] );

		add_action( 'mcm_optimizer_render_cards', [ $this, 'render_card' ] );
		add_action( 'admin_enqueue_scripts',      [ $this, 'assets' ] );
	}

	/* ---------------------------------------------------------------
	 * Referentie-index
	 * ------------------------------------------------------------- */

	/**
	 * Bouwt de verzameling van afbeeldingen die ergens gebruikt worden.
	 *
	 * @return array{ids: array<int,int>, files: array<string,int>}
	 */
	private static function build_reference_index() {
		global $wpdb;

		$ids   = [];
		$files = [];

		// Uitgelichte afbeeldingen (alle posttypes).
		foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value > 0" ) as $v ) {
			$ids[ (int) $v ] = 1;
		}

		// WPClever variatie-foto's.
		foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'woovr_image_id' AND meta_value > 0" ) as $v ) {
			$ids[ (int) $v ] = 1;
		}

		// WooCommerce product-galerijen (komma-gescheiden).
		foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value <> ''" ) as $g ) {
			foreach ( explode( ',', (string) $g ) as $x ) {
				$x = (int) trim( $x );
				if ( $x ) {
					$ids[ $x ] = 1;
				}
			}
		}

		// Logo, site-icon en numerieke customizer-waarden.
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( $logo ) {
			$ids[ $logo ] = 1;
		}
		$icon = (int) get_option( 'site_icon' );
		if ( $icon ) {
			$ids[ $icon ] = 1;
		}
		foreach ( (array) get_theme_mods() as $mv ) {
			if ( is_numeric( $mv ) ) {
				$ids[ (int) $mv ] = 1;
			}
		}

		// Afbeeldingen die in post-content staan: via wp-image-ID, via
		// shortcode-ID's, en via /uploads/-URL's (op bestandsnaam gematcht).
		$rows = $wpdb->get_col(
			"SELECT post_content FROM {$wpdb->posts}
			 WHERE post_content LIKE '%wp-image-%' OR post_content LIKE '%/uploads/%'"
		);
		foreach ( $rows as $c ) {
			$c = (string) $c;
			if ( preg_match_all( '/wp-image-(\d+)/', $c, $m ) ) {
				foreach ( $m[1] as $x ) {
					$ids[ (int) $x ] = 1;
				}
			}
			if ( preg_match_all( '/(?:image_id|attachment_id|ids)=["\']?([0-9|,\s]+)/', $c, $m ) ) {
				foreach ( $m[1] as $list ) {
					foreach ( preg_split( '/[|,\s]+/', $list ) as $x ) {
						$x = (int) $x;
						if ( $x ) {
							$ids[ $x ] = 1;
						}
					}
				}
			}
			if ( preg_match_all( '#/uploads/[^"\'\s)]+\.(?:jpe?g|png|gif|webp)#i', $c, $m ) ) {
				foreach ( $m[0] as $u ) {
					$fn = preg_replace( '/-\d+x\d+(?=\.[a-z]+$)/i', '', basename( $u ) );
					$fn = preg_replace( '/-scaled(?=\.[a-z]+$)/i', '', $fn );
					$files[ strtolower( $fn ) ] = 1;
				}
			}
		}

		return [ 'ids' => $ids, 'files' => $files ];
	}

	/**
	 * Snelle her-controle vlak voor het prullen: zit de afbeelding intussen
	 * tóch weer in een uitgelichte afbeelding, variatie-foto of galerij?
	 */
	private static function still_referenced( $id ) {
		global $wpdb;
		$id = (int) $id;

		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1", $id ) ) ) {
			return true;
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = 'woovr_image_id' AND meta_value = %d LIMIT 1", $id ) ) ) {
			return true;
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND FIND_IN_SET(%d, meta_value) LIMIT 1", $id ) ) ) {
			return true;
		}
		return false;
	}

	/** Aantal afbeeldingen dat nu door deze tool in de prullenbak staat. */
	private static function count_trashed() {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 WHERE p.post_type = 'attachment' AND p.post_status = 'trash'",
				self::TRASH_META
			)
		);
	}

	/* ---------------------------------------------------------------
	 * Scan
	 * ------------------------------------------------------------- */

	private static function scan() {
		global $wpdb;

		$atts = $wpdb->get_results(
			"SELECT p.ID, pm.meta_value AS file
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type LIKE 'image/%'
			   AND p.post_status != 'trash'"
		);

		$ref     = self::build_reference_index();
		$orphans = [];
		$buckets = [];

		foreach ( $atts as $a ) {
			$id   = (int) $a->ID;
			$file = (string) $a->file;
			$base = strtolower( basename( $file ) );

			if ( isset( $ref['ids'][ $id ] ) || ( '' !== $base && isset( $ref['files'][ $base ] ) ) ) {
				continue;
			}

			$orphans[] = $id;
			$seg = ( false !== strpos( $file, '/' ) ) ? substr( $file, 0, strpos( $file, '/' ) ) : '(root)';
			$buckets[ $seg ] = ( $buckets[ $seg ] ?? 0 ) + 1;
		}

		arsort( $buckets );

		$sample = [];
		foreach ( array_slice( $orphans, 0, 24 ) as $sid ) {
			$url = wp_get_attachment_image_url( $sid, 'thumbnail' );
			if ( ! $url ) {
				$url = wp_get_attachment_image_url( $sid, 'full' );
			}
			$sample[] = [ 'id' => $sid, 'url' => $url ?: '' ];
		}

		return [
			'time'       => current_time( 'mysql' ),
			'total'      => count( $atts ),
			'referenced' => count( $atts ) - count( $orphans ),
			'orphans'    => count( $orphans ),
			'orphan_ids' => $orphans,
			'buckets'    => $buckets,
			'sample'     => $sample,
		];
	}

	/* ---------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------- */

	public function ajax_scan() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		$data = self::scan();
		update_option( self::SCAN_OPT, $data, false );

		// Stuur niet de volledige ID-lijst mee terug (kan groot zijn).
		$response = $data;
		unset( $response['orphan_ids'] );
		$response['trashed'] = self::count_trashed();

		wp_send_json_success( $response );
	}

	public function ajax_trash() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		$scan = get_option( self::SCAN_OPT, [] );
		$ids  = ( isset( $scan['orphan_ids'] ) && is_array( $scan['orphan_ids'] ) ) ? $scan['orphan_ids'] : [];

		if ( empty( $ids ) ) {
			wp_send_json_success( [ 'done' => true, 'processed' => 0, 'remaining' => 0, 'trashed' => self::count_trashed() ] );
		}

		$batch     = array_splice( $ids, 0, self::BATCH );
		$processed = 0;

		foreach ( $batch as $id ) {
			$id  = (int) $id;
			$att = get_post( $id );
			if ( ! $att || 'attachment' !== $att->post_type ) {
				continue;
			}
			if ( self::still_referenced( $id ) ) {
				continue; // intussen tóch in gebruik — overslaan.
			}
			wp_trash_post( $id );
			update_post_meta( $id, self::TRASH_META, current_time( 'mysql' ) );
			$processed++;
		}

		$scan['orphan_ids'] = array_values( $ids );
		update_option( self::SCAN_OPT, $scan, false );

		wp_send_json_success( [
			'done'      => empty( $ids ),
			'processed' => $processed,
			'remaining' => count( $ids ),
			'trashed'   => self::count_trashed(),
		] );
	}

	public function ajax_restore() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 WHERE p.post_type = 'attachment' AND p.post_status = 'trash'
				 LIMIT %d",
				self::TRASH_META,
				self::BATCH
			)
		);

		$processed = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			wp_untrash_post( $id );
			delete_post_meta( $id, self::TRASH_META );
			$processed++;
		}

		$remaining = self::count_trashed();
		wp_send_json_success( [ 'done' => 0 === $remaining, 'processed' => $processed, 'remaining' => $remaining ] );
	}

	public function ajax_purge() {
		check_ajax_referer( 'mcm_optimizer_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Geen toegang.' );
		}

		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 WHERE p.post_type = 'attachment' AND p.post_status = 'trash'
				 LIMIT %d",
				self::TRASH_META,
				self::BATCH
			)
		);

		$processed = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_attachment( (int) $id, true ) ) {
				$processed++;
			}
		}

		$remaining = self::count_trashed();
		wp_send_json_success( [ 'done' => 0 === $remaining, 'processed' => $processed, 'remaining' => $remaining ] );
	}

	/* ---------------------------------------------------------------
	 * UI
	 * ------------------------------------------------------------- */

	public function render_card() {
		$trashed = self::count_trashed();
		?>
		<div class="mcm-opt-card">
			<div class="mcm-opt-card-header">
				<span class="dashicons dashicons-format-image"></span>
				<h2>Ongebruikte media</h2>
				<button type="button" id="mcm-media-scan" class="button mcm-opt-btn-primary" style="margin-left:auto;">
					<span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
					Scan Starten
				</button>
			</div>
			<div class="mcm-opt-card-body">
				<p class="description" style="margin-top:0;">
					Zoekt afbeeldingen die nergens gebruikt worden (uitgelichte afbeelding, WooCommerce-galerij,
					variatie-foto's, content, logo). Wees-afbeeldingen gaan naar de <strong>prullenbak</strong> —
					terugzetbaar; bestanden blijven op schijf tot je ze definitief verwijdert.
				</p>

				<div id="mcm-media-loading" style="display:none;">
					<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
					Bezig met scannen...
				</div>

				<div id="mcm-media-results"></div>

				<div id="mcm-media-progress" style="display:none;margin-top:14px;">
					<div style="background:#e2e4e7;border-radius:6px;height:22px;overflow:hidden;">
						<div id="mcm-media-bar" style="background:var(--mcm-primary);height:100%;width:0;transition:width .3s;"></div>
					</div>
					<p id="mcm-media-status" style="font-size:13px;margin:6px 0 0;"></p>
				</div>

				<div id="mcm-media-trash-box" style="<?php echo $trashed > 0 ? '' : 'display:none;'; ?>margin-top:16px;padding-top:14px;border-top:1px solid var(--mcm-border);">
					<strong>In prullenbak:</strong> <span id="mcm-media-trash-count"><?php echo (int) $trashed; ?></span> afbeeldingen.
					<div style="margin-top:8px;">
						<button type="button" id="mcm-media-restore" class="button">Alles terugzetten</button>
						<button type="button" id="mcm-media-purge" class="button mcm-opt-btn-clean" style="color:var(--mcm-terracotta);">Definitief verwijderen</button>
					</div>
				</div>
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

	function fmt(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }

	function setBar(done, total) {
		var pct = total > 0 ? Math.min(100, done / total * 100) : 100;
		$('#mcm-media-bar').css('width', pct.toFixed(1) + '%');
	}

	/* ---- Scan ---- */
	$('#mcm-media-scan').on('click', function() {
		var btn = $(this);
		btn.prop('disabled', true);
		$('#mcm-media-loading').show();
		$('#mcm-media-results').html('');
		$('#mcm-media-progress').hide();

		$.post(mcmOptimizer.ajaxUrl, {
			action: 'mcm_media_scan',
			nonce: mcmOptimizer.nonce
		}, function(res) {
			btn.prop('disabled', false);
			$('#mcm-media-loading').hide();

			if (!res.success) {
				$('#mcm-media-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Scan mislukt.</div>');
				return;
			}

			var d = res.data;
			var html = '';
			html += '<div class="mcm-opt-db-size">';
			html += 'Afbeeldingen: <strong>' + fmt(d.total) + '</strong> &middot; ';
			html += 'in gebruik: <strong>' + fmt(d.referenced) + '</strong> &middot; ';
			html += 'ongebruikt: <strong style="color:var(--mcm-terracotta);">' + fmt(d.orphans) + '</strong>';
			html += '</div>';

			if (d.orphans === 0) {
				html += '<div class="mcm-opt-alert mcm-opt-alert-safe"><span class="dashicons dashicons-yes-alt"></span> Geen ongebruikte afbeeldingen gevonden.</div>';
				$('#mcm-media-results').html(html);
				return;
			}

			var b = d.buckets || {};
			html += '<p style="font-size:13px;margin:4px 0 8px;"><strong>Ongebruikt per map:</strong> ';
			var parts = [];
			for (var k in b) { parts.push(k + ': ' + fmt(b[k])); }
			html += parts.join(' &middot; ') + '</p>';

			if (d.sample && d.sample.length) {
				html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0;">';
				for (var i = 0; i < d.sample.length; i++) {
					if (d.sample[i].url) {
						html += '<img src="' + d.sample[i].url + '" title="#' + d.sample[i].id + '" style="width:64px;height:64px;object-fit:cover;border:1px solid var(--mcm-border);border-radius:4px;">';
					}
				}
				html += '</div>';
				html += '<p class="description">Steekproef — controleer dat dit inderdaad ongebruikte afbeeldingen zijn.</p>';
			}

			html += '<div class="mcm-opt-bulk-actions" style="border-top:none;padding-top:8px;">';
			html += '<button type="button" id="mcm-media-trash" class="button mcm-opt-btn-primary mcm-opt-btn-large" data-total="' + d.orphans + '">';
			html += '<span class="dashicons dashicons-trash" style="vertical-align:middle;margin-top:-2px;"></span> ';
			html += fmt(d.orphans) + ' afbeeldingen naar prullenbak';
			html += '</button></div>';

			$('#mcm-media-results').html(html);
		}).fail(function() {
			btn.prop('disabled', false);
			$('#mcm-media-loading').hide();
			$('#mcm-media-results').html('<div class="mcm-opt-alert mcm-opt-alert-danger">Verbindingsfout.</div>');
		});
	});

	/* ---- Generieke batch-runner ---- */
	function runBatch(action, total, label, onDone) {
		$('#mcm-media-progress').show();
		var done = 0;

		function next() {
			$.post(mcmOptimizer.ajaxUrl, { action: action, nonce: mcmOptimizer.nonce }, function(res) {
				if (!res.success) {
					$('#mcm-media-status').html('<strong style="color:var(--mcm-terracotta);">Fout — ververs de pagina en probeer opnieuw.</strong>');
					return;
				}
				var d = res.data;
				done += d.processed;
				setBar(done, total);
				$('#mcm-media-status').text(label + ': ' + fmt(done) + ' verwerkt, ' + fmt(d.remaining) + ' te gaan.');
				if (typeof d.trashed !== 'undefined') {
					$('#mcm-media-trash-count').text(d.trashed);
				}
				if (d.done) {
					$('#mcm-media-status').html('<strong>Klaar — ' + fmt(done) + ' verwerkt.</strong>');
					if (onDone) { onDone(d); }
				} else {
					next();
				}
			}).fail(function() {
				$('#mcm-media-status').html('<strong style="color:var(--mcm-terracotta);">Verbindingsfout — probeer opnieuw.</strong>');
			});
		}
		next();
	}

	/* ---- Naar prullenbak ---- */
	$(document).on('click', '#mcm-media-trash', function() {
		var total = parseInt($(this).data('total'), 10) || 0;
		if (!confirm('Weet je zeker dat je ' + fmt(total) + ' ongebruikte afbeeldingen naar de prullenbak verplaatst?\n\nDit is terugzetbaar.')) {
			return;
		}
		$(this).prop('disabled', true);
		runBatch('mcm_media_trash', total, 'Naar prullenbak', function() {
			$('#mcm-media-trash-box').show();
		});
	});

	/* ---- Terugzetten ---- */
	$(document).on('click', '#mcm-media-restore', function() {
		var total = parseInt($('#mcm-media-trash-count').text(), 10) || 0;
		if (!confirm('Alle ' + fmt(total) + ' afbeeldingen uit de prullenbak terugzetten?')) { return; }
		$(this).prop('disabled', true);
		runBatch('mcm_media_restore', total, 'Terugzetten', function() {
			$('#mcm-media-restore, #mcm-media-purge').prop('disabled', true);
		});
	});

	/* ---- Definitief verwijderen ---- */
	$(document).on('click', '#mcm-media-purge', function() {
		var total = parseInt($('#mcm-media-trash-count').text(), 10) || 0;
		if (!confirm('LET OP: ' + fmt(total) + ' afbeeldingen definitief verwijderen (incl. bestanden op schijf)?\n\nDit kan NIET ongedaan worden gemaakt.')) {
			return;
		}
		$(this).prop('disabled', true);
		runBatch('mcm_media_purge', total, 'Definitief verwijderen', function() {
			$('#mcm-media-restore, #mcm-media-purge').prop('disabled', true);
		});
	});
});
JS;
	}
}

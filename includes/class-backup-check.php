<?php
/**
 * WPvivid Backup Pro detectie en trigger.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Backup_Check {

	/**
	 * Check of WPvivid Backup Pro actief is.
	 */
	public static function is_wpvivid_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// WPvivid Backup Pro
		if ( is_plugin_active( 'wpvivid-backup-pro/wpvivid-backup-pro.php' ) ) {
			return 'pro';
		}

		// WPvivid Backup (gratis)
		if ( is_plugin_active( 'wpvivid-backuprestore/wpvivid-backuprestore.php' ) ) {
			return 'free';
		}

		return false;
	}

	/**
	 * Haal laatste backup info op.
	 */
	public static function get_last_backup_info() {
		$status = self::is_wpvivid_active();

		if ( ! $status ) {
			return [
				'available' => false,
				'message'   => 'WPvivid Backup is niet geïnstalleerd of geactiveerd.',
			];
		}

		// Probeer de laatste backup tijd op te halen via WPvivid opties.
		$backup_list = get_option( 'wpvivid_backup_list', [] );

		if ( empty( $backup_list ) ) {
			return [
				'available' => true,
				'version'   => $status,
				'last'      => null,
				'message'   => 'WPvivid is actief maar er zijn nog geen backups gevonden.',
			];
		}

		// Zoek de meest recente backup.
		$latest_time = 0;
		foreach ( $backup_list as $backup ) {
			if ( isset( $backup['create_time'] ) && $backup['create_time'] > $latest_time ) {
				$latest_time = $backup['create_time'];
			}
		}

		$time_diff = time() - $latest_time;
		$hours_ago = round( $time_diff / 3600 );

		return [
			'available' => true,
			'version'   => $status,
			'last'      => $latest_time,
			'hours_ago' => $hours_ago,
			'message'   => sprintf(
				'Laatste backup: %s (%s uur geleden)',
				date_i18n( 'd-m-Y H:i', $latest_time ),
				$hours_ago
			),
		];
	}

	/**
	 * Check of het veilig is om door te gaan (backup recent genoeg).
	 */
	public static function is_safe_to_proceed() {
		$info = self::get_last_backup_info();

		if ( ! $info['available'] ) {
			return [
				'safe'    => false,
				'reason'  => 'no_wpvivid',
				'message' => 'WPvivid Backup is niet actief. Maak eerst een backup voordat je optimaliseert.',
			];
		}

		if ( null === $info['last'] ) {
			return [
				'safe'    => false,
				'reason'  => 'no_backup',
				'message' => 'Er is nog geen backup gemaakt. Maak eerst een backup via WPvivid.',
			];
		}

		// Waarschuw als backup ouder is dan 24 uur.
		if ( $info['hours_ago'] > 24 ) {
			return [
				'safe'    => true,
				'warning' => true,
				'reason'  => 'old_backup',
				'message' => sprintf(
					'Laatste backup is %s uur oud. Overweeg eerst een nieuwe backup te maken.',
					$info['hours_ago']
				),
			];
		}

		return [
			'safe'    => true,
			'warning' => false,
			'message' => $info['message'],
		];
	}
}

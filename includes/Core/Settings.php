<?php
/**
 * The plugin's one setting: the year the register summary covers.
 *
 * Until 2026-09-15 the settings form saved four keys and showed two. Every save replaced `sync_hinschg` and
 * `officer_email` (which no form contained) with defaults, stored any year and any threshold without the bounds the
 * HTML promised, and nothing read `high_risk_threshold`, `sync_hinschg` or `officer_email`. REST and WP-CLI used the
 * current calendar year while the admin tab used this setting. Now every caller reads the year from here and a save
 * keeps the keys it does not change.
 *
 * @package WMCyber\LkSG
 */

declare(strict_types=1);

namespace WMCyber\LkSG\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public const OPTION = 'wm_lksg_settings';

	/** Explicit opt-in option controlling whether uninstall.php drops tables and options. */
	public const DELETE_ON_UNINSTALL_OPTION = 'wm_lksg_delete_data_on_uninstall';

	/** Lowest year accepted; a sanity bound for the input, not a legal date. */
	public const MIN_YEAR = 2000;

	public static function max_year(): int {
		return (int) gmdate( 'Y' ) + 1;
	}

	public static function year_in_range( int $year ): bool {
		return $year >= self::MIN_YEAR && $year <= self::max_year();
	}

	/**
	 * The stored year, or the previous calendar year when none or an out-of-range value is stored.
	 */
	public static function reporting_year(): int {
		$stored = get_option( self::OPTION, [] );
		$year   = is_array( $stored ) && isset( $stored['reporting_year'] ) && is_numeric( $stored['reporting_year'] ) ? (int) $stored['reporting_year'] : 0;

		return self::year_in_range( $year ) ? $year : (int) gmdate( 'Y' ) - 1;
	}

	/**
	 * Store the year; keeps every other stored key. A value that is not a four-digit year in range changes nothing.
	 */
	public static function save_reporting_year( mixed $value ): bool {
		if ( ! is_scalar( $value ) || 1 !== preg_match( '/^\d{4}$/', trim( (string) $value ) ) || ! self::year_in_range( (int) $value ) ) {
			return false;
		}

		$stored                   = get_option( self::OPTION, [] );
		$stored                   = is_array( $stored ) ? $stored : [];
		$stored['reporting_year'] = (int) $value;
		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Register WordPress settings metadata for the explicit uninstall opt-in.
	 */
	public static function register(): void {
		if ( ! function_exists( 'register_setting' ) ) {
			return;
		}

		register_setting(
			'wm_lksg_settings_group',
			'wm_lksg_delete_data_on_uninstall',
			[
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_delete_flag' ],
				'default'           => '0',
			]
		);
	}

	public static function sanitize_delete_flag( mixed $value ): string {
		return in_array( $value, [ true, 1, '1', 'yes', 'true' ], true ) ? '1' : '0';
	}

	/**
	 * Whether deleting the plugin should drop tables and options (default false).
	 */
	public static function delete_data_on_uninstall(): bool {
		return '1' === self::sanitize_delete_flag( get_option( 'wm_lksg_delete_data_on_uninstall', '0' ) );
	}

	/**
	 * Store the explicit opt-in flag for uninstall data deletion.
	 */
	public static function save_delete_data_on_uninstall( bool $enabled ): void {
		update_option( 'wm_lksg_delete_data_on_uninstall', $enabled ? '1' : '0', false );
	}
}

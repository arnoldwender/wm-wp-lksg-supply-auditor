<?php
declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * WM Suite LkSG Supply Chain Auditor — Opt-in Uninstaller
 *
 * § 10 Abs. 1 LkSG requires due-diligence documentation to be retained for at least seven years.
 * By default, deleting the plugin preserves all supplier/assessment/self-disclosure tables and
 * options unless the site administrator explicitly enabled `wm_lksg_delete_data_on_uninstall`.
 *
 * @package WMCyber\LkSG
 */

$wm_lksg_delete_data = get_option( 'wm_lksg_delete_data_on_uninstall', '0' );
if ( ! in_array( $wm_lksg_delete_data, [ true, 1, '1', 'yes', 'true' ], true ) ) {
	return;
}

global $wpdb;

// 1. Options.
delete_option( 'wm_lksg_delete_data_on_uninstall' );
delete_site_option( 'wm_lksg_delete_data_on_uninstall' );
foreach ( [ 'wm_lksg_settings', 'wm_lksg_schema_version' ] as $wm_lksg_option ) {
	delete_option( $wm_lksg_option );
	delete_site_option( $wm_lksg_option );
}

// 2. Transients with the plugin prefix (values and timeouts).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( '_transient_wm_lksg_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wm_lksg_' ) . '%'
	)
);

// 3. Tables.
foreach ( [ 'corrective_actions', 'complaints', 'audits', 'risk_assessments', 'suppliers' ] as $wm_lksg_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wm_lksg_' . $wm_lksg_table ) );
}

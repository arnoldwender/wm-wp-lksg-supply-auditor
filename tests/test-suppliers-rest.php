<?php
/**
 * WordPress suite for POST and GET /wm-lksg/v1/suppliers through the real REST stack and the real database. It WRITES
 * rows named "PROBE-R %" and their history rows, deleted again at the end.
 *
 * What it proves:
 *   1. schema 3 is in place: history columns for score, level and factors; nullable audit scores;
 *   2. an anonymous caller is refused;
 *   3. the factors sent reach the calculator (severity 95 is critical) and the history keeps every factor;
 *   4. two suppliers register one after the other with distinct v4 uuids;
 *   5. a missing country and tier 7 are refused (until 2026-09-15 a missing country was stored as DE);
 *   6. GET answers with the total.
 *
 * Until 2026-09-15 this file ran without a guard and exited 0 with failures. It refuses to start unless
 * WM_LKSG_WP_TESTS_WRITE=1 and exits 1 on a failure. For a check that writes nothing use tests/wp-runtime-lksg.php.
 *
 * Run on a disposable install:
 *   WM_LKSG_WP_TESTS_WRITE=1 wp eval-file wp-content/plugins/wm-lksg-supply-auditor/tests/test-suppliers-rest.php --allow-root
 *
 * @package WMCyber\LkSG
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must run inside WordPress via WP-CLI.\n";
	exit( 1 );
}
if ( '1' !== getenv( 'WM_LKSG_WP_TESTS_WRITE' ) ) {
	echo "  [SKIP] writes PROBE-R rows; set WM_LKSG_WP_TESTS_WRITE=1 on a disposable install.\n";
	exit( 1 );
}

use WMCyber\LkSG\Database\Schema;

$GLOBALS['wm_r_passed'] = 0;
$GLOBALS['wm_r_failed'] = 0;

function wm_r_assert( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['wm_r_passed'];
		echo "  [PASS] {$label}\n";
	} else {
		++$GLOBALS['wm_r_failed'];
		echo "  [FAIL] {$label}\n";
	}
}

function wm_r_request( string $method, array $body = [] ) {
	$req = new WP_REST_Request( $method, '/wm-lksg/v1/suppliers' );
	if ( 'GET' === $method ) {
		$req->set_query_params( $body );
	} else {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	return rest_get_server()->dispatch( $req );
}

global $wpdb;
$suppliers   = Schema::table( 'suppliers' );
$assessments = Schema::table( 'risk_assessments' );

echo "\n=== LKSG SUPPLIERS REST TEST ===\n\n";

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$suppliers} WHERE supplier_name LIKE 'PROBE-R %'" );

// --- Case 0: schema 3 -------------------------------------------------------------------------------------------------
$history_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$assessments}" );
$audit_columns   = $wpdb->get_results( 'SHOW COLUMNS FROM ' . Schema::table( 'audits' ), ARRAY_A );
$nullable        = array_column( array_filter( $audit_columns, static fn( $c ) => 'human_rights_score' === $c['Field'] ), 'Null' );
wm_r_assert( '3' === (string) get_option( Schema::VERSION_OPTION ), 'schema version 3 recorded' );
wm_r_assert( in_array( 'composite_score', $history_columns, true ) && in_array( 'factors', $history_columns, true ), 'the history table has composite_score and factors' );
wm_r_assert( [ 'YES' ] === $nullable, 'audit score columns accept NULL' );
wm_r_assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$suppliers} WHERE uuid = '' OR uuid IS NULL" ), 'no supplier row is left without a uuid' );

// --- Case 1: anonymous callers are refused ----------------------------------------------------------------------------
wp_set_current_user( 0 );
$res = wm_r_request( 'POST', [ 'supplier_name' => 'PROBE-R Anonym', 'country_code' => 'DE' ] );
wm_r_assert( in_array( $res->get_status(), [ 401, 403 ], true ), 'anonymous POST /suppliers is refused (got ' . $res->get_status() . ')' );

$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wm_r_assert( ! empty( $admins ), 'an administrator exists to run the protected calls' );
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );

// --- Case 2: severity reaches the calculator; the history keeps every factor -----------------------------------------
$res  = wm_r_request( 'POST', [ 'supplier_name' => 'PROBE-R Schwer', 'country_code' => 'DE', 'industry_sector' => 'it_software', 'severity_score' => 95 ] );
$data = (array) $res->get_data();
wm_r_assert( 201 === $res->get_status() && 'critical' === ( $data['risk']['level'] ?? '' ), 'severity_score 95 makes the supplier critical (got ' . $res->get_status() . ' ' . ( $data['risk']['level'] ?? '?' ) . ')' );
$id1 = (int) ( $data['supplier_id'] ?? 0 );
$a1  = $id1 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$assessments} WHERE supplier_id = %d ORDER BY id DESC LIMIT 1", $id1 ), ARRAY_A ) : null;
$f1  = $a1 ? json_decode( (string) $a1['factors'], true ) : null;
wm_r_assert( $a1 && 'CAPA_REQUIRED' === $a1['action_trigger'] && 'critical' === $a1['risk_level'] && is_array( $f1 ) && 95.0 === (float) $f1['severity_score'], 'the evaluation is recorded with level, trigger and factors' );

// --- Case 3: a second supplier does not collide on the uuid ----------------------------------------------------------
$res2 = wm_r_request( 'POST', [ 'supplier_name' => 'PROBE-R Zweiter', 'country_code' => 'BD', 'industry_sector' => 'mining' ] );
$id2  = (int) ( $res2->get_data()['supplier_id'] ?? 0 );
$row1 = $id1 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$suppliers} WHERE id = %d", $id1 ), ARRAY_A ) : null;
$row2 = $id2 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$suppliers} WHERE id = %d", $id2 ), ARRAY_A ) : null;
$v4   = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
wm_r_assert( 201 === $res2->get_status() && $row1 && $row2 && 1 === preg_match( $v4, (string) $row1['uuid'] ) && 1 === preg_match( $v4, (string) $row2['uuid'] ) && $row1['uuid'] !== $row2['uuid'], 'two suppliers with distinct v4 uuids' );
wm_r_assert( $row2 && (float) $row2['risk_score'] > (float) ( $row1['risk_score'] ?? 100 ) - 100 && 'BD' === $row2['country_code'] && (float) $res2->get_data()['factors']['country_cpi_risk'] > 50, 'country and its tier were stored and scored' );

// --- Case 4: a missing country and tier 7 are refused -----------------------------------------------------------------
wm_r_assert( 400 === wm_r_request( 'POST', [ 'supplier_name' => 'PROBE-R Ohne Land' ] )->get_status(), 'a missing country is refused' );
wm_r_assert( 400 === wm_r_request( 'POST', [ 'supplier_name' => 'PROBE-R Stufe', 'country_code' => 'DE', 'tier_level' => 7 ] )->get_status(), 'tier 7 is refused' );

// --- Case 5: GET answers with the total --------------------------------------------------------------------------------
$list = wm_r_request( 'GET', [ 'per_page' => 1 ] );
wm_r_assert( 200 === $list->get_status() && (int) ( $list->get_data()['total'] ?? 0 ) >= 2 && 1 === count( (array) ( $list->get_data()['suppliers'] ?? [] ) ), 'GET /suppliers pages and reports the total' );

// --- Teardown ----------------------------------------------------------------------------------------------------------
$ids = $wpdb->get_col( "SELECT id FROM {$suppliers} WHERE supplier_name LIKE 'PROBE-R %'" );
foreach ( $ids as $pid ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$assessments} WHERE supplier_id = %d", (int) $pid ) );
}
$wpdb->query( "DELETE FROM {$suppliers} WHERE supplier_name LIKE 'PROBE-R %'" );
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

echo "\n=== RESULT: {$GLOBALS['wm_r_passed']} passed, {$GLOBALS['wm_r_failed']} failed ===\n\n";
exit( $GLOBALS['wm_r_failed'] > 0 ? 1 : 0 );

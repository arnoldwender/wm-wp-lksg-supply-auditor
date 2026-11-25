<?php
/**
 * WordPress suite for the supplier self-disclosure route. It WRITES: rows named "PROBE %" in the supplier and audit
 * tables and the rate-limit transient of the WP-CLI client address, all deleted again at the end.
 *
 * Measured on 2026-08-27: the portal form's submit handler called preventDefault(), waited 600 ms and showed a
 * success panel. No request left the page; a supplier affirming the absence of child and forced labour transmitted
 * nothing and was told the assessment had been filed with the BAFA.
 *
 * Until 2026-09-15 this file ran without a guard, deleted every self-disclosure rate-limit transient of the site (other
 * clients' counters included), flushed the whole object cache and exited 0 with failures. It now refuses to start
 * unless WM_LKSG_WP_TESTS_WRITE=1, touches only its own transient, and exits 1 on a failure. For a check that writes
 * nothing on a shared install use tests/wp-runtime-lksg.php (rolled back).
 *
 * Run on a disposable install:
 *   WM_LKSG_WP_TESTS_WRITE=1 wp eval-file wp-content/plugins/wm-lksg-supply-auditor/tests/test-self-disclosure.php --allow-root
 *   docker exec -e WM_LKSG_WP_TESTS_WRITE=1 the local WordPress container wp eval-file /var/www/html/wp-content/plugins/wm-lksg-supply-auditor/tests/test-self-disclosure.php --allow-root
 *
 * @package WMCyber\LkSG
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must run inside WordPress via WP-CLI.\n";
	exit( 1 );
}
if ( '1' !== getenv( 'WM_LKSG_WP_TESTS_WRITE' ) ) {
	echo "  [SKIP] writes PROBE rows and a transient; set WM_LKSG_WP_TESTS_WRITE=1 on a disposable install.\n";
	exit( 1 );
}

use WMCyber\LkSG\API\RestController;
use WMCyber\LkSG\Database\Schema;

// eval-file runs the file inside a function, so `global` would not bind for the helper below.
$GLOBALS['wm_s_passed'] = 0;
$GLOBALS['wm_s_failed'] = 0;

function wm_s_assert( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['wm_s_passed'];
		echo "  [PASS] {$label}\n";
	} else {
		++$GLOBALS['wm_s_failed'];
		echo "  [FAIL] {$label}\n";
	}
}

function wm_s_post( array $body ) {
	$req = new WP_REST_Request( 'POST', '/wm-lksg/v1/self-disclosure' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( $body ) );
	return rest_get_server()->dispatch( $req );
}

global $wpdb;
$suppliers = Schema::table( 'suppliers' );
$audits    = Schema::table( 'audits' );
// The handler keys the limit on REMOTE_ADDR, which WP-CLI does not set.
$bucket = RestController::rate_limit_key( (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
$rl_key = RestController::rest_rate_limit_key( (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );

echo "\n=== LKSG SELF-DISCLOSURE TEST ===\n\n";

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$suppliers} WHERE supplier_name LIKE 'PROBE %'" );
delete_transient( $bucket );
delete_transient( $rl_key );

$valid = [
	'supplier_name'        => 'PROBE Lieferant GmbH',
	'country_code'         => 'VN',
	'industry_sector'      => 'textiles',
	'tier_level'           => 2,
	'has_iso_14001'        => true,
	'has_sa_8000'          => false,
	'has_iso_45001'        => true,
	'declaration_accepted' => true,
];

// --- Case 1: the route exists --------------------------------------------------------------------------------------
wm_s_assert( isset( rest_get_server()->get_routes()['/wm-lksg/v1/self-disclosure'] ), 'the public self-disclosure route is registered' );

// --- Case 2: a valid anonymous submission is stored with its declared facts -----------------------------------------
wp_set_current_user( 0 );
$res  = wm_s_post( $valid );
$data = (array) $res->get_data();
wm_s_assert( 201 === $res->get_status(), 'an anonymous supplier can submit (got ' . $res->get_status() . ')' );
wm_s_assert( ! isset( $data['supplier_id'] ) && 36 === strlen( (string) ( $data['reference'] ?? '' ) ), 'the answer carries a uuid reference, not the row id' );

$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$suppliers} WHERE uuid = %s", (string) ( $data['reference'] ?? '' ) ), ARRAY_A );
$audit = $row ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$audits} WHERE supplier_id = %d", (int) $row['id'] ), ARRAY_A ) : null;
wm_s_assert( null !== $row && 'VN' === $row['country_code'] && 'unassessed' === $row['risk_level'] && 'self_disclosed' === $row['audit_status'], 'stored as an unassessed self-disclosure' );
wm_s_assert( null !== $audit && '1' === (string) $audit['declared_iso_14001'] && '0' === (string) $audit['declared_sa_8000'] && null === $audit['human_rights_score'], 'the audit row keeps the declared certifications and NULL scores' );
wm_s_assert( null !== $audit && true === RestController::verify_signature( $audit ), 'the stored signature verifies against the stored row' );

// --- Case 3: without an affirmed declaration nothing is stored ------------------------------------------------------
$no_decl                  = $valid;
$no_decl['supplier_name'] = 'PROBE Ohne Erklärung';
unset( $no_decl['declaration_accepted'] );
wm_s_assert( 400 === wm_s_post( $no_decl )->get_status(), 'a submission without the declaration is refused' );
$no_decl['declaration_accepted'] = false;
wm_s_assert( 400 === wm_s_post( $no_decl )->get_status(), 'a submission with declaration_accepted false is refused' );

// --- Case 4: a client cannot assert its own risk score --------------------------------------------------------------
$forged = $valid + [ 'risk_score' => 1.0, 'risk_level' => 'low' ];
$forged['supplier_name'] = 'PROBE Selbstbewertet';
$fres = wm_s_post( $forged );
$frow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$suppliers} WHERE uuid = %s", (string) ( $fres->get_data()['reference'] ?? '' ) ), ARRAY_A );
wm_s_assert( null !== $frow && 'unassessed' === $frow['risk_level'] && 0.0 === (float) $frow['risk_score'], 'client-supplied risk fields are ignored' );

// --- Case 5: invalid countries are refused --------------------------------------------------------------------------
foreach ( [ 'DEUTSCHLAND', 'XX' ] as $bad_country ) {
	$bad = [ 'supplier_name' => 'PROBE Land ' . $bad_country, 'country_code' => $bad_country ] + $valid;
	wm_s_assert( 400 === wm_s_post( $bad )->get_status(), "country {$bad_country} is refused" );
}

// --- Case 6: the portal renders its own form without inline script ---------------------------------------------------
$html = do_shortcode( '[wm_lksg_supplier_portal]' );
wm_s_assert( str_contains( $html, 'data-wm-lksg-portal' ) && ! str_contains( $html, '<script' ) && str_contains( $html, 'name="declaration_accepted"' ), 'the portal renders a form with a declaration checkbox and no inline script' );

// --- Teardown -------------------------------------------------------------------------------------------------------
$ids = $wpdb->get_col( "SELECT id FROM {$suppliers} WHERE supplier_name LIKE 'PROBE %'" );
foreach ( $ids as $pid ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$audits} WHERE supplier_id = %d", (int) $pid ) );
}
$wpdb->query( "DELETE FROM {$suppliers} WHERE supplier_name LIKE 'PROBE %'" );
delete_transient( $bucket );
delete_transient( $rl_key );
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

echo "\n=== RESULT: {$GLOBALS['wm_s_passed']} passed, {$GLOBALS['wm_s_failed']} failed ===\n\n";
exit( $GLOBALS['wm_s_failed'] > 0 ? 1 : 0 );

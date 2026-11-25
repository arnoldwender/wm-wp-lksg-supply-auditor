<?php
/**
 * Runtime check of wm-lksg-supply-auditor inside a real WordPress, rolled back.
 *
 * It calls the REST routes through rest_do_request() (so core's argument validation and permission callbacks run),
 * registers a real supplier with its history row, files a real self-disclosure and checks its signature, reads the
 * yearly summary, runs the hub adapter, saves the setting and renders the admin screen and the portal. Everything runs
 * inside a transaction that is rolled back when PHP shuts down: transaction statements of other plugins become
 * savepoints, DDL is refused, a persistent object cache is refused. After the rollback the counts of the five tables,
 * the settings option and the plugin transients are read again with SQL and compared with the values from before; a
 * difference exits 1. Auto-increment counters are not transactional.
 *
 *   wp eval-file wp-content/plugins/wm-lksg-supply-auditor/tests/wp-runtime-lksg.php --user=1
 *   ssh <box> 'wp --path=<www> eval-file - --user=1' < tests/wp-runtime-lksg.php
 *
 * `wp eval-file` runs the file inside a method: state lives in $GLOBALS.
 *
 * @package WMCyber\LkSG
 */

defined( 'ABSPATH' ) || exit;

if ( wp_using_ext_object_cache() ) {
	WP_CLI::error( 'A persistent object cache is active; the rollback would leave cached options behind. Not run.' );
}
if ( ! class_exists( 'WMCyber\LkSG\Database\Register' ) ) {
	WP_CLI::error( 'wm-lksg-supply-auditor (with Database\Register) is not loaded.' );
}
if ( ! current_user_can( 'manage_options' ) ) {
	WP_CLI::error( 'Run with --user=<an administrator>.' );
}

global $wpdb;
$GLOBALS['lkrt']       = [ 'pass' => 0, 'fail' => 0 ];
$GLOBALS['lkrt_check'] = static function ( bool $ok, string $label ): void {
	$GLOBALS['lkrt'][ $ok ? 'pass' : 'fail' ]++;
	echo ( $ok ? '  [PASS] ' : '  [FAIL] ' ) . $label . "\n";
};
$GLOBALS['lkrt_state'] = static function (): array {
	global $wpdb;
	$state = [];
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	foreach ( WMCyber\LkSG\Database\Schema::TABLES as $name ) {
		$table          = WMCyber\LkSG\Database\Schema::table( $name );
		$exists         = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		$state[ $name ] = $exists ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) : null;
	}
	$state['settings']   = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'wm_lksg_settings' ) );
	$state['transients'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $wpdb->options, '%' . $wpdb->esc_like( 'transient_wm_lksg_' ) . '%' ) );
	// phpcs:enable
	return $state;
};
$GLOBALS['lkrt_before'] = $GLOBALS['lkrt_state']();

// --- Transaction with savepoint rewrite ----------------------------------------------------------------------------
$GLOBALS['lkrt_depth']   = 0;
$GLOBALS['lkrt_rewrite'] = static function ( $query ) {
	$q = ltrim( (string) $query );
	if ( preg_match( '/^(START\s+TRANSACTION|BEGIN)\b/i', $q ) ) {
		++$GLOBALS['lkrt_depth'];
		return 'SAVEPOINT lkrt_' . $GLOBALS['lkrt_depth'];
	}
	if ( preg_match( '/^COMMIT\b/i', $q ) ) {
		return $GLOBALS['lkrt_depth'] > 0 ? 'RELEASE SAVEPOINT lkrt_' . $GLOBALS['lkrt_depth']-- : 'SELECT 1';
	}
	if ( preg_match( '/^ROLLBACK\b/i', $q ) ) {
		return $GLOBALS['lkrt_depth'] > 0 ? 'ROLLBACK TO SAVEPOINT lkrt_' . $GLOBALS['lkrt_depth']-- : 'SELECT 1';
	}
	return preg_match( '/^(ALTER|CREATE|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES|SET\s+autocommit)\b/i', $q ) ? '' : $query;
};
$wpdb->query( 'START TRANSACTION' );
add_filter( 'query', $GLOBALS['lkrt_rewrite'] );
register_shutdown_function(
	static function (): void {
		global $wpdb;
		remove_filter( 'query', $GLOBALS['lkrt_rewrite'] );
		$wpdb->query( 'ROLLBACK' );
		$after = $GLOBALS['lkrt_state']();
		$same  = $after === $GLOBALS['lkrt_before'];
		echo "\n  ROLLBACK CHECK: " . ( $same ? 'table counts, settings and transients equal to before' : 'DIFFERENT ' . wp_json_encode( [ 'before' => $GLOBALS['lkrt_before'], 'after' => $after ] ) ) . "\n";
		echo "  RUNTIME RESULTS: {$GLOBALS['lkrt']['pass']} PASSED | {$GLOBALS['lkrt']['fail']} FAILED\n";
		exit( $same && 0 === $GLOBALS['lkrt']['fail'] ? 0 : 1 );
	}
);

$check = $GLOBALS['lkrt_check'];
$call  = static function ( string $method, string $route, array $params = [] ): WP_REST_Response {
	$request = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );
	}
	return rest_ensure_response( rest_do_request( $request ) );
};

echo "\n=== WM LKSG SUPPLY AUDITOR · RUNTIME (rolled back) ===\n";
echo '  [INFO] before: ' . wp_json_encode( $GLOBALS['lkrt_before'] ) . "\n";

$routes = rest_get_server()->get_routes();
$check( isset( $routes['/wm-lksg/v1/suppliers'], $routes['/wm-lksg/v1/self-disclosure'], $routes['/wm-lksg/v1/bafa-report'] ), 'routes registered' );

$audit_columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', WMCyber\LkSG\Database\Schema::table( 'audits' ) ), ARRAY_A );
$nullable      = array_column( array_filter( $audit_columns, static fn( $c ) => 'human_rights_score' === $c['Field'] ), 'Null' );
$history_cols  = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', WMCyber\LkSG\Database\Schema::table( 'risk_assessments' ) ) );
$check( '3' === (string) get_option( 'wm_lksg_schema_version' ) && [ 'YES' ] === $nullable && in_array( 'factors', $history_cols, true ), 'schema 3 applied on this install (nullable audit scores, factors in the history)' );

$r = $call( 'POST', '/wm-lksg/v1/suppliers', [ 'supplier_name' => 'RUNTIME ohne Land' ] );
$check( 400 === $r->get_status(), 'POST /suppliers without country_code -> 400 (was: stored as DE, score 39.00)' );

$r1 = $call( 'POST', '/wm-lksg/v1/suppliers', [ 'supplier_name' => 'RUNTIME XX', 'country_code' => 'XX' ] );
$r2 = $call( 'POST', '/wm-lksg/v1/suppliers', [ 'supplier_name' => 'RUNTIME Germany', 'country_code' => 'Germany' ] );
$r3 = $call( 'POST', '/wm-lksg/v1/suppliers', [ 'supplier_name' => 'RUNTIME Stufe', 'country_code' => 'DE', 'tier_level' => 7 ] );
$check( 400 === $r1->get_status() && 400 === $r2->get_status() && 400 === $r3->get_status(), 'country XX, country "Germany" and tier 7 -> 400' );

$r    = $call( 'POST', '/wm-lksg/v1/suppliers', [ 'supplier_name' => 'RUNTIME Bergbau BD', 'country_code' => 'BD', 'industry_sector' => 'mining', 'severity_score' => 60 ] );
$data = (array) $r->get_data();
$sid  = (int) ( $data['supplier_id'] ?? 0 );
$hist = $sid ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE supplier_id = %d', WMCyber\LkSG\Database\Schema::table( 'risk_assessments' ), $sid ), ARRAY_A ) : null;
$fact = $hist ? json_decode( (string) $hist['factors'], true ) : null;
$check( 201 === $r->get_status() && $hist && abs( (float) $hist['composite_score'] - (float) $data['risk']['score'] ) < 0.01 && $data['risk']['level'] === $hist['risk_level'] && is_array( $fact ) && 9 === count( $fact ), 'POST /suppliers BD/mining -> 201; history row with composite score ' . ( $data['risk']['score'] ?? '?' ) . ', level and 9 factors' );

$r = $call( 'GET', '/wm-lksg/v1/suppliers', [ 'per_page' => 1 ] );
$check( 200 === $r->get_status() && (int) ( $r->get_data()['total'] ?? 0 ) >= 1 && 1 === count( (array) ( $r->get_data()['suppliers'] ?? [] ) ) && isset( $r->get_headers()['X-WP-Total'] ), 'GET /suppliers pages with total ' . ( $r->get_data()['total'] ?? '?' ) . ' and X-WP-Total' );

$uid = get_current_user_id();
wp_set_current_user( 0 );
delete_transient( WMCyber\LkSG\API\RestController::rate_limit_key( (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) ) );
$base = [ 'supplier_name' => 'RUNTIME Lieferant GmbH', 'country_code' => 'VN', 'industry_sector' => 'textiles', 'tier_level' => 2, 'has_iso_14001' => true, 'declaration_accepted' => true ];
$bad  = $call( 'POST', '/wm-lksg/v1/self-disclosure', [ 'country_code' => 'XX' ] + $base );
$nod  = $call( 'POST', '/wm-lksg/v1/self-disclosure', [ 'declaration_accepted' => false ] + $base );
$ok   = $call( 'POST', '/wm-lksg/v1/self-disclosure', $base );
wp_set_current_user( $uid );
$ref   = (string) ( $ok->get_data()['reference'] ?? '' );
$srow  = '' !== $ref ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s', WMCyber\LkSG\Database\Schema::table( 'suppliers' ), $ref ), ARRAY_A ) : null;
$audit = $srow ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE supplier_id = %d', WMCyber\LkSG\Database\Schema::table( 'audits' ), (int) $srow['id'] ), ARRAY_A ) : null;
$check( 400 === $bad->get_status() && 400 === $nod->get_status(), 'anonymous self-disclosure with XX or an unticked declaration -> 400 (was: XX stored)' );
$check( 201 === $ok->get_status() && ! isset( $ok->get_data()['supplier_id'] ) && $srow && 'unassessed' === $srow['risk_level'], 'anonymous self-disclosure -> 201 with a uuid reference, stored unassessed' );
$check( $audit && true === WMCyber\LkSG\API\RestController::verify_signature( $audit ) && null === $audit['human_rights_score'] && '1' === (string) $audit['declared_iso_14001'], 'the audit row stores the declared facts, NULL scores, and its signature verifies' );

$r      = $call( 'GET', '/wm-lksg/v1/bafa-report', [ 'year' => (int) gmdate( 'Y' ) ] );
$report = (array) ( $r->get_data()['report'] ?? [] );
$trues  = 0;
array_walk_recursive( $report, static function ( $v ) use ( &$trues ): void {
	$trues += true === $v ? 1 : 0;
} );
$check( 200 === $r->get_status() && 'wm_lksg_register_summary' === ( $report['report_type'] ?? '' ) && 0 === $trues && (int) gmdate( 'Y' ) === ( $report['reporting_year'] ?? 0 ) && ( $report['register_at_period_end']['suppliers'] ?? 0 ) >= 2, 'GET /bafa-report -> register summary for ' . gmdate( 'Y' ) . ' without any true (was: five fixed statements)' );
$check( 400 === $call( 'GET', '/wm-lksg/v1/bafa-report', [ 'year' => 1999 ] )->get_status(), 'GET /bafa-report?year=1999 -> 400' );

if ( class_exists( 'WMCyber\LkSG\Adapters\Lksg_Spoke_Adapter' ) ) {
	$adapter = new WMCyber\LkSG\Adapters\Lksg_Spoke_Adapter();
	$e0      = $adapter->execute_action( 'risk_eval', [ 'severity_score' => 0 ] )['result']['score'] ?? null;
	$e100    = $adapter->execute_action( 'risk_eval', [ 'severity_score' => 100 ] )['result']['score'] ?? null;
	$tele    = $adapter->get_telemetry_metrics();
	$check( 'HEALTHY' === $adapter->get_health_status() && null !== $e0 && $e0 < $e100 && ! isset( $tele['bafa_export_engine'] ) && str_contains( (string) $adapter->get_settings_url(), 'tab=settings' ), "hub adapter: HEALTHY, risk_eval {$e0} < {$e100}, no report engine in telemetry" );
} else {
	echo "  [INFO] hub not active: adapter not checked\n";
}

$check( WMCyber\LkSG\Core\Settings::save_reporting_year( '2025' ) && 2025 === WMCyber\LkSG\Core\Settings::reporting_year() && ! WMCyber\LkSG\Core\Settings::save_reporting_year( '1850' ), 'setting: 2025 stored, 1850 refused' );

$GLOBALS['lkrt_warnings'] = [];
set_error_handler( static function ( $no, $msg, $file, $line ) {
	$GLOBALS['lkrt_warnings'][] = $msg . ' @ ' . basename( (string) $file ) . ':' . $line;
	return true;
} );
$plugin = WMCyber\LkSG\Core\Plugin::instance();
$admin  = new WMCyber\LkSG\Admin\AdminDashboard( $plugin->get_risk_calculator(), $plugin->get_bafa_exporter() );
ob_start();
$admin->render_dashboard_widget();
$html = (string) ob_get_clean();
foreach ( [ 'overview', 'calculator', 'export', 'settings', 'help' ] as $tab ) {
	$_GET['tab'] = $tab;
	ob_start();
	$admin->render_dashboard_page();
	$html .= (string) ob_get_clean();
}
$portal = new WMCyber\LkSG\Frontend\SupplierPortal( $plugin->get_risk_calculator() );
$forms  = $portal->render_portal() . $portal->render_portal();
restore_error_handler();
$check( [] === $GLOBALS['lkrt_warnings'], 'admin tabs, widget and portal render without PHP notices' . ( $GLOBALS['lkrt_warnings'] ? ': ' . $GLOBALS['lkrt_warnings'][0] : '' ) );
$check( ! preg_match( '/PREPARADO|VINCULADO|AL DÍA|Risikomanagement aktiv|Berichtsbereit|CSDDD|style=/u', $html ) && (bool) preg_match( '#bafa-report[^"]*_wpnonce=#', $html ), 'admin screen without the fixed claims or inline styles; the JSON link carries a REST nonce' );
preg_match_all( '/id="(wm-lksg-portal-\d+)-company"/', $forms, $ids );
$check( 2 === count( array_unique( $ids[1] ) ) && ! str_contains( $forms, '<script' ), 'two portals on one page get distinct IDs and no inline script' );

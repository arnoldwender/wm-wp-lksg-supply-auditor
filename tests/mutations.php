<?php
/**
 * Literal mutations for tests/run-mutations.sh: each entry undoes one correction of 2026-09-15; the
 * standalone suite must report a failure for every one. Search strings must occur exactly once.
 */

return [
	[
		'D3 POST /suppliers without a country check again',
		[
			[ 'includes/API/RestController.php', "'Der Lieferantenname fehlt.', 'wm-lksg-supply-auditor' ), [ 'status' => 400 ] );\n\t\t}\n\t\tif ( ! Countries::is_valid( \$country ) ) {", "'Der Lieferantenname fehlt.', 'wm-lksg-supply-auditor' ), [ 'status' => 400 ] );\n\t\t}\n\t\tif ( false ) {" ],
		],
	],
	[
		'D13 tier unbounded again',
		[
			[ 'includes/API/RestController.php', 'return $tier >= 1 && $tier <= 3 ? $tier : null;', 'return $tier;' ],
		],
	],
	[
		'D12 history failure ignored by POST /suppliers',
		[
			[ 'includes/API/RestController.php', "\t\tif ( ! AssessmentLog::record( \$supplier_id, \$factors, \$risk_result ) ) {", "\t\tAssessmentLog::record( \$supplier_id, \$factors, \$risk_result );\n\t\tif ( false ) {" ],
		],
	],
	[
		'D1 country_code argument without validation',
		[
			[ 'includes/API/RestController.php', '\'country_code\'    => [ \'type\' => \'string\', \'required\' => true, \'validate_callback\' => [ self::class, \'validate_country_arg\' ] ],', '\'country_code\'    => [ \'type\' => \'string\' ],' ],
		],
	],
	[
		'D4 GET /suppliers without the table check',
		[
			[ 'includes/API/RestController.php', "\t\tif ( ! Schema::table_exists( 'suppliers' ) ) {\n\t\t\treturn new WP_Error( 'wm_lksg_missing_table'", "\t\tif ( false ) {\n\t\t\treturn new WP_Error( 'wm_lksg_missing_table'" ],
		],
	],
	[
		'D1 /bafa-report ignores ?year= again',
		[
			[ 'includes/API/RestController.php', '$year = null === $raw ? Settings::reporting_year() : ( is_numeric( $raw ) ? (int) $raw : 0 );', '$year = (int) gmdate( \'Y\' );' ],
		],
	],
	[
		'D14 self-disclosure accepts any two letters again',
		[
			[ 'includes/API/RestController.php', "'Der Unternehmensname fehlt.', 'wm-lksg-supply-auditor' ), [ 'status' => 400 ] );\n\t\t}\n\t\tif ( ! Countries::is_valid( \$country ) ) {", "'Der Unternehmensname fehlt.', 'wm-lksg-supply-auditor' ), [ 'status' => 400 ] );\n\t\t}\n\t\tif ( false ) {" ],
		],
	],
	[
		'D14 self-disclosure accepts any sector again',
		[
			[ 'includes/API/RestController.php', 'if ( ! in_array( $sector, SupplierPortal::SECTOR_KEYS, true ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'Declaration accepted for any non-empty value ("false")',
		[
			[ 'includes/API/RestController.php', 'if ( true !== self::flag( $params[\'declaration_accepted\'] ?? null ) ) {', 'if ( empty( $params[\'declaration_accepted\'] ) ) {' ],
		],
	],
	[
		'Declaration not a required argument',
		[
			[ 'includes/API/RestController.php', '\'declaration_accepted\' => [ \'type\' => \'boolean\', \'required\' => true ],', '\'declaration_accepted\' => [ \'type\' => \'boolean\' ],' ],
		],
	],
	[
		'D5 declared certifications not stored',
		[
			[ 'includes/API/RestController.php', '\'declared_iso_14001\'  => self::flag( $params[\'has_iso_14001\'] ?? false ) ? 1 : 0,', '\'declared_iso_14001\'  => 0,' ],
		],
	],
	[
		'D5 declaration text not stored',
		[
			[ 'includes/API/RestController.php', '\'declaration_text\'    => self::declaration_text(),', '\'declaration_text\'    => null,' ],
		],
	],
	[
		'D15 unassessed human-rights score stored as 0',
		[
			[ 'includes/API/RestController.php', '\'human_rights_score\'  => null,', '\'human_rights_score\'  => 0.0,' ],
		],
	],
	[
		'D5 signature does not cover SA 8000',
		[
			[ 'includes/API/RestController.php', "\t\t\t\t'sa_8000'      => (bool) (int) ( \$audit['declared_sa_8000'] ?? 0 ),\n", '' ],
		],
	],
	[
		'D5 verification always true',
		[
			[ 'includes/API/RestController.php', 'return hash_equals( self::signature( $audit ), (string) ( $audit[\'digital_signature\'] ?? \'\' ) );', 'return true;' ],
		],
	],
	[
		'D12 orphan supplier kept when the audit row fails',
		[
			[ 'includes/API/RestController.php', "\t\t\t\$wpdb->delete( \$supplier, [ 'id' => \$supplier_id ], [ '%d' ] );\n", '' ],
		],
	],
	[
		'D12 audit row failure answers 201 again',
		[
			[ 'includes/API/RestController.php', "\t\tif ( false === \$audit_inserted ) {", "\t\tif ( false ) {" ],
		],
	],
	[
		'D2 public answer reveals the row id again',
		[
			[ 'includes/API/RestController.php', "\t\t\t\t'reference'    => \$uuid,", "\t\t\t\t'supplier_id'  => \$supplier_id,\n\t\t\t\t'reference'    => \$uuid," ],
		],
	],
	[
		'Rate-limit window restarts on every submission',
		[
			[ 'includes/API/RestController.php', 'set_transient( $bucket, [ \'count\' => $count + 1, \'started\' => $started ], max( 1, HOUR_IN_SECONDS - ( $now_ts - $started ) ) );', 'set_transient( $bucket, [ \'count\' => $count + 1, \'started\' => $now_ts ], HOUR_IN_SECONDS );' ],
		],
	],
	[
		'Rate limit never trips',
		[
			[ 'includes/API/RestController.php', 'if ( $count >= self::MAX_DISCLOSURES_PER_HOUR ) {', 'if ( false ) {' ],
		],
	],
	[
		'D19 transliterated umlauts again',
		[
			[ 'includes/API/RestController.php', '\'Zu viele Übermittlungen von dieser Adresse. Bitte versuchen Sie es später erneut.\'', '\'Zu viele Uebermittlungen von dieser Adresse. Bitte versuchen Sie es spaeter erneut.\'' ],
		],
	],
	[
		'Declaration misattributes § 2 again',
		[
			[ 'includes/API/RestController.php', 'Verbote der Kinderarbeit und der Zwangsarbeit (§ 2 Abs. 2 Nr. 1 bis 3 LkSG) und gegen die umweltbezogenen Verbote des § 2 Abs. 3 LkSG vorliegt.', 'Verbote der Kinderarbeit, Zwangsarbeit oder gravierenden Umweltbelastungen nach § 2 LkSG vorliegt.' ],
		],
	],
	[
		'D4 unassessed rows last again',
		[
			[ 'includes/Database/Register.php', '\'SELECT * FROM %i ORDER BY (risk_level = %s) DESC, risk_score DESC, id DESC LIMIT %d OFFSET %d\',', '\'SELECT * FROM %i ORDER BY (risk_level = %s) ASC, risk_score DESC, id DESC LIMIT %d OFFSET %d\',' ],
		],
	],
	[
		'D4 per_page not capped',
		[
			[ 'includes/Database/Register.php', '$per_page = max( 1, min( 100, $per_page ) );', '$per_page = max( 1, $per_page );' ],
		],
	],
	[
		'D7 unassessed counted as assessed',
		[
			[ 'includes/Database/Register.php', '\'assessed\'         => $total - $unassessed,', '\'assessed\'         => $total,' ],
		],
	],
	[
		'D4 no paging offset',
		[
			[ 'includes/Database/Register.php', '( $page - 1 ) * $per_page', '0' ],
		],
	],
	[
		'D1 suppliers not filtered by year',
		[
			[ 'includes/Export/BafaReportExporter.php', '\'SELECT COUNT(*) FROM %i WHERE created_at < %s\', $suppliers, $to ) ),', '\'SELECT COUNT(*) FROM %i\', $suppliers ) ),' ],
		],
	],
	[
		'D2 tier 1 counts every tier again',
		[
			[ 'includes/Export/BafaReportExporter.php', '\'SELECT COUNT(*) FROM %i WHERE created_at < %s AND tier_level = %d\', $suppliers, $to, 1 )', '\'SELECT COUNT(*) FROM %i WHERE created_at < %s\', $suppliers, $to )' ],
		],
	],
	[
		'D2 every audit row counted as a self-disclosure',
		[
			[ 'includes/Export/BafaReportExporter.php', '\'SELECT COUNT(*) FROM %i WHERE audit_type = %s AND execution_date >= %s AND execution_date < %s\', $audits, \'self_disclosure\', $from, $to )', '\'SELECT COUNT(*) FROM %i WHERE execution_date >= %s AND execution_date < %s\', $audits, $from, $to )' ],
		],
	],
	[
		'D1 assessments counted outside the year',
		[
			[ 'includes/Export/BafaReportExporter.php', '\'SELECT COUNT(*) FROM %i WHERE assessment_date >= %s AND assessment_date < %s\', $assessments, $from, $to )', '\'SELECT COUNT(*) FROM %i WHERE assessment_date < %s\', $assessments, $to )' ],
		],
	],
	[
		'D1 a fixed true statement again',
		[
			[ 'includes/Export/BafaReportExporter.php', '\'not_recorded\'           => self::NOT_RECORDED,', "'not_recorded'           => self::NOT_RECORDED,\n\t\t\t'risk_management_system' => [ 'policy_statement_active' => true ]," ],
		],
	],
	[
		'Missing table counted as 0',
		[
			[ 'includes/Export/BafaReportExporter.php', 'null === $audits ? null :', 'null === $audits ? 0 :' ],
		],
	],
	[
		'D8 save overwrites keys the form lacks',
		[
			[ 'includes/Core/Settings.php', "\t\t\$stored                   = get_option( self::OPTION, [] );\n\t\t\$stored                   = is_array( \$stored ) ? \$stored : [];", "\t\t\$stored                   = [];" ],
		],
	],
	[
		'D8 year stored without bounds',
		[
			[ 'includes/Core/Settings.php', '|| ! self::year_in_range( (int) $value ) ) {', ') {' ],
		],
	],
	[
		'Default year is the current year',
		[
			[ 'includes/Core/Settings.php', 'return self::year_in_range( $year ) ? $year : (int) gmdate( \'Y\' ) - 1;', 'return self::year_in_range( $year ) ? $year : (int) gmdate( \'Y\' );' ],
		],
	],
	[
		'D12 composite score not kept',
		[
			[ 'includes/Risk/AssessmentLog.php', "\t\t\t\t'composite_score'     => (float) ( \$risk['score'] ?? 0.0 ),\n", '' ],
			[ 'includes/Risk/AssessmentLog.php', '[ \'%s\', \'%d\', \'%s\', \'%s\', \'%f\', \'%f\', \'%f\', \'%f\', \'%s\', \'%s\', \'%s\', \'%s\' ]', '[ \'%s\', \'%d\', \'%s\', \'%s\', \'%f\', \'%f\', \'%f\', \'%s\', \'%s\', \'%s\', \'%s\' ]' ],
		],
	],
	[
		'D12 factors not kept',
		[
			[ 'includes/Risk/AssessmentLog.php', "\t\t\t\t'factors'             => (string) wp_json_encode( \$factors ),\n", '' ],
			[ 'includes/Risk/AssessmentLog.php', '[ \'%s\', \'%d\', \'%s\', \'%s\', \'%f\', \'%f\', \'%f\', \'%f\', \'%s\', \'%s\', \'%s\', \'%s\' ]', '[ \'%s\', \'%d\', \'%s\', \'%s\', \'%f\', \'%f\', \'%f\', \'%f\', \'%s\', \'%s\', \'%s\' ]' ],
		],
	],
	[
		'D25 Schema.php without the ABSPATH guard',
		[
			[ 'includes/Database/Schema.php', "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\nfinal class Schema {", 'final class Schema {' ],
		],
	],
	[
		'D16 supplier level defaults to low again',
		[
			[ 'includes/Database/Schema.php', 'risk_level varchar(16) NOT NULL DEFAULT \'unassessed\',', 'risk_level varchar(16) NOT NULL DEFAULT \'low\',' ],
		],
	],
	[
		'D15 audit scores NOT NULL again on new installs',
		[
			[ 'includes/Database/Schema.php', "\t\t\thuman_rights_score decimal(5,2) DEFAULT NULL,", "\t\t\thuman_rights_score decimal(5,2) NOT NULL DEFAULT 0.00," ],
		],
	],
	[
		'D15 existing audits tables keep NOT NULL scores',
		[
			[ 'includes/Database/Schema.php', 'if ( \'NO\' === ( $column[\'Null\'] ?? \'\' ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'D12 factors column missing from the history table',
		[
			[ 'includes/Database/Schema.php', "\t\t\tfactors text DEFAULT NULL,\n", '' ],
		],
	],
	[
		'Missing tables unreported',
		[
			[ 'includes/Database/Schema.php', 'return array_values( array_filter( self::TABLES, static fn( string $name ): bool => ! self::table_exists( $name ) ) );', 'return [];' ],
		],
	],
	[
		'D6 health literal HEALTHY again',
		[
			[ 'includes/Adapters/Lksg_Spoke_Adapter.php', 'if ( [] !== Schema::missing_tables() || ! $this->self_test()[\'success\'] ) {', 'if ( false ) {' ],
		],
	],
	[
		'D6 risk_eval ignores its parameters again',
		[
			[ 'includes/Adapters/Lksg_Spoke_Adapter.php', '$factors = RiskFactors::from_input( $params, RiskFactors::derived_from( Countries::is_valid( $country ) ? $country : \'\', $sector ) );', '$factors = RiskFactors::from_input( [], [] );' ],
		],
	],
	[
		'D6 self-test passes whatever it computes',
		[
			[ 'includes/Adapters/Lksg_Spoke_Adapter.php', '$match = $case[\'level\'] === ( $got[\'level\'] ?? null );', '$match = true;' ],
		],
	],
	[
		'D6 telemetry reports a READY report engine again',
		[
			[ 'includes/Adapters/Lksg_Spoke_Adapter.php', '\'bafa_report\'                => \'not_produced\',', '\'bafa_export_engine\'         => \'READY\',' ],
		],
	],
	[
		'Hub settings link without the settings tab',
		[
			[ 'includes/Adapters/Lksg_Spoke_Adapter.php', 'admin_url( \'admin.php?page=wm-lksg-auditor&tab=settings\' )', 'admin_url( \'admin.php?page=wm-lksg-auditor\' )' ],
		],
	],
	[
		'SBOM compliance property again',
		[
			[ 'includes/Adapters/Lksg_Spoke_Adapter.php', '\'name\'  => \'wm:features:outbound-requests\',', '\'name\'  => \'wm:compliance:LkSG\',' ],
		],
	],
	[
		'D6 widget green on an empty register',
		[
			[ 'includes/Admin/AdminDashboard.php', "\$banner = 'wm-dash-banner';\n\t\t\t\$title  = __( 'Noch keine Lieferanten im Register'", "\$banner = 'wm-dash-banner is-valid';\n\t\t\t\$title  = __( 'Noch keine Lieferanten im Register'" ],
		],
	],
	[
		'D7 overview counts the rows of the page',
		[
			[ 'includes/Admin/AdminDashboard.php', '<span class="wm-stat-value"><?php echo esc_html( number_format_i18n( $counts[\'total\'] ) ); ?></span>', '<span class="wm-stat-value"><?php echo esc_html( number_format_i18n( count( $page[\'rows\'] ) ) ); ?></span>' ],
		],
	],
	[
		'D9 JSON link without a REST nonce',
		[
			[ 'includes/Admin/AdminDashboard.php', '$url    = wp_nonce_url( add_query_arg( \'year\', $year, rest_url( \'wm-lksg/v1/bafa-report\' ) ), \'wp_rest\' );', '$url    = add_query_arg( \'year\', $year, rest_url( \'wm-lksg/v1/bafa-report\' ) );' ],
		],
	],
	[
		'D16 unknown level painted as low',
		[
			[ 'includes/Admin/AdminDashboard.php', 'default      => [ \'class\' => \'wm-status-pill\', \'label\' => __( \'Unbekannt\', \'wm-lksg-supply-auditor\' ) ],', 'default      => [ \'class\' => \'wm-status-pill wm-status-ok\', \'label\' => __( \'Niedrig\', \'wm-lksg-supply-auditor\' ) ],' ],
		],
	],
	[
		'D17 a reload stores the supplier again',
		[
			[ 'includes/Admin/AdminDashboard.php', 'if ( false !== get_transient( $token_key ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'D17 a form without a token saves',
		[
			[ 'includes/Admin/AdminDashboard.php', 'if ( 32 !== strlen( $token ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'D11 demo suppliers added on every click',
		[
			[ 'includes/Admin/AdminDashboard.php', "\t\t\tif ( \$exists > 0 ) {\n\t\t\t\tcontinue;\n\t\t\t}\n", '' ],
		],
	],
	[
		'D11 demo scores invented again',
		[
			[ 'includes/Admin/AdminDashboard.php', "\$risk    = \$this->risk_calculator->calculate_risk( \$factors );\n\t\t\tif ( \$this->insert_supplier( \$demo['name']", "\$risk    = [ 'score' => 12.0, 'level' => 'low', 'action_trigger' => 'NO_ACTION', 'abstract_score' => 0.0, 'concrete_score' => 0.0, 'high_risk_flags' => [] ];\n\t\t\tif ( \$this->insert_supplier( \$demo['name']" ],
		],
	],
	[
		'D22 family sheets not loaded on the dashboard',
		[
			[ 'includes/Admin/AdminDashboard.php', 'if ( \'index.php\' !== $hook && \'toplevel_page_\' . self::PAGE !== $hook ) {', 'if ( \'toplevel_page_\' . self::PAGE !== $hook ) {' ],
		],
	],
	[
		'D8 threshold field again',
		[
			[ 'includes/Admin/AdminDashboard.php', '<input type="number" id="wm-lksg-year"', '<input type="number" name="high_risk_threshold" value="70" /><input type="number" id="wm-lksg-year"' ],
		],
	],
	[
		'D6 fixed header claim again',
		[
			[ 'includes/Admin/AdminDashboard.php', '<span class="wm-badge wm-badge-ghost"><?php esc_html_e( \'Keine externen Anfragen\', \'wm-lksg-supply-auditor\' ); ?></span>', '<span class="wm-badge wm-badge-ghost"><?php esc_html_e( \'INFORME BAFA DISPONIBLE\', \'wm-lksg-supply-auditor\' ); ?></span>' ],
		],
	],
	[
		'D12 admin keeps a supplier whose history row failed',
		[
			[ 'includes/Admin/AdminDashboard.php', "\t\tif ( ! AssessmentLog::record( \$id, \$factors, \$risk, \$source ) ) {\n\t\t\t\$wpdb->delete( \$table, [ 'id' => \$id ], [ '%d' ] );\n\t\t\treturn false;\n\t\t}", "\t\tAssessmentLog::record( \$id, \$factors, \$risk, \$source );" ],
		],
	],
	[
		'Settings action without a capability check',
		[
			[ 'includes/Admin/AdminDashboard.php', "if ( ! current_user_can( 'manage_options' ) ) {\n\t\t\twp_die( esc_html__( 'Keine Berechtigung.'", "if ( false ) {\n\t\t\twp_die( esc_html__( 'Keine Berechtigung.'" ],
		],
	],
	[
		'D23 inline style in the admin again',
		[
			[ 'includes/Admin/AdminDashboard.php', '<div class="wrap wm-admin wm-lksg-admin-wrap">', '<div class="wrap wm-admin wm-lksg-admin-wrap" style="margin:0">' ],
		],
	],
	[
		'D3 CLI defaults the country to DE again',
		[
			[ 'includes/CLI/LksgCommand.php', '$country = Countries::normalize( $assoc_args[\'country\'] ?? \'\' );', '$country = Countries::normalize( $assoc_args[\'country\'] ?? \'DE\' );' ],
		],
	],
	[
		'CLI accepts an invalid country',
		[
			[ 'includes/CLI/LksgCommand.php', 'if ( \'\' !== $country && ! Countries::is_valid( $country ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'D1 export-bafa ignores --year',
		[
			[ 'includes/CLI/LksgCommand.php', '$year = isset( $assoc_args[\'year\'] ) && is_numeric( $assoc_args[\'year\'] ) ? (int) $assoc_args[\'year\'] : ( isset( $assoc_args[\'year\'] ) ? 0 : Settings::reporting_year() );', '$year = (int) gmdate( \'Y\' );' ],
		],
	],
	[
		'D18 fixed element IDs again',
		[
			[ 'includes/Frontend/SupplierPortal.php', '$id = wp_unique_id( \'wm-lksg-portal-\' );', '$id = \'wm-lksg-portal-1\';' ],
		],
	],
	[
		'Declaration checkbox not required',
		[
			[ 'includes/Frontend/SupplierPortal.php', '<input type="checkbox" name="declaration_accepted" value="1" required />', '<input type="checkbox" name="declaration_accepted" value="1" />' ],
		],
	],
	[
		'D14 route accepts a sector the form does not offer',
		[
			[ 'includes/Frontend/SupplierPortal.php', 'public const SECTOR_KEYS = [ \'manufacturing\', \'electronics\', \'textiles\', \'mining\', \'it_services\', \'logistics\' ];', 'public const SECTOR_KEYS = [ \'manufacturing\', \'electronics\', \'textiles\', \'mining\', \'it_services\', \'logistics\', \'anything-at-all\' ];' ],
		],
	],
	[
		'Conformity badge on the portal again',
		[
			[ 'includes/Frontend/SupplierPortal.php', 'esc_html_e( \'Lieferanten-Selbstauskunft\', \'wm-lksg-supply-auditor\' );', 'esc_html_e( \'LkSG § 5 & EU CSDDD Konformität\', \'wm-lksg-supply-auditor\' );' ],
		],
	],
	[
		'Portal script sends a fixed declaration true',
		[
			[ 'assets/js/wm-lksg-portal.js', 'declaration_accepted: !! field( \'declaration_accepted\' ).checked', 'declaration_accepted: true' ],
		],
	],
	[
		'D18 portal script binds by a fixed ID',
		[
			[ 'assets/js/wm-lksg-portal.js', 'document.querySelectorAll( \'form[data-wm-lksg-portal]\' ).forEach( bind );', 'bind( document.getElementById( \'wm-lksg-self-audit-form\' ) );' ],
		],
	],
	[
		'D23 hex colour in the portal sheet',
		[
			[ 'assets/css/wm-lksg-portal.css', "\tbackground: var(--lksg-accent);", "\tbackground: #0369a1;" ],
		],
	],
	[
		'D10 uninstall leaves two tables',
		[
			[ 'uninstall.php', '[ \'corrective_actions\', \'complaints\', \'audits\', \'risk_assessments\', \'suppliers\' ]', '[ \'complaints\', \'audits\', \'suppliers\' ]' ],
		],
	],
	[
		'D10 uninstall leaves the schema version',
		[
			[ 'uninstall.php', '[ \'wm_lksg_settings\', \'wm_lksg_schema_version\' ]', '[ \'wm_lksg_settings\' ]' ],
		],
	],
	[
		'D10 uninstall leaves the transients',
		[
			[ 'uninstall.php', '$wpdb->esc_like( \'_transient_wm_lksg_\' ) . \'%\',', '$wpdb->esc_like( \'_transient_wm_lksg_none_\' ) . \'%\',' ],
		],
	],
	[
		'WP suite writes without the guard',
		[
			[ 'tests/test-self-disclosure.php', 'if ( \'1\' !== getenv( \'WM_LKSG_WP_TESTS_WRITE\' ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'WP suite exits 0 with failures',
		[
			[ 'tests/test-suppliers-rest.php', 'exit( $GLOBALS[\'wm_r_failed\'] > 0 ? 1 : 0 );', 'exit( 0 );' ],
		],
	],
	[
		'Header claims a report generator again',
		[
			[ 'wm-lksg-supply-auditor.php', 'It does not produce a BAFA report.', 'Automated BAFA annual report generator.' ],
		],
	],
	[
		'D24 marketing images in the package again',
		[
			[ '.distignore', 'assets/images/', 'assets/imgs-kept/' ],
		],
	],
	[
		'D20 de_DE catalog double-encoded again',
		[
			[ 'languages/wm-lksg-supply-auditor-de_DE.po', 'msgid "Jahresübersicht"', 'msgid "JahresÃ¼bersicht"' ],
		],
	],
	[
		'D20 template without the German source strings',
		[
			[ 'languages/wm-lksg-supply-auditor.pot', 'msgid "Nicht bewertet"', 'msgid "Sin evaluar"' ],
		],
	],
	[
		'README compliance badge again',
		[
			[ 'README.md', '> **A supplier register for the German', "![LkSG](https://img.shields.io/badge/LkSG-Compliant-10b981)\n\n> **A supplier register for the German" ],
		],
	],
	[
		'humans.txt CEO again',
		[
			[ 'public/humans.txt', 'Author: Arnold Wender', 'Author: Arnold Wender -- Founder & CEO' ],
		],
	],
	[
		'Legal doc claims the plugin makes a company compliant',
		[
			[ 'docs/LEGAL-COMPLIANCE.md', '- The duties are the enterprise\'s.', '- The plugin keeps an enterprise compliant with these duties.' ],
		],
	],
	[
		'CI without the mutation check',
		[
			[ '.github/workflows/ci.yml', 'run: bash tests/run-mutations.sh', 'run: echo skipped' ],
		],
	],
	[
		'D-UNINSTALL drops tables without explicit opt-in again',
		[
			[ 'uninstall.php', "if ( ! in_array( \$wm_lksg_delete_data, [ true, 1, '1', 'yes', 'true' ], true ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'SEC-02 self-disclosure route uses __return_true again',
		[
			[ 'includes/API/RestController.php', "'permission_callback' => [ \$this, 'check_self_disclosure_access' ],", "'permission_callback' => '__return_true'," ],
		],
	],
	[
		'SEC-02 REST permission rate limit never trips',
		[
			[ 'includes/API/RestController.php', 'if ( $limit <= 0 || $count >= $limit ) {', 'if ( false ) {' ],
		],
	],
	[
		'Gutenberg block missing editorScript in block.json',
		[
			[ 'blocks/supplier-portal/block.json', '"editorScript": "wm-lksg-blocks-editor",', '"editorScript": "",' ],
		],
	],
	[
		'Ecosystem wm_lksg_ready action not fired',
		[
			[ 'includes/Core/Plugin.php', "do_action( 'wm_lksg_ready', \$this );", '// omitted' ],
		],
	],
	[
		'Ecosystem wm_lksg_risk_score filter bypassed',
		[
			[ 'includes/Risk/RiskMatrixCalculator.php', "apply_filters( 'wm_lksg_risk_score', \$result, \$factors )", '$result' ],
		],
	],
	[
		'Ecosystem Block Bindings source omitted',
		[
			[ 'includes/Frontend/EcosystemIntegration.php', "'wm-lksg/metrics',", "'wm-lksg/missing'," ],
		],
	],
	[
		'R2-B8 wp_initialize_site hook omitted',
		[
			[ 'wm-lksg-supply-auditor.php', "add_action( 'wp_initialize_site',", "add_action( 'wp_initialize_site_disabled'," ],
		],
	],
	[
		'R2-B8 wp_initialize_site skips is_multisite check',
		[
			[ 'wm-lksg-supply-auditor.php', "\tif ( ! is_multisite() ) {\n\t\treturn;\n\t}", "\tif ( false ) {\n\t\treturn;\n\t}" ],
		],
	],
	[
		'R2-B8 wp_initialize_site skips is_plugin_active_for_network check',
		[
			[ 'wm-lksg-supply-auditor.php', "if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'R2-B8 wp_initialize_site does not switch_to_blog',
		[
			[ 'wm-lksg-supply-auditor.php', "\tswitch_to_blog( \$blog_id );", "\t// omitted switch_to_blog" ],
		],
	],
	[
		'R2-B8 wp_initialize_site omits try/finally around restore_current_blog',
		[
			[ 'wm-lksg-supply-auditor.php', "\tswitch_to_blog( \$blog_id );\n\ttry {\n\t\tDatabase\\Schema::activate();\n\t} finally {\n\t\trestore_current_blog();\n\t}", "\tswitch_to_blog( \$blog_id );\n\tDatabase\\Schema::activate();\n\trestore_current_blog();" ],
		],
	],
	[
		'R2-B8 network activation ignores $network_wide in Schema::activate',
		[
			[ 'includes/Database/Schema.php', "if ( \$network_wide && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'R2-B8 Schema::activate_site autoloads version option',
		[
			[ 'includes/Database/Schema.php', 'update_option( self::VERSION_OPTION, self::VERSION, false );', 'update_option( self::VERSION_OPTION, self::VERSION );' ],
		],
	],
];

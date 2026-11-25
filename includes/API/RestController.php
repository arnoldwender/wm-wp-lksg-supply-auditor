<?php
/**
 * REST routes under wm-lksg/v1.
 *
 * @package WMCyber\LkSG
 */

declare(strict_types=1);

namespace WMCyber\LkSG\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\LkSG\Core\Settings;
use WMCyber\LkSG\Database\Register;
use WMCyber\LkSG\Database\Schema;
use WMCyber\LkSG\Export\BafaReportExporter;
use WMCyber\LkSG\Frontend\SupplierPortal;
use WMCyber\LkSG\Risk\AssessmentLog;
use WMCyber\LkSG\Risk\Countries;
use WMCyber\LkSG\Risk\RiskFactors;
use WMCyber\LkSG\Risk\RiskMatrixCalculator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RestController {
	private const REST_NAMESPACE = 'wm-lksg/v1';

	/** Self-disclosures accepted per client address within one hour from the first one. */
	public const MAX_DISCLOSURES_PER_HOUR = 5;

	/** Default per-minute REST request limit on the public self-disclosure endpoint (filter: wm_lksg_rest_rate_limit). */
	public const DEFAULT_REST_RATE_LIMIT = 30;

	/** Version of what a self-disclosure signature covers; rows before 2026-09-15 carry none. */
	public const SIGNATURE_VERSION = 'v2';

	public function __construct(
		private readonly RiskMatrixCalculator $risk_calculator,
		private readonly BafaReportExporter $bafa_exporter
	) {}

	public function register_routes(): void {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	/**
	 * Routes with declared arguments, so core answers 400 for a missing or malformed value before a handler runs.
	 * The handlers check the same values again: they are also called directly (standalone suite, WP-CLI).
	 */
	public function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route( self::REST_NAMESPACE, '/suppliers', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_suppliers' ],
			'permission_callback' => $admin,
			'args'                => [
				'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/suppliers', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_supplier' ],
			'permission_callback' => $admin,
			'args'                => [
				'supplier_name'   => [ 'type' => 'string', 'required' => true ],
				'country_code'    => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ self::class, 'validate_country_arg' ] ],
				'industry_sector' => [ 'type' => 'string' ],
				'tier_level'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 3 ],
				'has_iso_14001'   => [ 'type' => 'boolean' ],
				'has_sa_8000'     => [ 'type' => 'boolean' ],
			],
		] );

		// Public for external suppliers who hold no WordPress account, guarded at the REST permission
		// layer by HMAC-hashed per-minute IP rate-limiting (wm_lksg_rl_*, bypassed for manage_options).
		// Only declared facts are accepted; everything scored is derived server-side, and the
		// record is filed as a self-disclosure, never as an audit.
		register_rest_route( self::REST_NAMESPACE, '/self-disclosure', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'submit_self_disclosure' ],
			'permission_callback' => [ $this, 'check_self_disclosure_access' ],
			'args'                => [
				'supplier_name'        => [ 'type' => 'string', 'required' => true ],
				'country_code'         => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ self::class, 'validate_country_arg' ] ],
				'industry_sector'      => [ 'type' => 'string', 'required' => true, 'enum' => SupplierPortal::SECTOR_KEYS ],
				'tier_level'           => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 3, 'default' => 1 ],
				'has_iso_14001'        => [ 'type' => 'boolean', 'default' => false ],
				'has_sa_8000'          => [ 'type' => 'boolean', 'default' => false ],
				'has_iso_45001'        => [ 'type' => 'boolean', 'default' => false ],
				'declaration_accepted' => [ 'type' => 'boolean', 'required' => true ],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/bafa-report', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_bafa_report' ],
			'permission_callback' => $admin,
			'args'                => [
				'year' => [ 'type' => 'integer', 'minimum' => Settings::MIN_YEAR, 'maximum' => Settings::max_year() ],
			],
		] );
	}

	/**
	 * validate_callback for country_code: an officially assigned ISO 3166-1 alpha-2 code.
	 */
	public static function validate_country_arg( mixed $value, mixed $request = null, string $param = 'country_code' ): bool|WP_Error {
		if ( is_string( $value ) && Countries::is_valid( $value ) ) {
			return true;
		}
		return new WP_Error( 'rest_invalid_param', self::country_message(), [ 'status' => 400 ] );
	}

	private static function country_message(): string {
		return __( 'Das Land fehlt oder ist kein ISO-3166-1-Alpha-2-Code (zum Beispiel DE, CN, BD).', 'wm-lksg-supply-auditor' );
	}

	private static function rate_limited_message(): string {
		return __( 'Zu viele Übermittlungen von dieser Adresse. Bitte versuchen Sie es später erneut.', 'wm-lksg-supply-auditor' );
	}

	/**
	 * Permission callback for POST /self-disclosure: bypasses rate-limiting for administrators (`manage_options`)
	 * and enforces a per-minute HMAC-SHA256 hashed IP rate limit (`wm_lksg_rl_*`, default 30 req/min) for anonymous
	 * external suppliers without storing raw IP addresses.
	 */
	public function check_self_disclosure_access( WP_REST_Request $request ): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$limit = self::DEFAULT_REST_RATE_LIMIT;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the per-minute REST request rate limit for the self-disclosure endpoint.
			 *
			 * @param int             $limit   Maximum requests per minute per client hash (default 30).
			 * @param WP_REST_Request $request Current REST request.
			 */
			$limit = (int) apply_filters( 'wm_lksg_rest_rate_limit', $limit, $request );
		}

		$address    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$rl_key     = self::rest_rate_limit_key( $address );
		$window_sec = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
		$now_ts     = time();
		$state      = get_transient( $rl_key );
		$count      = 0;
		$started    = $now_ts;
		if ( is_array( $state ) && isset( $state['count'], $state['started'] ) && $now_ts - (int) $state['started'] < $window_sec ) {
			$count   = (int) $state['count'];
			$started = (int) $state['started'];
		}

		if ( $limit <= 0 || $count >= $limit ) {
			return new WP_Error(
				'wm_lksg_rate_limited',
				self::rate_limited_message(),
				[ 'status' => 429 ]
			);
		}

		set_transient( $rl_key, [ 'count' => $count + 1, 'started' => $started ], max( 1, $window_sec - ( $now_ts - $started ) ) );

		return true;
	}

	/**
	 * One page of the register, unassessed suppliers first. Until 2026-09-15 the first 50 rows by score, without a
	 * total: a self-disclosure (score 0.00) was the first row to disappear.
	 */
	public function get_suppliers( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! Schema::table_exists( 'suppliers' ) ) {
			return new WP_Error( 'wm_lksg_missing_table', __( 'Die Lieferantentabelle fehlt. Das Plugin einmal deaktivieren und wieder aktivieren.', 'wm-lksg-supply-auditor' ), [ 'status' => 500 ] );
		}

		$page     = is_numeric( $request->get_param( 'page' ) ) ? (int) $request->get_param( 'page' ) : 1;
		$per_page = is_numeric( $request->get_param( 'per_page' ) ) ? (int) $request->get_param( 'per_page' ) : 50;
		$result   = Register::page( $page, $per_page );

		$response = new WP_REST_Response( [
			'success'   => true,
			'total'     => $result['total'],
			'page'      => $result['page'],
			'per_page'  => $result['per_page'],
			'pages'     => $result['pages'],
			'suppliers' => $result['rows'],
		] );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['pages'] );

		return $response;
	}

	/**
	 * Register and evaluate a supplier (manage_options).
	 *
	 * Factors: an explicit key in the body wins (country_cpi_risk, sector_risk, product_risk,
	 * severity_score, probability_score, dependency_ratio, causal_contribution, has_iso_14001,
	 * has_sa_8000); otherwise country_code and industry_sector set the abstract tier; otherwise
	 * the calculator's defaults. The old human_rights_indicator / environmental_indicator keys are
	 * translated — until 2026-09-14 they were dropped and every supplier scored 42.60.
	 *
	 * Until 2026-09-15 a missing country was stored and scored as "DE", the lowest country tier (a supplier nobody
	 * located scored 39.00 instead of 43.20), a missing sector as "manufacturing", any tier number was stored, and a
	 * failed assessment row still answered 201.
	 */
	public function create_supplier( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;
		$params = (array) $request->get_params();

		$name    = self::text( $params['supplier_name'] ?? '' );
		$country = Countries::normalize( $params['country_code'] ?? '' );
		$sector  = self::text( $params['industry_sector'] ?? '' );
		$tier    = self::tier( $params['tier_level'] ?? 1 );

		if ( '' === $name ) {
			return new WP_Error( 'wm_lksg_invalid_name', __( 'Der Lieferantenname fehlt.', 'wm-lksg-supply-auditor' ), [ 'status' => 400 ] );
		}
		if ( ! Countries::is_valid( $country ) ) {
			return new WP_Error( 'wm_lksg_invalid_country', self::country_message(), [ 'status' => 400 ] );
		}
		if ( null === $tier ) {
			return new WP_Error( 'wm_lksg_invalid_tier', __( 'Die Lieferantenstufe muss 1, 2 oder 3 sein.', 'wm-lksg-supply-auditor' ), [ 'status' => 400 ] );
		}

		$factors     = RiskFactors::from_input( $params, RiskFactors::derived_from( $country, $sector ) );
		$risk_result = $this->risk_calculator->calculate_risk( $factors );

		$table = Schema::table( 'suppliers' );
		$now   = current_time( 'mysql', true );
		$uuid  = wp_generate_uuid4();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$table,
			[
				// uuid is NOT NULL UNIQUE. Without it MySQL stored '' and the second supplier ever
				// registered collided with the first — while this handler still answered 201.
				'uuid'             => $uuid,
				'supplier_name'    => $name,
				'country_code'     => $country,
				'industry_sector'  => $sector,
				'tier_level'       => $tier,
				'dependency_ratio' => $factors['dependency_ratio'],
				'risk_score'       => $risk_result['score'],
				'risk_level'       => $risk_result['level'],
				'audit_status'     => RiskFactors::audit_status_for_level( (string) $risk_result['level'] ),
				'created_at'       => $now,
				'updated_at'       => $now,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%f', '%f', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return new WP_Error( 'wm_lksg_db_error', __( 'Der Lieferant konnte nicht gespeichert werden.', 'wm-lksg-supply-auditor' ), [ 'status' => 500 ] );
		}

		$supplier_id = (int) $wpdb->insert_id;
		if ( ! AssessmentLog::record( $supplier_id, $factors, $risk_result ) ) {
			// A supplier with a score and no record of how it was computed is what the history exists to prevent.
			$wpdb->delete( $table, [ 'id' => $supplier_id ], [ '%d' ] );
			return new WP_Error( 'wm_lksg_assessment_not_recorded', __( 'Die Bewertung konnte nicht protokolliert werden; der Lieferant wurde deshalb nicht gespeichert.', 'wm-lksg-supply-auditor' ), [ 'status' => 500 ] );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return new WP_REST_Response( [
			'success'     => true,
			'supplier_id' => $supplier_id,
			'uuid'        => $uuid,
			'risk'        => $risk_result,
			'factors'     => $factors,
		], 201 );
	}

	/**
	 * Accept a supplier self-disclosure from the public portal.
	 *
	 * The portal form used to submit nowhere at all: its handler called preventDefault(), waited
	 * 600 ms and swapped in a success panel. A supplier pressing the submit button under a paragraph
	 * affirming the absence of child and forced labour in their supply chain transmitted nothing and
	 * stored nothing, while being told it had worked.
	 *
	 * Until 2026-09-15 this route also accepted "XX" as a country and any string as a sector; it threw the declared
	 * certifications away after hashing them, so the signature could not be checked against anything stored; it
	 * wrote 0 ("no child-labour risk") into four score columns nobody had assessed; a failed audit row still answered
	 * 201; the answer carried the auto-increment id, which gave away the size of the register; and every accepted
	 * submission restarted the one-hour window.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_self_disclosure( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$bucket  = self::rate_limit_key( $address );
		$window  = get_transient( $bucket );
		$now_ts  = time();
		$count   = 0;
		$started = $now_ts;
		if ( is_array( $window ) && isset( $window['count'], $window['started'] ) && $now_ts - (int) $window['started'] < HOUR_IN_SECONDS ) {
			$count   = (int) $window['count'];
			$started = (int) $window['started'];
		}

		if ( $count >= self::MAX_DISCLOSURES_PER_HOUR ) {
			return new WP_Error(
				'wm_lksg_rate_limited',
				self::rate_limited_message(),
				[ 'status' => 429 ]
			);
		}

		$params = (array) $request->get_params();

		$name    = self::text( $params['supplier_name'] ?? '' );
		$country = Countries::normalize( $params['country_code'] ?? '' );
		$sector  = self::text( $params['industry_sector'] ?? '' );
		$tier    = self::tier( $params['tier_level'] ?? 1 );

		if ( '' === $name ) {
			return new WP_Error( 'wm_lksg_invalid_name', __( 'Der Unternehmensname fehlt.', 'wm-lksg-supply-auditor' ), [ 'status' => 400 ] );
		}
		if ( ! Countries::is_valid( $country ) ) {
			return new WP_Error( 'wm_lksg_invalid_country', self::country_message(), [ 'status' => 400 ] );
		}
		if ( ! in_array( $sector, SupplierPortal::SECTOR_KEYS, true ) ) {
			return new WP_Error( 'wm_lksg_invalid_sector', __( 'Die Branche ist keine der Auswahlmöglichkeiten des Formulars.', 'wm-lksg-supply-auditor' ), [ 'status' => 400 ] );
		}
		if ( null === $tier ) {
			return new WP_Error( 'wm_lksg_invalid_tier', __( 'Die Lieferantenstufe muss 1, 2 oder 3 sein.', 'wm-lksg-supply-auditor' ), [ 'status' => 400 ] );
		}

		// The declaration is the legally loaded part of this form: only an explicit true counts.
		if ( true !== self::flag( $params['declaration_accepted'] ?? null ) ) {
			return new WP_Error( 'wm_lksg_no_declaration', __( 'Die Erklärung wurde nicht bestätigt.', 'wm-lksg-supply-auditor' ), [ 'status' => 400 ] );
		}

		$now      = current_time( 'mysql', true );
		$uuid     = wp_generate_uuid4();
		$supplier = Schema::table( 'suppliers' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$supplier,
			[
				'uuid'            => $uuid,
				'supplier_name'   => $name,
				'country_code'    => $country,
				'industry_sector' => $sector,
				'tier_level'      => $tier,
				// A self-disclosure is an INPUT to a risk assessment, not the assessment. Writing
				// a score here would assert something nobody has evaluated.
				'risk_score'      => 0.00,
				'risk_level'      => 'unassessed',
				'audit_status'    => 'self_disclosed',
				'created_at'      => $now,
				'updated_at'      => $now,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return new WP_Error( 'wm_lksg_db_error', __( 'Die Übermittlung konnte nicht gespeichert werden.', 'wm-lksg-supply-auditor' ), [ 'status' => 500 ] );
		}

		$supplier_id = (int) $wpdb->insert_id;

		$audit = [
			'supplier_id'         => $supplier_id,
			'execution_date'      => $now,
			'audit_type'          => 'self_disclosure',
			// Nobody assessed these; NULL, not 0.
			'human_rights_score'  => null,
			'environmental_score' => null,
			'child_labor_risk'    => null,
			'forced_labor_risk'   => null,
			'declared_iso_14001'  => self::flag( $params['has_iso_14001'] ?? false ) ? 1 : 0,
			'declared_sa_8000'    => self::flag( $params['has_sa_8000'] ?? false ) ? 1 : 0,
			'declared_iso_45001'  => self::flag( $params['has_iso_45001'] ?? false ) ? 1 : 0,
			'declaration_text'    => self::declaration_text(),
			'signature_version'   => self::SIGNATURE_VERSION,
			'created_at'          => $now,
		];
		$audit['digital_signature'] = self::signature( $audit );

		$audit_inserted = $wpdb->insert(
			Schema::table( 'audits' ),
			$audit,
			[ '%d', '%s', '%s', '%f', '%f', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
		);
		if ( false === $audit_inserted ) {
			$wpdb->delete( $supplier, [ 'id' => $supplier_id ], [ '%d' ] );
			return new WP_Error( 'wm_lksg_db_error', __( 'Die Übermittlung konnte nicht gespeichert werden.', 'wm-lksg-supply-auditor' ), [ 'status' => 500 ] );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// The window runs one hour from the first accepted submission; later ones do not extend it.
		set_transient( $bucket, [ 'count' => $count + 1, 'started' => $started ], max( 1, HOUR_IN_SECONDS - ( $now_ts - $started ) ) );

		return new WP_REST_Response(
			[
				'success'      => true,
				'reference'    => $uuid,
				'audit_status' => 'self_disclosed',
				'message'      => __( 'Ihre Selbstauskunft wurde übermittelt und gespeichert. Die Risikobewertung nimmt der Auftraggeber vor.', 'wm-lksg-supply-auditor' ),
			],
			201
		);
	}

	/**
	 * The declaration a supplier affirms by ticking the box.
	 *
	 * Kept in one place so the wording shown in the portal and the wording stored and signed cannot drift apart. Until
	 * 2026-09-15 it spoke of "gravierenden Umweltbelastungen nach § 2 LkSG", a term § 2 does not use; it now names the
	 * provisions: § 2 Abs. 2 Nr. 1 to 3 (employment of children, worst forms of child labour, forced labour) and the
	 * environment-related prohibitions of § 2 Abs. 3.
	 *
	 * @return string
	 */
	public static function declaration_text(): string {
		return __( 'Ich bestätige, dass in unserer Betriebsstätte und unserer Lieferkette kein Verstoß gegen die Verbote der Kinderarbeit und der Zwangsarbeit (§ 2 Abs. 2 Nr. 1 bis 3 LkSG) und gegen die umweltbezogenen Verbote des § 2 Abs. 3 LkSG vorliegt.', 'wm-lksg-supply-auditor' );
	}

	/**
	 * What a self-disclosure signature covers: the supplier row, the stored text, the three declared certifications
	 * and the time, all read from the audit row, so a stored row can be checked.
	 *
	 * @param array<string, mixed> $audit Audit row (as written or as read back).
	 */
	public static function signature_payload( array $audit ): string {
		return (string) wp_json_encode(
			[
				'version'      => self::SIGNATURE_VERSION,
				'supplier_id'  => (int) ( $audit['supplier_id'] ?? 0 ),
				'declaration'  => (string) ( $audit['declaration_text'] ?? '' ),
				'iso_14001'    => (bool) (int) ( $audit['declared_iso_14001'] ?? 0 ),
				'sa_8000'      => (bool) (int) ( $audit['declared_sa_8000'] ?? 0 ),
				'iso_45001'    => (bool) (int) ( $audit['declared_iso_45001'] ?? 0 ),
				'submitted_at' => (string) ( $audit['created_at'] ?? '' ),
			]
		);
	}

	/**
	 * HMAC-SHA256 keyed with wp_salt( 'secure_auth' ): the SECURE_AUTH_KEY/SALT constants of wp-config.php when they
	 * are set, otherwise a key WordPress generated and stored in the database.
	 *
	 * @param array<string, mixed> $audit Audit row.
	 */
	public static function signature( array $audit ): string {
		return hash_hmac( 'sha256', self::signature_payload( $audit ), wp_salt( 'secure_auth' ) );
	}

	/**
	 * true or false for a row written since signature version v2; null for older rows, which do not store what was
	 * signed.
	 *
	 * @param array<string, mixed> $audit Audit row as read from the database.
	 */
	public static function verify_signature( array $audit ): ?bool {
		if ( self::SIGNATURE_VERSION !== ( $audit['signature_version'] ?? null ) || ! isset( $audit['declaration_text'] ) ) {
			return null;
		}
		return hash_equals( self::signature( $audit ), (string) ( $audit['digital_signature'] ?? '' ) );
	}

	/**
	 * Transient key of the self-disclosure rate limit for a client address (keyed hash, the address is not stored).
	 */
	public static function rate_limit_key( string $address ): string {
		return 'wm_lksg_sd_' . substr( hash_hmac( 'sha256', $address, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Transient key of the per-minute REST permission rate limit (HMAC-SHA256 hash, raw IP never stored).
	 */
	public static function rest_rate_limit_key( string $address ): string {
		return 'wm_lksg_rl_' . substr( hash_hmac( 'sha256', $address, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Register summary for one calendar year (`?year=`, default: the settings tab, else the previous calendar year).
	 * Until 2026-09-15 the current calendar year while the admin tab used the setting.
	 */
	public function get_bafa_report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$raw  = $request->get_param( 'year' );
		$year = null === $raw ? Settings::reporting_year() : ( is_numeric( $raw ) ? (int) $raw : 0 );
		if ( ! Settings::year_in_range( $year ) ) {
			return new WP_Error( 'wm_lksg_invalid_year', __( 'Das Jahr liegt außerhalb des zulässigen Bereichs.', 'wm-lksg-supply-auditor' ), [ 'status' => 400 ] );
		}

		return new WP_REST_Response( [
			'success' => true,
			'report'  => $this->bafa_exporter->generate_bafa_report_data( $year ),
		] );
	}

	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/** 1, 2 or 3; null for anything else. */
	private static function tier( mixed $value ): ?int {
		if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) ) {
			$tier = (int) $value;
			return $tier >= 1 && $tier <= 3 ? $tier : null;
		}
		return null;
	}

	/** A boolean from JSON, a form or core's sanitised arguments; "false", "0" and "" are false. */
	private static function flag( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		return is_string( $value ) && true === filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	}
}

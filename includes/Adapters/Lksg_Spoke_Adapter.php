<?php
/**
 * WM Suite LkSG Supply Chain Auditor — Suite Hub Spoke Adapter
 *
 * Health from the tables and a self-test of the risk matrix; telemetry from the register; one SBOM component.
 *
 * Until 2026-09-15 health was the literal HEALTHY, telemetry reported `bafa_export_engine: READY` and counted
 * anonymous self-disclosures as "completed_audits", `risk_eval` ignored its parameters (severity 0 and 100 both
 * gave 56.30) and the self-test reported success for a fixed sample whatever it computed, and the SBOM carried
 * "compliance" properties for §§ 1-10 LkSG, the EU CSDDD and a "§ 10 BAFA report generator".
 *
 * @package WMCyber\LkSG
 */

declare(strict_types=1);

namespace WMCyber\LkSG\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	return;
}

use ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface;
use WMCyber\LkSG\Core\Plugin;
use WMCyber\LkSG\Database\Register;
use WMCyber\LkSG\Database\Schema;
use WMCyber\LkSG\Risk\Countries;
use WMCyber\LkSG\Risk\RiskFactors;

final class Lksg_Spoke_Adapter implements WM_Plugin_Module_Interface {

	/** Factor sets with the level the matrix must return for each. */
	private const SELF_TEST_CASES = [
		'low'      => [
			'factors' => [ 'country_cpi_risk' => 15.0, 'sector_risk' => 15.0, 'product_risk' => 10.0, 'severity_score' => 10.0, 'probability_score' => 10.0, 'dependency_ratio' => 10.0, 'causal_contribution' => 10.0, 'has_iso_14001' => false, 'has_sa_8000' => false ],
			'level'   => 'low',
		],
		'high'     => [
			'factors' => [ 'country_cpi_risk' => 60.0, 'sector_risk' => 70.0, 'product_risk' => 60.0, 'severity_score' => 70.0, 'probability_score' => 60.0, 'dependency_ratio' => 30.0, 'causal_contribution' => 20.0, 'has_iso_14001' => false, 'has_sa_8000' => false ],
			'level'   => 'high',
		],
		'critical' => [
			'factors' => [ 'country_cpi_risk' => 15.0, 'sector_risk' => 15.0, 'product_risk' => 10.0, 'severity_score' => 95.0, 'probability_score' => 10.0, 'dependency_ratio' => 10.0, 'causal_contribution' => 10.0, 'has_iso_14001' => false, 'has_sa_8000' => false ],
			'level'   => 'critical',
		],
	];

	/** @var (callable(array): array)|null Replaces the risk matrix in the self-test; the hub constructs without it. */
	private $calculate;

	public function __construct( ?callable $calculate = null ) {
		$this->calculate = $calculate;
	}

	public function get_module_slug(): string {
		return 'wm-lksg-supply-auditor';
	}

	public function get_name(): string {
		return 'WM LkSG Supply Chain Auditor';
	}

	public function get_version(): string {
		return defined( 'WM_LKSG_VERSION' ) ? WM_LKSG_VERSION : '1.2.3';
	}

	public function get_description(): string {
		return 'Lieferantenregister mit einer zweistufigen heuristischen Risikopunktzahl, einem öffentlichen Selbstauskunftsformular und einer Jahresübersicht der Registerzahlen. Erstellt keinen BAFA-Bericht.';
	}

	/**
	 * `ERROR` when a table is missing or the risk-matrix self-test fails, `HEALTHY` otherwise.
	 */
	public function get_health_status(): string {
		if ( [] !== Schema::missing_tables() || ! $this->self_test()['success'] ) {
			return 'ERROR';
		}
		return 'HEALTHY';
	}

	public function get_sbom_data(): array {
		return [
			'bom-ref'     => 'pkg:wordpress/wm-lksg-supply-auditor@' . $this->get_version(),
			'type'        => 'application',
			'name'        => $this->get_name(),
			'version'     => $this->get_version(),
			'description' => $this->get_description(),
			'scope'       => 'required',
			'hashes'      => [
				[
					'alg'     => 'SHA-256',
					'content' => hash_file( 'sha256', WM_LKSG_FILE ),
				],
			],
			'licenses'    => [
				[
					'license' => [
						'id' => 'GPL-2.0-or-later',
					],
				],
			],
			'properties'  => [
				[
					'name'  => 'wm:features:risk-score',
					'value' => 'two levels with fixed heuristic weights: country, sector, product; severity, probability, dependency, causal contribution, ISO 14001, SA 8000',
				],
				[
					'name'  => 'wm:features:yearly-summary',
					'value' => 'register counts per calendar year; not a report under § 10 Abs. 2 LkSG',
				],
				[
					'name'  => 'wm:features:outbound-requests',
					'value' => 'none',
				],
			],
		];
	}

	public function get_dependencies(): array {
		return [];
	}

	public function get_settings_url(): ?string {
		// The menu slug is wm-lksg-auditor; wm-lksg-dashboard was never registered.
		return admin_url( 'admin.php?page=wm-lksg-auditor&tab=settings' );
	}

	public function get_telemetry_metrics(): array {
		global $wpdb;
		$counts = Register::counts();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$assessments = Schema::table_exists( 'risk_assessments' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'risk_assessments' ) ) ) : null;
		$disclosures = Schema::table_exists( 'audits' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE audit_type = %s', Schema::table( 'audits' ), 'self_disclosure' ) ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return [
			'suppliers_registered'       => $counts['total'],
			'suppliers_unassessed'       => $counts['unassessed'],
			'suppliers_high_or_critical' => $counts['high_or_critical'],
			'risk_assessments_recorded'  => $assessments,
			'self_disclosures_received'  => $disclosures,
			'missing_tables'             => count( Schema::missing_tables() ),
			'risk_matrix_self_test'      => $this->self_test()['success'] ? 'PASS' : 'FAIL',
			'bafa_report'                => 'not_produced',
			'outbound_requests'          => 0,
		];
	}

	public function execute_action( string $action, array $params = [] ): array {
		switch ( $action ) {
			case 'self_test':
				return $this->self_test();

			case 'risk_eval':
				$country = Countries::normalize( $params['country_code'] ?? '' );
				$sector  = is_scalar( $params['industry_sector'] ?? null ) ? (string) $params['industry_sector'] : '';
				$factors = RiskFactors::from_input( $params, RiskFactors::derived_from( Countries::is_valid( $country ) ? $country : '', $sector ) );
				$eval    = $this->calculate( $factors );
				return [
					'success' => true,
					'message' => sprintf( 'Bewertung: Score %.2f, Stufe %s, Auslöser %s, Flags: %s.', (float) $eval['score'], (string) $eval['level'], (string) $eval['action_trigger'], $eval['high_risk_flags'] ? implode( ', ', (array) $eval['high_risk_flags'] ) : 'keine' ),
					'result'  => $eval,
					'factors' => $factors,
				];

			default:
				// Plain text: the hub escapes where it renders.
				return [
					'success' => false,
					'message' => sprintf( 'Unbekannte Spoke-Aktion "%s".', $action ),
				];
		}
	}

	/**
	 * The risk matrix against three fixed factor sets and the order of two country tiers; the message says what came
	 * out.
	 *
	 * @return array{success: bool, message: string}
	 */
	private function self_test(): array {
		$parts = [];
		$ok    = true;
		try {
			foreach ( self::SELF_TEST_CASES as $name => $case ) {
				$got   = $this->calculate( $case['factors'] );
				$match = $case['level'] === ( $got['level'] ?? null );
				$ok    = $ok && $match;
				$parts[] = sprintf( '%s %.2f → %s%s', $name, (float) ( $got['score'] ?? 0 ), (string) ( $got['level'] ?? '?' ), $match ? '' : ' (erwartet ' . $case['level'] . ')' );
			}
		} catch ( \Throwable $e ) {
			return [ 'success' => false, 'message' => 'Risikomatrix-Selbsttest fehlgeschlagen: ' . $e->getMessage() ];
		}

		$tiers_ordered = (float) RiskFactors::country_cpi_risk( 'BD' ) > (float) RiskFactors::country_cpi_risk( 'DE' );
		$ok            = $ok && $tiers_ordered;
		$parts[]       = $tiers_ordered ? 'Länderstufe BD über DE' : 'Länderstufe BD nicht über DE';

		return [
			'success' => $ok,
			'message' => ( $ok ? 'Risikomatrix-Selbsttest bestanden: ' : 'Risikomatrix-Selbsttest fehlgeschlagen: ' ) . implode( '; ', $parts ) . '.',
		];
	}

	/** @param array<string, float|bool> $factors */
	private function calculate( array $factors ): array {
		return null !== $this->calculate ? ( $this->calculate )( $factors ) : Plugin::instance()->get_risk_calculator()->calculate_risk( $factors );
	}
}

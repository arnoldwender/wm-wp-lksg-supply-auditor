<?php
declare(strict_types=1);

namespace WMCyber\LkSG\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WMCyber\LkSG\Risk\RiskFactors;
use WMCyber\LkSG\Risk\RiskMatrixCalculator;

/**
 * RiskFactors is the one place that turns what a caller has (a REST body, CLI flags, the admin
 * form) into the keys RiskMatrixCalculator actually reads.
 *
 * Until 2026-09-14 the four production entry points passed `human_rights_indicator` and
 * `environmental_indicator`, keys the calculator never read (commit 1d57522 measured the score
 * stuck at 42.60 for any value). The admin calculator form did not even send the country/sector
 * risk fields its handler read, so every supplier — Germany or Bangladesh, software or mining —
 * scored the same.
 */
final class RiskFactorsTest extends TestCase {

	public function test_legacy_keys_are_translated_instead_of_dropped(): void {
		$calc = new RiskMatrixCalculator();
		$base = [ 'country_cpi_risk' => 40.0, 'sector_risk' => 45.0 ];

		$scores = [];
		foreach ( [ 0.0, 50.0, 100.0 ] as $v ) {
			$scores[] = $calc->calculate_risk( RiskFactors::from_input( $base + [ 'human_rights_indicator' => $v, 'environmental_indicator' => $v ] ) )['score'];
		}

		$this->assertCount( 3, array_unique( $scores ), 'the human-rights channel must move the score once it is translated' );
		$this->assertSame( $scores, array_values( $scores ) );
		$this->assertLessThan( $scores[1], $scores[0] );
		$this->assertLessThan( $scores[2], $scores[1] );
	}

	public function test_canonical_keys_win_over_their_alias(): void {
		$f = RiskFactors::from_input( [ 'severity_score' => 10.0, 'human_rights_indicator' => 90.0 ] );
		$this->assertSame( 10.0, $f['severity_score'] );
	}

	public function test_values_are_clamped_and_booleans_parsed(): void {
		$f = RiskFactors::from_input( [ 'severity_score' => 250, 'probability_score' => -5, 'dependency_ratio' => '55.5', 'has_iso_14001' => 'false', 'has_sa_8000' => '1' ] );
		$this->assertSame( 100.0, $f['severity_score'] );
		$this->assertSame( 0.0, $f['probability_score'] );
		$this->assertSame( 55.5, $f['dependency_ratio'] );
		$this->assertFalse( $f['has_iso_14001'] );
		$this->assertTrue( $f['has_sa_8000'] );
	}

	public function test_country_and_sector_tiers_order_as_the_admin_labels_promise(): void {
		$this->assertLessThan( RiskFactors::country_cpi_risk( 'BD' ), RiskFactors::country_cpi_risk( 'DE' ) );
		$this->assertLessThan( RiskFactors::country_cpi_risk( 'CN' ), RiskFactors::country_cpi_risk( 'TW' ) );
		$this->assertNull( RiskFactors::country_cpi_risk( 'XX' ), 'an unknown country must not be scored as anything' );

		$software = RiskFactors::sector_profile( 'it_software' );
		$mining   = RiskFactors::sector_profile( 'mining_raw' );
		$this->assertNotNull( $software );
		$this->assertNotNull( $mining );
		$this->assertLessThan( $mining['sector_risk'], $software['sector_risk'] );
		$this->assertLessThan( $mining['product_risk'], $software['product_risk'] );
		$this->assertNull( RiskFactors::sector_profile( 'unknown' ) );
	}

	public function test_audit_status_never_calls_a_critical_supplier_verified(): void {
		$this->assertSame( 'mitigation_required', RiskFactors::audit_status_for_level( 'critical' ) );
		$this->assertSame( 'mitigation_required', RiskFactors::audit_status_for_level( 'high' ) );
		$this->assertSame( 'in_review', RiskFactors::audit_status_for_level( 'medium' ) );
		$this->assertSame( 'evaluated', RiskFactors::audit_status_for_level( 'low' ) );
		$this->assertSame( 'unassessed', RiskFactors::audit_status_for_level( 'anything else' ) );
	}

	public function test_cli_and_admin_mappings_reach_the_calculator(): void {
		$cli = RiskFactors::from_cli_args( [ 'country' => 'VN', 'severity' => '95', 'probability' => '70', 'dependency' => '85', 'causality' => '20', 'iso14001' => true ] );
		$this->assertSame( 95.0, $cli['severity_score'] );
		$this->assertSame( 85.0, $cli['dependency_ratio'] );
		$this->assertTrue( $cli['has_iso_14001'] );
		$this->assertSame( RiskFactors::country_cpi_risk( 'VN' ), $cli['country_cpi_risk'], 'without --cpi the country tier applies' );

		$admin = RiskFactors::from_admin_form( [ 'wm_calc_country' => 'IN', 'wm_calc_sector' => 'agriculture', 'wm_calc_severity' => '88', 'wm_calc_sa8000' => '1' ] );
		$this->assertSame( 88.0, $admin['severity_score'] );
		$this->assertTrue( $admin['has_sa_8000'] );
		$this->assertSame( RiskFactors::country_cpi_risk( 'IN' ), $admin['country_cpi_risk'] );
		$this->assertSame( 'critical', ( new RiskMatrixCalculator() )->calculate_risk( $admin )['level'] );
	}
}

<?php
declare(strict_types=1);

namespace WMCyber\LkSG\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WMCyber\LkSG\Risk\RiskMatrixCalculator;

/**
 * Tests de RiskMatrixCalculator.
 *
 * CONTEXTO — divergencia de tres vías encontrada el 2026-08-31
 * ------------------------------------------------------------
 * Este fichero pasaba `human_rights_indicator` y `environmental_indicator`,
 * claves que el calculador NO lee. El modelo implementado es de dos niveles y
 * usa las Angemessenheitskriterien del § 3 Abs. 2 LkSG (Schwere,
 * Wahrscheinlichkeit, Verursachungsbeitrag, Einflussvermögen):
 *
 *   nivel 1 (abstracto) = country·0.40 + sector·0.40 + product·0.20
 *   nivel 2 (concreto)  = severity·0.40 + probability·0.40
 *                       + dependency·0.10 + causality·0.10 − certificaciones
 *   final               = abstracto·0.30 + concreto·0.70
 *
 * `docs/ARCHITECTURE.md` documentaba OTRO modelo, de un solo nivel
 * (0.30·C + 0.25·S + 0.25·H + 0.20·E); desde el 2026-09-14 documenta el de
 * dos niveles que implementa el código. Los pesos son heurísticos: el § 3
 * Abs. 2 LkSG nombra criterios, no pesos, y el calculador pide la dependencia
 * del proveedor, no el Einflussvermögen.
 */
final class RiskMatrixCalculatorTest extends TestCase {
	private RiskMatrixCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new RiskMatrixCalculator();
	}

	public function test_low_risk_certified_supplier(): void {
		$res = $this->calculator->calculate_risk( [
			'country_cpi_risk'    => 20.0,
			'sector_risk'         => 25.0,
			'product_risk'        => 20.0,
			'severity_score'      => 15.0,
			'probability_score'   => 20.0,
			'dependency_ratio'    => 10.0,
			'causal_contribution' => 10.0,
			'has_iso_14001'       => true,
			'has_sa_8000'         => true,
		] );

		$this->assertSame( 'low', $res['level'] );
		$this->assertLessThan( 25.0, $res['score'] );
		$this->assertSame( 'NO_ACTION', $res['action_trigger'] );
	}

	public function test_high_risk_uncertified_supplier(): void {
		$res = $this->calculator->calculate_risk( [
			'country_cpi_risk'    => 85.0,
			'sector_risk'         => 80.0,
			'product_risk'        => 85.0,
			'severity_score'      => 90.0,
			'probability_score'   => 80.0,
			'dependency_ratio'    => 85.0,
			'causal_contribution' => 70.0,
			'has_iso_14001'       => false,
			'has_sa_8000'         => false,
		] );

		// severity 90 >= 85 dispara 'critical' por la vía directa del § 3.
		$this->assertSame( 'critical', $res['level'] );
		$this->assertSame( 'CAPA_REQUIRED', $res['action_trigger'] );
		$this->assertGreaterThanOrEqual( 75.0, $res['score'] );
		$this->assertContains( 'HIGH_COUNTRY_CORRUPTION_RISK', $res['high_risk_flags'] );
		$this->assertContains( 'HIGH_HUMAN_RIGHTS_EXPOSURE', $res['high_risk_flags'] );
		$this->assertContains( 'CRITICAL_ENVIRONMENTAL_HAZARD', $res['high_risk_flags'] );
		$this->assertContains( 'HIGH_SUPPLIER_DEPENDENCY', $res['high_risk_flags'] );
	}

	public function test_certifications_reduce_the_concrete_score(): void {
		$factors = [
			'country_cpi_risk'    => 50.0,
			'sector_risk'         => 50.0,
			'product_risk'        => 50.0,
			'severity_score'      => 60.0,
			'probability_score'   => 60.0,
			'dependency_ratio'    => 20.0,
			'causal_contribution' => 20.0,
		];

		$sin = $this->calculator->calculate_risk(
			$factors + [ 'has_iso_14001' => false, 'has_sa_8000' => false ]
		);
		$con = $this->calculator->calculate_risk(
			$factors + [ 'has_iso_14001' => true, 'has_sa_8000' => true ]
		);

		// ISO 14001 (−10) + SA 8000 (−15) = −25 sobre el concreto, que pesa 0.70.
		$this->assertEqualsWithDelta( 17.5, $sin['score'] - $con['score'], 0.01 );
	}

	public function test_concrete_score_never_leaves_the_0_100_band(): void {
		$res = $this->calculator->calculate_risk( [
			'severity_score'    => 0.0,
			'probability_score' => 0.0,
			'has_iso_14001'     => true,
			'has_sa_8000'       => true,
		] );
		// −25 de certificaciones sobre una base baja no puede dar negativo.
		$this->assertGreaterThanOrEqual( 0.0, $res['concrete_score'] );
		$this->assertLessThanOrEqual( 100.0, $res['concrete_score'] );
	}

	/**
	 * El calculador ignora las claves que no son suyas; los llamadores traducen.
	 *
	 * Historia: hasta el 2026-09-14 los cuatro puntos de entrada de producción
	 * (CLI, REST, Admin, Spoke) pasaban `human_rights_indicator` y
	 * `environmental_indicator` directamente aquí, el calculador las descartaba
	 * y el 70 % del score salía de los valores por defecto — medido: score
	 * clavado en 42.60 para cualquier valor del indicador de derechos humanos.
	 * Este test nació como caracterización de ese defecto.
	 *
	 * El arreglo no cambió el calculador: cambió a los llamadores, que ahora
	 * pasan por RiskFactors::from_input(), donde las claves antiguas se traducen
	 * (human_rights_indicator → severity_score, environmental_indicator →
	 * product_risk; ver RiskFactorsTest). Este test sigue siendo verdad y sigue
	 * teniendo valor: garantiza que el calculador NO adquiere alias propios a
	 * escondidas y que la traducción vive en un solo sitio.
	 */
	public function test_el_calculador_ignora_claves_ajenas_y_la_traduccion_vive_en_RiskFactors(): void {
		$base = [
			'country_cpi_risk' => 40.0,
			'sector_risk'      => 45.0,
			'has_iso_14001'    => false,
			'has_sa_8000'      => false,
		];

		$scores = [];
		foreach ( [ 0.0, 50.0, 100.0 ] as $v ) {
			$res = $this->calculator->calculate_risk(
				$base + [
					'human_rights_indicator'  => $v,
					'environmental_indicator' => $v,
				]
			);
			$scores[] = $res['score'];
		}

		$this->assertSame(
			[ 42.60, 42.60, 42.60 ],
			$scores,
			'El calculador no debe leer claves ajenas por su cuenta; la traducción '
			. 'está en RiskFactors::from_input() y se prueba en RiskFactorsTest.'
		);

		// Control positivo: el canal que el código SÍ lee sí mueve el score.
		// Sin esto, el assert de arriba podría estar midiendo un calculador
		// muerto en vez de un canal muerto.
		$vivo = [];
		foreach ( [ 0.0, 50.0, 100.0 ] as $v ) {
			$res = $this->calculator->calculate_risk(
				$base + [ 'severity_score' => $v, 'probability_score' => $v ]
			);
			$vivo[] = $res['score'];
		}
		$this->assertCount( 3, array_unique( $vivo ), 'severity/probability sí deben mover el score' );
	}
}

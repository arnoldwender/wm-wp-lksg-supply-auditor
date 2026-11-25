<?php
declare(strict_types=1);

namespace WMCyber\LkSG\Risk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RiskMatrixCalculator {
	
	/**
	 * Compute composite LkSG supplier risk score using two-level evaluation.
	 *
	 * @param array{
	 *   country_cpi_risk: float,
	 *   sector_risk: float,
	 *   product_risk: float,
	 *   severity_score: float,
	 *   probability_score: float,
	 *   dependency_ratio: float,
	 *   causal_contribution: float,
	 *   has_iso_14001: bool,
	 *   has_sa_8000: bool
	 * } $factors
	 * @return array{
	 *   abstract_score: float,
	 *   concrete_score: float,
	 *   score: float,
	 *   level: string,
	 *   high_risk_flags: array<string>,
	 *   action_trigger: string
	 * }
	 */
	public function calculate_risk( array $factors ): array {
		// 1. Level 1: Abstract Risk (Sektor, Land, Produkt)
		$country = (float) ( $factors['country_cpi_risk'] ?? 50.0 );
		$sector  = (float) ( $factors['sector_risk'] ?? 50.0 );
		$product = (float) ( $factors['product_risk'] ?? 50.0 );
		
		$abstract_score = ( $country * 0.40 ) + ( $sector * 0.40 ) + ( $product * 0.20 );

		// 2. Level 2: Concrete Risk / Angemessenheitskriterien (§ 3 Abs. 2 LkSG)
		$severity    = (float) ( $factors['severity_score'] ?? 50.0 );
		$probability = (float) ( $factors['probability_score'] ?? 50.0 );
		$dependency  = (float) ( $factors['dependency_ratio'] ?? 10.0 );
		$causality   = (float) ( $factors['causal_contribution'] ?? 10.0 );

		// Base concrete risk heavily relies on severity and probability
		$concrete_raw = ( $severity * 0.40 ) + ( $probability * 0.40 ) + ( $dependency * 0.10 ) + ( $causality * 0.10 );

		// Certifications reduce the operational concrete risk
		if ( ! empty( $factors['has_iso_14001'] ) ) {
			$concrete_raw -= 10.0;
		}
		if ( ! empty( $factors['has_sa_8000'] ) ) {
			$concrete_raw -= 15.0;
		}

		$concrete_score = max( 0.0, min( 100.0, $concrete_raw ) );

		// The final overall score is heavily weighted towards the concrete risk, but abstract risk serves as a baseline
		$final_score = ( $abstract_score * 0.30 ) + ( $concrete_score * 0.70 );

		// Flags
		$flags = [];
		if ( $country > 70.0 ) {
			$flags[] = 'HIGH_COUNTRY_CORRUPTION_RISK';
		}
		if ( $severity > 75.0 ) {
			$flags[] = 'HIGH_HUMAN_RIGHTS_EXPOSURE';
		}
		if ( $product > 80.0 ) {
			$flags[] = 'CRITICAL_ENVIRONMENTAL_HAZARD';
		}
		if ( $dependency > 80.0 ) {
			$flags[] = 'HIGH_SUPPLIER_DEPENDENCY';
		}

		// Level & Action Trigger
		if ( $final_score >= 75.0 || $severity >= 85.0 ) {
			$level          = 'critical';
			$action_trigger = 'CAPA_REQUIRED';
		} elseif ( $final_score >= 50.0 ) {
			$level          = 'high';
			$action_trigger = 'AUDIT_REQUIRED';
		} elseif ( $final_score >= 25.0 ) {
			$level          = 'medium';
			$action_trigger = 'QUESTIONNAIRE_REQUIRED';
		} else {
			$level          = 'low';
			$action_trigger = 'NO_ACTION';
		}

		$result = [
			'abstract_score'  => round( $abstract_score, 2 ),
			'concrete_score'  => round( $concrete_score, 2 ),
			'score'           => round( $final_score, 2 ),
			'level'           => $level,
			'high_risk_flags' => $flags,
			'action_trigger'  => $action_trigger,
		];

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the computed heuristic supplier risk evaluation result.
			 *
			 * @param array<string, mixed>      $result  Calculated score, level, flags and trigger.
			 * @param array<string, float|bool> $factors Normalized input risk factors.
			 */
			$filtered = apply_filters( 'wm_lksg_risk_score', $result, $factors );
			if ( is_array( $filtered ) ) {
				$result = array_merge( $result, $filtered );
			}
		}

		return $result;
	}
}

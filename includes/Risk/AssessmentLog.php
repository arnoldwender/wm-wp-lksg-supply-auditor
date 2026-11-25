<?php
declare(strict_types=1);

namespace WMCyber\LkSG\Risk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\LkSG\Database\Schema;

/**
 * Writes one row per evaluation into wm_lksg_risk_assessments.
 *
 * The table existed since 1.0.0 and nothing wrote to it until 2026-09-14. From then until 2026-09-15 a row kept four
 * numbers (abstract score, severity, concrete score, trigger): the composite score, the level, eight of the nine
 * factors and the certifications were lost, so a stored evaluation could not be reproduced, and a failed insert was
 * ignored while the caller reported the evaluation as recorded. A row now keeps the composite score, the level and
 * every factor as JSON, and the callers remove the supplier row again when this returns false.
 */
final class AssessmentLog {

	/** risk_category_id of rows written by the admin demo button. */
	public const SOURCE_DEMO = 'demo_seed';

	/**
	 * @param int                       $supplier_id Row id in wm_lksg_suppliers.
	 * @param array<string, float|bool> $factors     What the calculator was given (RiskFactors::from_input()).
	 * @param array<string, mixed>      $risk        What it returned (RiskMatrixCalculator::calculate_risk()).
	 * @param string                    $source      `lksg_composite`, or `demo_seed` for the demo button.
	 */
	public static function record( int $supplier_id, array $factors, array $risk, string $source = 'lksg_composite' ): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- append-only history table owned by this plugin.
		$inserted = $wpdb->insert(
			Schema::table( 'risk_assessments' ),
			[
				'assessment_id'       => wp_generate_uuid4(),
				'supplier_id'         => $supplier_id,
				'assessment_date'     => $now,
				'risk_category_id'    => $source,
				'abstract_score'      => (float) ( $risk['abstract_score'] ?? 0.0 ),
				'severity_score'      => (float) ( $factors['severity_score'] ?? 0.0 ),
				'total_concrete_risk' => (float) ( $risk['concrete_score'] ?? 0.0 ),
				'composite_score'     => (float) ( $risk['score'] ?? 0.0 ),
				'risk_level'          => (string) ( $risk['level'] ?? '' ),
				'action_trigger'      => (string) ( $risk['action_trigger'] ?? '' ),
				'factors'             => (string) wp_json_encode( $factors ),
				'created_at'          => $now,
			],
			[ '%s', '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s' ]
		);

		return false !== $inserted;
	}
}

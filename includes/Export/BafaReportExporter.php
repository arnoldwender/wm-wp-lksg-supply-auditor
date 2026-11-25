<?php
/**
 * Yearly summary of the supplier register (route /bafa-report, `wp lksg export-bafa`, admin tab "Jahresübersicht").
 *
 * Until 2026-09-15 this returned `bafa_report_standard: BAFA_LKSG_JAHRESBERICHT_V2026` (an identifier without a
 * source), `policy_statement_active`, `preventative_actions_taken` and `trainings_conducted` as the literal `true`,
 * a "human_rights_officer" and a "WM HinSchG Whistleblower Interlinked Gateway" that exist nowhere, on an empty
 * register as on a full one. `total_tier1_suppliers` counted every tier, `completed_risk_audits` counted anonymous
 * self-disclosures, and the reporting year was echoed but filtered nothing: 2019 and 2026 gave the same numbers.
 *
 * The class and method keep their names for the callers; what comes back is a count of what the register holds for
 * one calendar year (UTC), with the facts the register does not hold listed by name instead of asserted.
 *
 * @package WMCyber\LkSG
 */

declare(strict_types=1);

namespace WMCyber\LkSG\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\LkSG\Database\Schema;

final class BafaReportExporter {

	public const REPORT_TYPE = 'wm_lksg_register_summary';

	/** What a report under § 10 Abs. 2 LkSG has to cover and this plugin does not record, with the provision. */
	public const NOT_RECORDED = [
		'responsible_person'          => '§ 4 Abs. 3 LkSG',
		'policy_statement'            => '§ 6 Abs. 2 LkSG',
		'prevention_measures'         => '§ 6 Abs. 1, 3 und 4 LkSG',
		'remedial_measures'           => '§ 7 LkSG',
		'complaints_procedure'        => '§ 8 LkSG',
		'assessment_of_effectiveness' => '§ 10 Abs. 2 Satz 2 Nr. 3 LkSG',
	];

	/**
	 * Counts for one calendar year: the register as it stood at the end of the year, and what was recorded during it.
	 * A count is null when its table is missing.
	 *
	 * @param int $reporting_year Four-digit year.
	 * @return array<string, mixed>
	 */
	public function generate_bafa_report_data( int $reporting_year ): array {
		global $wpdb;

		$from = sprintf( '%04d-01-01 00:00:00', $reporting_year );
		$to   = sprintf( '%04d-01-01 00:00:00', $reporting_year + 1 );

		$suppliers   = Schema::table_exists( 'suppliers' ) ? Schema::table( 'suppliers' ) : null;
		$assessments = Schema::table_exists( 'risk_assessments' ) ? Schema::table( 'risk_assessments' ) : null;
		$audits      = Schema::table_exists( 'audits' ) ? Schema::table( 'audits' ) : null;

		// One literal query per count, values through prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$register = array_fill_keys( [ 'suppliers', 'tier_1_direct', 'tier_2_or_higher', 'unassessed', 'high_or_critical_level_now' ], null );
		if ( null !== $suppliers ) {
			$register = [
				'suppliers'                  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at < %s', $suppliers, $to ) ),
				'tier_1_direct'              => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at < %s AND tier_level = %d', $suppliers, $to, 1 ) ),
				'tier_2_or_higher'           => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at < %s AND tier_level >= %d', $suppliers, $to, 2 ) ),
				'unassessed'                 => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at < %s AND risk_level = %s', $suppliers, $to, 'unassessed' ) ),
				'high_or_critical_level_now' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at < %s AND risk_level IN (%s, %s)', $suppliers, $to, 'high', 'critical' ) ),
			];
		}
		$assessed  = null === $assessments ? null : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE assessment_date >= %s AND assessment_date < %s', $assessments, $from, $to ) );
		$disclosed = null === $audits ? null : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE audit_type = %s AND execution_date >= %s AND execution_date < %s', $audits, 'self_disclosure', $from, $to ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return [
			'report_type'            => self::REPORT_TYPE,
			'note'                   => 'Counts from the supplier register of this plugin. This is not a report under § 10 Abs. 2 LkSG and not a BAFA form. Levels are the current ones; the register keeps no level history for rows written before 2026-09-15.',
			'reporting_year'         => $reporting_year,
			'period_utc'             => [
				'from'  => sprintf( '%04d-01-01', $reporting_year ),
				'until' => sprintf( '%04d-12-31', $reporting_year ),
			],
			'generated_at'           => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'register_at_period_end' => $register,
			'recorded_in_period'     => [
				'risk_assessments' => $assessed,
				'self_disclosures' => $disclosed,
			],
			'not_recorded'           => self::NOT_RECORDED,
			'missing_tables'         => Schema::missing_tables(),
		];
	}
}

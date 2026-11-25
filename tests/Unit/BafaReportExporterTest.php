<?php
declare(strict_types=1);

namespace WMCyber\LkSG\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WMCyber\LkSG\Export\BafaReportExporter;

/**
 * The yearly summary counts what the register holds for one calendar year and asserts nothing else.
 *
 * Until 2026-09-15 this test pinned the defect: it asserted the identifier BAFA_LKSG_JAHRESBERICHT_V2026 and that a
 * mocked COUNT(*) of 42 landed in total_tier1_suppliers, while the exporter returned policy_statement_active,
 * trainings_conducted and preventative_actions_taken as the literal true and ignored the year.
 */
final class BafaReportExporterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['lksg_unit_sql'] = [];
		$GLOBALS['wpdb']          = new class() {
			public string $prefix = 'wp_';

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $query, mixed ...$args ): string {
				return (string) preg_replace_callback(
					'/%[isdf]/',
					static function ( array $m ) use ( &$args ): string {
						$value = array_shift( $args );
						return '%d' === $m[0] ? (string) (int) $value : "'" . (string) $value . "'";
					},
					$query
				);
			}

			public function get_var( string $query ): ?string {
				$GLOBALS['lksg_unit_sql'][] = $query;
				if ( 1 === preg_match( "/^SHOW TABLES LIKE '(.*)'$/", $query, $m ) ) {
					return stripslashes( $m[1] );
				}
				return '7';
			}
		};
	}

	public function test_counts_are_filtered_by_the_calendar_year_and_the_tier(): void {
		$report = ( new BafaReportExporter() )->generate_bafa_report_data( 2026 );
		$counts = implode( "\n", array_filter( $GLOBALS['lksg_unit_sql'], static fn( string $q ): bool => str_starts_with( $q, 'SELECT COUNT' ) ) );

		$this->assertSame( 2026, $report['reporting_year'] );
		$this->assertSame( 7, $report['register_at_period_end']['suppliers'] );
		$this->assertStringContainsString( "created_at < '2027-01-01 00:00:00'", $counts );
		$this->assertStringContainsString( "assessment_date >= '2026-01-01 00:00:00' AND assessment_date < '2027-01-01 00:00:00'", $counts );
		$this->assertStringContainsString( 'tier_level = 1', $counts );
		$this->assertStringContainsString( "audit_type = 'self_disclosure'", $counts );
	}

	public function test_the_summary_asserts_no_fact_the_register_does_not_hold(): void {
		$report = ( new BafaReportExporter() )->generate_bafa_report_data( 2026 );
		$trues  = 0;
		array_walk_recursive(
			$report,
			static function ( mixed $value ) use ( &$trues ): void {
				$trues += true === $value ? 1 : 0;
			}
		);

		$this->assertSame( 0, $trues );
		$this->assertSame( BafaReportExporter::REPORT_TYPE, $report['report_type'] );
		$this->assertArrayNotHasKey( 'bafa_report_standard', $report );
		$this->assertArrayNotHasKey( 'risk_management_system', $report );
		$this->assertArrayHasKey( 'policy_statement', $report['not_recorded'] );
		$this->assertSame( [], $report['missing_tables'] );
	}
}

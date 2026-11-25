<?php
declare(strict_types=1);

namespace WMCyber\LkSG\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_CLI;
use WP_CLI_Command;
use WMCyber\LkSG\Core\Plugin;
use WMCyber\LkSG\Core\Settings;
use WMCyber\LkSG\Risk\Countries;
use WMCyber\LkSG\Risk\RiskFactors;

final class LksgCommand extends WP_CLI_Command {
	public static function register(): void {
		WP_CLI::add_command( 'lksg', self::class );
	}

	/**
	 * Calculate the LkSG risk score for a supplier (nothing is stored).
	 *
	 * Abstract level: country, sector and product risk. Without --cpi the country tier applies; without
	 * --sector-risk/--product-risk the sector profile applies; otherwise the calculator's defaults. Concrete level:
	 * severity, probability, dependency and causal contribution, minus certifications. The weights are heuristics.
	 *
	 * ## OPTIONS
	 *
	 * <supplier_name>
	 * : Name of the supplier.
	 *
	 * [--country=<country>]
	 * : ISO 3166-1 alpha-2 code. DE, TW, CN, VN, IN and BD carry a tier; any other code, or none, scores the country
	 * level 50 unless --cpi is given. Until 2026-09-15 the default was DE, the lowest tier.
	 *
	 * [--sector=<key>]
	 * : Sector key: it_software, it_services, cloud_infrastructure, logistics, electronics, manufacturing, energy_storage, agriculture, textiles, mining, mining_raw.
	 *
	 * [--cpi=<score>]
	 * : Country corruption risk (0-100). Overrides the country tier.
	 *
	 * [--sector-risk=<score>]
	 * : Sector risk (0-100). Overrides the sector profile.
	 *
	 * [--product-risk=<score>]
	 * : Product risk (0-100). Overrides the sector profile.
	 *
	 * [--severity=<score>]
	 * : Severity of the potential violation (0-100). 85 or more is critical on its own.
	 *
	 * [--probability=<score>]
	 * : Probability of the violation (0-100).
	 *
	 * [--dependency=<score>]
	 * : Dependency on the supplier (0-100).
	 *
	 * [--causality=<score>]
	 * : Own causal contribution (0-100).
	 *
	 * [--iso14001]
	 * : Supplier holds ISO 14001.
	 *
	 * [--sa8000]
	 * : Supplier holds SA 8000.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lksg score "Acme Global Manufacturing" --country=CN --cpi=65.0 --sector-risk=70.0
	 *     wp lksg score "Hanoi Textile JSC" --country=VN --sector=textiles --severity=80 --probability=60 --dependency=85
	 */
	public function score( array $args, array $assoc_args ): void {
		$report = self::score_report( (string) ( $args[0] ?? '' ), $assoc_args );
		if ( null !== $report['error'] ) {
			WP_CLI::error( $report['error'] );
		}
		foreach ( $report['lines'] as $line ) {
			WP_CLI::line( $line );
		}
		if ( $report['flags'] ) {
			WP_CLI::warning( sprintf( 'High risk flags: %s', implode( ', ', $report['flags'] ) ) );
		} else {
			WP_CLI::success( 'No high-risk flag raised. The score is a heuristic, not a finding.' );
		}
	}

	/**
	 * What `wp lksg score` prints, without WP_CLI.
	 *
	 * @param array<string, mixed> $assoc_args WP-CLI flags.
	 * @return array{error: ?string, lines: string[], flags: string[], result: ?array}
	 */
	public static function score_report( string $name, array $assoc_args ): array {
		$country = Countries::normalize( $assoc_args['country'] ?? '' );
		if ( '' !== $country && ! Countries::is_valid( $country ) ) {
			return [ 'error' => sprintf( '--country=%s is not an ISO 3166-1 alpha-2 code.', $country ), 'lines' => [], 'flags' => [], 'result' => null ];
		}

		// Until 2026-09-14 this passed human_rights_indicator / environmental_indicator — keys the
		// calculator never read — so --cpi and --sector-risk moved 30 % of the score and nothing
		// else could move the rest.
		$factors = RiskFactors::from_cli_args( [ 'country' => $country ] + $assoc_args );
		$res     = Plugin::instance()->get_risk_calculator()->calculate_risk( $factors );

		if ( isset( $assoc_args['cpi'] ) ) {
			$country_source = '--cpi';
		} elseif ( null !== RiskFactors::country_cpi_risk( $country ) ) {
			$country_source = 'tier of ' . $country;
		} else {
			$country_source = '' === $country ? 'no country given, default' : 'no tier for ' . $country . ', default';
		}

		return [
			'error'  => null,
			'lines'  => [
				'=== WM LkSG Supply Chain Auditor — risk score (nothing stored) ===',
				sprintf( 'Supplier:        %s (%s)', $name, '' !== $country ? $country : 'country not given' ),
				sprintf( 'Abstract level:  country %.1f (%s) · sector %.1f · product %.1f → %.2f', $factors['country_cpi_risk'], $country_source, $factors['sector_risk'], $factors['product_risk'], $res['abstract_score'] ),
				sprintf( 'Concrete level:  severity %.1f · probability %.1f · dependency %.1f · causality %.1f · ISO 14001 %s · SA 8000 %s → %.2f', $factors['severity_score'], $factors['probability_score'], $factors['dependency_ratio'], $factors['causal_contribution'], $factors['has_iso_14001'] ? 'yes' : 'no', $factors['has_sa_8000'] ? 'yes' : 'no', $res['concrete_score'] ),
				sprintf( 'Risk Score:      %.2f / 100.00', $res['score'] ),
				sprintf( 'Risk Level:      %s (%s)', strtoupper( (string) $res['level'] ), (string) $res['action_trigger'] ),
			],
			'flags'  => array_values( (array) $res['high_risk_flags'] ),
			'result' => $res,
		];
	}

	/**
	 * Print the yearly register summary as JSON: the counts behind the admin tab "Jahresübersicht". It is not a
	 * report under § 10 Abs. 2 LkSG and not a BAFA form.
	 *
	 * ## OPTIONS
	 *
	 * [--year=<year>]
	 * : Calendar year (UTC). Default: the year on the settings tab, else the previous calendar year.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lksg export-bafa --year=2025
	 *
	 * @subcommand export-bafa
	 */
	public function export_bafa( array $args, array $assoc_args ): void {
		$year = isset( $assoc_args['year'] ) && is_numeric( $assoc_args['year'] ) ? (int) $assoc_args['year'] : ( isset( $assoc_args['year'] ) ? 0 : Settings::reporting_year() );
		if ( ! Settings::year_in_range( $year ) ) {
			WP_CLI::error( sprintf( '--year must be a year between %d and %d.', Settings::MIN_YEAR, Settings::max_year() ) );
		}

		WP_CLI::line( (string) wp_json_encode( Plugin::instance()->get_bafa_exporter()->generate_bafa_report_data( $year ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		WP_CLI::success( sprintf( 'Register summary for %d printed. It is not a report under § 10 Abs. 2 LkSG.', $year ) );
	}
}

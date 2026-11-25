<?php
declare(strict_types=1);

namespace WMCyber\LkSG\Risk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns what a caller has — a REST body, WP-CLI flags, the admin calculator form — into the keys
 * RiskMatrixCalculator reads.
 *
 * Until 2026-09-14 the four production entry points passed `human_rights_indicator` and
 * `environmental_indicator`, keys the calculator never read; 70 % of every score came from the
 * calculator's defaults and the human-rights channel had zero variance (commit 1d57522, fleet
 * audit 2026-09-13 §6.2 #26). The admin form did not even send the fields its handler read, so
 * Germany and Bangladesh, software and mining, all scored 42.60.
 */
final class RiskFactors {

	/** Keys the calculator reads, with the calculator's own defaults. */
	public const DEFAULTS = [
		'country_cpi_risk'    => 50.0,
		'sector_risk'         => 50.0,
		'product_risk'        => 50.0,
		'severity_score'      => 50.0,
		'probability_score'   => 50.0,
		'dependency_ratio'    => 10.0,
		'causal_contribution' => 10.0,
	];

	/** Keys older clients sent; translated instead of silently dropped. */
	private const ALIASES = [
		'human_rights_indicator'  => 'severity_score',
		'environmental_indicator' => 'product_risk',
	];

	/**
	 * Coarse country tiers behind the admin select (0 = clean, 100 = worst). They order the way
	 * the select's labels promise; they are NOT the Transparency International index. Sites with
	 * real CPI data plug it in through the `wm_lksg_country_cpi_risk` filter, or pass
	 * `country_cpi_risk` explicitly over REST and `--cpi` on the CLI.
	 */
	private const COUNTRY_CPI_RISK = [
		'DE' => 15.0,
		'TW' => 30.0,
		'CN' => 60.0,
		'VN' => 60.0,
		'IN' => 70.0,
		'BD' => 85.0,
	];

	/** Sector key → [sector_risk, product_risk]. WM Suite heuristics matching the form labels. */
	private const SECTOR_PROFILE = [
		'it_software'          => [ 15.0, 10.0 ],
		'it_services'          => [ 15.0, 10.0 ],
		'cloud_infrastructure' => [ 15.0, 10.0 ],
		'logistics'            => [ 40.0, 35.0 ],
		'electronics'          => [ 45.0, 40.0 ],
		'manufacturing'        => [ 45.0, 40.0 ],
		'energy_storage'       => [ 55.0, 60.0 ],
		'agriculture'          => [ 65.0, 60.0 ],
		'textiles'             => [ 70.0, 60.0 ],
		'mining'               => [ 85.0, 85.0 ],
		'mining_raw'           => [ 85.0, 85.0 ],
	];

	/**
	 * Build the calculator's factor array.
	 *
	 * Precedence per key: an explicit value in $input, then a value in $derived (country and
	 * sector tiers), then the calculator's default. Everything numeric is clamped to 0-100.
	 *
	 * @param array<string, mixed> $input   Caller data (REST body, form, flags).
	 * @param array<string, mixed> $derived Values derived from country / sector, see derived_from().
	 * @return array<string, float|bool>
	 */
	public static function from_input( array $input, array $derived = [] ): array {
		foreach ( self::ALIASES as $alias => $key ) {
			if ( isset( $input[ $alias ] ) && ! isset( $input[ $key ] ) ) {
				$input[ $key ] = $input[ $alias ];
			}
		}

		$out = self::DEFAULTS;
		foreach ( $derived as $key => $value ) {
			if ( array_key_exists( $key, self::DEFAULTS ) && is_numeric( $value ) ) {
				$out[ $key ] = self::clamp( (float) $value );
			}
		}
		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = self::clamp( (float) $input[ $key ] );
			}
		}

		$out['has_iso_14001'] = self::to_bool( $input['has_iso_14001'] ?? false );
		$out['has_sa_8000']   = self::to_bool( $input['has_sa_8000'] ?? false );

		return $out;
	}

	/**
	 * Abstract-level factors implied by a country code and a sector key; empty for unknown ones,
	 * so the calculator's defaults apply rather than an invented number.
	 *
	 * @return array<string, float>
	 */
	public static function derived_from( string $country_code, string $sector ): array {
		$derived = [];

		$cpi = self::country_cpi_risk( $country_code );
		if ( null !== $cpi ) {
			$derived['country_cpi_risk'] = $cpi;
		}

		$profile = self::sector_profile( $sector );
		if ( null !== $profile ) {
			$derived['sector_risk']  = $profile['sector_risk'];
			$derived['product_risk'] = $profile['product_risk'];
		}

		return $derived;
	}

	/**
	 * Country tier, or null when the country is not on the list.
	 */
	public static function country_cpi_risk( string $country_code ): ?float {
		$map = self::COUNTRY_CPI_RISK;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the country → corruption-risk map (0 = clean, 100 = worst).
			 *
			 * @param array<string, float> $map ISO 3166-1 alpha-2 → risk.
			 */
			$map = (array) apply_filters( 'wm_lksg_country_cpi_risk', $map );
		}

		$code = strtoupper( trim( $country_code ) );
		if ( ! isset( $map[ $code ] ) || ! is_numeric( $map[ $code ] ) ) {
			return null;
		}

		return self::clamp( (float) $map[ $code ] );
	}

	/**
	 * Sector and product risk for a sector key, or null when unknown.
	 *
	 * @return array{sector_risk: float, product_risk: float}|null
	 */
	public static function sector_profile( string $sector ): ?array {
		$key = strtolower( trim( $sector ) );
		if ( ! isset( self::SECTOR_PROFILE[ $key ] ) ) {
			return null;
		}

		[ $sector_risk, $product_risk ] = self::SECTOR_PROFILE[ $key ];

		return [
			'sector_risk'  => $sector_risk,
			'product_risk' => $product_risk,
		];
	}

	/**
	 * Factors from `wp lksg score` flags.
	 *
	 * @param array<string, mixed> $assoc_args WP-CLI associative args.
	 * @return array<string, float|bool>
	 */
	public static function from_cli_args( array $assoc_args ): array {
		$input = [
			'country_cpi_risk'    => $assoc_args['cpi'] ?? null,
			'sector_risk'         => $assoc_args['sector-risk'] ?? null,
			'product_risk'        => $assoc_args['product-risk'] ?? null,
			'severity_score'      => $assoc_args['severity'] ?? null,
			'probability_score'   => $assoc_args['probability'] ?? null,
			'dependency_ratio'    => $assoc_args['dependency'] ?? null,
			'causal_contribution' => $assoc_args['causality'] ?? null,
			'has_iso_14001'       => $assoc_args['iso14001'] ?? false,
			'has_sa_8000'         => $assoc_args['sa8000'] ?? false,
		];

		return self::from_input(
			array_filter( $input, static fn( $v ) => null !== $v ),
			self::derived_from( (string) ( $assoc_args['country'] ?? '' ), (string) ( $assoc_args['sector'] ?? '' ) )
		);
	}

	/**
	 * Factors from the admin calculator form (already unslashed POST data).
	 *
	 * @param array<string, mixed> $post Form fields.
	 * @return array<string, float|bool>
	 */
	public static function from_admin_form( array $post ): array {
		$input = [
			'country_cpi_risk'    => $post['wm_calc_cpi'] ?? null,
			'sector_risk'         => $post['wm_calc_sec_r'] ?? null,
			'product_risk'        => $post['wm_calc_product'] ?? null,
			'severity_score'      => $post['wm_calc_severity'] ?? null,
			'probability_score'   => $post['wm_calc_probability'] ?? null,
			'dependency_ratio'    => $post['wm_calc_dependency'] ?? null,
			'causal_contribution' => $post['wm_calc_causality'] ?? null,
			'has_iso_14001'       => $post['wm_calc_iso14001'] ?? false,
			'has_sa_8000'         => $post['wm_calc_sa8000'] ?? false,
		];

		return self::from_input(
			array_filter( $input, static fn( $v ) => null !== $v && '' !== $v ),
			self::derived_from( (string) ( $post['wm_calc_country'] ?? '' ), (string) ( $post['wm_calc_sector'] ?? '' ) )
		);
	}

	/**
	 * What the register says about a supplier after an evaluation. Before this existed a
	 * critical supplier was filed as "verified" and painted green in the overview table.
	 */
	public static function audit_status_for_level( string $level ): string {
		return match ( strtolower( trim( $level ) ) ) {
			'critical', 'high' => 'mitigation_required',
			'medium'           => 'in_review',
			'low'              => 'evaluated',
			default            => 'unassessed',
		};
	}

	private static function clamp( float $value ): float {
		return max( 0.0, min( 100.0, $value ) );
	}

	private static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0.0 !== (float) $value;
		}

		return (bool) filter_var( (string) $value, FILTER_VALIDATE_BOOLEAN );
	}
}

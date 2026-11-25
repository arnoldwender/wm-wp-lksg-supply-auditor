<?php
/**
 * Ecosystem bridges: WordPress 6.5+ Block Bindings (`wm-lksg/metrics`) and GenerateBlocks 2.x Dynamic Tag
 * (`{{wm_lksg_metric}}`).
 *
 * Every exposed value comes directly from the supplier register counts or the configured summary year — never a
 * compliance verdict.
 *
 * @package WMCyber\LkSG
 */

declare(strict_types=1);

namespace WMCyber\LkSG\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\LkSG\Core\Settings;
use WMCyber\LkSG\Database\Register;

final class EcosystemIntegration {

	/** Supported metric keys for Block Bindings and GenerateBlocks dynamic tags. */
	public const METRIC_KEYS = [ 'total', 'assessed', 'unassessed', 'high_or_critical', 'reporting_year' ];

	public function init(): void {
		add_action( 'init', [ $this, 'register_block_bindings' ] );
		add_action( 'init', [ $this, 'register_generateblocks_tags' ] );
		add_filter( 'generateblocks_dynamic_tag_replacement', [ $this, 'filter_generateblocks_replacement' ], 10, 2 );
	}

	/**
	 * Register the WP 6.5+ Block Bindings source `wm-lksg/metrics`.
	 */
	public function register_block_bindings(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			'wm-lksg/metrics',
			[
				'label'              => __( 'LkSG-Registerkennzahlen', 'wm-lksg-supply-auditor' ),
				'get_value_callback' => [ $this, 'get_bound_metric_value' ],
				'uses_context'       => [],
			]
		);
	}

	/**
	 * Register the GenerateBlocks 2.x Dynamic Tag `{{wm_lksg_metric}}` when GenerateBlocks 2.x is active.
	 */
	public function register_generateblocks_tags(): void {
		if ( ! class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
			return;
		}

		new \GenerateBlocks_Register_Dynamic_Tag(
			[
				'title'       => __( 'LkSG-Registerkennzahl', 'wm-lksg-supply-auditor' ),
				'tag'         => 'wm_lksg_metric',
				'type'        => 'site',
				'supports'    => [],
				'description' => __( 'Gibt eine Zählung aus dem LkSG-Lieferantenregister aus.', 'wm-lksg-supply-auditor' ),
				'options'     => [
					'key' => [
						'type'    => 'select',
						'label'   => __( 'Kennzahl', 'wm-lksg-supply-auditor' ),
						'default' => 'total',
						'options' => [
							[
								'value' => 'total',
								'label' => __( 'Im Register', 'wm-lksg-supply-auditor' ),
							],
							[
								'value' => 'assessed',
								'label' => __( 'Bewertet', 'wm-lksg-supply-auditor' ),
							],
							[
								'value' => 'unassessed',
								'label' => __( 'Nicht bewertet', 'wm-lksg-supply-auditor' ),
							],
							[
								'value' => 'high_or_critical',
								'label' => __( 'Hoch oder kritisch', 'wm-lksg-supply-auditor' ),
							],
							[
								'value' => 'reporting_year',
								'label' => __( 'Jahr der Jahresübersicht', 'wm-lksg-supply-auditor' ),
							],
						],
					],
				],
				'return'      => [ $this, 'render_generateblocks_tag' ],
			]
		);
	}

	/**
	 * Resolve a Block Bindings attribute value.
	 *
	 * @param array<string, mixed> $source_args    Source arguments (`key`).
	 * @param mixed                $block_instance Block instance.
	 * @param string               $attribute_name Target attribute name.
	 */
	public function get_bound_metric_value( array $source_args, mixed $block_instance = null, string $attribute_name = 'content' ): string {
		$key = isset( $source_args['key'] ) && is_scalar( $source_args['key'] )
			? sanitize_key( (string) $source_args['key'] )
			: 'total';

		return self::resolve_metric( $key );
	}

	/**
	 * Callback for `GenerateBlocks_Register_Dynamic_Tag`.
	 *
	 * @param array<string, mixed> $options Tag options (`key`).
	 * @param mixed                $block   Block data.
	 * @param mixed                $instance Block instance.
	 */
	public function render_generateblocks_tag( array $options = [], mixed $block = null, mixed $instance = null ): string {
		$key = isset( $options['key'] ) && is_scalar( $options['key'] )
			? sanitize_key( (string) $options['key'] )
			: 'total';

		return esc_html( self::resolve_metric( $key ) );
	}

	/**
	 * Filter fallback for `generateblocks_dynamic_tag_replacement` (`{{wm_lksg_metric}}` / `{{wm_lksg_metric key:unassessed}}`).
	 *
	 * @param mixed $replacement Current replacement string.
	 * @param mixed $tag_details Parsed tag details array when provided by GenerateBlocks.
	 */
	public function filter_generateblocks_replacement( mixed $replacement, mixed $tag_details = null ): mixed {
		if ( ! is_array( $tag_details ) || 'wm_lksg_metric' !== ( $tag_details['tag'] ?? '' ) ) {
			return $replacement;
		}
		$options = is_array( $tag_details['options'] ?? null ) ? $tag_details['options'] : [];
		return $this->render_generateblocks_tag( $options );
	}

	/**
	 * Compute a plain-text register metric; returns `–` when the suppliers table is missing, and `''` for an unknown key.
	 */
	public static function resolve_metric( string $key ): string {
		if ( 'reporting_year' === $key ) {
			return (string) Settings::reporting_year();
		}

		if ( ! in_array( $key, [ 'total', 'assessed', 'unassessed', 'high_or_critical' ], true ) ) {
			return '';
		}

		$counts = Register::counts();
		$val    = $counts[ $key ] ?? null;
		if ( null === $val ) {
			return '–';
		}

		return function_exists( 'number_format_i18n' ) ? number_format_i18n( $val ) : (string) $val;
	}
}

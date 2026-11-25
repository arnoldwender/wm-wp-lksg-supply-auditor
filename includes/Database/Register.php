<?php
/**
 * Reads of the supplier register shared by the REST route, the admin screen, the dashboard widget and the hub adapter.
 *
 * Until 2026-09-15 each of them ran its own `SELECT * … ORDER BY risk_score DESC LIMIT 50`: the overview counted at
 * most 50 suppliers as "Proveedores Evaluados" while the widget counted all of them, and an unassessed
 * self-disclosure (score 0.00) was the first row to drop out of every list. Here the order puts unassessed rows first
 * and the lists are paged.
 *
 * @package WMCyber\LkSG
 */

declare(strict_types=1);

namespace WMCyber\LkSG\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Register {

	/**
	 * Counts over the whole register; every value is null when the suppliers table is missing.
	 *
	 * @return array{total: ?int, assessed: ?int, unassessed: ?int, high_or_critical: ?int}
	 */
	public static function counts(): array {
		global $wpdb;

		if ( ! Schema::table_exists( 'suppliers' ) ) {
			return [ 'total' => null, 'assessed' => null, 'unassessed' => null, 'high_or_critical' => null ];
		}

		$table = Schema::table( 'suppliers' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$unassessed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE risk_level = %s', $table, 'unassessed' ) );
		$high       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE risk_level IN (%s, %s)', $table, 'high', 'critical' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return [
			'total'            => $total,
			'assessed'         => $total - $unassessed,
			'unassessed'       => $unassessed,
			'high_or_critical' => $high,
		];
	}

	/**
	 * One page of the register: unassessed rows first, then by score, newest first among equals.
	 *
	 * @return array{total: int, page: int, per_page: int, pages: int, rows: array<int, array<string, mixed>>}
	 */
	public static function page( int $page, int $per_page ): array {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$table    = Schema::table( 'suppliers' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY (risk_level = %s) DESC, risk_score DESC, id DESC LIMIT %d OFFSET %d',
				$table,
				'unassessed',
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return [
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => max( 1, (int) ceil( $total / $per_page ) ),
			'rows'     => $rows,
		];
	}
}

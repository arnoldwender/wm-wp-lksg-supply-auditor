<?php
/**
 * Tables of the plugin and their upgrades.
 *
 * @package WMCyber\LkSG
 */

declare(strict_types=1);

namespace WMCyber\LkSG\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {

	/**
	 * Bumped whenever activate() changes; maybe_upgrade() replays it on sites that are already active.
	 *
	 * 3 (2026-09-15): the assessment history keeps the composite score, the level and every factor; a self-disclosure
	 * keeps the declared certifications and the declared text next to its signature; the four score columns of an
	 * audit row may be NULL (a self-disclosure stored 0, "no child-labour risk", for things nobody assessed); a
	 * supplier row without a level defaults to 'unassessed' instead of 'low'.
	 */
	public const VERSION = '3';

	public const VERSION_OPTION = 'wm_lksg_schema_version';

	/** Table suffixes after `{prefix}wm_lksg_`. */
	public const TABLES = [ 'suppliers', 'risk_assessments', 'audits', 'complaints', 'corrective_actions' ];

	/** Audit columns that must accept NULL. */
	private const NULLABLE_AUDIT_COLUMNS = [ 'human_rights_score', 'environmental_score', 'child_labor_risk', 'forced_labor_risk' ];

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_lksg_' . $name;
	}

	public static function table_exists( string $name ): bool {
		global $wpdb;
		$table = self::table( $name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/** @return string[] Suffixes of the tables that do not exist. */
	public static function missing_tables(): array {
		return array_values( array_filter( self::TABLES, static fn( string $name ): bool => ! self::table_exists( $name ) ) );
	}

	/**
	 * Replays the schema on installs that were active before a schema change. activate() only
	 * runs on the activation click, so a site that already had the plugin on would never get the
	 * uuid column, the industry_sector column or the risk_assessments table otherwise.
	 */
	public static function maybe_upgrade(): void {
		if ( version_compare( (string) get_option( self::VERSION_OPTION, '0' ), self::VERSION, '>=' ) ) {
			return;
		}
		self::activate();
	}

	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
			$site_ids = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);
			foreach ( (array) $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					self::activate_site();
				} finally {
					restore_current_blog();
				}
			}
			return;
		}

		self::activate_site();
	}

	private static function activate_site(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// 1. Suppliers — two passes. Rows written before the uuid column existed, or by the inserts
		// that forgot it, hold ''. MySQL refuses the UNIQUE index while two of them exist, and
		// under WP_DEBUG that refusal is printed, which makes activate_plugin() report
		// "unexpected output". So: add the columns without the index, give every row a uuid,
		// then add the index over distinct values.
		dbDelta( self::suppliers_sql( self::table( 'suppliers' ), $charset_collate, false ) );
		self::backfill_uuids( self::table( 'suppliers' ), 'uuid' );
		dbDelta( self::suppliers_sql( self::table( 'suppliers' ), $charset_collate, true ) );

		dbDelta( self::assessments_sql( self::table( 'risk_assessments' ), $charset_collate ) );
		dbDelta( self::audits_sql( self::table( 'audits' ), $charset_collate ) );
		// dbDelta compares column types and quoted defaults, not NULL / NOT NULL: existing tables need an ALTER.
		self::make_audit_scores_nullable( self::table( 'audits' ) );
		dbDelta( self::complaints_sql( self::table( 'complaints' ), $charset_collate ) );
		dbDelta( self::corrective_actions_sql( self::table( 'corrective_actions' ), $charset_collate ) );

		self::backfill_uuids( self::table( 'risk_assessments' ), 'assessment_id' );
		self::backfill_uuids( self::table( 'corrective_actions' ), 'capa_id' );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * CREATE TABLE statement for the suppliers register, with or without the uuid index.
	 *
	 * industry_sector was written by every insert and read by the dashboard, but until
	 * 2026-09-14 it was missing from this definition: a fresh install could not store a single
	 * supplier (Unknown column), while installs created by an older definition worked.
	 */
	public static function suppliers_sql( string $table, string $charset_collate, bool $with_uuid_index ): string {
		$uuid_index = $with_uuid_index ? "UNIQUE KEY idx_uuid (uuid),\n\t\t\t" : '';

		return "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			tax_number varchar(64) DEFAULT NULL,
			supplier_name varchar(255) NOT NULL,
			country_code char(3) NOT NULL,
			industry_sector varchar(128) DEFAULT NULL,
			nace_code varchar(64) DEFAULT NULL,
			tier_level int unsigned NOT NULL DEFAULT 1,
			dependency_ratio decimal(5,2) NOT NULL DEFAULT 0.00,
			risk_score decimal(5,2) NOT NULL DEFAULT 0.00,
			risk_level varchar(16) NOT NULL DEFAULT 'unassessed',
			audit_status varchar(32) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			{$uuid_index}KEY idx_risk (risk_level),
			KEY idx_tier (tier_level)
		) $charset_collate;";
	}

	/** One row per evaluation (AssessmentLog). */
	public static function assessments_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			assessment_id char(36) NOT NULL,
			supplier_id bigint(20) unsigned NOT NULL,
			assessment_date datetime NOT NULL,
			risk_category_id varchar(64) NOT NULL,
			abstract_score decimal(5,2) NOT NULL DEFAULT 0.00,
			severity_score decimal(5,2) NOT NULL DEFAULT 0.00,
			total_concrete_risk decimal(5,2) NOT NULL DEFAULT 0.00,
			composite_score decimal(5,2) DEFAULT NULL,
			risk_level varchar(16) DEFAULT NULL,
			action_trigger varchar(64) NOT NULL,
			factors text DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_assessment_id (assessment_id),
			KEY idx_supplier_id (supplier_id)
		) $charset_collate;";
	}

	/** Audit rows; the only writer is the self-disclosure route. */
	public static function audits_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			supplier_id bigint(20) unsigned NOT NULL,
			execution_date datetime NOT NULL,
			audit_type varchar(32) NOT NULL DEFAULT 'self_disclosure',
			audit_framework varchar(64) DEFAULT NULL,
			non_conformities_major int unsigned NOT NULL DEFAULT 0,
			human_rights_score decimal(5,2) DEFAULT NULL,
			environmental_score decimal(5,2) DEFAULT NULL,
			child_labor_risk tinyint(1) DEFAULT NULL,
			forced_labor_risk tinyint(1) DEFAULT NULL,
			declared_iso_14001 tinyint(1) DEFAULT NULL,
			declared_sa_8000 tinyint(1) DEFAULT NULL,
			declared_iso_45001 tinyint(1) DEFAULT NULL,
			declaration_text text DEFAULT NULL,
			signature_version varchar(16) DEFAULT NULL,
			digital_signature varchar(128) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY idx_supplier_id (supplier_id)
		) $charset_collate;";
	}

	/** Created since 1.0.0; nothing in the plugin writes or reads it. */
	public static function complaints_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			supplier_id bigint(20) unsigned NOT NULL,
			received_date datetime NOT NULL,
			hinschg_case_reference varchar(64) DEFAULT NULL,
			category_id varchar(64) NOT NULL,
			violation_type varchar(64) NOT NULL,
			is_anonymous tinyint(1) NOT NULL DEFAULT 0,
			remedial_action_taken text DEFAULT NULL,
			status varchar(32) NOT NULL DEFAULT 'investigating',
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY idx_supplier_complaint (supplier_id)
		) $charset_collate;";
	}

	/** Created since 1.0.0; nothing in the plugin writes or reads it. */
	public static function corrective_actions_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			capa_id char(36) NOT NULL,
			source_type varchar(32) NOT NULL,
			source_id bigint(20) unsigned NOT NULL,
			action_details text NOT NULL,
			deadline date DEFAULT NULL,
			status varchar(32) NOT NULL DEFAULT 'open',
			ultima_ratio_flag tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_capa_id (capa_id),
			KEY idx_source (source_type, source_id)
		) $charset_collate;";
	}

	/**
	 * Let the four score columns of an existing audits table hold NULL. Existing rows keep their values.
	 *
	 * @return string[] The columns that were changed.
	 */
	public static function make_audit_scores_nullable( string $table ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$columns  = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		$not_null = [];
		foreach ( $columns as $column ) {
			if ( 'NO' === ( $column['Null'] ?? '' ) ) {
				$not_null[] = (string) ( $column['Field'] ?? '' );
			}
		}
		$changed = array_values( array_intersect( self::NULLABLE_AUDIT_COLUMNS, $not_null ) );

		// One literal statement per column: no SQL is assembled from strings.
		if ( in_array( 'human_rights_score', $changed, true ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY human_rights_score decimal(5,2) NULL DEFAULT NULL', $table ) );
		}
		if ( in_array( 'environmental_score', $changed, true ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY environmental_score decimal(5,2) NULL DEFAULT NULL', $table ) );
		}
		if ( in_array( 'child_labor_risk', $changed, true ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY child_labor_risk tinyint(1) NULL DEFAULT NULL', $table ) );
		}
		if ( in_array( 'forced_labor_risk', $changed, true ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY forced_labor_risk tinyint(1) NULL DEFAULT NULL', $table ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

		return $changed;
	}

	/**
	 * Give a v4 uuid to every row whose uuid column is empty or NULL.
	 *
	 * @return int Rows updated.
	 */
	public static function backfill_uuids( string $table, string $column ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table and column names come from this class, not from input.
		$ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE %i = %s OR %i IS NULL', $table, $column, '', $column ) );
		$done = 0;
		foreach ( $ids as $id ) {
			if ( false !== $wpdb->update( $table, [ $column => wp_generate_uuid4() ], [ 'id' => (int) $id ], [ '%s' ], [ '%d' ] ) ) {
				++$done;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $done;
	}

	public static function deactivate(): void {}
}

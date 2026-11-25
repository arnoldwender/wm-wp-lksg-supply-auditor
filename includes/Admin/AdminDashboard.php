<?php
/**
 * Admin screen, dashboard widget and form actions.
 *
 * Rewritten in German on 2026-09-15. The Spanish screen before showed fixed statements whatever the register held:
 * "AUDITORÍA ESG AL DÍA", "INFORME BAFA DISPONIBLE", "Estado Informe BAFA: PREPARADO — Conforme a § 10 LkSG", "Canal
 * de Denuncias: VINCULADO — Canal HinSchG activo", and on the dashboard a green "LkSG § 4–10 Risikomanagement aktiv"
 * with "BAFA Berichtsbereit". It counted at most 50 suppliers as "evaluated", listed "legal actions required" for a
 * heuristic score, offered a threshold nothing read, linked the JSON without a nonce (401 for the logged-in admin),
 * stored the supplier again on every reload of the calculator result, seeded Foxconn and Hetzner with invented scores
 * marked "verified", and carried emoji, inline colours and a widget without its stylesheet on the dashboard.
 *
 * @package WMCyber\LkSG
 */

declare(strict_types=1);

namespace WMCyber\LkSG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\LkSG\Core\Settings;
use WMCyber\LkSG\Database\Register;
use WMCyber\LkSG\Database\Schema;
use WMCyber\LkSG\Export\BafaReportExporter;
use WMCyber\LkSG\Risk\AssessmentLog;
use WMCyber\LkSG\Risk\Countries;
use WMCyber\LkSG\Risk\RiskFactors;
use WMCyber\LkSG\Risk\RiskMatrixCalculator;

final class AdminDashboard {

	public const PAGE = 'wm-lksg-auditor';

	public const PER_PAGE = 50;

	/** Made-up demo suppliers; their scores are computed like any other evaluation and logged as `demo_seed`. */
	public const DEMO_SUPPLIERS = [
		[
			'name'    => 'Beispiel: Elektronikfertigung Nord Co., Ltd.',
			'country' => 'TW',
			'sector'  => 'electronics',
			'tier'    => 1,
			'factors' => [ 'severity_score' => 40, 'probability_score' => 30, 'dependency_ratio' => 60, 'causal_contribution' => 10, 'has_iso_14001' => true ],
		],
		[
			'name'    => 'Beispiel: Batteriezellen Süd Ltd.',
			'country' => 'CN',
			'sector'  => 'energy_storage',
			'tier'    => 1,
			'factors' => [ 'severity_score' => 70, 'probability_score' => 55, 'dependency_ratio' => 40, 'causal_contribution' => 20 ],
		],
		[
			'name'    => 'Beispiel: Textilwerk Ost JSC',
			'country' => 'VN',
			'sector'  => 'textiles',
			'tier'    => 2,
			'factors' => [ 'severity_score' => 88, 'probability_score' => 60, 'dependency_ratio' => 30, 'causal_contribution' => 20 ],
		],
		[
			'name'    => 'Beispiel: Rechenzentrum West GmbH',
			'country' => 'DE',
			'sector'  => 'it_services',
			'tier'    => 1,
			'factors' => [ 'severity_score' => 10, 'probability_score' => 10, 'dependency_ratio' => 20, 'causal_contribution' => 10, 'has_iso_14001' => true ],
		],
	];

	public function __construct(
		private readonly RiskMatrixCalculator $risk_calculator,
		private readonly BafaReportExporter $bafa_exporter
	) {}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_init', [ Settings::class, 'register' ] );
		add_action( 'admin_init', [ $this, 'handle_form_actions' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
	}

	/** @return array<string, string> Countries of the calculator select (each has a tier in RiskFactors). */
	public static function country_labels(): array {
		return [
			'DE' => __( 'Deutschland', 'wm-lksg-supply-auditor' ),
			'TW' => __( 'Taiwan', 'wm-lksg-supply-auditor' ),
			'CN' => __( 'China', 'wm-lksg-supply-auditor' ),
			'VN' => __( 'Vietnam', 'wm-lksg-supply-auditor' ),
			'IN' => __( 'Indien', 'wm-lksg-supply-auditor' ),
			'BD' => __( 'Bangladesch', 'wm-lksg-supply-auditor' ),
		];
	}

	/** @return array<string, string> Sectors of the calculator select (each has a profile in RiskFactors). */
	public static function sector_labels(): array {
		return [
			'it_software' => __( 'IT, Software und Hosting', 'wm-lksg-supply-auditor' ),
			'electronics' => __( 'Elektronik und Bauteile', 'wm-lksg-supply-auditor' ),
			'textiles'    => __( 'Textil und Bekleidung', 'wm-lksg-supply-auditor' ),
			'mining_raw'  => __( 'Bergbau und Rohstoffe', 'wm-lksg-supply-auditor' ),
			'agriculture' => __( 'Landwirtschaft und Lebensmittel', 'wm-lksg-supply-auditor' ),
		];
	}

	/**
	 * Badge class and label for a stored level. An unknown level gets a neutral badge: until 2026-09-15 anything that
	 * was not critical, high, medium or unassessed fell through to a green "low risk".
	 *
	 * @return array{class: string, label: string}
	 */
	public static function level_badge( string $level ): array {
		return match ( $level ) {
			'critical'   => [ 'class' => 'wm-status-pill wm-status-error', 'label' => __( 'Kritisch', 'wm-lksg-supply-auditor' ) ],
			'high'       => [ 'class' => 'wm-status-pill wm-status-error', 'label' => __( 'Hoch', 'wm-lksg-supply-auditor' ) ],
			'medium'     => [ 'class' => 'wm-status-pill wm-status-warn', 'label' => __( 'Mittel', 'wm-lksg-supply-auditor' ) ],
			'low'        => [ 'class' => 'wm-status-pill wm-status-ok', 'label' => __( 'Niedrig', 'wm-lksg-supply-auditor' ) ],
			'unassessed' => [ 'class' => 'wm-status-pill', 'label' => __( 'Nicht bewertet', 'wm-lksg-supply-auditor' ) ],
			default      => [ 'class' => 'wm-status-pill', 'label' => __( 'Unbekannt', 'wm-lksg-supply-auditor' ) ],
		};
	}

	public function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wm_lksg_supply_widget',
			__( 'WM LkSG Supply Auditor', 'wm-lksg-supply-auditor' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	/**
	 * Counts from the register. Until 2026-09-15 a green "LkSG § 4–10 Risikomanagement aktiv" and "BAFA
	 * Berichtsbereit" on an empty register.
	 */
	public function render_dashboard_widget(): void {
		$counts = Register::counts();
		$none   = '–';

		if ( null === $counts['total'] ) {
			$banner = 'wm-dash-banner is-warning';
			$title  = __( 'Die Lieferantentabelle fehlt', 'wm-lksg-supply-auditor' );
			$sub    = __( 'Das Plugin einmal deaktivieren und wieder aktivieren.', 'wm-lksg-supply-auditor' );
		} elseif ( 0 === $counts['total'] ) {
			$banner = 'wm-dash-banner';
			$title  = __( 'Noch keine Lieferanten im Register', 'wm-lksg-supply-auditor' );
			$sub    = __( 'Lieferanten über den Rechner, POST /suppliers oder das Selbstauskunftsformular erfassen.', 'wm-lksg-supply-auditor' );
		} elseif ( $counts['unassessed'] > 0 ) {
			$banner = 'wm-dash-banner is-warning';
			/* translators: %d: number of self-disclosures nobody has assessed */
			$title = sprintf( _n( '%d Selbstauskunft nicht bewertet', '%d Selbstauskünfte nicht bewertet', $counts['unassessed'], 'wm-lksg-supply-auditor' ), $counts['unassessed'] );
			$sub   = __( 'Die Punktzahl ist eine Heuristik; das Register ersetzt keine Risikoanalyse.', 'wm-lksg-supply-auditor' );
		} else {
			$banner = 'wm-dash-banner';
			/* translators: %d: number of suppliers in the register */
			$title = sprintf( _n( '%d Lieferant im Register', '%d Lieferanten im Register', $counts['total'], 'wm-lksg-supply-auditor' ), $counts['total'] );
			$sub   = __( 'Die Punktzahl ist eine Heuristik; das Register ersetzt keine Risikoanalyse.', 'wm-lksg-supply-auditor' );
		}
		?>
		<div class="wm-dash-widget-box" data-wm-theme="<?php echo esc_attr( function_exists( 'wm_get_suite_theme' ) ? wm_get_suite_theme() : 'cyber-events' ); ?>">
			<div class="<?php echo esc_attr( $banner ); ?>">
				<span class="wm-dash-banner-icon dashicons dashicons-networking" aria-hidden="true"></span>
				<div>
					<div class="wm-dash-banner-title"><?php echo esc_html( $title ); ?></div>
					<div class="wm-dash-banner-sub"><?php echo esc_html( $sub ); ?></div>
				</div>
			</div>

			<div class="wm-dash-kpi-grid">
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( null === $counts['total'] ? $none : number_format_i18n( $counts['total'] ) ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Lieferanten', 'wm-lksg-supply-auditor' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="<?php echo esc_attr( (int) $counts['unassessed'] > 0 ? 'wm-dash-kpi-val text-amber' : 'wm-dash-kpi-val' ); ?>"><?php echo esc_html( null === $counts['unassessed'] ? $none : number_format_i18n( $counts['unassessed'] ) ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Nicht bewertet', 'wm-lksg-supply-auditor' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="<?php echo esc_attr( (int) $counts['high_or_critical'] > 0 ? 'wm-dash-kpi-val text-rose' : 'wm-dash-kpi-val' ); ?>"><?php echo esc_html( null === $counts['high_or_critical'] ? $none : number_format_i18n( $counts['high_or_critical'] ) ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Hoch oder kritisch', 'wm-lksg-supply-auditor' ); ?></div>
				</div>
			</div>

			<div class="wm-dash-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" class="wm-dash-btn">
					<span><?php esc_html_e( 'Register öffnen', 'wm-lksg-supply-auditor' ); ?></span> <span aria-hidden="true">&rarr;</span>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Family menu icon: a monochrome hub-and-spoke mark, filled shapes only so the WordPress
	 * svg-painter can recolour it with the admin colour scheme. Placeholder until the family
	 * icon is decided (hackaton plan D12).
	 */
	public static function family_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.6" fill="black"/><circle cx="10" cy="3" r="1.8" fill="black"/><circle cx="16.1" cy="13.5" r="1.8" fill="black"/><circle cx="3.9" cy="13.5" r="1.8" fill="black"/><rect x="9.3" y="4.5" width="1.4" height="3.4" fill="black"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(-60 10 10)"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(60 10 10)"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function register_admin_menu(): void {
		add_menu_page(
			__( 'LkSG Supply Chain Auditor', 'wm-lksg-supply-auditor' ),
			__( 'LkSG-Lieferanten', 'wm-lksg-supply-auditor' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render_dashboard_page' ],
			self::family_menu_icon(),
			33
		);
	}

	/**
	 * Family sheets on the plugin's page and on the WordPress dashboard, where the widget renders. Until 2026-09-15
	 * only the plugin page loaded them, so the widget's classes were unstyled on the dashboard.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'index.php' !== $hook && 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}

		$root    = dirname( __DIR__, 2 ) . '/assets/css/';
		$version = static fn( string $file ): string => is_file( $root . $file ) ? (string) filemtime( $root . $file ) : WM_LKSG_VERSION;
		wp_enqueue_style( 'wm-admin-tokens', WM_LKSG_URL . 'assets/css/wm-admin-tokens.css', [], $version( 'wm-admin-tokens.css' ) );
		wp_enqueue_style( 'wm-admin-ui', WM_LKSG_URL . 'assets/css/wm-admin-ui.css', [ 'wm-admin-tokens' ], $version( 'wm-admin-ui.css' ) );
	}

	/**
	 * Demo button and settings: manage_options and one nonce per form, redirect afterwards.
	 */
	public function handle_form_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked per action below.
		$action = isset( $_POST['wm_lksg_action'] ) ? sanitize_key( wp_unslash( $_POST['wm_lksg_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wm-lksg-supply-auditor' ), 403 );
		}

		if ( 'seed_demo_data' === $action ) {
			check_admin_referer( 'wm_lksg_seed', 'wm_lksg_nonce' );
			$added = $this->seed_demo_data();
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&tab=overview&seeded=' . $added ) );
			exit;
		}

		if ( 'save_settings' === $action ) {
			check_admin_referer( 'wm_lksg_save_settings', 'wm_lksg_nonce' );
			$saved = Settings::save_reporting_year( isset( $_POST['reporting_year'] ) ? sanitize_text_field( wp_unslash( $_POST['reporting_year'] ) ) : '' );
			if ( $saved ) {
				Settings::save_delete_data_on_uninstall( ! empty( $_POST['wm_lksg_delete_data_on_uninstall'] ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&tab=settings&' . ( $saved ? 'saved=1' : 'year_error=1' ) ) );
			exit;
		}
	}

	/**
	 * Add the demo suppliers that are not in the register yet, scored by the calculator and logged as `demo_seed`.
	 *
	 * @return int Rows added.
	 */
	public function seed_demo_data(): int {
		global $wpdb;
		if ( ! Schema::table_exists( 'suppliers' ) ) {
			return 0;
		}

		$added = 0;
		foreach ( self::DEMO_SUPPLIERS as $demo ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE supplier_name = %s', Schema::table( 'suppliers' ), $demo['name'] ) );
			if ( $exists > 0 ) {
				continue;
			}
			$factors = RiskFactors::from_input( $demo['factors'], RiskFactors::derived_from( $demo['country'], $demo['sector'] ) );
			$risk    = $this->risk_calculator->calculate_risk( $factors );
			if ( $this->insert_supplier( $demo['name'], $demo['country'], $demo['sector'], $demo['tier'], $factors, $risk, AssessmentLog::SOURCE_DEMO ) ) {
				++$added;
			}
		}
		return $added;
	}

	/**
	 * Insert a scored supplier and its assessment row; the supplier row is removed again when the assessment row
	 * cannot be written.
	 *
	 * @param array<string, float|bool> $factors
	 * @param array<string, mixed>      $risk
	 */
	private function insert_supplier( string $name, string $country, string $sector, int $tier, array $factors, array $risk, string $source ): bool {
		global $wpdb;
		$table = Schema::table( 'suppliers' );
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own register.
		$inserted = $wpdb->insert(
			$table,
			[
				'uuid'             => wp_generate_uuid4(),
				'supplier_name'    => $name,
				'country_code'     => $country,
				'industry_sector'  => $sector,
				'tier_level'       => $tier,
				'dependency_ratio' => $factors['dependency_ratio'],
				'risk_score'       => $risk['score'],
				'risk_level'       => $risk['level'],
				'audit_status'     => RiskFactors::audit_status_for_level( (string) $risk['level'] ),
				'created_at'       => $now,
				'updated_at'       => $now,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%f', '%f', '%s', '%s', '%s', '%s' ]
		);
		if ( false === $inserted ) {
			return false;
		}
		$id = (int) $wpdb->insert_id;
		if ( ! AssessmentLog::record( $id, $factors, $risk, $source ) ) {
			$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
			return false;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return true;
	}

	/**
	 * The calculator form, if one was posted: validates, scores, and stores once per form token. A reload of the
	 * result page posts the same token again and stores nothing.
	 *
	 * @return array<string, mixed>|null
	 */
	public function calculate(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified right below.
		if ( ! isset( $_POST['wm_lksg_calc_nonce'] ) ) {
			return null;
		}

		$state = [
			'error'     => null,
			'name'      => '',
			'country'   => '',
			'sector'    => '',
			'tier'      => 1,
			'risk'      => null,
			'factors'   => null,
			'saved'     => false,
			'failed'    => false,
			'duplicate' => false,
		];

		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wm_lksg_calc_nonce'] ) ), 'wm_lksg_calc' ) ) {
			$state['error'] = __( 'Die Sicherheitsprüfung ist fehlgeschlagen. Bitte die Seite neu laden.', 'wm-lksg-supply-auditor' );
			return $state;
		}

		$post = [];
		foreach ( [ 'wm_calc_name', 'wm_calc_country', 'wm_calc_sector', 'wm_calc_tier', 'wm_calc_severity', 'wm_calc_probability', 'wm_calc_dependency', 'wm_calc_causality', 'wm_calc_iso14001', 'wm_calc_sa8000', 'save_to_register', 'wm_calc_token' ] as $key ) {
			if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
				$post[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		$state['name']    = $post['wm_calc_name'] ?? '';
		$state['country'] = Countries::normalize( $post['wm_calc_country'] ?? '' );
		$state['sector']  = sanitize_key( $post['wm_calc_sector'] ?? '' );
		$state['tier']    = (int) ( $post['wm_calc_tier'] ?? 1 );

		if ( '' === $state['name'] ) {
			$state['error'] = __( 'Der Lieferantenname fehlt.', 'wm-lksg-supply-auditor' );
		} elseif ( ! Countries::is_valid( $state['country'] ) ) {
			$state['error'] = __( 'Das Land fehlt oder ist kein ISO-3166-1-Alpha-2-Code (zum Beispiel DE, CN, BD).', 'wm-lksg-supply-auditor' );
		} elseif ( $state['tier'] < 1 || $state['tier'] > 3 ) {
			$state['error'] = __( 'Die Lieferantenstufe muss 1, 2 oder 3 sein.', 'wm-lksg-supply-auditor' );
		}
		if ( null !== $state['error'] ) {
			return $state;
		}

		$state['factors'] = RiskFactors::from_admin_form( $post );
		$state['risk']    = $this->risk_calculator->calculate_risk( $state['factors'] );

		if ( empty( $post['save_to_register'] ) ) {
			return $state;
		}

		$token = (string) preg_replace( '/[^a-f0-9]/', '', $post['wm_calc_token'] ?? '' );
		if ( 32 !== strlen( $token ) ) {
			$state['failed'] = true;
			return $state;
		}
		$token_key = 'wm_lksg_calc_' . $token;
		if ( false !== get_transient( $token_key ) ) {
			$state['duplicate'] = true;
			return $state;
		}

		if ( $this->insert_supplier( $state['name'], $state['country'], $state['sector'], $state['tier'], $state['factors'], $state['risk'], 'lksg_composite' ) ) {
			set_transient( $token_key, 1, DAY_IN_SECONDS );
			$state['saved'] = true;
		} else {
			$state['failed'] = true;
		}

		return $state;
	}

	/** @return array<string, array{label: string, icon: string}> Tab slug => label and dashicon. */
	private function tabs(): array {
		return [
			'overview'   => [ 'label' => __( 'Register', 'wm-lksg-supply-auditor' ), 'icon' => 'dashicons-list-view' ],
			'calculator' => [ 'label' => __( 'Rechner', 'wm-lksg-supply-auditor' ), 'icon' => 'dashicons-calculator' ],
			'export'     => [ 'label' => __( 'Jahresübersicht', 'wm-lksg-supply-auditor' ), 'icon' => 'dashicons-media-spreadsheet' ],
			'settings'   => [ 'label' => __( 'Einstellungen', 'wm-lksg-supply-auditor' ), 'icon' => 'dashicons-admin-settings' ],
			'help'       => [ 'label' => __( 'Begriffe', 'wm-lksg-supply-auditor' ), 'icon' => 'dashicons-book' ],
		];
	}

	public function render_dashboard_page(): void {
		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing.
		$current = sanitize_key( wp_unslash( $_GET['tab'] ?? 'overview' ) );
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'overview';
		}
		?>
		<div class="wrap wm-admin wm-lksg-admin-wrap">
			<header class="wm-admin-header">
				<div class="wm-header-brand">
					<div class="wm-header-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
					</div>
					<div>
						<h1><?php esc_html_e( 'LkSG Supply Chain Auditor', 'wm-lksg-supply-auditor' ); ?></h1>
						<p class="wm-subtitle"><?php esc_html_e( 'Lieferantenregister mit heuristischer Risikopunktzahl, Selbstauskunftsformular und Jahresübersicht der Registerzahlen.', 'wm-lksg-supply-auditor' ); ?></p>
					</div>
				</div>
				<div class="wm-header-badges">
					<span class="wm-badge-family" title="<?php esc_attr_e( 'Teil der WM-Suite-Pluginfamilie', 'wm-lksg-supply-auditor' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
						<?php esc_html_e( 'WM Suite', 'wm-lksg-supply-auditor' ); ?>
						<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-lksg-supply-auditor' ) : esc_html__( 'Standalone', 'wm-lksg-supply-auditor' ); ?></span>
					</span>
					<span class="wm-badge wm-badge-ghost"><?php esc_html_e( 'Keine externen Anfragen', 'wm-lksg-supply-auditor' ); ?></span>
				</div>
			</header>

			<?php $this->render_notices(); ?>

			<nav class="wm-nav-tabs" aria-label="<?php esc_attr_e( 'Bereiche', 'wm-lksg-supply-auditor' ); ?>">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $slug ) ); ?>" class="wm-tab-link <?php echo $current === $slug ? 'active' : ''; ?>"<?php echo $current === $slug ? ' aria-current="page"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			switch ( $current ) {
				case 'calculator':
					$this->render_calculator();
					break;
				case 'export':
					$this->render_export();
					break;
				case 'settings':
					$this->render_settings();
					break;
				case 'help':
					$this->render_help();
					break;
				default:
					$this->render_overview();
			}
			?>
		</div>
		<?php
	}

	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display flags after a redirect.
		if ( isset( $_GET['seeded'] ) ) {
			$added = absint( wp_unslash( $_GET['seeded'] ) );
			$text  = 0 === $added
				? __( 'Keine Beispiel-Lieferanten angelegt: Sie stehen schon im Register.', 'wm-lksg-supply-auditor' )
				/* translators: %d: number of demo suppliers added */
				: sprintf( _n( '%d Beispiel-Lieferant angelegt.', '%d Beispiel-Lieferanten angelegt.', $added, 'wm-lksg-supply-auditor' ), $added );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
		}
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Einstellungen gespeichert.', 'wm-lksg-supply-auditor' ) . '</p></div>';
		}
		if ( isset( $_GET['year_error'] ) ) {
			/* translators: 1: lowest year, 2: highest year */
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'Das Jahr wurde nicht gespeichert: bitte eine Jahreszahl zwischen %1$d und %2$d eingeben.', 'wm-lksg-supply-auditor' ), Settings::MIN_YEAR, Settings::max_year() ) ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private function render_overview(): void {
		$counts = Register::counts();
		if ( null === $counts['total'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Die Lieferantentabelle fehlt. Das Plugin einmal deaktivieren und wieder aktivieren.', 'wm-lksg-supply-auditor' ) . '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging.
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$page  = Register::page( $paged, self::PER_PAGE );
		?>
		<div class="wm-tab-content">
			<div class="wm-grid-4">
				<div class="wm-stat-card">
					<span class="wm-stat-label"><?php esc_html_e( 'Im Register', 'wm-lksg-supply-auditor' ); ?></span>
					<span class="wm-stat-value"><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></span>
					<span class="wm-stat-delta"><?php esc_html_e( 'alle Einträge', 'wm-lksg-supply-auditor' ); ?></span>
				</div>
				<div class="wm-stat-card">
					<span class="wm-stat-label"><?php esc_html_e( 'Bewertet', 'wm-lksg-supply-auditor' ); ?></span>
					<span class="wm-stat-value"><?php echo esc_html( number_format_i18n( $counts['assessed'] ) ); ?></span>
					<span class="wm-stat-delta"><?php esc_html_e( 'über den Rechner oder POST /suppliers', 'wm-lksg-supply-auditor' ); ?></span>
				</div>
				<div class="wm-stat-card">
					<span class="wm-stat-label"><?php esc_html_e( 'Nicht bewertet', 'wm-lksg-supply-auditor' ); ?></span>
					<span class="wm-stat-value"><?php echo esc_html( number_format_i18n( $counts['unassessed'] ) ); ?></span>
					<span class="wm-stat-delta"><?php esc_html_e( 'Selbstauskünfte', 'wm-lksg-supply-auditor' ); ?></span>
				</div>
				<div class="wm-stat-card">
					<span class="wm-stat-label"><?php esc_html_e( 'Hoch oder kritisch', 'wm-lksg-supply-auditor' ); ?></span>
					<span class="wm-stat-value"><?php echo esc_html( number_format_i18n( $counts['high_or_critical'] ) ); ?></span>
					<span class="wm-stat-delta"><?php esc_html_e( 'aktuelle Stufe', 'wm-lksg-supply-auditor' ); ?></span>
				</div>
			</div>

			<div class="wm-card">
				<h2><?php esc_html_e( 'Lieferantenregister', 'wm-lksg-supply-auditor' ); ?></h2>
				<p class="wm-card-caption"><?php esc_html_e( 'Nicht bewertete Selbstauskünfte stehen oben. Eine Selbstauskunft lässt sich hier nicht bewerten: Eine Bewertung über den Rechner oder POST /suppliers legt einen neuen Eintrag an.', 'wm-lksg-supply-auditor' ); ?></p>

				<?php if ( ! $page['rows'] ) : ?>
					<p class="wm-empty-state"><?php esc_html_e( 'Noch keine Lieferanten im Register.', 'wm-lksg-supply-auditor' ); ?></p>
				<?php else : ?>
					<div class="wm-table-container">
						<table class="wm-table widefat striped">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Lieferant', 'wm-lksg-supply-auditor' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Land', 'wm-lksg-supply-auditor' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Branche', 'wm-lksg-supply-auditor' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Stufe', 'wm-lksg-supply-auditor' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Punktzahl', 'wm-lksg-supply-auditor' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Einstufung', 'wm-lksg-supply-auditor' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Status', 'wm-lksg-supply-auditor' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $page['rows'] as $row ) : ?>
									<?php $badge = self::level_badge( (string) ( $row['risk_level'] ?? '' ) ); ?>
									<tr>
										<td><strong><?php echo esc_html( (string) $row['supplier_name'] ); ?></strong></td>
										<td><code><?php echo esc_html( (string) $row['country_code'] ); ?></code></td>
										<td><?php echo esc_html( (string) ( $row['industry_sector'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) (int) $row['tier_level'] ); ?></td>
										<td><?php echo 'unassessed' === ( $row['risk_level'] ?? '' ) ? '&ndash;' : esc_html( number_format_i18n( (float) $row['risk_score'], 1 ) ); ?></td>
										<td><span class="<?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span></td>
										<td><code><?php echo esc_html( (string) $row['audit_status'] ); ?></code></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<?php if ( $page['pages'] > 1 ) : ?>
						<nav class="tablenav" aria-label="<?php esc_attr_e( 'Seiten des Registers', 'wm-lksg-supply-auditor' ); ?>">
							<div class="tablenav-pages">
								<span class="displaying-num">
									<?php
									/* translators: 1: current page, 2: number of pages */
									echo esc_html( sprintf( __( 'Seite %1$d von %2$d', 'wm-lksg-supply-auditor' ), $page['page'], $page['pages'] ) );
									?>
								</span>
								<?php if ( $page['page'] > 1 ) : ?>
									<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=overview&paged=' . ( $page['page'] - 1 ) ) ); ?>"><?php esc_html_e( 'Vorherige Seite', 'wm-lksg-supply-auditor' ); ?></a>
								<?php endif; ?>
								<?php if ( $page['page'] < $page['pages'] ) : ?>
									<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=overview&paged=' . ( $page['page'] + 1 ) ) ); ?>"><?php esc_html_e( 'Nächste Seite', 'wm-lksg-supply-auditor' ); ?></a>
								<?php endif; ?>
							</div>
						</nav>
					<?php endif; ?>
				<?php endif; ?>

				<form method="post" action="">
					<?php wp_nonce_field( 'wm_lksg_seed', 'wm_lksg_nonce' ); ?>
					<input type="hidden" name="wm_lksg_action" value="seed_demo_data" />
					<p><button type="submit" class="button"><?php esc_html_e( 'Beispiel-Lieferanten anlegen (erfundene Namen, berechnete Punktzahlen)', 'wm-lksg-supply-auditor' ); ?></button></p>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_calculator(): void {
		$state     = $this->calculate();
		$token     = bin2hex( random_bytes( 16 ) );
		$countries = self::country_labels();
		$sectors   = self::sector_labels();
		$chosen    = is_array( $state ) ? $state : [ 'name' => '', 'country' => 'DE', 'sector' => 'it_software', 'tier' => 1 ];
		?>
		<div class="wm-tab-content">
			<div class="wm-grid-2">
				<div class="wm-card">
					<h2><?php esc_html_e( 'Lieferanten bewerten', 'wm-lksg-supply-auditor' ); ?></h2>
					<p class="wm-card-caption"><?php esc_html_e( 'Abstrakte Stufe aus Land und Branche, konkrete Stufe aus Schwere, Wahrscheinlichkeit, Abhängigkeit vom Lieferanten und eigenem Verursachungsbeitrag, abzüglich Zertifizierungen. Gewichte und Grenzen sind Heuristiken von WM Suite.', 'wm-lksg-supply-auditor' ); ?></p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=calculator' ) ); ?>">
						<?php wp_nonce_field( 'wm_lksg_calc', 'wm_lksg_calc_nonce' ); ?>
						<input type="hidden" name="wm_calc_token" value="<?php echo esc_attr( $token ); ?>" />

						<div class="wm-form-group">
							<label class="wm-label" for="wm-calc-name"><?php esc_html_e( 'Name des Lieferanten', 'wm-lksg-supply-auditor' ); ?></label>
							<input type="text" id="wm-calc-name" name="wm_calc_name" class="wm-input" required maxlength="255" value="<?php echo esc_attr( (string) $chosen['name'] ); ?>" />
						</div>

						<div class="wm-form-group">
							<label class="wm-label" for="wm-calc-country"><?php esc_html_e( 'Land', 'wm-lksg-supply-auditor' ); ?></label>
							<select id="wm-calc-country" name="wm_calc_country" class="wm-select">
								<?php foreach ( $countries as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( (string) $chosen['country'], $code ); ?>>
										<?php
										/* translators: 1: country name, 2: ISO code, 3: country tier 0-100 */
										echo esc_html( sprintf( __( '%1$s (%2$s), Länderstufe %3$s', 'wm-lksg-supply-auditor' ), $label, $code, number_format_i18n( (float) RiskFactors::country_cpi_risk( $code ) ) ) );
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="wm-form-group">
							<label class="wm-label" for="wm-calc-sector"><?php esc_html_e( 'Branche', 'wm-lksg-supply-auditor' ); ?></label>
							<select id="wm-calc-sector" name="wm_calc_sector" class="wm-select">
								<?php foreach ( $sectors as $key => $label ) : ?>
									<?php $profile = RiskFactors::sector_profile( $key ); ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) $chosen['sector'], $key ); ?>>
										<?php
										/* translators: 1: sector name, 2: sector risk 0-100, 3: product risk 0-100 */
										echo esc_html( sprintf( __( '%1$s (Sektor %2$s, Produkt %3$s)', 'wm-lksg-supply-auditor' ), $label, number_format_i18n( (float) ( $profile['sector_risk'] ?? 50 ) ), number_format_i18n( (float) ( $profile['product_risk'] ?? 50 ) ) ) );
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="wm-form-group">
							<label class="wm-label" for="wm-calc-tier"><?php esc_html_e( 'Lieferantenstufe', 'wm-lksg-supply-auditor' ); ?></label>
							<select id="wm-calc-tier" name="wm_calc_tier" class="wm-select">
								<option value="1" <?php selected( (int) $chosen['tier'], 1 ); ?>><?php esc_html_e( '1 (unmittelbarer Zulieferer)', 'wm-lksg-supply-auditor' ); ?></option>
								<option value="2" <?php selected( (int) $chosen['tier'], 2 ); ?>><?php esc_html_e( '2 (mittelbarer Zulieferer)', 'wm-lksg-supply-auditor' ); ?></option>
								<option value="3" <?php selected( (int) $chosen['tier'], 3 ); ?>><?php esc_html_e( '3 oder weiter', 'wm-lksg-supply-auditor' ); ?></option>
							</select>
						</div>

						<fieldset class="wm-form-group">
							<legend class="wm-label"><?php esc_html_e( 'Konkrete Stufe, jeweils 0 bis 100', 'wm-lksg-supply-auditor' ); ?></legend>
							<p class="wm-field-desc"><?php esc_html_e( 'Eine Schwere ab 85 ist für sich allein kritisch.', 'wm-lksg-supply-auditor' ); ?></p>
							<div class="wm-grid-2">
								<p>
									<label class="wm-label" for="wm-calc-severity"><?php esc_html_e( 'Schwere', 'wm-lksg-supply-auditor' ); ?></label>
									<input type="number" id="wm-calc-severity" name="wm_calc_severity" class="wm-input" min="0" max="100" step="1" value="50" required />
								</p>
								<p>
									<label class="wm-label" for="wm-calc-probability"><?php esc_html_e( 'Wahrscheinlichkeit', 'wm-lksg-supply-auditor' ); ?></label>
									<input type="number" id="wm-calc-probability" name="wm_calc_probability" class="wm-input" min="0" max="100" step="1" value="50" required />
								</p>
								<p>
									<label class="wm-label" for="wm-calc-dependency"><?php esc_html_e( 'Abhängigkeit vom Lieferanten', 'wm-lksg-supply-auditor' ); ?></label>
									<input type="number" id="wm-calc-dependency" name="wm_calc_dependency" class="wm-input" min="0" max="100" step="1" value="10" required />
								</p>
								<p>
									<label class="wm-label" for="wm-calc-causality"><?php esc_html_e( 'Eigener Verursachungsbeitrag', 'wm-lksg-supply-auditor' ); ?></label>
									<input type="number" id="wm-calc-causality" name="wm_calc_causality" class="wm-input" min="0" max="100" step="1" value="10" required />
								</p>
							</div>
						</fieldset>

						<fieldset class="wm-form-group">
							<legend class="wm-label"><?php esc_html_e( 'Zertifizierungen des Lieferanten', 'wm-lksg-supply-auditor' ); ?></legend>
							<label class="wm-toggle-label"><input type="checkbox" name="wm_calc_iso14001" value="1" /> <?php esc_html_e( 'ISO 14001 (minus 10 Punkte auf der konkreten Stufe)', 'wm-lksg-supply-auditor' ); ?></label>
							<label class="wm-toggle-label"><input type="checkbox" name="wm_calc_sa8000" value="1" /> <?php esc_html_e( 'SA 8000 (minus 15 Punkte auf der konkreten Stufe)', 'wm-lksg-supply-auditor' ); ?></label>
						</fieldset>

						<div class="wm-form-group">
							<label class="wm-toggle-label"><input type="checkbox" name="save_to_register" value="1" checked /> <?php esc_html_e( 'Ergebnis im Register speichern', 'wm-lksg-supply-auditor' ); ?></label>
						</div>

						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Punktzahl berechnen', 'wm-lksg-supply-auditor' ); ?></button></p>
					</form>
				</div>

				<div class="wm-card">
					<h2><?php esc_html_e( 'Ergebnis', 'wm-lksg-supply-auditor' ); ?></h2>
					<?php if ( null === $state ) : ?>
						<p class="wm-empty-state"><?php esc_html_e( 'Noch keine Berechnung.', 'wm-lksg-supply-auditor' ); ?></p>
					<?php elseif ( null !== $state['error'] ) : ?>
						<div class="notice notice-error inline"><p><?php echo esc_html( (string) $state['error'] ); ?></p></div>
					<?php else : ?>
						<?php
						$risk    = $state['risk'];
						$factors = $state['factors'];
						$badge   = self::level_badge( (string) $risk['level'] );
						?>
						<p class="wm-stat-value"><?php echo esc_html( number_format_i18n( (float) $risk['score'], 2 ) ); ?> / 100</p>
						<p><span class="<?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span> <code><?php echo esc_html( (string) $risk['action_trigger'] ); ?></code></p>
						<p class="wm-card-caption">
							<?php
							echo esc_html( sprintf(
								/* translators: 1: abstract score, 2: country risk, 3: sector risk, 4: product risk, 5: concrete score, 6: severity, 7: probability, 8: dependency, 9: causality */
								__( 'Abstrakte Stufe %1$s (Land %2$s, Sektor %3$s, Produkt %4$s) · konkrete Stufe %5$s (Schwere %6$s, Wahrscheinlichkeit %7$s, Abhängigkeit %8$s, Verursachungsbeitrag %9$s)', 'wm-lksg-supply-auditor' ),
								number_format_i18n( (float) $risk['abstract_score'], 1 ),
								number_format_i18n( (float) $factors['country_cpi_risk'] ),
								number_format_i18n( (float) $factors['sector_risk'] ),
								number_format_i18n( (float) $factors['product_risk'] ),
								number_format_i18n( (float) $risk['concrete_score'], 1 ),
								number_format_i18n( (float) $factors['severity_score'] ),
								number_format_i18n( (float) $factors['probability_score'] ),
								number_format_i18n( (float) $factors['dependency_ratio'] ),
								number_format_i18n( (float) $factors['causal_contribution'] )
							) );
							?>
						</p>
						<?php if ( ! empty( $risk['high_risk_flags'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Hinweise:', 'wm-lksg-supply-auditor' ); ?></strong> <?php echo esc_html( implode( ', ', (array) $risk['high_risk_flags'] ) ); ?></p>
						<?php endif; ?>

						<?php if ( $state['saved'] ) : ?>
							<div class="wm-inline-notice is-success"><?php esc_html_e( 'Im Register gespeichert, mit der Bewertung im Verlauf.', 'wm-lksg-supply-auditor' ); ?></div>
						<?php elseif ( $state['duplicate'] ) : ?>
							<div class="wm-inline-notice is-warning"><?php esc_html_e( 'Dieses Formular wurde schon gespeichert (die Seite wurde neu geladen); es wurde kein zweiter Eintrag angelegt.', 'wm-lksg-supply-auditor' ); ?></div>
						<?php elseif ( $state['failed'] ) : ?>
							<div class="wm-inline-notice is-danger"><?php esc_html_e( 'Nicht im Register gespeichert. Die Bewertung wird angezeigt, ist aber nicht festgehalten.', 'wm-lksg-supply-auditor' ); ?></div>
						<?php endif; ?>

						<h3><?php esc_html_e( 'Einordnung', 'wm-lksg-supply-auditor' ); ?></h3>
						<p><?php echo esc_html( self::level_explanation( (string) $risk['level'] ) ); ?></p>
						<p class="wm-card-caption"><?php esc_html_e( '§ 3 Abs. 2 und § 5 Abs. 2 LkSG nennen Kriterien, aber keine Gewichte. Welche Präventions- oder Abhilfemaßnahmen angemessen sind, entscheidet das Unternehmen, nicht diese Zahl.', 'wm-lksg-supply-auditor' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * What a level means in the plugin. Until 2026-09-15 the result listed "Acciones legales requeridas" for a score,
	 * including a corrective action plan "(§ 7 Abs. 2 LkSG)", and called a low score compliance.
	 */
	public static function level_explanation( string $level ): string {
		return match ( $level ) {
			'critical' => __( 'Kritisch: Punktzahl ab 75 oder Schwere ab 85. Das Plugin ordnet dem den Auslöser CAPA_REQUIRED zu. Abhilfemaßnahmen nach § 7 LkSG knüpfen an eine festgestellte oder unmittelbar bevorstehende Verletzung an, nicht an eine Punktzahl.', 'wm-lksg-supply-auditor' ),
			'high'     => __( 'Hoch: Punktzahl 50 bis unter 75. Das Plugin ordnet dem den Auslöser AUDIT_REQUIRED zu.', 'wm-lksg-supply-auditor' ),
			'medium'   => __( 'Mittel: Punktzahl 25 bis unter 50. Das Plugin ordnet dem den Auslöser QUESTIONNAIRE_REQUIRED zu.', 'wm-lksg-supply-auditor' ),
			'low'      => __( 'Niedrig: Punktzahl unter 25. Das Plugin ordnet dem den Auslöser NO_ACTION zu. Eine niedrige Punktzahl belegt nicht, dass keine Risiken bestehen; sie kann ganz auf Standardwerten beruhen.', 'wm-lksg-supply-auditor' ),
			default    => __( 'Unbekannte Stufe.', 'wm-lksg-supply-auditor' ),
		};
	}

	/**
	 * The register summary for the settings year. Until 2026-09-15 the JSON carried fixed `true` statements and the
	 * link to it had no REST nonce, so the logged-in administrator got 401.
	 */
	private function render_export(): void {
		$year   = Settings::reporting_year();
		$report = $this->bafa_exporter->generate_bafa_report_data( $year );
		$url    = wp_nonce_url( add_query_arg( 'year', $year, rest_url( 'wm-lksg/v1/bafa-report' ) ), 'wp_rest' );
		?>
		<div class="wm-tab-content">
			<div class="wm-card">
				<h2>
					<?php
					/* translators: %d: calendar year */
					echo esc_html( sprintf( __( 'Jahresübersicht %d', 'wm-lksg-supply-auditor' ), $year ) );
					?>
				</h2>
				<p class="wm-card-caption">
					<?php
					/* translators: %d: calendar year */
					echo esc_html( sprintf( __( 'Zählt, was das Register für das Kalenderjahr %d enthält (UTC). Das ist kein Bericht nach § 10 Abs. 2 LkSG und kein BAFA-Formular. Das Jahr stellen Sie unter Einstellungen ein.', 'wm-lksg-supply-auditor' ), $year ) );
					?>
				</p>
				<pre class="wm-code-block"><?php echo esc_html( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
				<p><a class="button button-primary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'JSON öffnen', 'wm-lksg-supply-auditor' ); ?></a></p>
				<div class="wm-inline-notice"><?php esc_html_e( 'Nicht erfasst werden: die Zuständigkeit (§ 4 Abs. 3 LkSG), die Grundsatzerklärung (§ 6 Abs. 2), Präventionsmaßnahmen (§ 6), Abhilfemaßnahmen (§ 7), das Beschwerdeverfahren (§ 8) und die Bewertung der Wirksamkeit der Maßnahmen.', 'wm-lksg-supply-auditor' ); ?></div>
			</div>
		</div>
		<?php
	}

	private function render_settings(): void {
		$year = Settings::reporting_year();
		?>
		<div class="wm-tab-content">
			<div class="wm-card">
				<h2><?php esc_html_e( 'Einstellungen', 'wm-lksg-supply-auditor' ); ?></h2>
				<form method="post" action="">
					<?php wp_nonce_field( 'wm_lksg_save_settings', 'wm_lksg_nonce' ); ?>
					<input type="hidden" name="wm_lksg_action" value="save_settings" />
					<div class="wm-field-card">
						<label class="wm-field-title" for="wm-lksg-year"><?php esc_html_e( 'Jahr der Jahresübersicht', 'wm-lksg-supply-auditor' ); ?></label>
						<p class="wm-field-desc"><?php esc_html_e( 'Kalenderjahr, das die Jahresübersicht, WP-CLI und die Route /bafa-report ohne Jahresangabe zählen. Ohne Eintrag gilt das Vorjahr.', 'wm-lksg-supply-auditor' ); ?></p>
						<input type="number" id="wm-lksg-year" name="reporting_year" class="wm-input" required min="<?php echo esc_attr( (string) Settings::MIN_YEAR ); ?>" max="<?php echo esc_attr( (string) Settings::max_year() ); ?>" step="1" value="<?php echo esc_attr( (string) $year ); ?>" />
					</div>
					<div class="wm-field-card">
						<label class="wm-toggle-label" for="wm-lksg-delete-on-uninstall">
							<input type="checkbox" id="wm-lksg-delete-on-uninstall" name="wm_lksg_delete_data_on_uninstall" value="1" <?php checked( Settings::delete_data_on_uninstall() ); ?> />
							<?php esc_html_e( 'Alle Plugin-Tabellen und Einstellungen beim Löschen des Plugins unwiderruflich entfernen', 'wm-lksg-supply-auditor' ); ?>
						</label>
						<p class="wm-field-desc"><?php esc_html_e( 'Standardmäßig bleiben Lieferantenregister, Bewertungsverlauf und Selbstauskünfte beim Löschen des Plugins erhalten (§ 10 Abs. 1 LkSG sieht eine Aufbewahrung von mindestens sieben Jahren vor). Nur aktivieren, wenn sämtliche Daten endgültig entfernt werden sollen.', 'wm-lksg-supply-auditor' ); ?></p>
					</div>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Speichern', 'wm-lksg-supply-auditor' ); ?></button></p>
				</form>
				<div class="wm-inline-notice"><?php esc_html_e( 'Einen eigenen Schwellenwert gibt es nicht: Die Stufen folgen festen Grenzen (kritisch ab 75 Punkten oder ab einer Schwere von 85, hoch ab 50, mittel ab 25). Das frühere Schwellenwert-Feld wurde von nichts gelesen.', 'wm-lksg-supply-auditor' ); ?></div>
			</div>
		</div>
		<?php
	}

	private function render_help(): void {
		?>
		<div class="wm-tab-content">
			<div class="wm-glossary-grid">
				<div class="wm-glossary-card">
					<div class="wm-glossary-term"><?php esc_html_e( 'Zwei Stufen, feste Gewichte', 'wm-lksg-supply-auditor' ); ?></div>
					<p class="wm-glossary-def"><?php esc_html_e( 'Abstrakte Stufe: 0,40 × Land + 0,40 × Sektor + 0,20 × Produkt. Konkrete Stufe: 0,40 × Schwere + 0,40 × Wahrscheinlichkeit + 0,10 × Abhängigkeit + 0,10 × Verursachungsbeitrag, minus 10 für ISO 14001 und 15 für SA 8000. Gesamt: 0,30 × abstrakt + 0,70 × konkret. Länderstufen (DE 15 bis BD 85) und Sektorprofile sind Heuristiken von WM Suite, kein Korruptionsindex.', 'wm-lksg-supply-auditor' ); ?></p>
				</div>
				<div class="wm-glossary-card">
					<div class="wm-glossary-term"><?php esc_html_e( '§ 3 Abs. 2 LkSG', 'wm-lksg-supply-auditor' ); ?></div>
					<p class="wm-glossary-def"><?php esc_html_e( 'Die angemessene Weise eines Handelns bestimmt sich nach Art und Umfang der Geschäftstätigkeit, dem Einflussvermögen auf den unmittelbaren Verursacher, der typischerweise zu erwartenden Schwere, der Umkehrbarkeit und der Wahrscheinlichkeit der Verletzung sowie der Art des Verursachungsbeitrags. Der Rechner fragt Schwere, Wahrscheinlichkeit, Abhängigkeit vom Lieferanten und Verursachungsbeitrag ab; Art und Umfang der Geschäftstätigkeit, Einflussvermögen und Umkehrbarkeit fragt er nicht ab.', 'wm-lksg-supply-auditor' ); ?></p>
				</div>
				<div class="wm-glossary-card">
					<div class="wm-glossary-term"><?php esc_html_e( 'Selbstauskunft', 'wm-lksg-supply-auditor' ); ?></div>
					<p class="wm-glossary-def"><?php esc_html_e( 'Das Formular [wm_lksg_supplier_portal] speichert Firmenname, Land, Branche, Stufe, die angekreuzten Zertifizierungen und den bestätigten Wortlaut, mit einer HMAC-Signatur. Wer absendet, wird nicht identifiziert. Eine Selbstauskunft bleibt „nicht bewertet“.', 'wm-lksg-supply-auditor' ); ?></p>
				</div>
				<div class="wm-glossary-card">
					<div class="wm-glossary-term"><?php esc_html_e( 'Jahresübersicht', 'wm-lksg-supply-auditor' ); ?></div>
					<p class="wm-glossary-def"><?php esc_html_e( 'Zählt die Einträge des Registers für ein Kalenderjahr. Sie ist kein Bericht nach § 10 Abs. 2 LkSG und kein BAFA-Formular.', 'wm-lksg-supply-auditor' ); ?></p>
				</div>
				<div class="wm-glossary-card">
					<div class="wm-glossary-term"><?php esc_html_e( 'Aufbewahrung', 'wm-lksg-supply-auditor' ); ?></div>
					<p class="wm-glossary-def"><?php esc_html_e( '§ 10 Abs. 1 LkSG: „Die Dokumentation ist ab ihrer Erstellung mindestens sieben Jahre lang aufzubewahren.“ Standardmäßig bleiben alle Tabellen und Einstellungen beim Löschen des Plugins erhalten, sofern unter Einstellungen nicht ausdrücklich das endgültige Löschen aktiviert wurde.', 'wm-lksg-supply-auditor' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}

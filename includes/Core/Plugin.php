<?php
declare(strict_types=1);

namespace WMCyber\LkSG\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\LkSG\Risk\RiskMatrixCalculator;
use WMCyber\LkSG\Export\BafaReportExporter;
use WMCyber\LkSG\API\RestController;
use WMCyber\LkSG\CLI\LksgCommand;
use WMCyber\LkSG\Admin\AdminDashboard;
use WMCyber\LkSG\Frontend\EcosystemIntegration;
use WMCyber\LkSG\Frontend\SupplierPortal;

final class Plugin {
	private static ?Plugin $instance = null;
	private RiskMatrixCalculator $risk_calculator;
	private BafaReportExporter $bafa_exporter;

	private function __construct() {
		$this->risk_calculator = new RiskMatrixCalculator();
		$this->bafa_exporter   = new BafaReportExporter();
	}

	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'init', function (): void {
			load_plugin_textdomain(
				'wm-lksg-supply-auditor',
				false,
				dirname( plugin_basename( WM_LKSG_FILE ) ) . '/languages'
			);
		} );

		// Sites that were active before a schema change never click "activate" again.
		\WMCyber\LkSG\Database\Schema::maybe_upgrade();

		( new RestController( $this->risk_calculator, $this->bafa_exporter ) )->register_routes();

		if ( is_admin() ) {
			( new AdminDashboard( $this->risk_calculator, $this->bafa_exporter ) )->init();
		}

		// Initialize Frontend Supplier Self-Assessment Portal & Ecosystem Bridges
		( new SupplierPortal( $this->risk_calculator ) )->init();
		( new EcosystemIntegration() )->init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			LksgCommand::register();
		}

		if ( function_exists( 'do_action' ) ) {
			/**
			 * Fires once WM LkSG Supply Chain Auditor has booted its services and hooks.
			 *
			 * @param Plugin $plugin Booted plugin instance.
			 */
			do_action( 'wm_lksg_ready', $this );
		}
	}

	public function get_risk_calculator(): RiskMatrixCalculator {
		return $this->risk_calculator;
	}

	public function get_bafa_exporter(): BafaReportExporter {
		return $this->bafa_exporter;
	}
}

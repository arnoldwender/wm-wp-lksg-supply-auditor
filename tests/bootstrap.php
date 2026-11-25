<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
}
if ( ! defined( 'WM_LKSG_PATH' ) ) {
	define( 'WM_LKSG_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WM_LKSG_VERSION' ) ) {
	define( 'WM_LKSG_VERSION', '1.2.3' );
}

require_once dirname( __DIR__ ) . '/includes/Risk/RiskMatrixCalculator.php';
require_once dirname( __DIR__ ) . '/includes/Risk/RiskFactors.php';
require_once dirname( __DIR__ ) . '/includes/Database/Schema.php';
require_once dirname( __DIR__ ) . '/includes/Export/BafaReportExporter.php';

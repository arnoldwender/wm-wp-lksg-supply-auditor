<?php
/**
 * Static guard: the admin dashboard may not call methods that do not exist.
 *
 * A `Call to undefined method` is a white screen, not a warning. On 2026-08-27 the BAFA export tab
 * fatal'd in production on generate_report_array(), a method the exporter never had — and two more
 * calls of the same kind sat unfired on another tab. PHP only finds these at the moment of
 * rendering, so nothing short of opening every tab would have caught them by hand.
 *
 * @package WMCyber\LkSG
 */

declare(strict_types=1);

namespace WMCyber\LkSG\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DashboardCallGraphTest extends TestCase {

	/** Property name on AdminDashboard => collaborating class file. */
	private const COLLABORATORS = array(
		'bafa_exporter'   => 'includes/Export/BafaReportExporter.php',
		'risk_calculator' => 'includes/Risk/RiskMatrixCalculator.php',
	);

	/**
	 * Public method names declared in a PHP source file.
	 *
	 * @param string $file Absolute path.
	 * @return string[]
	 */
	private function public_methods( string $file ): array {
		$src = (string) file_get_contents( $file );
		preg_match_all( '/public function ([a-zA-Z_][a-zA-Z0-9_]*)/', $src, $m );
		return $m[1];
	}

	/**
	 * Method calls made on a given property inside a source file.
	 *
	 * @param string $file     Absolute path.
	 * @param string $property Property name without the dollar sign.
	 * @return string[]
	 */
	private function calls_on( string $file, string $property ): array {
		$src = (string) file_get_contents( $file );
		preg_match_all( '/\$this->' . preg_quote( $property, '/' ) . '->([a-zA-Z_][a-zA-Z0-9_]*)\(/', $src, $m );
		return array_unique( $m[1] );
	}

	public function test_every_collaborator_call_resolves_to_a_real_method(): void {
		$root      = dirname( __DIR__, 2 );
		$dashboard = $root . '/includes/Admin/AdminDashboard.php';

		$this->assertFileExists( $dashboard );

		$checked = 0;
		foreach ( self::COLLABORATORS as $property => $relative ) {
			$collaborator = $root . '/' . $relative;
			$this->assertFileExists( $collaborator, "Collaborator source missing: {$relative}" );

			$available = $this->public_methods( $collaborator );
			$this->assertNotEmpty( $available, "No public methods parsed from {$relative}" );

			foreach ( $this->calls_on( $dashboard, $property ) as $call ) {
				++$checked;
				$this->assertContains(
					$call,
					$available,
					sprintf(
						'AdminDashboard calls $this->%s->%s() but %s declares no such public method. '
						. 'This is a fatal error the moment that tab renders.',
						$property,
						$call,
						basename( $relative )
					)
				);
			}
		}

		// Guard the guard: if the regex stops matching, this test must not pass vacuously.
		$this->assertGreaterThan( 0, $checked, 'No collaborator calls were inspected — the parser found nothing.' );
	}
}

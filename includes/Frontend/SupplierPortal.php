<?php
/**
 * Public self-disclosure form: shortcodes [wm_lksg_supplier_portal] and [wm_lksg_declaration], block
 * wm/lksg-supplier-portal (blocks/supplier-portal/block.json).
 *
 * Until 2026-09-15 every instance printed the same element IDs, an inline script and inline dark-theme colours: two
 * forms on a page bound two submit listeners to the first form (two POSTs per click) and left the second unwired; the
 * script sent `declaration_accepted: true` although the form had no checkbox; the badge said "LkSG § 5 & EU CSDDD
 * Konformität" and the button "rechtsverbindlich übermitteln" for a submitter nobody identifies. The form now carries
 * its own IDs, a required declaration checkbox, a stylesheet with its tokens and a script file that binds each form
 * once.
 *
 * @package WMCyber\LkSG
 */

declare(strict_types=1);

namespace WMCyber\LkSG\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\LkSG\API\RestController;
use WMCyber\LkSG\Risk\RiskMatrixCalculator;

final class SupplierPortal {

	/** Sector keys the form offers and /self-disclosure accepts; each has a profile in RiskFactors. */
	public const SECTOR_KEYS = [ 'manufacturing', 'electronics', 'textiles', 'mining', 'it_services', 'logistics' ];

	public function __construct(
		private readonly RiskMatrixCalculator $risk_calculator
	) {}

	public function init(): void {
		add_shortcode( 'wm_lksg_supplier_portal', [ $this, 'render_portal' ] );
		add_shortcode( 'wm_lksg_declaration', [ $this, 'render_portal' ] );
		add_action( 'init', [ $this, 'register_block' ] );
	}

	public function register_block(): void {
		$asset_file = WM_LKSG_PATH . 'build/index.asset.php';
		$asset      = is_file( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ],
				'version'      => WM_LKSG_VERSION,
			];

		if ( function_exists( 'wp_register_style' ) ) {
			$css_path = WM_LKSG_PATH . 'assets/css/wm-lksg-portal.css';
			wp_register_style(
				'wm-lksg-portal',
				WM_LKSG_URL . 'assets/css/wm-lksg-portal.css',
				[],
				is_file( $css_path ) ? (string) filemtime( $css_path ) : WM_LKSG_VERSION
			);
		}

		if ( function_exists( 'wp_register_script' ) ) {
			wp_register_script(
				'wm-lksg-blocks-editor',
				WM_LKSG_URL . 'build/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
		}

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'wm-lksg-blocks-editor',
				'wm-lksg-supply-auditor',
				WM_LKSG_PATH . 'languages'
			);
		}

		if ( function_exists( 'register_block_type' ) ) {
			register_block_type(
				WM_LKSG_PATH . 'blocks/supplier-portal',
				[
					'render_callback' => [ $this, 'render_portal' ],
				]
			);
		}
	}

	/** @return array<string, string> Sector key => label. */
	public static function sector_labels(): array {
		return [
			'manufacturing' => __( 'Fertigung und Produktion', 'wm-lksg-supply-auditor' ),
			'electronics'   => __( 'Elektronik und Halbleiter', 'wm-lksg-supply-auditor' ),
			'textiles'      => __( 'Textil- und Bekleidungsindustrie', 'wm-lksg-supply-auditor' ),
			'mining'        => __( 'Rohstoffgewinnung und Bergbau', 'wm-lksg-supply-auditor' ),
			'it_services'   => __( 'IT- und Cloud-Dienstleistungen', 'wm-lksg-supply-auditor' ),
			'logistics'     => __( 'Transport und Logistik', 'wm-lksg-supply-auditor' ),
		];
	}

	private function enqueue_assets(): void {
		$root    = dirname( __DIR__, 2 ) . '/assets/';
		$version = static fn( string $file ): string => is_file( $root . $file ) ? (string) filemtime( $root . $file ) : WM_LKSG_VERSION;
		wp_enqueue_style( 'wm-lksg-portal', WM_LKSG_URL . 'assets/css/wm-lksg-portal.css', [], $version( 'css/wm-lksg-portal.css' ) );
		wp_enqueue_script( 'wm-lksg-portal', WM_LKSG_URL . 'assets/js/wm-lksg-portal.js', [], $version( 'js/wm-lksg-portal.js' ), [ 'in_footer' => true, 'strategy' => 'defer' ] );
	}

	/**
	 * Render the self-disclosure form for shortcodes and the Gutenberg block.
	 *
	 * @param array<string, mixed>|string $attributes Block or shortcode attributes.
	 */
	public function render_portal( array|string $attributes = [] ): string {
		$this->enqueue_assets();
		$attrs        = is_array( $attributes ) ? $attributes : [];
		$show_intro   = ! array_key_exists( 'showIntro', $attrs ) || (bool) $attrs['showIntro'];
		$default_tier = isset( $attrs['defaultTier'] ) && in_array( (int) $attrs['defaultTier'], [ 1, 2, 3 ], true ) ? (int) $attrs['defaultTier'] : 1;
		$id = wp_unique_id( 'wm-lksg-portal-' );

		ob_start();
		?>
		<section class="wm-lksg-portal" aria-labelledby="<?php echo esc_attr( $id ); ?>-title">
			<p class="wm-lksg-portal-eyebrow"><?php esc_html_e( 'Lieferanten-Selbstauskunft', 'wm-lksg-supply-auditor' ); ?></p>
			<h2 id="<?php echo esc_attr( $id ); ?>-title" class="wm-lksg-portal-title"><?php esc_html_e( 'Selbstauskunft für Lieferanten', 'wm-lksg-supply-auditor' ); ?></h2>
			<?php if ( $show_intro ) : ?>
				<p class="wm-lksg-portal-intro"><?php esc_html_e( 'Ihre Angaben gehen an den Betreiber dieser Website und werden in dessen Lieferantenregister als nicht bewertete Selbstauskunft gespeichert. Die Risikobewertung nimmt der Auftraggeber vor. Eine Meldung an eine Behörde erfolgt hierdurch nicht.', 'wm-lksg-supply-auditor' ); ?></p>
			<?php endif; ?>

			<form class="wm-lksg-portal-form" data-wm-lksg-portal
				data-endpoint="<?php echo esc_url( rest_url( 'wm-lksg/v1/self-disclosure' ) ); ?>"
				data-msg-sending="<?php esc_attr_e( 'Wird übermittelt …', 'wm-lksg-supply-auditor' ); ?>"
				data-msg-failed="<?php esc_attr_e( 'Die Übermittlung ist fehlgeschlagen. Ihre Erklärung wurde NICHT eingereicht.', 'wm-lksg-supply-auditor' ); ?>"
				data-msg-network="<?php esc_attr_e( 'Die Verbindung ist fehlgeschlagen. Ihre Erklärung wurde NICHT eingereicht.', 'wm-lksg-supply-auditor' ); ?>">
				<div class="wm-lksg-portal-grid">
					<p class="wm-lksg-portal-field">
						<label for="<?php echo esc_attr( $id ); ?>-company"><?php esc_html_e( 'Unternehmensname *', 'wm-lksg-supply-auditor' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $id ); ?>-company" name="supplier_name" required maxlength="255" autocomplete="organization" />
					</p>
					<p class="wm-lksg-portal-field">
						<label for="<?php echo esc_attr( $id ); ?>-country"><?php esc_html_e( 'Standortland (ISO-3166-1-Code, zum Beispiel DE) *', 'wm-lksg-supply-auditor' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $id ); ?>-country" name="country_code" required minlength="2" maxlength="2" pattern="[A-Za-z]{2}" autocomplete="country" />
					</p>
				</div>

				<div class="wm-lksg-portal-grid">
					<p class="wm-lksg-portal-field">
						<label for="<?php echo esc_attr( $id ); ?>-sector"><?php esc_html_e( 'Branche *', 'wm-lksg-supply-auditor' ); ?></label>
						<select id="<?php echo esc_attr( $id ); ?>-sector" name="industry_sector" required>
							<?php foreach ( self::sector_labels() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="wm-lksg-portal-field">
						<label for="<?php echo esc_attr( $id ); ?>-tier"><?php esc_html_e( 'Lieferantenstufe *', 'wm-lksg-supply-auditor' ); ?></label>
						<select id="<?php echo esc_attr( $id ); ?>-tier" name="tier_level" required>
							<option value="1"<?php echo 1 !== $default_tier ? '' : ''; ?>><?php esc_html_e( 'Stufe 1 (unmittelbarer Vertragslieferant)', 'wm-lksg-supply-auditor' ); ?></option>
							<option value="2"<?php selected( $default_tier, 2 ); ?>><?php esc_html_e( 'Stufe 2 (Vorlieferant)', 'wm-lksg-supply-auditor' ); ?></option>
							<option value="3"<?php selected( $default_tier, 3 ); ?>><?php esc_html_e( 'Stufe 3 oder weiter (Rohstoff- oder Komponentenquelle)', 'wm-lksg-supply-auditor' ); ?></option>
						</select>
					</p>
				</div>

				<fieldset class="wm-lksg-portal-fieldset">
					<legend><?php esc_html_e( 'Vorhandene Zertifizierungen', 'wm-lksg-supply-auditor' ); ?></legend>
					<label class="wm-lksg-portal-check"><input type="checkbox" name="has_iso_14001" value="1" /> <span><?php esc_html_e( 'ISO 14001 (Umweltmanagement)', 'wm-lksg-supply-auditor' ); ?></span></label>
					<label class="wm-lksg-portal-check"><input type="checkbox" name="has_sa_8000" value="1" /> <span><?php esc_html_e( 'SA8000 (Sozialstandard)', 'wm-lksg-supply-auditor' ); ?></span></label>
					<label class="wm-lksg-portal-check"><input type="checkbox" name="has_iso_45001" value="1" /> <span><?php esc_html_e( 'ISO 45001 (Arbeitsschutzmanagement)', 'wm-lksg-supply-auditor' ); ?></span></label>
				</fieldset>

				<p class="wm-lksg-portal-declaration">
					<label class="wm-lksg-portal-check"><input type="checkbox" name="declaration_accepted" value="1" required /> <span><?php echo esc_html( RestController::declaration_text() ); ?> *</span></label>
				</p>

				<p class="wm-lksg-portal-status" role="status" aria-live="polite" hidden></p>

				<p><button type="submit" class="wm-lksg-portal-submit"><?php esc_html_e( 'Selbstauskunft übermitteln', 'wm-lksg-supply-auditor' ); ?></button></p>
			</form>

			<div class="wm-lksg-portal-result" tabindex="-1" hidden>
				<h3><?php esc_html_e( 'Selbstauskunft übermittelt', 'wm-lksg-supply-auditor' ); ?></h3>
				<p>
					<?php esc_html_e( 'Ihre Angaben wurden gespeichert. Vorgangsnummer:', 'wm-lksg-supply-auditor' ); ?>
					<code class="wm-lksg-portal-reference"></code>
				</p>
				<p class="wm-lksg-portal-intro">
					<?php
					// Was: "Die Risikobewertung wurde nach LkSG-Kriterien berechnet und im zentralen
					// BAFA-Auditregister hinterlegt." Neither happened.
					esc_html_e( 'Die Risikobewertung nimmt der Auftraggeber vor; Ihre Angaben sind die Grundlage dafür und stehen bis dahin als nicht bewertet im Lieferantenregister. Eine Meldung an die BAFA erfolgt hierdurch nicht.', 'wm-lksg-supply-auditor' );
					?>
				</p>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}

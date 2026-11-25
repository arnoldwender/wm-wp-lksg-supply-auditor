=== WM LkSG Supply Chain Auditor ===
Contributors: arnoldwender
Tags: lksg, supply-chain, due-diligence, suppliers, risk-assessment
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

LkSG supplier register: heuristic two-level risk score, supplier self-disclosure block/form and yearly register counts. No BAFA report.

== Description ==

The plugin keeps a register of suppliers and scores each one on two levels: an abstract level from country, sector and product, and a concrete level from severity, probability, dependency on the supplier and the company's own causal contribution, reduced by ISO 14001 and SA 8000. The weights, the six country tiers and the sector profiles are heuristics; the act names criteria (section 3 (2) LkSG), not weights. The score sets a level (low, medium, high, critical) and a trigger.

* **Register and calculator:** a German admin screen lists the suppliers 50 per page, unassessed self-disclosures first, and offers a calculator that stores a supplier once per form.
* **Assessment history:** every stored evaluation keeps its score, level, trigger and factors; if the history row cannot be written, the supplier is not stored.
* **Self-disclosure block and shortcode:** Gutenberg block `wm/lksg-supplier-portal` (with InspectorControls and live preview) and shortcode `[wm_lksg_supplier_portal]` let an external supplier file company, country (ISO 3166-1 alpha-2), sector, tier, certifications and a declaration checkbox. Stored as unassessed with the declared facts, the declared text and an HMAC-SHA256 signature; protected by HMAC-hashed IP rate-limiting without storing raw IP addresses.
* **Yearly summary:** counts from the register for one calendar year (REST `wm-lksg/v1/bafa-report?year=`, `wp lksg export-bafa --year=`, admin tab). It is not the report under section 10 (2) LkSG and names what the plugin does not record.
* **Opt-in uninstall preservation:** deleting the plugin keeps all supplier, assessment and self-disclosure tables by default in view of the seven-year documentation retention under section 10 (1) LkSG unless permanent deletion (`wm_lksg_delete_data_on_uninstall`) is explicitly enabled under Settings.
* **Block Bindings & GenerateBlocks:** WordPress 6.5+ Block Bindings source `wm-lksg/metrics` and GenerateBlocks 2.x Dynamic Tag `{{wm_lksg_metric}}` expose live register counts.
* **REST API & WP-CLI:** `wm-lksg/v1/suppliers` (GET paged and POST, administrators), `wm-lksg/v1/self-disclosure` (HMAC rate-limited permission callback), `wm-lksg/v1/bafa-report` (administrators); `wp lksg score` and `wp lksg export-bafa`.
* **Translations:** 28 translated locales (`.po`/`.mo` and JED `.json`).

Not included: assessing an existing self-disclosure, a complaints procedure, corrective-action management, a policy statement, a report or submission to the BAFA, country index data.

Zero-CDN: every script and style ships inside the plugin.

== Frequently Asked Questions ==

= Is technical or private support provided? =

No. This plugin comes with zero support (`0` support). Private inquiries sent via email, `arnoldwender.com`, or social/chat channels are not answered. Reproducible bugs belong solely on GitHub Issues (`https://github.com/arnoldwender/wm-wp-lksg-supply-auditor/issues`) with no guarantee of reply or resolution. The software is provided **"AS IS"** at your sole risk with zero warranty or liability (see `SUPPORT.md` in the repository).

== Screenshots ==

1. Internal supplier register with country/sector scoring heuristics and the register counts
2. Block editor with wm/lksg-supplier-portal self-disclosure block
3. Supplier self-disclosure frontend portal ([wm_lksg_supplier_portal])

== Changelog ==

= 1.2.3 =
* Fixed: Screenshot 1 showed the WM Suite menu from before WM Suite Hub 1.2.3, with entries the hub no longer has («Marketplace (Lösungspakete)», «Merkle-Audit-Protokoll», «CRA- & SBOM-Zentrale»). It is retaken with the current menu; the plugin's code is unchanged.

= 1.2.2 =
* Fixed: the supplier and self-disclosure counts repeated one plural form in eleven languages with three or more forms; every form their plural rule needs is translated now, in the `.po`/`.mo` and the editor `.json` catalogs.
* The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.

= 1.2.1 =
* Fixed: The Portuguese catalog still named the agency that 1.1.4 removed from the catalogs, in the calculator's note on the score weights. It names WM Suite now, as the German source string does.

= 1.2.0 =
* The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
* The `.wordpress-org/` banners show the current version.
* The first screenshot description names the register counts the screen shows; the yearly summary is another tab.
* Removed: The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

= 1.1.4 =
* Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.
* Added root `SUPPORT.md`, GitHub Issue templates, explicit zero-support & "AS IS" liability notices in READMEs and `readme.txt`, and normalized canonical GPLv2+ `arnoldwender.com` / `arnoldwender/wm-wp-lksg-supply-auditor` links.
* Updated `.wordpress-org/` visual banners to personal `ARNOLD WENDER · OPEN SOURCE` branding.

= 1.1.3 =
* Add `.wordpress-org/` vector icons, banners (`772x250`, `1544x500`), live WordPress screenshots (`screenshot-1..3.png`) and `== Screenshots ==` descriptions.
* Update trilingual READMEs and `llms.txt` with live previews and direct plugin catalog link (`https://arnoldwender.com/wordpress-plugins/wm-lksg-supply-auditor/`).

= 1.1.2 =
* Point Plugin URI to the official plugin page.

= 1.1.1 =
* Fixed: multisite new-site schema provisioning on `wp_initialize_site` (when network-activated, inside `try/finally` around `restore_current_blog()`), network-wide activation support (`$network_wide`) in `Database\Schema::activate()`, and guarded `dbDelta` / `plugin.php` requires.

= 1.1.0 =
* Added: compiled Gutenberg block `wm/lksg-supplier-portal` (`blocks/supplier-portal/block.json`, `build/index.js`, `build/index.asset.php`) visible in the Block Inserter with `InspectorControls` (`showIntro`, `defaultTier`), `ServerSideRender` and `wp_set_script_translations()`.
* Added: WordPress 6.5+ Block Bindings source `wm-lksg/metrics` and GenerateBlocks 2.x Dynamic Tag `{{wm_lksg_metric}}` for live register counts (`total`, `assessed`, `unassessed`, `high_or_critical`, `reporting_year`).
* Added: `do_action( 'wm_lksg_ready', $plugin )` action and `wm_lksg_risk_score` and `wm_lksg_rest_rate_limit` filters.
* Added: CycloneDX 1.6 `bom.json` SBOM and WordPress Playground blueprint (`.wordpress-org/blueprints/blueprint.json`).
* Changed: `POST /wm-lksg/v1/self-disclosure` permission callback now enforces per-minute HMAC-SHA256 IP rate-limiting (`wm_lksg_rl_*`, default 30 req/min, bypassed for `manage_options`, raw IP addresses never stored).
* Changed: `uninstall.php` preserves all plugin tables and options by default unless explicit opt-in option `wm_lksg_delete_data_on_uninstall` is enabled on the settings screen.
* Added: complete translations across all 28 locales (`.po`, `.mo`, and JED `.json` block script catalogs) and unified GPLv2-or-later license metadata.

= 1.0.0 =
* Initial release with two-level heuristic supplier risk matrix, assessment history, signed supplier self-disclosure form, annual register count summary, German admin interface, REST API and WP-CLI commands.

== Upgrade Notice ==

= 1.2.3 =
Screenshot 1 retaken with the current WM Suite menu. No code, data or settings change.

= 1.2.2 =
The family sheet of WM Suite Hub 1.2.5 and plural forms in eleven translations. No data or settings change.

= 1.2.1 =
Translation fix only: the Portuguese catalog no longer names the agency. No data or settings change.

= 1.2.0 =
Hub users: update WM Suite Hub to 1.2.0 together with this plugin. The hub contract moved to a new PHP namespace; next to an older hub the plugin keeps working, but it is not listed in the cockpit and its admin page has no menu entry until the hub is updated.

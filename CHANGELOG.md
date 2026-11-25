# Changelog — WM LkSG Supply Chain Auditor

All notable changes to **WM LkSG Supply Chain Auditor** are documented in this file in adherence to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). This file was created on 2026-09-15; the history before it is summarised from `git log` (31 commits since 2026-08-27).

---

## [Unreleased]

### Fixed
- Renamed `RestController::NAMESPACE` to `REST_NAMESPACE` for PHP 8.5/8.6 compatibility.

## [1.2.3] - 2026-11-21

### Fixed

- Screenshot 1 showed the WM Suite menu from before WM Suite Hub 1.2.3, with entries the hub no longer has («Marketplace (Lösungspakete)», «Merkle-Audit-Protokoll», «CRA- & SBOM-Zentrale»). It is retaken with the current menu; the plugin's code is unchanged.

## [1.2.2] - 2026-11-21

### Fixed

- The register counts "%d Lieferant im Register", "%d Selbstauskunft nicht bewertet" and the notice "%d Beispiel-Lieferant angelegt." repeated one plural form for every count above one in languages with three or more forms; Arabic, Czech, Croatian, Irish, Latvian, Lithuanian, Maltese, Romanian, Slovak and Slovenian now carry each form their plural rule needs, and Polish the genitive plural for five and more self-disclosures. The editor catalogs (`.json`) had only two forms per entry and now carry all of them.

### Changed

- All 28 editor catalogs are rebuilt from their `.po`, so the block strings carry their context as WordPress writes it, and the catalogs carry the plugin page as Plugin URI and the Author URI as the plugin header has it.

- The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.

## [1.2.1] - 2026-11-21

### Fixed
- The Portuguese catalog still named the agency that 1.1.4 removed from the catalogs, in the calculator's note on the score weights. It names WM Suite now, as the German source string does. Its editor catalog (`.json`) is rebuilt from the `.po`, so its block strings carry their context as WordPress writes it.

## [1.2.0] - 2026-11-20

### Changed
- The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
- The `.wordpress-org/` banners show the current version.
- The first screenshot description (`readme.txt`, `README.md`) names the register counts the screen shows instead of "yearly metrics", which live on the *Jahresübersicht* tab. The READMEs no longer list the archive among the known issues.

### Removed
- The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

## [1.1.4] - 2026-11-19

- Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.

- Added root `SUPPORT.md`, `.github/ISSUE_TEMPLATE/{config.yml,bug_report.yml}`, and explicit zero-support / "AS IS" liability notices across `README.md`, `README.de.md`, `README.es.md`, `readme.txt`, `SECURITY.md`, and `llms.txt`.
- Migrated plugin author/catalog/repository metadata to personal open-source distribution (`Arnold Wender`, `https://arnoldwender.com/wordpress-plugins/wm-lksg-supply-auditor/`, `https://github.com/arnoldwender/wm-wp-lksg-supply-auditor`), normalized FSF GPL-2.0 `LICENSE` metadata where applicable, and updated `.wordpress-org/` banner branding to `ARNOLD WENDER · OPEN SOURCE`.

## [Unreleased]

## [1.1.3] - 2026-11-19

### Added
- Official `.wordpress-org/` directory visual kit (`icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`) and live Playwright screenshots (`screenshot-1.png` through `screenshot-3.png`) with `== Screenshots ==` in `readme.txt`.
- Visual previews and direct link to `https://arnoldwender.com/wordpress-plugins/wm-lksg-supply-auditor/` in `README.md`, `README.de.md`, `README.es.md`, and `llms.txt`.

### Changed
- Archived superseded early mockups and banners non-destructively in `versionen/visuals-pre-2026-11-19/` (keeping `docs/screenshots/design-family/` intact) and excluded `versionen/` from distribution packages via `.distignore`.
- Updated release metadata, block manifest (`blocks/supplier-portal/block.json`, `build/index.asset.php`), test bootstrap, `SECURITY.md`, and SBOM (`bom.json`) to `1.1.3`.

---

## [1.1.2] - 2026-11-19

### Changed
- Point `Plugin URI` to `https://arnoldwender.com/wordpress-plugins/wm-lksg-supply-auditor/` and align release metadata at `v1.1.2`.

## [1.1.1] - 2026-11-18

### Fixed
- **Multisite schema provisioning & network activation (`R2-B8`):** Hooked `wp_initialize_site` in `wm-lksg-supply-auditor.php` (priority `20`) so that when `is_multisite()` and `is_plugin_active_for_network( plugin_basename( __FILE__ ) )`, new blogs created in a network receive all five schema tables and `wm_lksg_schema_version` (`autoload = false`) via `switch_to_blog()` -> `Database\Schema::activate()` -> `restore_current_blog()` inside `try/finally`.
- **Network-wide activation & `dbDelta` require guard:** `Database\Schema::activate( bool $network_wide = false )` now iterates over all network sites (`get_sites( [ 'fields' => 'ids', 'number' => 0 ] )`) with `try/finally { restore_current_blog(); }` when network-activated, and guards `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` with `if ( ! function_exists( 'dbDelta' ) )`.
- **Tests:** `tests/test-suite.php` now loads `wm-lksg-supply-auditor.php` directly and verifies `wp_initialize_site`, `$network_wide` activation, `try/finally` blog restoration on error, and `autoload = false` on all plugin `update_option` calls, defended by 7 new mutations in `tests/mutations.php`.

## [1.1.0] - 2026-11-16

### Added
- Compiled Gutenberg block `wm/lksg-supplier-portal` (`blocks/supplier-portal/block.json`, `blocks-src/index.js`, `build/index.js`, `build/index.asset.php`) visible in the Block Inserter with `InspectorControls` (`showIntro`, `defaultTier`), `ServerSideRender`, and `wp_set_script_translations()`.
- WordPress 6.5+ Block Bindings source `wm-lksg/metrics` and GenerateBlocks 2.x Dynamic Tag `{{wm_lksg_metric}}` (`includes/Frontend/EcosystemIntegration.php`) exposing factual register counts (`total`, `assessed`, `unassessed`, `high_or_critical`, `reporting_year`).
- Developer extensibility hooks: `do_action( 'wm_lksg_ready', $plugin )`, `wm_lksg_risk_score` filter on `RiskMatrixCalculator::calculate_risk()`, and `wm_lksg_rest_rate_limit` filter on `RestController::check_self_disclosure_access()`.
- CycloneDX 1.6 Software Bill of Materials (`bom.json`) and WordPress Playground blueprint (`.wordpress-org/blueprints/blueprint.json`).
- Complete translations across all 28 locales (28 `.po`/`.mo` files plus 28 JED `.json` block editor catalogs).

### Changed
- `POST /wm-lksg/v1/self-disclosure` replaces `'__return_true'` with `RestController::check_self_disclosure_access()`, enforcing transient-based HMAC-SHA256 IP rate-limiting (`wm_lksg_rl_*`, default 30 requests/minute, bypassed for `manage_options`, raw IPs never stored) ahead of handler execution while preserving anonymous supplier submissions.
- `uninstall.php` now preserves all supplier, assessment and self-disclosure tables and options by default in view of the seven-year retention requirement in § 10 Abs. 1 LkSG unless explicit opt-in `wm_lksg_delete_data_on_uninstall` is enabled on the settings tab.
- Aligned license declarations across `README.md`, `README.de.md`, `README.es.md`, `readme.txt`, `composer.json`, `bom.json`, `LICENSE`, and plugin header to `GPLv2 or later` (`GPL-2.0-or-later`), and replaced `AGENTS.md` with a symlink to `CLAUDE.md`.


### Added — the catalog freshness gate (2026-09-20)

- `tests/test-pot-freshness.php` and two CI steps on PHP 8.3: the gate extracts the translatable strings from the source and fails if any of them lacks an entry in the `.pot`. A string with no catalog entry cannot be translated by anyone into any language — the translator never sees it, nothing reports an error, and the interface simply stays German.
- **This catalog was already current** — 161 translatable strings, 161 entries, nothing missing and nothing stale — and that is the reason to add the gate rather than a reason to skip it. Fifteen of the fleet's twenty-four plugins had drifted, one of them down to a single entry still shared with the code, and not one of them had a check that could notice. Being correct today is not a mechanism.
- The gate takes its definition of "fresh" from `wp i18n make-pot` rather than a regex of its own, reads the text domain from the plugin header instead of holding a second copy, treats 0 extracted strings as a failure rather than a pass, ignores the plugin-header fields `make-pot` adds, requires wp-cli with no escape hatch, and runs its own self-test before judging the plugin. **This plugin keeps its catalog with `xgettext` (CLAUDE.md rule 8), not with `make-pot`** — that is unchanged: the gate measures the result, whatever tool produced it.
### Family sheets (2026-09-16)
- Re-synced from the hub: the badge variants carry the state tokens on body surfaces, where the command-banner inks measured 1.49:1 to 2.21:1 against the composed background (hub PR #19), and the canonical sheet dropped five classes that belonged to a single plugin (hub PR #21). `tests/test-ui-family.php` re-pins both md5s.

### Fixed (reader pass, 2026-09-15)

- **The BAFA export stated facts nobody had entered.** On an empty register it returned `policy_statement_active`, `preventative_actions_taken` and `trainings_conducted` as `true`, a human-rights officer "Enterprise Compliance Officer", a complaints procedure "WM HinSchG Whistleblower Interlinked Gateway" and the identifier `BAFA_LKSG_JAHRESBERICHT_V2026`; the year filtered nothing, `total_tier1_suppliers` counted every tier and `completed_risk_audits` counted anonymous self-disclosures. It now returns `wm_lksg_register_summary`: counts per calendar year, tier 1 and higher tiers apart, unassessed and high or critical suppliers, assessments and self-disclosures recorded in the year, and by name what a report under § 10 Abs. 2 LkSG covers and the plugin does not record. REST takes `?year=`, WP-CLI `--year=`; both default to the settings year, as the admin tab.
- **A supplier without a country was scored as Germany.** `POST /suppliers` and `wp lksg score` defaulted to `DE`, the lowest tier (39.00 instead of 43.20 for an unknown country); the route also defaulted the sector to `manufacturing` and stored any tier number. The route now refuses a missing or invalid country and a tier outside 1 to 3; the CLI scores a missing country with the default 50 and says so.
- **Countries were not checked.** The self-disclosure route accepted any two letters (`XX` was stored) and any sector string; `POST /suppliers` took `Germany`. Every entry point now checks the 249 ISO 3166-1 alpha-2 codes, and the self-disclosure accepts only the sectors of its form.
- **Self-disclosures dropped out of every list.** Lists were the first 50 rows by score, so a self-disclosure (score 0.00) disappeared first, and the overview counted at most 50 suppliers as evaluated. Lists are paged, unassessed rows first, with totals; the README's instruction to assess a self-disclosure through `POST /suppliers` described something that creates a second row and now says so.
- **Declared certifications were thrown away** after hashing, and the declaration text was not stored, so the signature could not be checked against anything. The audit row stores the declared certifications, the text and a signature version; `RestController::verify_signature()` checks a row. A failed audit row answered 201; it now removes the supplier row and answers 500.
- **Status texts said all was well whatever the data.** The overview showed "PREPARADO — Conforme a § 10 LkSG" and "VINCULADO — Canal HinSchG activo", the header "AUDITORÍA ESG AL DÍA" and "INFORME BAFA DISPONIBLE", the dashboard widget a green "LkSG § 4–10 Risikomanagement aktiv" and "BAFA Berichtsbereit". They are gone; the widget and the counts come from the register.
- **Hub adapter:** health was the literal `HEALTHY`, telemetry reported `bafa_export_engine: READY`, `risk_eval` ignored its parameters (severity 0 and 100 both gave 56.30), the self-test reported success for a fixed sample, the SBOM carried "compliance" properties for §§ 1-10 LkSG, the CSDDD and a report generator. Health follows the tables and a self-test with three expected levels; `risk_eval` uses its parameters; the settings link opens the settings tab.
- **Settings:** every save replaced `sync_hinschg` and `officer_email` with defaults; the year and the threshold were stored without the bounds the form promised; nothing read the threshold, which claimed to mark suppliers red. Only the year remains, checked, and saves keep the other keys.
- **The JSON link answered 401** for the logged-in administrator (no REST nonce); it carries one and the year.
- **Reloading the calculator result stored the supplier again**; a one-time form token prevents it. The calculator stored every supplier as tier 1; it asks for the tier.
- **Two portals on one page sent two requests** from the first form and left the second unwired; the portal script binds each form once. The form sent `declaration_accepted: true` although it had no checkbox; the checkbox is required now.
- **The assessment history lost the score, the level and eight factors**, and a failed history insert was ignored while the admin said "registrada". Rows keep all of it; a supplier whose history row fails is removed and the caller gets an error.
- **Demo seeder:** Foxconn Precision Industrial Co. (38.50, low, verified), Shenzhen Battery Tech Ltd (58.20, medium), Hanoi Textile & Garments JSC (76.40, high) and Hetzner Online GmbH (12.00, low, verified), with levels that contradicted the calculator and four more rows on every click. Replaced by four made-up suppliers whose names start with "Beispiel:", computed by the calculator, added once and logged as `demo_seed`.
- **Self-disclosures stored 0 as the human-rights, environmental, child-labour and forced-labour scores** nobody had assessed; they are NULL. The badge of a row with an unknown level fell back to green "BAJO RIESGO"; it is neutral. A supplier row without a level defaulted to `low`; it defaults to `unassessed`.
- **Declaration wording:** it spoke of "gravierenden Umweltbelastungen nach § 2 LkSG", a term § 2 does not use; it now names § 2 Abs. 2 Nr. 1 to 3 and § 2 Abs. 3. The button said "rechtsverbindlich übermitteln" and the badge "LkSG § 5 & EU CSDDD Konformität" for a submitter nobody identifies; both are gone. Messages used "Uebermittlung", "spaeter", "Erklaerung".
- **The rate limit restarted its hour with every accepted submission**; the window now runs from the first.
- **Uninstall** dropped three of five tables and deleted one of two options; it now removes all tables, both options and the plugin transients, and documents the seven-year retention of § 10 Abs. 1 LkSG.
- **The WordPress test suites** wrote without a guard, deleted every self-disclosure rate-limit transient of the site, flushed the object cache and exited 0 with failures. `Schema.php` had no direct-access guard.

### Changed (reader pass, 2026-09-15)

- **Admin screen in German**, rewritten from a Spanish screen with emoji, 53 inline style attributes and "Acciones legales requeridas" for a heuristic score. The dashboard widget loads the family sheets on the dashboard.
- **Portal** with its own stylesheet (colours and dimensions as custom properties) and script instead of 30 inline style attributes and an inline script.
- **Schema version 3:** history columns for the score, level and factors; declared facts on audit rows; nullable audit scores.
- **Documentation measured against the code:** README (EN, new DE and ES), `readme.txt`, header description, `docs/ARCHITECTURE.md`, `docs/LEGAL-COMPLIANCE.md` (quotes of §§ 1 to 10 LkSG from the raw statutory text and the status of the reporting duty from bafa.de and bundestag.de), new `DEVELOPERS.md` (replacing `docs/DEVELOPER-GUIDE.md`), `CLAUDE.md`, `AGENTS.md`, `SECURITY.md`, `CONTRIBUTING.md`, `llms.txt`, `public/humans.txt` (without "Founder & CEO", "WCAG 2.2 AAA", "CSP Strict", Astro and the phone number). The badges "LkSG §§ 1-10 Compliant", "EU CSDDD Ready" and "BAFA Report Automated" are gone; banner and mockup are no longer shown and excluded from the package.
- **Catalogs:** double-encoded strings repaired, `.pot` regenerated from the German source, the 28 `.po` merged and recompiled.
- **Tests and CI:** the standalone suite runs on SQLite with tables built from `Schema`, with a mutation check; a PHPUnit test for the summary replaces the one that pinned the fixed identifier; a rolled-back runtime check runs inside WordPress. CI runs `php -l`, the suite, the family sheets, the mutation check, PHPUnit with the strict root configuration and the static security audit.

### Design family (hackaton phase 3, 2026-09-14)

- Admin screen on the WM Suite family sheets (`3339bb9`, re-synced in `96f2f38`); Plugin Check on the shipped code: translators comment on the tier label and an English readme description (`587c89a`).

### Fixed (hackaton phase 1, 2026-09-14)

- **Every supplier scored 42.60:** the entry points passed keys the calculator never read. **The second supplier ever registered failed** on the empty NOT NULL UNIQUE uuid while the API answered 201. **A critical supplier was filed as verified.** The history table existed and nothing wrote to it (`b585a8a`).

---

## [1.0.0] — 2026-08-27

- Initial plugin (`79719f3`, 2026-08-27): risk matrix, BAFA exporter, REST routes, admin dashboard, supplier portal, WP-CLI command; later the hub adapter, uninstaller and tests, and on 2026-08-29 catalogs described as "24 EU language" (`541ebc6`) that were mostly copies of the source strings. What it did and did not do is described in the reader-pass entries above.

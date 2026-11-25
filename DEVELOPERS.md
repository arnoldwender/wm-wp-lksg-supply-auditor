# WM LkSG Supply Chain Auditor — Developer Manual

> Measured against the code in this repository (2026-09-15). It replaces `docs/DEVELOPER-GUIDE.md`.

---

## 1. Layout

| Path | Role |
| --- | --- |
| `wm-lksg-supply-auditor.php` | Header, constants (`WM_LKSG_VERSION`, `_FILE`, `_PATH`, `_URL`), autoloader for `WMCyber\LkSG\` → `includes/`, activation and deactivation hooks, multisite `wp_initialize_site` schema provisioning when network-activated, hub registration on `wm_register_suite_module`, boot on `plugins_loaded`. |
| `includes/Core/Plugin.php` | Text domain, `Schema::maybe_upgrade()`, REST routes, admin screen, portal, ecosystem bridges, WP-CLI, `do_action( 'wm_lksg_ready', $this )`. |
| `includes/Core/Settings.php` | Option `wm_lksg_settings`: `reporting_year()`, `save_reporting_year()`, `MIN_YEAR`, `max_year()`; explicit opt-in `wm_lksg_delete_data_on_uninstall` (`register()`, `delete_data_on_uninstall()`, `save_delete_data_on_uninstall()`). |
| `includes/Risk/RiskMatrixCalculator.php` | `calculate_risk( $factors )` (filter `wm_lksg_risk_score`). |
| `includes/Risk/RiskFactors.php` | `from_input()`, `derived_from()`, `from_cli_args()`, `from_admin_form()`, `country_cpi_risk()` (filter `wm_lksg_country_cpi_risk`), `sector_profile()`, `audit_status_for_level()`. |
| `includes/Risk/AssessmentLog.php` | `record( $supplier_id, $factors, $risk, $source = 'lksg_composite' )`, `SOURCE_DEMO`. |
| `includes/Risk/Countries.php` | `CODES` (249 ISO 3166-1 alpha-2 codes from the tz database file `iso3166.tab`), `normalize()`, `is_valid()`. |
| `includes/Database/Schema.php` | `VERSION` 3, `TABLES`, `table()`, `table_exists()`, `missing_tables()`, `maybe_upgrade()`, `activate( $network_wide )`, the five `*_sql()` statements, `make_audit_scores_nullable()`, `backfill_uuids()`. |
| `includes/Database/Register.php` | `counts()`, `page( $page, $per_page )`. |
| `includes/API/RestController.php` | Four routes under `wm-lksg/v1`; `check_self_disclosure_access()` (filter `wm_lksg_rest_rate_limit`), `validate_country_arg()`, `declaration_text()`, `signature_payload()`, `signature()`, `verify_signature()`, `rate_limit_key()`, `rest_rate_limit_key()`. |
| `includes/Export/BafaReportExporter.php` | `generate_bafa_report_data( $year )`: the yearly register summary; `REPORT_TYPE`, `NOT_RECORDED`. |
| `includes/Admin/AdminDashboard.php` | Menu, family sheets (plugin page and dashboard), widget, tabs, `calculate()`, `seed_demo_data()`, `level_badge()`, `level_explanation()`, settings action. |
| `includes/Frontend/SupplierPortal.php` | Shortcodes `wm_lksg_supplier_portal` and `wm_lksg_declaration`, Gutenberg block `wm/lksg-supplier-portal` (`blocks/supplier-portal/block.json`, `build/index.js`, `build/index.asset.php`), `SECTOR_KEYS`, `sector_labels()`; enqueues `assets/css/wm-lksg-portal.css` and `assets/js/wm-lksg-portal.js`. |
| `includes/Frontend/EcosystemIntegration.php` | WordPress 6.5+ Block Bindings source `wm-lksg/metrics` and GenerateBlocks 2.x Dynamic Tag `{{wm_lksg_metric}}`. |
| `includes/CLI/LksgCommand.php` | `wp lksg score`, `wp lksg export-bafa`; `score_report()` without WP_CLI. |
| `includes/Adapters/Lksg_Spoke_Adapter.php` | WM Suite Hub contract. |
| `uninstall.php` | Preserves tables and options by default; removes five tables, options and `wm_lksg_` transients only when `wm_lksg_delete_data_on_uninstall` is enabled. |
| `bom.json` | CycloneDX 1.6 Software Bill of Materials (`GPL-2.0-or-later`). |
| `languages/` | 28 translated `.po`/`.mo` catalogs, 28 JED `.json` block editor catalogs, and `.pot`; see §10. |

---

## 2. Risk model

Factors, each clamped to 0-100, with their defaults: `country_cpi_risk` 50, `sector_risk` 50, `product_risk` 50, `severity_score` 50, `probability_score` 50, `dependency_ratio` 10, `causal_contribution` 10; `has_iso_14001` and `has_sa_8000` false. Per key: an explicit input wins, then `derived_from( $country, $sector )`, then the default. `human_rights_indicator` is translated to `severity_score`, `environmental_indicator` to `product_risk`.

- abstract = 0.40 × country + 0.40 × sector + 0.20 × product
- concrete = clamp( 0.40 × severity + 0.40 × probability + 0.10 × dependency + 0.10 × causal − 10 × ISO 14001 − 15 × SA 8000 )
- score = 0.30 × abstract + 0.70 × concrete, rounded to two decimals
- `critical` / `CAPA_REQUIRED` when score ≥ 75 or severity ≥ 85; `high` / `AUDIT_REQUIRED` from 50; `medium` / `QUESTIONNAIRE_REQUIRED` from 25; `low` / `NO_ACTION`
- flags: country > 70, severity > 75, product > 80, dependency > 80

Country tiers: DE 15, TW 30, CN 60, VN 60, IN 70, BD 85. Sector profiles `[sector, product]`: `it_software`, `it_services`, `cloud_infrastructure` 15/10; `logistics` 40/35; `electronics`, `manufacturing` 45/40; `energy_storage` 55/60; `agriculture` 65/60; `textiles` 70/60; `mining`, `mining_raw` 85/85. `audit_status_for_level()`: critical and high → `mitigation_required`, medium → `in_review`, low → `evaluated`, anything else → `unassessed`. All of these values are WM Suite heuristics.

---

## 3. REST

| Method | Route | Permission | Arguments | Answer |
| --- | --- | --- | --- | --- |
| `GET` | `/wm-lksg/v1/suppliers` | `manage_options` | `page` (≥ 1, default 1), `per_page` (1-100, default 50) | 200 `{success, total, page, per_page, pages, suppliers}` with headers `X-WP-Total` and `X-WP-TotalPages`; 500 `wm_lksg_missing_table` |
| `POST` | `/wm-lksg/v1/suppliers` | `manage_options` | `supplier_name` (required), `country_code` (required, ISO), `industry_sector`, `tier_level` (1-3), the factor keys, `has_iso_14001`, `has_sa_8000` | 201 `{success, supplier_id, uuid, risk, factors}`; 400 from core argument validation or `wm_lksg_invalid_name` / `_invalid_country` / `_invalid_tier`; 500 `wm_lksg_db_error`; 500 `wm_lksg_assessment_not_recorded` (the supplier row is removed) |
| `POST` | `/wm-lksg/v1/self-disclosure` | `check_self_disclosure_access` (`wm_lksg_rl_*`, default 30 req/min via `wm_lksg_rest_rate_limit`, bypassed for `manage_options`) | `supplier_name`, `country_code` (ISO), `industry_sector` (one of `SECTOR_KEYS`), `tier_level` (1-3, default 1), `has_iso_14001`, `has_sa_8000`, `has_iso_45001` (default false), `declaration_accepted` (required, must be true) | 201 `{success, reference, audit_status, message}` (reference = supplier uuid); 400; 429 `wm_lksg_rate_limited`; 500 `wm_lksg_db_error` (the supplier row is removed when the audit row fails) |
| `GET` | `/wm-lksg/v1/bafa-report` | `manage_options` | `year` (2000 to next year; default `Settings::reporting_year()`) | 200 `{success, report}`; 400 `wm_lksg_invalid_year` |

The handlers check their values again, since the standalone suite and WP-CLI call them without core's argument validation.

---

## 4. Self-disclosure

- Supplier row: `risk_score` 0.00, `risk_level` `unassessed`, `audit_status` `self_disclosed`.
- Audit row: `audit_type` `self_disclosure`; `human_rights_score`, `environmental_score`, `child_labor_risk`, `forced_labor_risk` NULL; `declared_iso_14001`, `declared_sa_8000`, `declared_iso_45001` (0 or 1); `declaration_text`; `signature_version` `v2`; `digital_signature` = `hash_hmac( 'sha256', signature_payload( $row ), wp_salt( 'secure_auth' ) )` with `signature_payload` = `wp_json_encode( {version, supplier_id, declaration, iso_14001, sa_8000, iso_45001, submitted_at} )` built from the row.
- `verify_signature( $row )`: true or false for `v2` rows, null for rows written before 2026-09-15.
- Rate limit: transient `wm_lksg_sd_` + 32 hex characters of `hash_hmac( 'sha256', REMOTE_ADDR, wp_salt( 'auth' ) )`, value `{count, started}`; accepted while `count < 5` within 3600 s of `started`; the expiry is the rest of that window.
- Portal: `render_portal()` prints a `<section>` with IDs from `wp_unique_id()`, a form with `data-wm-lksg-portal`, `data-endpoint` and three message attributes, a required `declaration_accepted` checkbox whose label is `declaration_text()`, a `role="status"` line and a hidden result block. `wm-lksg-portal.js` binds every such form once (`data-wm-lksg-bound`), posts JSON, shows the result only on 201 and otherwise keeps the form with its values.

---

## 5. Yearly summary

`generate_bafa_report_data( $year )` returns `report_type` (`wm_lksg_register_summary`), `note`, `reporting_year`, `period_utc {from, until}`, `generated_at`, `register_at_period_end {suppliers, tier_1_direct, tier_2_or_higher, unassessed, high_or_critical_level_now}` (rows with `created_at` before 1 January of the next year; levels are current values), `recorded_in_period {risk_assessments, self_disclosures}` (`assessment_date`, and `execution_date` of audit rows of type `self_disclosure`, within the year), `not_recorded` (`NOT_RECORDED`: responsible person § 4 Abs. 3, policy statement § 6 Abs. 2, prevention § 6, remedial measures § 7, complaints procedure § 8, assessment of effectiveness § 10 Abs. 2 Satz 2 Nr. 3) and `missing_tables`. A count is null when its table is missing. Nothing in it is a boolean.

---

## 6. Admin

Menu *LkSG-Lieferanten* (`manage_options`, position 33), page `wm-lksg-auditor`, tabs `overview`, `calculator`, `export`, `settings`, `help`.

- **Register:** `Register::counts()` and `Register::page( $paged, 50 )`; a missing table shows an error; unassessed rows show no score.
- **Calculator:** nonce `wm_lksg_calc`; a hidden `wm_calc_token` (32 hex characters) per rendered form; after a save the transient `wm_lksg_calc_<token>` is set for a day, so posting the same form again stores nothing and says so. A missing token saves nothing.
- **Demo button:** action `seed_demo_data`, nonce `wm_lksg_seed`; `DEMO_SUPPLIERS` are four made-up suppliers, skipped when the name exists, scored by the calculator and logged with source `demo_seed`; redirect with `seeded=<added>`.
- **Settings:** action `save_settings`, nonce `wm_lksg_save_settings`; redirect with `saved=1` or `year_error=1`.
- **Widget** (`manage_options`): counts and a banner derived from them. Family sheets load on `toplevel_page_wm-lksg-auditor` and `index.php`.

---

## 7. WP-CLI and hub

`wp lksg score "<name>" [--country=<ISO>] [--sector=<key>] [--cpi] [--sector-risk] [--product-risk] [--severity] [--probability] [--dependency] [--causality] [--iso14001] [--sa8000]` prints both levels, where the country value came from, the score and the level; an invalid country is an error; nothing is stored. `wp lksg export-bafa [--year=]` prints the summary as JSON.

Hub adapter: health `ERROR` when `Schema::missing_tables()` is not empty or the self-test fails (three fixed factor sets must come out low, high and critical, and the BD tier must be above DE), otherwise `HEALTHY`; telemetry `suppliers_registered`, `suppliers_unassessed`, `suppliers_high_or_critical`, `risk_assessments_recorded`, `self_disclosures_received`, `missing_tables`, `risk_matrix_self_test`, `bafa_report` (`not_produced`), `outbound_requests`; actions `self_test` and `risk_eval` (factors from its parameters, country tier from `country_code`); settings URL `admin.php?page=wm-lksg-auditor&tab=settings`; one SBOM component whose properties describe features.

---

## 8. Schema

Version 3 (2026-09-15): `risk_assessments` gains `composite_score`, `risk_level` and `factors`; `audits` gains `declared_iso_14001`, `declared_sa_8000`, `declared_iso_45001`, `declaration_text`, `signature_version`, and its four score columns accept NULL; `suppliers.risk_level` defaults to `unassessed`. `maybe_upgrade()` replays `activate()` when the stored version is lower. `activate( $network_wide )` iterates over network sites on multisite network activation, and `wp_initialize_site` provisions new blogs inside `switch_to_blog()` / `try/finally { restore_current_blog(); }` when network-activated; `update_option()` stores `wm_lksg_schema_version` with `autoload = false`. `dbDelta` does not change NULL / NOT NULL, so `make_audit_scores_nullable()` runs one literal `ALTER TABLE … MODIFY` for each score column of an existing table that is still NOT NULL. Existing rows keep their values.

---

## 9. Tests

```bash
php tests/test-suite.php        # 14 groups; $wpdb on in-memory SQLite from Schema's own CREATE TABLE statements
bash tests/run-mutations.sh     # tests/mutations.php, every entry must turn the suite red
vendor/bin/phpunit              # tests/Unit (PHPUnit 11.5+ on PHP 8.2)
wp eval-file wp-content/plugins/wm-lksg-supply-auditor/tests/wp-runtime-lksg.php --user=1
WM_LKSG_WP_TESTS_WRITE=1 wp eval-file wp-content/plugins/wm-lksg-supply-auditor/tests/test-suppliers-rest.php --allow-root
WM_LKSG_WP_TESTS_WRITE=1 wp eval-file wp-content/plugins/wm-lksg-supply-auditor/tests/test-self-disclosure.php --allow-root
```

- The standalone suite fails a case on any PHP warning or notice and on SQL SQLite cannot run; values come back as strings.
- `tests/wp-runtime-lksg.php` runs inside WordPress: routes through `rest_do_request()`, a real supplier with its history row, a real self-disclosure and its signature, the summary, the hub adapter, the setting, the admin screen and two portals, all inside a transaction rolled back at shutdown (transaction statements of other plugins rewritten to savepoints, DDL refused, persistent object cache refused), comparing table counts, the settings option and the plugin transients before and after.
- The two `test-*.php` WordPress suites write `PROBE` rows and refuse to start without `WM_LKSG_WP_TESTS_WRITE=1`; they exit 1 on a failure.

---

## 10. Catalogs

The admin, portal, block and REST source strings are German. `languages/wm-lksg-supply-auditor.pot` is extracted via `wp i18n make-pot` (verified by `tests/test-pot-freshness.php`), and all 28 standard WordPress locales ship translated `.po` and compiled `.mo` catalogs alongside 28 JED `.json` catalogs bound to `build/index.js` (`wm-lksg-blocks-editor`).

# Architecture — WM LkSG Supply Chain Auditor

Measured against the code on 2026-09-15. Until then this file drew a "Remedial Action Manager (§ 7 LkSG Prevention)", a "HinSchG Whistleblower Complaints Link (§ 8)", a "BAFA Annual Report Generator (§ 10 LkSG JSON/PDF)" and continuous due diligence across indirect suppliers; none of them exists.

---

## 1. Entry points, classes and tables

| Entry point | Classes | Writes | Reads |
| --- | --- | --- | --- |
| `POST /wm-lksg/v1/suppliers` | `RestController` → `RiskFactors` → `RiskMatrixCalculator` → `AssessmentLog` | `wm_lksg_suppliers`, `wm_lksg_risk_assessments` | — |
| `GET /wm-lksg/v1/suppliers`, admin *Register*, dashboard widget, hub telemetry | `Register` | — | `wm_lksg_suppliers` |
| `[wm_lksg_supplier_portal]` / block `wm/lksg-supplier-portal` → `POST /wm-lksg/v1/self-disclosure` | `SupplierPortal` (markup, `blocks/supplier-portal/block.json`, `build/index.js`), `assets/js/wm-lksg-portal.js`, `RestController` | `wm_lksg_suppliers`, `wm_lksg_audits`, transients `wm_lksg_rl_*` & `wm_lksg_sd_*` | — |
| Block Bindings `wm-lksg/metrics`, GenerateBlocks `{{wm_lksg_metric}}` | `EcosystemIntegration` | — | `Register`, `Settings` |
| `GET /wm-lksg/v1/bafa-report`, `wp lksg export-bafa`, admin *Jahresübersicht* | `BafaReportExporter`, `Settings` | — | `wm_lksg_suppliers`, `wm_lksg_risk_assessments`, `wm_lksg_audits` |
| Admin *Rechner*, demo button | `AdminDashboard` → `RiskFactors` → `RiskMatrixCalculator` → `AssessmentLog` | `wm_lksg_suppliers`, `wm_lksg_risk_assessments`, transient `wm_lksg_calc_*` | — |
| `wp lksg score` | `LksgCommand` → `RiskFactors` → `RiskMatrixCalculator` | — | — |
| WM Suite Hub | `Lksg_Spoke_Adapter` | — | `Register`, `Schema` |
| — | `Schema` | creates `wm_lksg_complaints` and `wm_lksg_corrective_actions` | nothing writes or reads them |

Every country passes `Countries::is_valid()` (249 ISO 3166-1 alpha-2 codes). Writes are all or nothing: a supplier whose history row fails, or a self-disclosure whose audit row fails, is deleted again.

---

## 2. Risk calculation model (two levels, as implemented in `RiskMatrixCalculator`)

Until 2026-09-14 this section documented a single-level formula (0.30·C + 0.25·S + 0.25·H + 0.20·E) that the code never implemented. Every caller feeds the calculator through `includes/Risk/RiskFactors.php`.

**Abstract level (country, sector, product):**
$$A = 0.40 \cdot C + 0.40 \cdot S + 0.20 \cdot P$$
- $C$: country value (0-100). Explicit `country_cpi_risk`, else the country tier (DE 15, TW 30, CN 60, VN 60, IN 70, BD 85; filter `wm_lksg_country_cpi_risk`), else 50. The tiers are not a corruption index.
- $S$, $P$: sector and product values (0-100). Explicit, else the sector profile, else 50.

**Concrete level (severity, probability, dependency, causal contribution):**
$$K = \max\big(0, \min(100,\; 0.40 \cdot Sev + 0.40 \cdot Prob + 0.10 \cdot Dep + 0.10 \cdot Caus - 10 \cdot ISO\,14001 - 15 \cdot SA\,8000)\big)$$
- Defaults 50 / 50 / 10 / 10. § 3 Abs. 2 LkSG names type and scope of business, the enterprise's Einflussvermögen, severity, reversibility, probability and the type of causal contribution; the calculator asks for severity, probability, the dependency on the supplier and the causal contribution, and it weighs them with fixed heuristic weights.

**Score:** $R = 0.30 \cdot A + 0.70 \cdot K$ · level `critical` when $R \ge 75$ or $Sev \ge 85$ (`CAPA_REQUIRED`), `high` when $R \ge 50$ (`AUDIT_REQUIRED`), `medium` when $R \ge 25$ (`QUESTIONNAIRE_REQUIRED`), else `low` (`NO_ACTION`). Flags: `HIGH_COUNTRY_CORRUPTION_RISK` ($C > 70$), `HIGH_HUMAN_RIGHTS_EXPOSURE` ($Sev > 75$), `CRITICAL_ENVIRONMENTAL_HAZARD` ($P > 80$), `HIGH_SUPPLIER_DEPENDENCY` ($Dep > 80$).

Legacy keys `human_rights_indicator` and `environmental_indicator` are translated to $Sev$ and $P$ by `RiskFactors`; the calculator ignores them. Every evaluation that lands in the register writes a row to `wm_lksg_risk_assessments` with $R$, the level, the trigger and all factors.

---

## 3. Self-disclosure

1. The portal renders a form with its own element IDs and `data-wm-lksg-portal`; the script binds each form once and sends the ticked declaration as a boolean.
2. Core validates the declared arguments (country via `validate_country_arg`, sector from `SupplierPortal::SECTOR_KEYS`, tier 1-3, declaration required); the handler checks them again.
3. Rate limit: transient `wm_lksg_sd_<keyed hash of REMOTE_ADDR>` with `{count, started}`, five within one hour from the first.
4. Supplier row (`unassessed`, `self_disclosed`, score 0.00), then the audit row with NULL scores, the declared certifications, `declaration_text`, `signature_version` `v2` and `digital_signature = HMAC-SHA256( payload, wp_salt( 'secure_auth' ) )`, where the payload is built from the row itself (`RestController::signature_payload()`).
5. Answer: 201 with the supplier uuid as reference.

---

## 4. Schema versions

`Schema::VERSION` 3 is replayed by `maybe_upgrade()` on `plugins_loaded`. On multisite, `Schema::activate( $network_wide )` iterates over existing sites when network-activated and `wp_initialize_site` provisions newly created sites inside `switch_to_blog()` / `try/finally { restore_current_blog(); }` when `is_plugin_active_for_network()` is true (`update_option()` stores `wm_lksg_schema_version` with `autoload = false`). `dbDelta` adds the new columns; it does not change NULL / NOT NULL, so `make_audit_scores_nullable()` issues one `ALTER TABLE … MODIFY` for each score column of an existing audits table that is still NOT NULL. Existing rows keep their values.

---

<!-- wm-measured-inventory -->

## Measured inventory

Regenerated from the code, never hand-edited. Everything above this line is written by hand and stays.

```bash
python3 the fleet's architecture inventory tool wm-lksg-supply-auditor --append-to docs/ARCHITECTURE.md
```

## Inventory by layer

**18 PHP files, 3406 lines** (excluding `tests/`, `vendor/`, `node_modules/`, `languages/`).

| Layer | Files | Lines |
|---|---:|---:|
| `(raíz)` | 2 | 149 |
| `build` | 1 | 11 |
| `includes/API` | 1 | 531 |
| `includes/Adapters` | 1 | 214 |
| `includes/Admin` | 1 | 862 |
| `includes/CLI` | 1 | 153 |
| `includes/Core` | 2 | 180 |
| `includes/Database` | 2 | 375 |
| `includes/Export` | 1 | 92 |
| `includes/Frontend` | 2 | 363 |
| `includes/Risk` | 4 | 476 |

---

## Classes

| Class | File |
|---|---|
| `AdminDashboard` | [`includes/Admin/AdminDashboard.php`](../includes/Admin/AdminDashboard.php) |
| `AssessmentLog` | [`includes/Risk/AssessmentLog.php`](../includes/Risk/AssessmentLog.php) |
| `BafaReportExporter` | [`includes/Export/BafaReportExporter.php`](../includes/Export/BafaReportExporter.php) |
| `Countries` | [`includes/Risk/Countries.php`](../includes/Risk/Countries.php) |
| `EcosystemIntegration` | [`includes/Frontend/EcosystemIntegration.php`](../includes/Frontend/EcosystemIntegration.php) |
| `LksgCommand` | [`includes/CLI/LksgCommand.php`](../includes/CLI/LksgCommand.php) |
| `Lksg_Spoke_Adapter` | [`includes/Adapters/Lksg_Spoke_Adapter.php`](../includes/Adapters/Lksg_Spoke_Adapter.php) |
| `Plugin` | [`includes/Core/Plugin.php`](../includes/Core/Plugin.php) |
| `Register` | [`includes/Database/Register.php`](../includes/Database/Register.php) |
| `RestController` | [`includes/API/RestController.php`](../includes/API/RestController.php) |
| `RiskFactors` | [`includes/Risk/RiskFactors.php`](../includes/Risk/RiskFactors.php) |
| `RiskMatrixCalculator` | [`includes/Risk/RiskMatrixCalculator.php`](../includes/Risk/RiskMatrixCalculator.php) |
| `Schema` | [`includes/Database/Schema.php`](../includes/Database/Schema.php) |
| `Settings` | [`includes/Core/Settings.php`](../includes/Core/Settings.php) |
| `SupplierPortal` | [`includes/Frontend/SupplierPortal.php`](../includes/Frontend/SupplierPortal.php) |

---

## Entry points

**REST routes:** `/bafa-report`, `/self-disclosure`, `/suppliers`

**WP-CLI:** `lksg`

**Shortcodes:** `wm_lksg_declaration`, `wm_lksg_supplier_portal`

**Gutenberg blocks:** `wm/lksg-supplier-portal`

---

## Persistence — who writes each key and who reads it

| Key | Written by | Read by |
|---|---|---|
| `wm_lksg_delete_data_on_uninstall` | `includes/Core/Settings.php` | `includes/Core/Settings.php`, `uninstall.php` |

Every key has exactly one writing layer and at least one reader.


**Tables** (prefixed with `$wpdb->prefix`): `wm_lksg_`

---

## Gates

- `tests/bootstrap.php`
- `tests/mutations.php`
- `tests/run-mutations.sh`
- `tests/test-pot-freshness.php`
- `tests/test-self-disclosure.php`
- `tests/test-suite.php`
- `tests/test-suppliers-rest.php`
- `tests/test-ui-family.php`
- `tests/wp-runtime-lksg.php`
- `.github/workflows/ci.yml`

What CI actually runs is in the workflow files above — a test file that no workflow invokes is not a gate.

---

## Drift detected against the documentation

Nothing measurable: versions agree and every class named in the documents exists.


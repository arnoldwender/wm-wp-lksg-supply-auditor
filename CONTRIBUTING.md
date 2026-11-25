# Contributing to WM LkSG Supply Chain Auditor

Thank you for your interest in contributing to **WM LkSG Supply Chain Auditor**.

---

## Zero Support & Issue Scope

**Zero technical, configuration, or private support is provided.** See [SUPPORT.md](SUPPORT.md):

- Report reproducible code defects **exclusively** via public [GitHub Issues](https://github.com/arnoldwender/wm-wp-lksg-supply-auditor/issues).
- **Do not** contact the author privately via email, `arnoldwender.com`, or direct messages — private inquiries are never answered.
- Issues and pull requests carry no response or maintenance SLA, and the software is provided strictly **"AS IS"** without warranty or liability.

---

## Core Development Standards

1. **PHP 8.2+ strict typing:** every PHP file under `includes/` starts with `declare(strict_types=1);` and a direct-access guard.
2. **No outbound requests** and no assets from other hosts.
3. **No compliance claims:** no text says the plugin, a supplier or a company satisfies the LkSG or the CSDDD, or that a report is ready or filed.
4. **Heuristics stay labelled:** a change to weights, country tiers, sector profiles or thresholds is a product decision and is documented as a heuristic.
5. **The default is never "all good":** missing data is refused or left unassessed, never scored as the lowest risk.
6. **Docs measured against the code:** README.md (EN, DE, ES), DEVELOPERS.md and CHANGELOG.md change in the same pull request.

---

## Development Workflow

```bash
git clone https://github.com/arnoldwender/wm-wp-lksg-supply-auditor.git
cd wm-lksg-supply-auditor

php tests/test-suite.php
bash tests/run-mutations.sh
composer install && vendor/bin/phpunit
```

---

## Commit Conventions

- Format: `[Feature] ...`, `[Fix] ...`, `[Security] ...`, `[Docs] ...`.
- A fix adds an assertion to `tests/test-suite.php` that fails without it and an entry in `tests/mutations.php`.

For support and liability rules, read [SUPPORT.md](SUPPORT.md).

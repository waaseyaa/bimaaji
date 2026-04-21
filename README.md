# waaseyaa/bimaaji

**Bimaaji** is a Waaseyaa package for graph-oriented knowledge work. This repository currently contains scaffolding only; behavior will land in follow-up issues.

Roadmap context: [GitHub Milestone #67](https://github.com/waaseyaa/framework/milestone/67).

Full documentation will be added in [Issue #1209](https://github.com/waaseyaa/framework/issues/1209).

Graph observability guardrail: `tests/Integration/ApplicationGraphSnapshotTest.php` compares a generated application-graph shape against `tests/fixtures/graph-snapshots/minimal-kernel-graph.json`. Regenerate only for intentional schema/section changes using `REGENERATE_BIMAAJI_GRAPH_SNAPSHOT=1 ./vendor/bin/phpunit tests --filter ApplicationGraphSnapshotTest`; if the test drifts unexpectedly, treat it as a regression and fix the generator/providers instead.

CI also runs a **public-surface-check** job (`composer install --no-dev` + `scripts/check-public-surface-deps.php` + `scripts/public-surface-smoke.php`) so `src/` cannot depend on packages that are only declared in `require-dev` — the snapshot test alone cannot catch that because it runs with dev dependencies installed.

# Nativa PHP Core — the kernel-only tree.
#
# Small subset of the showcase's Makefile: what can run here runs here, what
# needs a module (seed, acceptance, dist) lives where the module lives. The
# frontend build runs here too — the pnpm workspace under Frontend/packages is
# part of this tree. Every target delegates to composer scripts or to a plain
# command; make adds nothing but names.

PHP ?= php
COMPOSER ?= composer
MINIMAL_ROOT ?= ../nativa-minimal
# Empty by default: the theme script fetches the nativa-themes repository's HEAD
# into storage/cache and compares against that. A sibling clone can be behind,
# only to compare against a checkout you mean to measure.
THEMES_ROOT ?=
LEKALO ?= $(shell command -v lekalo 2>/dev/null || echo "$$HOME/.cargo/bin/lekalo")
# Modules the advisory coupling / architecture-profile reports cover (two, not
# one: a single module says nothing about whether the shape holds elsewhere).
LEKALO_MODULES ?= users,blog

##@ Help
help: ## Show available targets
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

##@ Setup
install: ## Install PHP dev dependencies (the kernel runtime itself has none)
	$(COMPOSER) install

##@ Toolchain
# One registry for what this tree is checked with: `toolchain.json` — every tool
# pin, every target with the question it answers and whether it can fail, and
# every CI job. `tests/Architecture/ToolchainRegistryContractTest` fails when the
# registry and the Makefile, the workflow or a pin's own script drift apart in
# either direction; this target is the reading side of the same file.
gates: ## Print the toolchain registry: every pin, gate and the question it answers
	@$(PHP) bin/ops/toolchain-report.php

##@ Quality
lint: ## Lint all PHP files (php -l) — non-zero on a real syntax error
	@find src modules public tests bin -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null
# The `>/dev/null` drops php -l's "No syntax errors detected in ..." chatter
# while a real parse error still reaches stderr and still sets the exit status.
# The old shape piped through `grep -v 'No syntax errors' || true`, which meant
# the target printed every syntax error and exited 0 — a gate that could not
# fail, and the reason `make lint` was wired into CI as a real step.
#
# `modules/` is in the find because the catalogue is code an install runs: the
# earlier `src public tests bin` left a parse error there to whichever tool
# happened to load the file, which is not a gate. `php -l` still only answers
# "does this parse", not the type questions `phpstan` asks of the kernel alone.

test: ## Run PHPUnit tests
	$(COMPOSER) test

analyze: ## Run phpstan + rector dry + cs-fixer dry
	$(COMPOSER) phpstan
	$(COMPOSER) rector-dry
	$(COMPOSER) cs-fix-dry

# The floor, not the checklist. `$aif`'s security skill is a reading task and
# its `audit.sh` is re-installed under you, so the two rules that must hold on
# every push live in tracked code: `.env` is never tracked, and a
# secret-shaped assignment counts only when the value is a quoted literal.
# CI runs this target.
security-audit: ## Gate: no tracked .env, no secret-shaped literals in tracked code
	@bin/build/security-audit.sh

# Two lockfiles, two supply chains: `composer.lock` (development tooling — the
# kernel runtime itself has no packages) and `Frontend/packages/pnpm-lock.yaml`
# (the theme builds). Both are audited from the LOCKFILE, so neither `vendor/`
# nor `node_modules/` is needed — a fresh checkout can run this, which is what
# CI does.
#
# High and critical only. A moderate advisory in a dev formatter is a fact to
# read, not a reason to block an unrelated change, and a gate that reddens for
# it stops being read. The report still prints in full: the threshold decides
# the exit status, not what you see (measured on pnpm 12.15.0 — `--audit-level
# critical` exits 0 while printing the same 6 advisories `--audit-level high`
# exits 1 on).
dependency-audit: ## Gate: no high/critical advisories in either lockfile
	composer audit --locked --ignore-severity=low --ignore-severity=medium
	# picomatch's ReDoS advisory (GHSA-fc5q-39xc-vjpf) has no patched release —
	# every version is affected — so the one ignore lives in
	# Frontend/packages/pnpm-workspace.yaml (`auditConfig.ignoreGhsas`), where
	# pnpm keeps it. Drop it there when a fixed picomatch ships.
	cd Frontend/packages && pnpm audit --audit-level high

##@ Mago
# Informational, like `kernel-size` and `lekalo-verify`: the script exits 0
# whatever the tools say, because none of the numbers has been triaged into a
# gate. `MAGO_ARGS=--strict` is the switch a later decision would flip.
# What it measures, and the adoption verdict, live in docs/mago-evaluation.md.
MAGO_ARGS ?=

mago-spike: ## Informational: run mago lint/analyze/format/guard over the phpstan scope
	@bin/build/mago-spike.sh $(MAGO_ARGS)

# The catalogue alone, and a reading rather than a verdict: lint and analyze over
# `modules/`, reports in storage/cache/mago-spike/modules/, exit 0 whatever they
# find. The gates over that same tree are `mago-lint` (ceiling 0) and
# `mago-guard`; this target is how you look at module code quality without
# turning a number nobody has triaged into a job that blocks a merge.
mago-modules: ## Informational: measure the module catalogue alone (lint + analyze, never a gate)
	@bin/build/mago-spike.sh --scope=modules

# The gate, as against the report above: one rule (`mago.toml` [guard]), no
# exception list, and `--strict` so a kernel→module edge fails the job. Should a
# baseline ever come back, the script fails it on entries that no longer match
# an issue. CI runs exactly this target.
mago-guard: ## Gate: the kernel→module dependency rule
	@bin/build/mago-spike.sh --only=guard --strict

# The count, not the verdict: `mago lint` exits 1 while anything is left, so the
# count is the part that can gate. 22 findings were resolved on 2026-10-22
# without disabling a rule, and the ceiling is 0 — the first new one fails.
mago-lint: ## Gate: curated lint findings must be zero
	@bin/build/mago-spike.sh --only=lint --max-issues=0

##@ Measurement
# Informational by design: it reports whatever is true and exits 0. The gate on
# kernel shape is `tests/Architecture/KernelShapeTest` (the concrete allow-list) —
# this target is where its numbers, docs/kernel-shape.md and the plan all read
# from one source, so the three cannot drift apart.
kernel-size: ## Measure the kernel shape (bytes, files, layers, interface/concrete split)
	@bin/build/kernel-size.sh

##@ Extraction (task 2.1)
extract-check: ## Verify this tree against the upstream kernel slice
	bin/build/extract-kernel.sh --check --minimal-root $(MINIMAL_ROOT)

##@ Publishing the kernel
# `nativa-core` is generated from a commit of this tree: the kernel, the module
# catalogue and the Frontend sources, without the measurement log, the article
# corpus, the Lekalo model or the plans. The source of truth stays here, so a
# hand edit in the published repository is a defect rather than a fork.
#
# `core-export` writes a tree (default ../nativa-core); `core-export-check`
# exports into a temporary directory and runs the exported suite, which is what
# CI runs; `publish-core` replaces the public repository, and `--dry-run` shows
# what it would push without a network call.
CORE_TO ?=
CORE_EXPORT_ARGS ?=
PUBLISH_ARGS ?=

core-export: ## Write the nativa-core tree from a commit (CORE_TO=<dir>, CORE_EXPORT_ARGS=--ref <commit>)
	@bin/build/export-core.sh $(if $(CORE_TO),--to $(CORE_TO),) $(CORE_EXPORT_ARGS)

core-export-check: ## Gate: the exported tree assembles, lints and passes its own suite
	@bin/build/export-core.sh --check $(CORE_EXPORT_ARGS)

publish-core: ## Replace the public nativa-core repository with a fresh export (PUBLISH_ARGS=--dry-run)
	@bin/build/publish-core.sh $(PUBLISH_ARGS)

##@ Catalogue
# Generated, not edited: the index is the machine-readable form of `modules/`,
# and `--check` is the gate that catches a module or import that changed without
# it. Run `php bin/build/module-catalogue.php --write` after such a change.
catalogue-check: ## Gate: modules/catalogue.json matches every module and its closure
	@$(PHP) bin/build/module-catalogue.php --check

# The output is a static directory: registry.json plus one archive per module.
# Publish it anywhere HTTPS serves files; the consumer reads only that tree.
registry: ## Build a versioned static module registry (REGISTRY_OUT=<dir>, REGISTRY_VERSION=<label>)
	@test -n "$(REGISTRY_OUT)" || { echo 'usage: make registry REGISTRY_OUT=../nativa-modules [REGISTRY_VERSION=2026.11.13] [REGISTRY_FORCE=1]' >&2; exit 1; }
	@$(PHP) bin/build/module-registry.php --out="$(REGISTRY_OUT)" --root="$(CURDIR)" $(if $(REGISTRY_VERSION),--version="$(REGISTRY_VERSION)",) $(if $(REGISTRY_FORCE),--force,)

# The same two steps the `modules` and `mark-smoke` CI jobs run, so a red job
# can be reproduced here in one command instead of by reading the workflow.
modules-install: ## Install every catalogued module (closure order; refuses a cycle)
	bin/build/install-catalogue.sh

mark-smoke: ## Boot the panel with temp DBs and walk the admin flows over HTTP
	bin/build/mark-smoke.sh

# The bridge against a server this target starts: the DDL, the status
# read-back, the `user_files` refusal, `--typed` and the write gate are facts
# about ClickHouse, not about this tree, so `composer test` cannot see them.
# CI runs it as an optional, non-blocking job with a pinned, cached release
# (toolchain.json). Locally it needs CLICKHOUSE_BIN=… or an existing server
# (CLICKHOUSE_URL=… + CLICKHOUSE_USER_FILES=…).
clickhouse-smoke: ## Smoke the bridge against a local ClickHouse (attach, status, query, WAL, --checkpoint, concurrent write, --typed, --write)
	@bin/build/clickhouse-smoke.sh

##@ Distribution
# A clone of HEAD, not the working tree: `build-dist.sh` copies what is on disk,
# and the claim being tested is that a FRESH CLONE builds a green artifact — no
# vendor/, no locally installed module slice, no storage state, no uncommitted
# edits. CI runs exactly this target.
dist-check: ## Gate: build dist from a clean clone (no vendor/) and smoke it
	@bin/build/dist-check.sh

dist-modules-check: ## Gate: a clean clone installs the catalogue and ships that module set
	@bin/build/dist-check.sh --with-modules

# The profile branches all end in a GREEN build except the broken one, which is
# why each of them needs its own assertion rather than a single happy-path run.
dist-profile-test: ## Gate: the three deployment-profile branches (skip / skip / fail)
	@bin/build/dist-profile-test.sh

##@ Assets
assets: ## Build the theme bundles in-tree (pnpm workspace under Frontend/packages)
	@command -v pnpm >/dev/null 2>&1 || { \
		echo "pnpm not found — install it, or use 'make assets-import' to copy a built dist." >&2; \
		exit 1; \
	}
	cd Frontend/packages && pnpm install --frozen-lockfile && pnpm build
	@bin/build/check-theme-bundles.sh
	@echo "Built public/dist/<theme> from Frontend/packages (gitignored — a build artifact, not source)."

assets-import: ## Fallback: copy the showcase's built bundles when pnpm is not available
	@if [ ! -d "$(MINIMAL_ROOT)/public/dist" ]; then \
		echo "No $(MINIMAL_ROOT)/public/dist — build it there first: cd $(MINIMAL_ROOT)/Frontend/packages && pnpm install && pnpm build", >&2; \
		exit 1; \
	fi
	mkdir -p public/dist
	cp -r "$(MINIMAL_ROOT)/public/dist/." public/dist/
	@echo "Copied public/dist from $(MINIMAL_ROOT) (gitignored — a build artifact, not source)."

##@ Lekalo
# Gates assert and exit 1 on drift. Reports are informational: the advisory
# states they print are expected today, and a crash is the only failure.
#
# `validate --strict` is deliberately NOT run here, and the reason is not only
# the missing adapter (`lock --check` reports adapters 0). Measured 2026-10-16:
# strict is red on `LEK-AUTH-009 authorization.effect-unprotected` — protected
# effects with no authorization declaration — so turning it on is a model
# decision about coverage, not a switch waiting for an adapter.
#
# The whole model AND every module on its own: `lekalo validate` covers the
# project, and bin/build/lekalo-modules.sh adds the per-module pass with the
# diagnostics attributed to the module that owns them. `--module` alone cannot do
# that — it scopes the report, not the validation, so a healthy module exits 1
# while another module is red.
lekalo-check: ## Lekalo gate: lock --check, the whole model, and every module on its own
	@$(LEKALO) lock --check
	@bin/build/lekalo-modules.sh

lekalo-adapter-test: ## Lekalo gate: the php-nativa target adapter (manifest, conformance battery, scan rows)
	@$(PHP) bin/build/lekalo-adapter-manifest.php --check
	@$(LEKALO) adapter test php adapters/php-nativa/adapter.php
	@bin/build/lekalo-adapter-scan.sh

lekalo-module: ## Lekalo: one module's own report (MODULE=<id>, e.g. blog)
	@test -n "$(MODULE)" || { echo "usage: make lekalo-module MODULE=<id>   (ids: ls lekalo/modules/)" >&2; exit 1; }
	@$(LEKALO) validate --module $(MODULE)

workspace-audit: ## Lekalo gate: pnpm workspace root at Frontend/packages, no monorepo tooling
	@command -v "$(LEKALO)" > /dev/null 2>&1 || { echo "workspace-audit: FAILED — no lekalo CLI at '$(LEKALO)'; install it or pass LEKALO=<path>" >&2; exit 1; }
	@command -v jq > /dev/null 2>&1 || { echo "workspace-audit: FAILED — no jq on PATH" >&2; exit 1; }
	@$(LEKALO) init --adopt --dry-run --json \
		| jq -e '.detection | ((.workspaceRoots // []) | map(.path)) == ["Frontend/packages"] and ((.monorepoTools // []) == []) and (((.packageManagers // []) | map(.detail) | index("pnpm")) != null)' > /dev/null \
		&& echo "workspace-audit: OK — pnpm workspace root at Frontend/packages, no monorepo tools" \
		|| { echo "workspace-audit: FAILED — workspace shape drifted; inspect: $(LEKALO) init --adopt --dry-run --json | jq .detection" >&2; exit 1; }

lekalo-verify: ## Read-only Lekalo verification + CI report (informational)
	@mkdir -p storage/cache
	@rm -f storage/cache/lekalo-verify.md
	@$(LEKALO) verify --report-file storage/cache/lekalo-verify.md --report-format md
	@cat storage/cache/lekalo-verify.md

lekalo-reports: ## Coupling + architecture-profile reports for LEKALO_MODULES, as markdown (informational)
	@$(PHP) bin/ops/lekalo-reports.php --lekalo="$(LEKALO)" --modules="$(LEKALO_MODULES)"

lekalo-doctor: ## Read-only Lekalo readiness report (informational)
	@$(LEKALO) doctor

impact-report: ## Lekalo change-radius report for the working tree (BASE=<ref>, default HEAD)
	@if git diff --quiet $(BASE) -- lekalo/; then \
		echo "impact-report: no model changes vs $(BASE) — nothing to project"; \
	else \
		$(LEKALO) impact --changed --base $(BASE) --worktree; \
	fi

##@ Development
dev: ## Start the local dev server (localhost:8000)
	$(PHP) -S 127.0.0.1:8000 -t public/

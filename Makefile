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
THEMES_ROOT ?= ../nativa-themes
LEKALO ?= $(shell command -v lekalo 2>/dev/null || echo "$$HOME/.cargo/bin/lekalo")
# Modules the advisory coupling / architecture-profile reports cover (two, not
# one: a single module says nothing about whether the shape holds elsewhere).
LEKALO_MODULES ?= users,blog

##@ Help
help: ## Show available targets
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

##@ Setup
install: ## Install PHP dev dependencies (the kernel runtime itself has none)
	$(COMPOSER) install

##@ Quality
lint: ## Lint all PHP files (php -l) — non-zero on a real syntax error
	@find src public tests bin -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null
# The `>/dev/null` drops php -l's "No syntax errors detected in ..." chatter
# while a real parse error still reaches stderr and still sets the exit status.
# The old shape piped through `grep -v 'No syntax errors' || true`, which meant
# the target printed every syntax error and exited 0 — a gate that could not
# fail, and the reason `make lint` was wired into CI as a real step.

test: ## Run PHPUnit tests
	$(COMPOSER) test

analyze: ## Run phpstan + rector dry + cs-fixer dry
	$(COMPOSER) phpstan
	$(COMPOSER) rector-dry
	$(COMPOSER) cs-fix-dry

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

##@ Themes (the source is nativa-themes, not this tree)
# The five theme modules are authored in `evan70/nativa-themes`; the catalogue
# here is their copy, because `bin/cms module:add` installs from this tree.
# `themes-check` is the gate — a hand edit under modules/Themes/ is drift, not
# a fix, and `themes-sync` is how a reviewed change lands.
themes-check: ## Verify the theme catalogue against the nativa-themes checkout
	bin/build/sync-themes.sh --check --from $(THEMES_ROOT)

themes-sync: ## Copy modules/Themes from the nativa-themes checkout
	bin/build/sync-themes.sh --write --from $(THEMES_ROOT)

##@ Catalogue
# The same two steps the `modules` and `admin-smoke` CI jobs run, so a red job
# can be reproduced here in one command instead of by reading the workflow.
modules-install: ## Install every catalogued module (closure order; refuses a cycle)
	bin/build/install-catalogue.sh

admin-smoke: ## Boot the panel with temp DBs and walk the admin flows over HTTP
	bin/build/admin-smoke.sh

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

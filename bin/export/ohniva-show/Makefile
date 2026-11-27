# Nativa Ohniva, the ohniva-show.sk client site.
#
# This file is GENERATED into the nativa-ohniva-show repository from
# nativa-php-core. `.nativa-ohniva-show-export.json` names the commit it came
# from. A target that cannot
# run here is not listed, because a red gate on a fresh clone teaches a reader
# that the gates do not hold.
#
# Every target below is registered in toolchain.json with the question it
# answers; `make gates` prints the registry.

PHP ?= php
COMPOSER ?= composer
MAGO_ARGS ?=

##@ Help
help: ## Show available targets
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

##@ Setup
install: ## Install PHP dev dependencies (the runtime itself has none)
	$(COMPOSER) install

##@ Toolchain
gates: ## Print the toolchain registry: every pin and gate in this tree
	@$(PHP) bin/ops/toolchain-report.php

##@ Quality
lint: ## Lint all PHP files (php -l), non-zero on a real syntax error
	@find src modules public tests bin -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null

test: ## Run PHPUnit after migrating the installed site
	$(PHP) bin/cms migrate
	$(COMPOSER) test

analyze: ## Run phpstan + rector dry + cs-fixer dry
	$(COMPOSER) phpstan
	$(COMPOSER) rector-dry
	$(COMPOSER) cs-fix-dry

mago-spike: ## Informational: run mago lint/analyze/format/guard
	@bin/build/mago-spike.sh $(MAGO_ARGS)

mago-guard: ## Gate: the kernel to module dependency rule
	@bin/build/mago-spike.sh --only=guard --strict

mago-lint: ## Gate: curated lint findings must be zero
	@bin/build/mago-spike.sh --only=lint --max-issues=0

security-audit: ## Gate: no tracked .env, no secret-shaped literals in tracked code
	@bin/build/security-audit.sh

dependency-audit: ## Gate: no high/critical advisories in either lockfile
	composer audit --locked --ignore-severity=low --ignore-severity=medium
	cd Frontend/packages && pnpm audit --audit-level high

##@ Distribution
dist-check: ## Gate: build dist from a clean clone (no vendor/) and smoke it
	@bin/build/dist-check.sh

##@ Assets
assets: ## Build the Boson bundle (pnpm workspace under Frontend/packages)
	@command -v pnpm >/dev/null 2>&1 || { echo "pnpm not found. Install it, or use a deployment bundle." >&2; exit 1; }
	cd Frontend/packages && pnpm install --frozen-lockfile && pnpm build
	@bin/build/check-theme-bundles.sh
	@echo "Built public/dist/boson from Frontend/packages (gitignored)."

##@ Development
dev: ## Start the local dev server (localhost:8000)
	$(PHP) -S 127.0.0.1:8000 -t public/

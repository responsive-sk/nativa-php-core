# Nativa PHP Core — the kernel-only tree.
#
# Small subset of the showcase's Makefile: what can run here runs here, what
# needs a module (migrate, seed, acceptance, frontend build, dist) lives where
# the module lives. Every target delegates to composer scripts or to a plain
# command; make adds nothing but names.

PHP ?= php
COMPOSER ?= composer
MINIMAL_ROOT ?= ../nativa-minimal

##@ Help
help: ## Show available targets
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

##@ Setup
install: ## Install PHP dev dependencies (the kernel runtime itself has none)
	$(COMPOSER) install

##@ Quality
lint: ## Lint all PHP files (php -l)
	@find src public tests bin -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 $(PHP) -l | grep -v 'No syntax errors' || true

test: ## Run PHPUnit tests
	$(COMPOSER) test

analyze: ## Run phpstan + rector dry + cs-fixer dry
	$(COMPOSER) phpstan
	$(COMPOSER) rector-dry
	$(COMPOSER) cs-fix-dry

##@ Extraction (task 2.1)
extract-check: ## Verify this tree against the upstream kernel slice
	bin/build/extract-kernel.sh --check --minimal-root $(MINIMAL_ROOT)

##@ Development
dev: ## Start the local dev server (localhost:8000)
	$(PHP) -S 127.0.0.1:8000 -t public/

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

##@ Extraction (task 2.1)
extract-check: ## Verify this tree against the upstream kernel slice
	bin/build/extract-kernel.sh --check --minimal-root $(MINIMAL_ROOT)

##@ Assets
assets: ## Build the theme bundles in-tree (pnpm workspace under Frontend/packages)
	@command -v pnpm >/dev/null 2>&1 || { \
		echo "pnpm not found — install it, or use 'make assets-import' to copy a built dist." >&2; \
		exit 1; \
	}
	cd Frontend/packages && pnpm install --frozen-lockfile && pnpm build
	@echo "Built public/dist/<theme> from Frontend/packages (gitignored — a build artifact, not source)."

assets-import: ## Fallback: copy the showcase's built bundles when pnpm is not available
	@if [ ! -d "$(MINIMAL_ROOT)/public/dist" ]; then \
		echo "No $(MINIMAL_ROOT)/public/dist — build it there first: cd $(MINIMAL_ROOT)/Frontend/packages && pnpm install && pnpm build", >&2; \
		exit 1; \
	fi
	mkdir -p public/dist
	cp -r "$(MINIMAL_ROOT)/public/dist/." public/dist/
	@echo "Copied public/dist from $(MINIMAL_ROOT) (gitignored — a build artifact, not source)."

##@ Development
dev: ## Start the local dev server (localhost:8000)
	$(PHP) -S 127.0.0.1:8000 -t public/

<?php

declare(strict_types = 1);

namespace Shell;

/**
 * ShellNavItem — one navigation entry the shell chrome renders.
 *
 * Kernel-owned on purpose. The shell cannot carry a module's DTO, because a
 * DTO is a module's type: holding `Modules\Settings\Application\DTOs\MenuItemDTO`
 * inside the kernel would mean the kernel imports the module it should not know
 * about — the exact edge this port exists to cut. A module that owns a menu
 * maps its own rows into these.
 *
 * `children` is the same shape one level down (a module owns how deep its menu
 * goes); the kernel only carries the tree and never validates it.
 *
 * ## `label` and `translationKey`
 *
 * `label` is what the reader sees when nothing overwrites it, and it is
 * operator-owned content: a menu row an editor renamed stays renamed.
 * `translationKey` is the optional claim that the label is a TEMPLATE string
 * rather than content (`nav.home`). The kernel resolves it through the shell
 * port's `copy()` in the request's own locale, so one stored menu serves both
 * languages: the Slovak row says `nav.home` and `/en` reads its English value
 * from `locales.db`. A row with no key keeps its literal label untouched.
 */
final readonly class ShellNavItem
{
    /**
     * @param list<self> $children
     */
    public function __construct(
        public string $label,
        public string $href,
        public bool $active = false,
        public bool $external = false,
        public array $children = [],
        public ?string $translationKey = null,
    ) {
    }

    public function hasChildren(): bool
    {
        return [] !== $this->children;
    }

    /**
     * The same item with a label the caller resolved from a translation.
     */
    public function withLabel(string $label): self
    {
        return new self(
            $label,
            $this->href,
            $this->active,
            $this->external,
            $this->children,
            $this->translationKey,
        );
    }
}

# kernel-layout Specification

## Purpose

The kernel-only tree makes one claim a checkout can check: the kernel is
`Modules\Core` and `Modules\Database`, and every other class in `src/` belongs
to a module. This capability pins the shape that claim needs: one kernel home
under `src/Modules`, no second taxonomy beside it, and a fallback theme that
does not enter module discovery.

## Requirements

### Requirement: The source tree has one kernel home

`src/` SHALL contain no directory other than `Modules`, and SHALL contain no
PHP file at its root other than the entry files `composition.php`, `http.php`
and `init.php`. Kernel classes SHALL live under `src/Modules/Core`, and the
five former roots `Discovery`, `Seo`, `Shell`, `Themes` and `View` SHALL NOT
exist.

#### Scenario: A new directory beside Modules fails the guard

- GIVEN the source tree with only `Modules` under `src/`
- WHEN a directory such as `src/Seo/` is added
- THEN the layout guard fails and names the unexpected path

- PINS: tests/Architecture/KernelRootLayoutTest.php::testTheSourceTreeHoldsOnlyTheModulesDirectory

#### Scenario: A loose root PHP file fails the guard

- GIVEN the three known entry files at the root of `src/`
- WHEN another PHP file is added beside them
- THEN the layout guard fails and names the unexpected file

- PINS: tests/Architecture/KernelRootLayoutTest.php::testTheSourceRootHoldsOnlyTheEntryFiles

### Requirement: The fallback theme is not a discovered module

`ThemeRegistry` SHALL NOT register the kernel fallback theme. The fallback
SHALL stay available to `ThemeKernel` as the answer when no theme is installed,
and every theme carried by an installed module, including
`Modules\KernelTheme`, SHALL still be discovered.

#### Scenario: The fallback is absent from the discovered set

- GIVEN the kernel fallback now lives under `src/Modules/Core`
- WHEN `ThemeRegistry::all()` runs
- THEN the discovered classes never include `KernelFallbackTheme`
- AND an installed `Modules\KernelTheme` keeps the `kernel` entry it owns

- PINS: tests/Theme/KernelFallbackThemeTest.php::theFallbackIsNotADiscoveredThemeModule

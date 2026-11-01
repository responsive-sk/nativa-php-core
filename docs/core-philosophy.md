# Core philosophy > the kernel owns the contract, the module owns the answer

Nativa PHP is built on one sentence >
and every other rule in this tree is a consequence of it:

> A kernel file may not import a module. A module may import the kernel.

That is not a style preference.
It is what makes "you can remove this module" a property >
a checkout can prove rather than a claim in a README >
and it is enforced twice >
`tests/Architecture/KernelCouplingTest` fails on any `use Modules\<X>\…` >
line in a kernel file >
and `make mago-guard` reports the same coupling even when it arrives >
through an inline FQCN or a `::class` constant >
which a line scan cannot see.

## 1. The kernel holds the port, not the service

A surface the kernel must render without any module installed still needs >
an answer: the site name, the active theme, the default locale >
the navigation menu.
The kernel therefore owns the **port** >
an interface in `src/Modules/Core/` >
and a module owns the implementation.

The shape is always the same three parts:

| part | owned by | example |
|---|---|---|
| the port (interface) | kernel | `Domain/Contracts/ShellContextProvider` |
| the discovery attribute | kernel | `#[AsShellContextProvider]` |
| the registry that finds implementations | kernel | `ShellContextProviderRegistry` |
| the implementation | module | `Settings` supplies the site's own answers |

**Absence is a first-class answer.** >
With no provider installed the tree still renders >
the registry returns nothing and every surface degrades to what the kernel >
can say on its own.
`null` is not an error here >
it is the answer a kernel-only checkout is supposed to give.

The same pattern repeats for auth (`AuthProvider`) >
throttling (`RateLimiter`) >
and the security-event sink (`SecurityEventSink`).
A kernel that could only say "record an `AUTHZ_DENIED`" >
by naming `Modules\Outbox\…` still depended on the module >
guard or no guard >
so the kernel raises the fact through a port >
and the composition root (`src/http.php` >
the one place allowed to name modules) wires the adapter.

## 2. A module is a slice that can leave

The catalogue in `modules/<Name>/` is the source: tracked in git >
edited by an author.
`src/Modules/<Name>/` is the installed slice: generated, untracked >
the copy the running app loads.
`bin/cms module:add <Name>` copies one to the other >
`bin/cms module:remove <Name>` deletes the slice again.

Both halves are checked, and neither is a convention:

- `module:add` refuses when a dependency in the module's closure is missing,
  names the missing modules, and copies nothing;
- `module:remove` refuses while any remaining installed module still imports the
  name, names the importing file, and leaves the slice untouched.

The round trip is the proof: `modules/Scratch` is one route >
zero non-kernel imports >
and the tree hashes byte-identically before and after `add` → `request` → >
`remove`.

## 3. The closure rule

A module declares what it needs in two ways.
The scan reads its own files for `use Modules\<X>\…` (never self, `Core` >
`Database` or `Tooling` >
those are the tree, not something in it).
What a scan cannot see >
an npm package, a PHP package, a runtime dependency on another module >
goes in an optional `module.json`:

```json
{
  "depends": ["Locales"]
}
```

A module written before that file existed installs unchanged >
on the strength of the scan alone.
`modules/Scratch/` ships no `module.json` on purpose >
so the suite keeps proving that path.

## 4. Removal is not deletion

The code is not the data.
`module:remove` deletes the slice and **reports** what it leaves behind >
the module's `storage/data/<db>.db` (with `-wal`/`-shm`/ `-journal` when >
present), its uploaded assets, its frontend package entry.
The operator may hold the only copy >
a removal that silently deletes it is not a removal, it is loss.

The same rule decides what a module may not do to the kernel >
a module can add a route, a migrator, a middleware, a nav badge >
a markdown block, a shell provider, a theme >
it cannot change the rules by which those are found.

## Where to read next

- [modules](modules.md) : the module contract in full: catalogue vs installed
  slice, drift, the closure rule, `module.json`, removal.
- [pages](pages.md) : the first module guide, and the template the later ones
  follow.
- [themes](themes.md) : themes travel as modules; what a theme must carry and
  what protects it.
- [kernel-status](kernel-status.md) : the measured log: what the tree actually
  answers, read back off a running server rather than paraphrased from a plan.

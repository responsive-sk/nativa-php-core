# nativa-minimal archive

Source material from `evan70/nativa-minimal`, copied here on 2026-11-25
from commit `652ca623` before that repository was retired.
The documents are verbatim: no line was reformatted, and no em dash was removed,
because this is a record of what the showcase said, not a document this tree
publishes as current guidance.

The live tree already owns everything it uses from here:

- the kernel slice, imported on 2026-10-11, is `src/Modules/Core`
  and `src/Modules/Database`;
- the articles are `storage/blog/` and `storage/blog-images/`;
- the ported tests are `tests/Architecture/KernelCouplingTest.php`
  and `tests/Support/KernelCoupling.php`;
- the prose rules this tree follows are `docs/prose-rhythm.md`.

What the archive adds is the provenance those live files cite:

| path | what it is |
|---|---|
| `docs/` | the showcase documents, including the `lekalo/` and `adr/` trees |
| `bin/ops/tempo-analyzer.php` | the advisory sentence-length tool the prose rules came from |
| `openspec/changes/rhythm-is-an-aid-not-a-gate.md` | the decision that demoted rhythm from a gate to an aid |

Two paths differ from the showcase on purpose.
`docs/dex/` is not copied: it is old theme source rather than a document,
and `ThemeClassOwnershipTest` would read its markup as live.
`docs/admin-templates.md` is `docs/panel-templates.md` here, because
`FileNameVocabularyTest` refuses the word in a tracked path;
the content still carries it.

It sits outside `docs/` on purpose.
`EmDashContractTest` treats a document under `docs/` that is not in its frozen
table as a document that must contain zero em dashes, and these are historical
texts with 788 of them.
Converting them would be an edit to a record, and copying them as current docs
would give an old fact a second owner.

Nothing here is generated, and nothing reads it at run time.
If the prose decision is revised, revise `docs/prose-rhythm.md`
and leave this archive alone.

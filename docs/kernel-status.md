# Kernel status — the measured log, indexed

This is the index of the log this tree keeps about what it **actually does**
when it is booted — not what the plan intends it to do. Every status code in
the parts below was read back off this checkout with `curl` against a freshly
started server.

The log outgrew one file (2 385 lines, 141 KB, sixteen rounds of it), and an
append-only file that long stops being read. It is split **by round** now: this
page is the map, and each part below is verbatim the section it always was —
same headings, same numbers, nothing rewritten in the move.

## The parts

| Part | Covers | What it settled |
|---|---|---|
| [`kernel-status/01-import-and-edge-cuts.md`](kernel-status/01-import-and-edge-cuts.md) | what was imported; **Boots 1–15** | the import and its coupling ledger, the seven edge cuts that took the kernel to zero module imports, the PHPUnit harness, the extraction guard, the kernel theme, the admin stack installing and getting its stylesheet |
| [`kernel-status/02-admin-flow-and-self-hosting.md`](kernel-status/02-admin-flow-and-self-hosting.md) | **Boots 16–20** | the admin CRUD flow and the stale migration marker that ate its tables, the pnpm workspace coming home, `bin/cms migrate`, the two silent gaps after a 302, CI and the zero-dependency claim it falsified, the extract-check linkage added and removed |
| [`kernel-status/03-content-themes-and-the-admin-merge.md`](kernel-status/03-content-themes-and-the-admin-merge.md) | **Boots 21–25** + the CardboardKit merge | an article written by the module and a deployable dist, the `erik` theme and the asset defect it found, the menu nobody asked for (**Boot 23**, the prose that ate discovery), the admin link a visitor may not see, mono's meatball |
| [`kernel-status/04-kernel-contracts.md`](kernel-status/04-kernel-contracts.md) | the contract review; **§6** | the measured shape and `docs/kernel-shape.md` as the approval record, the tooling leaving `Modules\Core`, and §6 — the admin gate nobody had wired |
| [`kernel-status/05-security-findings-7-to-9.md`](kernel-status/05-security-findings-7-to-9.md) | **§7–§9** | audit findings #1–#8: the reset token that was written to the log, the comment form's bot defense, the password minimum, the two unthrottled public writes, the CSRF origin check |
| [`kernel-status/06-auth-discovery-and-mail-transport.md`](kernel-status/06-auth-discovery-and-mail-transport.md) | **§10–§11** | the auth provider discovered by `#[AsAuthProvider]` instead of named (the deferred half of §6), and the real transport for reset links with the log fallback that stays forbidden |
| [`kernel-status/07-ci-modules-gate.md`](kernel-status/07-ci-modules-gate.md) | **Boots 26–37** | the CI gate that could not see the catalogue: 243 of 318 tests skip on a fresh clone, so a fourth job installs the catalogue and fails on any test that did not run — and the `Cardboard → Security → Outbox → Cardboard` cycle that job hit before it could assert anything. Plus the live CRUD sweep it cannot do, in both worlds (Outbox installed and removed), and the flows made enforceable after it: the admin-smoke gate booting the panel, the article and page editors asserted on the file, the public comment form (honeypot, moderation, publication) and both queues — then **Boot 31**: the queue worker no operator could run, `bin/cms queue:work`, and the handler pairing that existed in the web process only; **Boot 32**: the first real producer (the article cover, on a trigger the measurement overruled the plan for), the module commands made loadable without `vendor/`, and the Lekalo model — gated in CI, and caught describing a delete the code does not perform; **Boot 33**: the two stale theme branches the reconciliation left open, merged after being re-measured rather than re-read; **Boot 34**: that delete performed — the orphan the model promised, and the two ways a fix that unlinks harder breaks a live article instead; and **Boot 35**: the queued reset link measured rather than parked — a retry guarantee bought against an invariant the audit already established, with `failed_jobs` retention shown to be unbounded; **Boot 36**: what a Lekalo adapter would actually cost — ~700 KB of PHP, 10 operations and 14 named capabilities, against a brownfield path the project itself calls experimental; and **Boot 37**: the retention `failed_jobs` never had — measured to grow without bound, and given an explicit `queue:prune` rather than a cron side effect, because a parked job is a work queue and not a log |

Section names are stable and that is how the code refers to them: "Boot 23" is
the discovery round whose prose trap is described in part 03, "§6" is part 04.
A docblock that cites one names the part it lives in.

## Reading a boot record

Each record is one round: what was measured before, what changed, what the
change measured afterwards, and — deliberately — what the round did **not**
close. The gaps are the useful part; a record whose "still open" list is empty
is a record that stopped looking.

**Restart the server after editing PHP.** `opcache.revalidate_freq` is 180s in
this environment and `-d opcache.enable_cli=0` does not switch it off for the
built-in server: the first version of this document was written from responses
that were partly stale, and the way that was caught was rendering through the
layout in a CLI process and comparing the two answers. A check run against
stale bytecode is a green run of a file that no longer exists. (Part 01, where
this was learned.)

## Adding to the log

A larger change earns a record here — a boot record of measured states, not a
plan (`CONTRIBUTING.md`). Append it to the **newest part** while that part is
still the current round of work; a part that has been closed and read stays
closed, and the next round starts a new part and adds its row to the table
above. Keep the headings in the log's own vocabulary (`Boot N` for a round of
work, `§N` for a numbered finding) so the references the code already carries
keep resolving to the section that holds them.

## The other record

- `docs/kernel-shape.md` — one row per kernel file, with the bucket and the
  byte count. It is machine-read: `tests/Architecture/KernelShapeTest.php`
  fails unless every runtime class is inventoried, so the shape can shrink but
  not silently grow (part 04).
- `docs/modules.md` — the module contract: catalogue vs. installation, the
  closure rule, `module.json`, removal, themes travelling as modules.
- `README.md` — what the sliced kernel is and what it answers today.

# ADR > decisions that outlive the change that made them

One decision, one file, one number, one owner. An ADR here answers a question
a later change would otherwise answer again by taste.

- The number is never reused and never edited away: a decision that changes is
  superseded by a new ADR that names the old one.
- An accepted ADR may gain corrections and measurements. Its decision does not
  change in place.
- Where the ADR carries a machine block, a gate reads it. A rule stated only in
  prose is a wish; the gate is what makes it hold.

## Index

| ADR | decision |
|---|---|
| [0001](0001-theme-assets-build-with-vite.md) | theme assets are built by Vite, through one config shape |

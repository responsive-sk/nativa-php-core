# Prose rhythm

The rhythm model here is not invented in this tree.
It comes from `nativa-minimal`, where it was written >
measured against the whole article corpus >
and then demoted from a gate to an aid.
This page is the version that fits this tree.
The separator is a renderer rule here >
so half of the source rhythm is visible on the page >
instead of being flattened into a paragraph.

## Rhythm of lines

A line ends where the reader should stop, in two shapes.
A sentence that continues ends with the separator >
and a sentence that ends starts a new line without one >
because the period already ended it.
Three habits follow, and only the third is mechanical.

**A pause deserves a paragraph.**
When a sentence says something deep, a definition, a point >
a piece of honesty, the next line is blank and a new paragraph begins.
The reader closes their eyes, thinks, and finds the place again by eye.

**An idea starts on its own line.**
A source line is a working hand, not the result >
and a sentence is never cut in the middle of an idea.
The end of a line is the end of a thought or its natural resting place.

**Structure owns its line.**
A heading and a fence are never glued to prose >
and the closing fence stays on a line of its own.
The renderer joins a soft wrap with a space >
so glue here would silently erase the structure.

A wall of text is a broken rhythm, not a long thought.

## Tempo

Sentence length is a design decision, not an accident.
Short sentences carry tension and longer ones release it.
A run of identical lengths reads like a metronome nobody asked for.
`nativa-minimal` measured the target per phase in words per sentence: >
INTRO 5 to 15, HMLA 8 to 15, SLNKO 15 to 25, NOC in the middle, TÉMA 10 to 20.
This tree has no phase headings >
so the numbers are a shape rather than a checklist.
Alternate, do not repeat, and let the shortest sentence carry the point.

## What stays hard

Tempo is taste, and these are not:

- the dignity of the word, with no filler words and no demonstrative standing in
  for the thing itself,
- the truth of every claim,
- a real source that can be checked.

Those have their own machines and they do not move.

## An aid, not a gate

The tools in `nativa-minimal` print and return zero unless asked for `--strict` >
because the published corpus scores the same as its weakest articles >
and `hello-world` fails several checks.
Forcing those numbers into a build would enforce a rule >
that the repository itself does not satisfy.
The same holds here.
The rhythm tests in this tree check the mechanical half: the separator, one >
sentence per line and no dangling function word.
A test that decided where a sentence may be cut >
would be judging the reader's ear with a string matcher.

## Where it comes from

- `nativa-minimal/docs/blog-voice.md` > the seven phases and the line rules.
- `nativa-minimal/bin/ops/tempo-analyzer.php` > the per-phase tempo targets.
- `nativa-minimal/openspec/changes/rhythm-is-an-aid-not-a-gate.md` > the decision
  that rhythm is advisory.

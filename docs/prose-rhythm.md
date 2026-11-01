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

**A colon ends its line.**
When a sentence introduces a list, a value or an explanation >
the colon closes the line and the next line says what it introduced.
The separator is not needed there >
because a colon already told the reader that the sentence continues.
A colon followed by the separator wears the same mark twice.

A wall of text is a broken rhythm, not a long thought.

## Tempo

Sentence length is a design decision, not an accident.
Short sentences carry tension and longer ones release it.
A run of identical lengths reads like a metronome nobody asked for.
`nativa-minimal` measured the target per phase in words per sentence:
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
The rhythm tests in this tree check the mechanical half.
That is the separator, one sentence per line, no dangling function word >
and a colon that ends its own line.
They also measure the tempo, in the three shapes below.
A test that decided where a sentence may be cut >
would be judging the reader's ear with a string matcher.

## What the tests hold

`ProseRhythmContractTest` measures the sentence tempo of every wrapped file.
Three things in it are not taste.

The middle sentence of a file stays between 5 and 25 words >
the envelope of the four phase targets above.
Every file has at least one sentence under 10 words >
so its longest have something to lean on.

The three ways a run of sentences goes flat are frozen per file.
One is a sentence over 40 words >
another a run of five sentences of the same length >
the third a paragraph of four with no short beat in it.
Frozen means the count may fall and never rise.
Writing that fixes a paragraph lowers it >
a conversion that adds one fails the build.

The phase numbers themselves stay advice >
because the corpus that measures them is the corpus they would reject.

## Where it comes from

- `nativa-minimal/docs/blog-voice.md` > the seven phases and the line rules.
- `nativa-minimal/bin/ops/tempo-analyzer.php` > the per-phase tempo targets.
- `nativa-minimal/openspec/changes/rhythm-is-an-aid-not-a-gate.md` > the decision
  that rhythm is advisory.

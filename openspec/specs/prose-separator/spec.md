# prose-separator Specification

Canonical spec for the separator this tree writes prose with: the responsive
`>` that ends a line and continues the same sentence. The em dash is banned
(`AGENTS.md`, rule 13), so the character that replaced it has to render, has to
survive a heading, and has to be pinned by a test.

## Purpose

A line of prose ends with the separator and the sentence continues on the next
line. The renderer's job is to make that glyph the visible character at the end
of the line instead of the empty bar a blockquote would draw, and to keep the
heading split working on the separator the author wrote rather than on a dash it
prints itself.

## Requirements

### Requirement: The separator renders as a visible glyph and a break

A Markdown line that ends with ` >` SHALL render the glyph itself and start the
next line below it (`&gt;<br>`), and a blank line after that line SHALL still
end the paragraph as usual. A line that OPENS with `>` and carries text SHALL
stay a blockquote: the separator closes a line it belongs to and never opens
one.

#### Scenario: A line ending with the separator breaks the line

- GIVEN `prvý bod >` followed by `druhý bod` on the next line
- WHEN `MarkdownRenderer::render()` runs
- THEN the output is `<p>prvý bod &gt;<br>druhý bod</p>`

- PINS: tests/Content/MarkdownSeparatorTest.php::theSeparatorStaysAtTheEndOfItsLine

#### Scenario: A sentence that ended starts its line on the page

- GIVEN two sentences on their own source lines, the first ending with a period
- WHEN the renderer runs
- THEN the output breaks between them (`<br>`) with no separator glyph
- AND a soft wrap without either shape still joins with a space

- PINS: tests/Content/MarkdownSeparatorTest.php::aNewSentenceStartsOnItsOwnLine

#### Scenario: A blank line still ends the paragraph

- GIVEN `prvý bod >` followed by a blank line and `druhý bod`
- WHEN the renderer runs
- THEN the two sentences are two paragraphs, each keeping its own shape

- PINS: tests/Content/MarkdownSeparatorTest.php::aBlankLineEndsTheParagraphAfterTheSeparator

#### Scenario: A line that opens with the glyph is still a quote

- GIVEN the Markdown `> quoted line`
- WHEN the renderer runs
- THEN the output is a `<blockquote>`, not a separator

- PINS: tests/Content/MarkdownSeparatorTest.php::aLineStartingWithTheSeparatorIsStillAQuote

#### Scenario: The separator is never read as a quote

- GIVEN a paragraph whose lines end with the separator
- WHEN the renderer runs
- THEN no `<blockquote>` is produced and every glyph survives as text

- PINS: tests/Content/MarkdownSeparatorTest.php::theSeparatorIsNeverReadAsAQuote

### Requirement: A heading carries the split inline and keeps its markup

A level-2 heading written `## <prefix> > <title>` SHALL split into a prefix, the
glyph and a title, inlining each part on its own so a title carrying `code`
keeps it. The renderer SHALL NOT emit an em dash of its own: the glyph it prints
is the one the author wrote.

#### Scenario: A prefixed heading splits into three parts

- GIVEN the Markdown `## Časť I > Prvý krok`
- WHEN the renderer runs
- THEN the heading carries the prefix, the glyph and the title as separate spans
- AND the table of contents still reads the whole heading

- PINS: tests/Content/MarkdownHeadingTest.php::aPrefixedHeadingIsSplitIntoAPrefixAndATitle

#### Scenario: The renderer never injects an em dash

- GIVEN a heading split on the separator
- WHEN the renderer runs
- THEN the HTML carries no `—`

- PINS: tests/Content/MarkdownHeadingTest.php::theRendererNeverInjectsAnEmDash

#### Scenario: A title carrying inline code keeps it

- GIVEN the Markdown `` ## Časť IV > `invalid-method-access`: 313 → 0 ``
- WHEN the renderer runs
- THEN the title span contains the `<code>` element and the rest of the title
- AND the prefix span holds only `Časť IV`

- PINS: tests/Content/MarkdownHeadingTest.php::aTitleWithInlineMarkupIsInlinedOnItsOwn

### Requirement: The written form, as far as a machine can check it

Prose in a wrapped file SHALL carry no em dash and SHALL put the separator only
inside a sentence: a line that ends its sentence ends on a plain newline, and a
line that continues the sentence ends with the separator. No line SHALL end on
a function word (a preposition, a conjunction, an auxiliary), because that is
the mechanical shadow of a phrase cut in the wrong place, and no line SHALL
run past 100 characters.

The list of wrapped files in `ProseRhythmContractTest` only grows: a file joins
it once its prose is converted, never leaves it. What the check cannot judge is
where a sentence may be cut, so the examples under this requirement are the
model: verb and object stay together, a noun keeps its attribute, and a longer
line is preferred to a broken phrase.

```text
the kernel therefore owns the **port** >
an interface in `src/Modules/Core/` >
and a module owns the implementation.
```

#### Scenario: A new sentence starts its own line

- GIVEN a paragraph of two sentences
- WHEN the written form is applied
- THEN the first sentence ends its line, and the second starts the next one
- AND neither line carries a separator, because a period already ended the
  sentence

- PINS: tests/Content/ProseRhythmContractTest.php::everySentenceStartsItsOwnLine

#### Scenario: A converted file carries no em dash

- GIVEN a file listed as wrapped
- WHEN the rhythm check reads it
- THEN it contains no `—`

- PINS: tests/Content/ProseRhythmContractTest.php::noWrappedFileCarriesAnEmDash

#### Scenario: The separator marks a sentence that continues

- GIVEN a paragraph whose lines end with the separator
- WHEN the rhythm check reads it
- THEN a line ending its sentence carries no separator
- AND a line that breaks mid-sentence without one is reported

- PINS: tests/Content/ProseRhythmContractTest.php::aLineEndsWithTheSeparatorOnlyInsideItsSentence

#### Scenario: No line leaves a function word dangling

- GIVEN a line that ends with the separator
- WHEN the rhythm check reads the word before it
- THEN a preposition, a conjunction or an auxiliary is reported

- PINS: tests/Content/ProseRhythmContractTest.php::noLineEndsOnAFunctionWord

#### Scenario: A line may run long, but not forever

- GIVEN a converted file
- WHEN the rhythm check measures its lines
- THEN every line stays within 100 characters

- PINS: tests/Content/ProseRhythmContractTest.php::noLineExceedsTheWidth

### Requirement: A colon ends its line

A colon in prose SHALL close the line it appears on, and the text it
introduces SHALL start on the next line. A line that ends with a colon SHALL
NOT also carry the separator, because the colon already says that the sentence
continues, and the number of colons that keep their text on the line SHALL
only fall: the corpus carries them, and the conversion pass lowers them one
file at a time.

```text
popis musí byť v tabuľke:
`hl.bind(keys, action, { description = "..." })`.
```

#### Scenario: The text after a colon starts on the next line

- GIVEN a sentence that introduces a list, a value or an explanation
- WHEN the text follows the colon on the same line
- THEN the line is reported, and the next line is where the text belongs

- PINS: tests/Content/ProseRhythmContractTest.php::aColonEndsItsLine

#### Scenario: A colon does not wear the separator too

- GIVEN a line that ends with a colon
- WHEN the rhythm check reads the separator after it
- THEN the doubled mark is reported

- PINS: tests/Content/ProseRhythmContractTest.php::aLineEndsWithTheSeparatorOnlyInsideItsSentence

### Requirement: Tempo is measured, and its counts only fall

The rhythm check SHALL measure the sentence tempo of every wrapped file, and
SHALL hold the three shapes that a metronome and a wall of text take from the
outside: the middle sentence of a file SHALL stay between 5 and 25 words, a
file SHALL contain at least one sentence under 10 words, and the counts below
SHALL never rise above the value frozen for that file.

- a sentence over 40 words,
- a run of five sentences whose lengths stay within five words of each other,
- a paragraph of four or more sentences with no sentence under 10 words.

The phase targets that `nativa-minimal` measured stay advice, because the
corpus here already contains those defects, and a gate on the whole tempo would
enforce a rule this repository does not satisfy. `docs/prose-rhythm.md` names
the numbers and says which half is a gate.

#### Scenario: The middle sentence stays inside the envelope

- GIVEN a wrapped file
- WHEN the rhythm check measures the length of its sentences
- THEN a median below 5 or above 25 words is reported

- PINS: tests/Content/ProseRhythmContractTest.php::tempoStaysInsideTheEnvelope

#### Scenario: A file keeps one short sentence

- GIVEN a wrapped file
- WHEN the rhythm check looks for its shortest sentence
- THEN a file whose shortest sentence is over 10 words is reported

- PINS: tests/Content/ProseRhythmContractTest.php::tempoStaysInsideTheEnvelope

#### Scenario: A new metronome run fails the build

- GIVEN a file with frozen tempo counts
- WHEN an edit adds a sentence over 40 words, a run of five, or a paragraph
  without a short beat
- THEN the count above the frozen value is reported

- PINS: tests/Content/ProseRhythmContractTest.php::tempoDoesNotRegress

#### Scenario: A newly wrapped file needs its own counts

- GIVEN a file added to the wrapped list
- WHEN the rhythm check looks for its frozen counts
- THEN a missing baseline row is reported

- PINS: tests/Content/ProseRhythmContractTest.php::tempoDoesNotRegress

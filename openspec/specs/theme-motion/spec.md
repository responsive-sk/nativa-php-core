# theme-motion Specification

## Purpose

A theme's decoration animates while it has work to do and stops when it has
none. The orb's pointer field is the first surface under this rule: it is a
301-node canvas of transforms, and an idle hero earns no frame.

## Requirements

### Requirement: The orb loop stops when the field has settled

The orb SHALL request an animation frame only while the pointer is travelling
toward its target. Once the pointer is within half a pixel of the target, no
frame SHALL be requested until a pointer event or a rebuild. The stopped frame
SHALL keep the dots at the displacement the settled pointer produced.

#### Scenario: An idle hero costs no animation frames

- GIVEN the hero is in the viewport and the orb has built its field
- WHEN the reader gives the page no pointer input for ten seconds
- THEN the page requests no animation frame for the orb
- AND the dots keep the position the settled pointer left them in
- PINS: modules/Themes/Boson/tests/BosonThemeTest.php::theOrbStopsDrawingWhenTheFieldIsAtRest

#### Scenario: A pointer move still animates the field

- GIVEN the orb's loop is idle
- WHEN the pointer moves across the field
- THEN frames run until the pointer arrives at its target
- AND the loop stops again once the field has arrived
- PINS: modules/Themes/Boson/tests/BosonThemeTest.php::theOrbStopsDrawingWhenTheFieldIsAtRest

# media-preview-fetch Specification

## Purpose
A folder tile may show one image from the folder it opens, which means the
page needs one remote answer per folder. That answer is optional: without it
the tile draws its glyph. This capability fixes how many of those answers a
single page asks for at once, and what a page does when one of them never
arrives.

## Requirements

### Requirement: The preview fetch bounds its in-flight requests

`CloudinaryApi::getMany()` SHALL run at most a bounded number of Admin API
transfers at once, and the bound SHALL be a parameter of the call whose
default keeps an ordinary page of folders inside a single batch. A bound of
zero or less SHALL behave as one, and the responses SHALL keep the keys they
were requested under.

#### Scenario: More folders than the bound

- GIVEN eight folders whose previews are not cached
- WHEN the fetch runs with a bound of six
- THEN the first batch carries six URLs and the second carries two
- AND every returned body is filed under the folder path it was asked for
- PINS: modules/Media/tests/MediaFolderPreviewTest.php::theFetchIsSplitIntoBoundedBatches

#### Scenario: A bound that would ask for nothing

- GIVEN three folders whose previews are not cached
- WHEN the fetch runs with a bound of zero
- THEN every URL is asked for in its own batch
- AND no folder is dropped
- PINS: modules/Media/tests/MediaFolderPreviewTest.php::aBoundOfZeroAsksForOneUrlAtATime

### Requirement: A missing preview keeps the glyph

A folder whose preview call failed, timed out, or was never answered SHALL
render its glyph, and the page SHALL render without an error. One folder's
failure SHALL NOT fail the fetch or the render.

#### Scenario: One folder answers with an error

- GIVEN a page of folders where one preview call returns a non-200 status
- WHEN the page renders
- THEN every other folder still shows its preview
- AND the failed folder shows its glyph
- PINS: none — the body is the 200-only filter in CloudinaryApi::getMany(),
  and a Cloudinary failure cannot be produced in CI; the glyph fallback it
  feeds is pinned by MediaFolderPreviewTest::aFolderWithoutAnImageHasNoPreview

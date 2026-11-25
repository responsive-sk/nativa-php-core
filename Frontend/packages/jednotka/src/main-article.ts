/**
 * main-article.ts — the jednotka article surface's script, and its stylesheet.
 *
 * Two things an article page needs and no other jednotka surface does:
 *
 * - `main-article.css`, the comments and reader-questions blocks.
 * - `initInlineEditor()`, the author's Markdown editor over the canonical
 *   `<textarea name="body">` that `Blog\...\BlogInlineEditor` renders.
 *
 * Until 2026-11-25 the editor was imported by `main.ts`, so a homepage, a
 * listing and every ordinary page downloaded a Markdown renderer, a toolbar
 * and a preview panel with no textarea to attach to.
 *
 * The stylesheet is imported rather than declared as a second input, so one
 * manifest row carries the surface's CSS and its script and the two cannot
 * drift apart in the build.
 */

import './main-article.css';
import { initInlineEditor } from './inline-editor';

const onDomReady = (): void => {
  initInlineEditor();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady);
} else {
  onDomReady();
}

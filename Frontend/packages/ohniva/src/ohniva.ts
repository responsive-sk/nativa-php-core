/**
 * ohniva.ts: the client theme's Vite entry.
 *
 * Both stylesheets are the module's own critical files, imported rather than
 * copied: one design, one source, and the build cannot fall behind the
 * no-build fallback. The entry emits no behaviour; the only script the theme
 * ships is the IE6 shiv inlined by `themeInitScript()`.
 */

import '../../../../modules/Themes/Ohniva/assets/critical/cascade-ohniva.css'
import '../../../../modules/Themes/Ohniva/assets/critical/ohniva.css'

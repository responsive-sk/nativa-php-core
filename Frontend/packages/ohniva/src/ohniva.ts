/**
 * ohniva.ts: the client theme's Vite entry.
 *
 * All three stylesheets are the module's own critical files, imported rather
 * than copied: one design, one source, and the build cannot fall behind the
 * no-build fallback. The IE6 sheet is inlined for every browser and matches
 * nothing outside `.oh-ie6`, which is what the kernel's single document can
 * do without a conditional comment. The entry emits no behaviour; the shiv
 * and the slide rotation live in the inline script `themeInitScript()` serves.
 */

import '../../../../modules/Themes/Ohniva/assets/critical/cascade-ohniva.css'
import '../../../../modules/Themes/Ohniva/assets/critical/ohniva.css'
import '../../../../modules/Themes/Ohniva/assets/critical/ohniva-ie6.css'

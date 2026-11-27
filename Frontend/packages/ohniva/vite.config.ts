/**
 * vite.config.ts: the ohniva theme bundle (client, selectable).
 *
 * CSS is not minified: Cascade Framework's `*zoom` and `_property` hacks are
 * what makes its float grid work in IE6, and a minifier is allowed to drop
 * them. The budget records the real, unminified bytes.
 */
import { createThemeConfig } from '../build/vite-theme-factory.ts'

export default createThemeConfig({
  theme: 'ohniva',
  cssMinify: false,
  inputs: {
    ohniva: 'src/ohniva.ts',
  },
})

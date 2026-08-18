# Asset type icons

The Movies page column headers reference four icon files that need to be placed
in this folder (they weren't provided as uploads, so this app ships without
them - the app will show broken image icons in the Movies table headers until
these are added):

- `poster-icon.png`
- `background-icon.svg`
- `square-art-icon.svg`
- `logo-icon.png`

Referenced from `assets/js/app.js` via the `ASSET_ICONS` map, sized to 17x17px
via `.asset-icon` in `assets/css/app.css` to match the status emoji next to
them. Any reasonably square image works - they're constrained to that size
regardless of their native dimensions.

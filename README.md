# Citykleta Shared Bikes

Private WordPress plugin for the **Citykleta** website, a peer-to-peer bike rental platform in
Havana, Cuba. It is built for this one site and is not meant to be distributed or installed
elsewhere.

The site runs on WordPress + Elementor. The plugin keeps a read-only copy of the bike catalogue
from the Citykleta app, renders the search, map, results and bike pages, and captures rental
requests and owner proposals.

> **WordPress is not the source of truth.** Bikes, bike types and map settings live in the
> Citykleta API (`citykleta-sharing-api`) and are managed from the Back Office. The plugin
> syncs them in and shows them; it never creates or publishes a bike itself.

## What it does

- **Catalogue sync**: pulls `GET /bikesPublic/sync` on a schedule or with "Sync now", creates and
  updates one `bike` post per bike, downloads its photos, and moves bikes that leave the API to
  draft (with safety nets so a bad response never empties the site). Bike subtypes, tags and the
  map settings come in the same run.
- **Content**: bikes, a two-level `bike_type` tree (types written by hand, subtypes from the sync),
  `bike_tag`, and destination pages (Vedado, Playa…) whose bikes are assigned by geofence.
- **Editable pages**: the results page, the bike page and the destination page each draw an
  Elementor template. One template serves every bike, because the plugin's widgets read the
  current bike from the query (no Elementor Pro needed).
- **Elementor widgets** (category "Shared Bikes"): search, grid, map, categories, bike rows; the
  bike page pieces (gallery, title, specs, owner, request, Bike Data…); the destination pieces
  (price, articles, weather); and Scroll Story, Group Chat and Dropdown Tabs for the home page.
  Most of them also work as shortcodes.
- **Rental requests**: a "Contact the owner" card that stores the lead and opens WhatsApp with the
  owner. It is a request, not a booking: no availability calendar and no payments yet.
- **Owner proposals**: an Elementor form sends submissions by webhook to a private inbox that the
  API pulls from; they are approved in the Back Office.
- **Stats**: views per bike, searched locations and empty searches, and a dashboard widget with the
  request funnel.

Settings live under **Bicicletas → Settings** (API, Maps, Communication, Slugs, Templates,
Proposals, Frontend).

## Setting up a fresh checkout

The vendor libraries are not in the repository. Download them from `https://cdn.jsdelivr.net/npm/`:

| File | Source |
|---|---|
| `assets/js/leaflet.js`, `assets/css/leaflet.css` | `leaflet@1.9.4/dist/` |
| `assets/js/leaflet.markercluster.js`, `assets/css/MarkerCluster.css`, `assets/css/MarkerCluster.Default.css` | `leaflet.markercluster@1.5.3/dist/` |
| `assets/js/flatpickr.min.js`, `assets/css/flatpickr.min.css`, `assets/js/flatpickr-es.js` | `flatpickr@4/dist/` (locale from `l10n/es.js`) |
| `assets/js/splide.min.js`, `assets/css/splide.min.css` | `@splidejs/splide@4/dist/` |

Without them the pages still work, just without the map, the date range picker or the gallery
carousel. The map markers also need a bike glyph at `assets/images/marker-bike.svg`.

Then set the API base URL in **Settings → API** and run **Sync now**.

## Notes to self

- Developed locally with LocalWP and pushed to GitHub only from time to time.
- Code, identifiers and wp-admin labels in English; visitor-facing text in Spanish.
- After editing CSS or JS, bump `CITYKLETA_VERSION` **and** the `Version:` header in
  `citykleta-shared-bikes.php`.
- `CLAUDE.md` has the full architecture notes and every gotcha found so far.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

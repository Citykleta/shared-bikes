# Citykleta Shared Bikes

WordPress plugin for **Citykleta**, a peer-to-peer bike rental platform in Havana, Cuba.

It turns a WordPress + Elementor site into the public face of the Citykleta app: it keeps a
read-only copy of the bike catalogue, renders search, map, results and bike pages that can be
designed in Elementor, and captures rental requests and owner proposals.

> **WordPress is not the source of truth.** Bikes, bike types and map settings live in the
> Citykleta API (`citykleta-sharing-api`) and are managed from the Back Office. This plugin
> syncs them in and shows them; it never creates or publishes a bike itself.

## Features

### Catalogue sync
- Pulls the public, PII-free catalogue (`GET /bikesPublic/sync`) on a WP-Cron schedule or on demand
  ("Sync now"), and creates/updates one `bike` post per bike, keyed by its `external_id`.
- Downloads up to 5 photos per bike into the Media Library, sharing one attachment per source URL.
- Retires bikes that disappear from the API (to draft, never deleted), with two safety nets: an
  incomplete response or a sudden drop below 50% of the published bikes retires nothing.
- Syncs bike subtypes and tags, and the map configuration (`GET /map-settings`) set in the Back Office.

### Content model
| Type | What it is |
|---|---|
| `bike` (CPT) | A synced bike: model, brand, size, wheel size, electric, location, coordinates, price per day (USD), owner name, gallery. |
| `bike_type` (hierarchical taxonomy) | Types (Urbano, Carretera…, written by hand) with subtypes (Road, MTB…, created by the sync). Each type and subtype shows a short Wikipedia-based summary. |
| `bike_tag` (taxonomy) | Tandem, Kids, Trailer, Electric… synced from the API. |
| `destination` (CPT) | A place page (Vedado, Playa…) with a geofence; bikes are assigned to it automatically by distance. Shows its bikes, average price, related posts and a weather forecast (Open-Meteo). |
| `citykleta_template` (CPT) | Elementor layouts for the results page, the bike page and the destination page. |
| `citykleta_bike_req` (private CPT) | Rental requests from the bike page. |
| `citykleta_bike_sub` (private CPT) | Owner bike proposals waiting to be imported by the API. |

### Pages designed in Elementor (free version is enough)
The results page (`/bikes/`), each bike page and each destination page draw an **editable
template**. One template serves every bike: the plugin's own widgets resolve the current bike or
destination from the query, so Elementor Pro's Dynamic Tags are not needed. Templates can be
duplicated and switched in **Settings → Templates**, and in a local environment a finished design
can be frozen as the plugin's default (**Mark as ready**).

### Elementor widgets
All widgets appear under the **Shared Bikes** category.

| Group | Widgets |
|---|---|
| Search & results | Bike Search, Bikes Grid, Bikes Map (Leaflet + clusters), Bike Categories, Bike Rows (one scrollable row per type, or "For you": recently viewed + nearby) |
| Bike page | Gallery, Title, Location & Tags, Owner, Description, Directions, Specs, Bike Request, Bike Data (any single field) |
| Destination page | Header, Description, Average Price, Articles, Weather |
| Marketing | Scroll Story (scroll-driven story inside a card), Group Chat (simulated conversation), Dropdown Tabs (nested tabs switched by a combobox) |

Search, grid, map and the bike/destination pieces are also available as shortcodes
(`[citykleta_search]`, `[citykleta_grid]`, `[citykleta_map]`, `[citykleta_bike_data field="price"]`…).
The full list is in **Settings → Frontend**.

### Rental requests
The bike page has a "Contact the owner" card: rental dates, name and email, and a WhatsApp button
that opens a chat with the owner with a prefilled message and an estimated total. It is a
**request, not a booking**: there is no availability calendar or payment yet, and the owner
confirms. Each request is stored, emails are sent best-effort, and every attempt (saved, failed by
reason, spam) is counted.

### Owner proposals
An Elementor form posts owner submissions by webhook to a private inbox. The Citykleta API pulls
them with its own key and the team approves or rejects them in the Back Office. When the bike
arrives in a later sync, the owner's WhatsApp from the proposal fills its contact.

### Stats
- Views per bike per day, counted once per browser session.
- Searched locations, and searches with no results (supply gaps).
- A **Citykleta Stats** dashboard widget with the request funnel: bike views → requests → confirmed.
- A map of all bikes above the **All Bikes** list in wp-admin.

## Requirements
- WordPress 6.0+ and PHP 7.4+
- Elementor (free) for the editable pages and widgets
- A running Citykleta API to sync from

## Installation

1. Copy the plugin folder to `wp-content/plugins/` and activate **Citykleta Shared Bikes**.
2. Download the vendor libraries, which are not kept in the repository:

   | File | Source |
   |---|---|
   | `assets/js/leaflet.js`, `assets/css/leaflet.css` | `leaflet@1.9.4/dist/` |
   | `assets/js/leaflet.markercluster.js`, `assets/css/MarkerCluster.css`, `assets/css/MarkerCluster.Default.css` | `leaflet.markercluster@1.5.3/dist/` |
   | `assets/js/flatpickr.min.js`, `assets/css/flatpickr.min.css`, `assets/js/flatpickr-es.js` | `flatpickr@4/dist/` (locale from `l10n/es.js`) |
   | `assets/js/splide.min.js`, `assets/css/splide.min.css` | `@splidejs/splide@4/dist/` |

   All are on `https://cdn.jsdelivr.net/npm/`. Every page still works without them, just without
   the map, the date range picker or the gallery carousel.
3. Add a bike glyph at `assets/images/marker-bike.svg` (a single filled path) for the map markers.

## Configuration
Everything lives under **Bicicletas → Settings**:

| Tab | What you set |
|---|---|
| API | API base URL, sync frequency, plugin key, sync status and **Sync now** |
| Maps | Read-only view of the map settings synced from the Back Office |
| Communication | WhatsApp message template (`{owner}`, `{bike}`, `{price}`, `{dates}`, `{url}`) and the request notification email |
| Slugs | URL slugs for bikes and destinations |
| Templates | Which template draws each view |
| Proposals | Keys for the proposal webhook and for the API |
| Frontend | Shortcode reference |

## REST API
Bikes are exposed through the standard `wp/v2/bike` endpoint, with their meta fields and
taxonomy names. The plugin's own routes live under `citykleta/v1`:

| Route | Use |
|---|---|
| `POST /bike-requests` | Store a rental request (public, honeypot-protected) |
| `POST /bike-requests/attempt` | Count a request that failed validation in the browser |
| `POST /proposals/webhook?key=…` | Receive an owner proposal from Elementor (rate-limited) |
| `GET /proposals` · `POST /proposals/{id}/imported` | For the Citykleta API, with the `x-citykleta-proposals-key` header |

## Development
- Built and tested locally with LocalWP; the code follows the WordPress Coding Standards.
- Code, identifiers and wp-admin labels are in English; visitor-facing text is in Spanish.
- After editing CSS or JS, bump `CITYKLETA_VERSION` in `citykleta-shared-bikes.php` to bust the cache.
- `CLAUDE.md` holds the detailed architecture notes and the gotchas found along the way.

## License
GPL-2.0-or-later. See [LICENSE](LICENSE).

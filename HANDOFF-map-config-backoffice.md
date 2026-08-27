HANDOFF — Configuración de mapas: Back Office la administra

Registro de un cambio de arquitectura que ya se implementó en los tres repos involucrados:
`citykleta-shared-bikes` (este plugin de WordPress), `citykleta-sharing-api` (el backend) y
`citykleta-backoffice` (la webapp de staff). Si hace falta más contexto del lado del plugin, la fuente
completa es su propio `CLAUDE.md` (sección "Settings page (pestañas Sync / Maps)" → "Configuración de
mapas: mudada a Back Office").


## Qué cambió

La configuración visual de los mapas de bicis (estilo de mapa base, tamaño del marcador, comportamiento
y colores de los clusters) se editaba a mano en wp-admin, en Bicicletas → Settings → Maps. Igual que ya
pasa con las bicis y sus tipos, WordPress **no** es la fuente de verdad de estos datos — es un espejo de
solo lectura que sincroniza desde un sistema externo. Ahora la configuración de mapas se administra
desde **Back Office** y llega a WordPress (y a la app móvil) por un endpoint público.


## Qué se implementó en el plugin de WordPress

- **Pestaña Maps de Settings → solo lectura.** Ya no tiene formulario ni botón "Save changes"; muestra
  los valores vigentes (estilo de mapa, access token enmascarado, tamaño de ícono, comportamiento y
  colores de cluster) con un aviso de "esto se administra desde Back Office" y la fecha de la última
  sincronización.
- **Nueva clase de sync** (`includes/sync/class-citykleta-map-settings-sync.php`,
  `Citykleta_Map_Settings_Sync`) que pide el endpoint y sobrescribe las mismas opciones de WordPress
  que antes se guardaban a mano (`citykleta_map_tile_style`, `citykleta_map_tile_api_key`,
  `citykleta_map_marker_icon_size`, `citykleta_map_cluster_radius`, `citykleta_map_cluster_disable_zoom`,
  y los tres colores de cluster). Corre automáticamente dentro de `Citykleta_Bike_Sync::run()` — misma
  frecuencia/botón "Sync now" que ya sincroniza bicis y tipos de bici, no hay un cron aparte.
- **Path fijo en código, no editable**: `Citykleta_Settings::MAP_SETTINGS_PATH` = `/map-settings`. A
  diferencia de `bikes`/`bike-types` (que sí siguen teniendo un campo de path editable en la pestaña
  Sync — sus rutas por defecto todavía no coinciden con las reales de sharing-api, ver más abajo), el
  contrato de `/map-settings` ya está confirmado, así que no tiene sentido dejarlo editable en wp-admin
  donde un typo podría romper el sync en silencio. La URL final es `{citykleta_api_base_url}` +
  `/map-settings` — la misma base URL que ya usa Back Office para servir bicis y tipos de bici.
- **Catálogo de estilos de mapa reducido a dos**: `osm` (OpenStreetMap, gratis, sin key) y `mapbox`
  (necesita access token). Antes había 8 más (CARTO, Esri, MapTiler, Stadia Maps) — se borraron por no
  usarse. Esta lista sigue viviendo en el código del plugin (`Citykleta_Settings::TILE_STYLES`) — ver
  "Decisión: el catálogo de proveedores NO se muda" más abajo.
- **Mock local actualizado**: la API falsa interna del plugin (`includes/mock/class-citykleta-mock-api.php`)
  expone `GET /wp-json/citykleta-mock/v1/map-settings`, y hay un `includes/mock/map-settings-mock.json`
  para pegar en Mockoon.


## Qué se implementó en `citykleta-sharing-api`

Siguiendo el mismo patrón que ya existía para precios / contact-reveal (`system_setting` como
almacenamiento key-value, un service dedicado con `get()`/`set()`, valores por defecto con
validación/clamp):

- **`src/constants/constants.ts`** — `mapTileStyles` (enum `"osm" | "mapbox"`), `mapSettingsDefaults`,
  `mapSettingsKeys` (las keys bajo las que cada valor vive en `system_setting`).
- **`src/services/impl/mapSettingsServiceImpl.ts`** (nuevo) — `static effective()` (valores vigentes,
  clampeados, con fallback silencioso al default si algo guardado no parsea o se sale de rango, igual
  que `contactRevealSettingsServiceImpl`/`pricingServiceImpl`); `get()` (vista de staff, mismo shape
  `{ value, stored, default }` por campo que ya usa Precios); `set()` (requiere rol **admin**, no
  support — mismo criterio que precios/integraciones: cambiar el look de la plataforma no es tarea de
  soporte). Cada cambio queda logueado.
- **`src/routes/generalSettingsRoutes.ts`** — nueva sub-ruta `GET/POST /general_settings/map`, detrás
  de `requireStaff` (ya aplicado a nivel de router en `app.ts`), mismo patrón que `/pricing` y
  `/contact-reveal`.
- **`src/routes/mapSettingsPublicRoutes.ts`** (nuevo) + `app.ts` — `GET /map-settings` montado **antes**
  del `verifyAuth` global, **sin gate de `requireAppOrPlugin`**: se decidió que no hace falta la key
  compartida de WordPress porque este endpoint no expone PII, mismo criterio que ya se usa en
  `GET /bikesPublic/sync`. El único dato semi-sensible (`tile_api_key`, el access token de Mapbox) de
  todas formas termina viajando en cada petición de tile que hace el navegador de cualquier visitante —
  no hay nada que gatear ahí de verdad.

**Contrato real confirmado en el código** (no es una propuesta): `GET /map-settings` responde un
**objeto plano, sin envelope `{ "data": [...] }`** — a diferencia de `/bikes`/`/bike-types`, esto sigue
la misma convención que ya usan los endpoints de un solo objeto de este backend (ej.
`GET /general_settings/pricing`, que tampoco envuelve su respuesta):

```json
{
  "tile_style": "osm",
  "tile_api_key": "",
  "marker_icon_size": 28,
  "cluster_radius": 40,
  "cluster_disable_zoom": 16,
  "cluster_colors": {
    "small": "#fed94c",
    "medium": "#fac114",
    "large": "#e0960a"
  }
}
```

| Campo | Tipo | Valores válidos | Notas |
|---|---|---|---|
| `tile_style` | string | `"osm"` \| `"mapbox"` | Cualquier otro valor cae a `"osm"`. |
| `tile_api_key` | string | — | Access token de Mapbox. Vacío es válido. |
| `marker_icon_size` | int | 12–80 | px. |
| `cluster_radius` | int | 10–200 | px. |
| `cluster_disable_zoom` | int | 0–19 | 0 = nunca desactivar el agrupamiento. |
| `cluster_colors.small` / `.medium` / `.large` | string (hex) | `#rrggbb` | Un color inválido/ausente cae al default. |

Del lado del plugin, `Citykleta_Map_Settings_Sync::run()` no toca nada si la respuesta no es `200`, o si
el body no decodifica a un objeto — igual que ya pasa hoy si `/bikes` o `/bike-types` fallan.


## Qué se implementó en `citykleta-backoffice`

Nueva sección **"Mapas"** dentro de Ajustes (`AjustesView.vue`), siguiendo el mismo patrón que Precios:

- **`src/lib/mapSettings.ts`** (nuevo) — `getMapSettings()` / `setMapSettings(patch)`, llaman a
  `/general_settings/map`.
- **`src/views/MapaSettingsView.vue`** (nuevo) — selector de proveedor (solo `osm`/`mapbox`), campo de
  access token (solo visible si el proveedor es `mapbox`), tamaño de ícono, radio y zoom de
  desagrupamiento de cluster, y los tres colores (con `<input type="color">` + hex a mano). Deshabilitado
  para rol `support` (solo lectura), igual que Precios. Al guardar, solo manda al servidor los campos que
  de verdad cambiaron respecto a lo cargado (para no ensuciar el log de auditoría con "cambios" a un
  valor idéntico).
- **`src/views/AjustesView.vue`** — monta `<MapaSettingsView />` como una sección más, entre Reseñas e
  Integraciones.

Verificado con `npx tsc --noEmit` (sharing-api) y `npm run typecheck` (back-office) — ambos limpios para
los archivos nuevos/tocados. **No se hizo una prueba end-to-end en vivo** (necesita Postgres corriendo);
falta abrir Back Office en el navegador, guardar un valor, y confirmar que "Sync now" en wp-admin lo
recoge.


## Decisión: el catálogo de proveedores de tiles NO se muda a Back Office

Se evaluó mover también el catálogo completo de proveedores (`tile_url`/`attribution` de cada estilo) a
Back Office, para que ahí se pudiera armar cualquier URL de tiles sin depender de un despliegue del
plugin. Se descartó: Back Office solo elige y sincroniza la **key** del estilo (`"osm"` / `"mapbox"`),
nunca un `tile_url` completo — así queda en un solo lugar (el plugin) la plantilla de URL de cada
proveedor, y agregar uno nuevo en el futuro de todas formas es un cambio de código en cada cliente
(plugin y app), no algo que una UI pudiera resolver sola.


## Resuelto: estilo Mapbox propio

`Citykleta_Settings::TILE_STYLES['mapbox']['tile_url']` (plugin) ya apunta al style propio de Citykleta
en Mapbox Studio (`mapbox://styles/citykleta-dev/cmh7t96hm00o401r50epi7e7s`), no al genérico
`mapbox/streets-v12`:

```
https://api.mapbox.com/styles/v1/citykleta-dev/cmh7t96hm00o401r50epi7e7s/tiles/{z}/{x}/{y}{r}
```

Sigue faltando cargar el access token de ese style — en Back Office (Ajustes → Mapas → estilo `mapbox`,
campo "Access token de Mapbox") o, para probar en local sin depender de Back Office, en
`includes/mock/map-settings-mock.json` / `class-citykleta-mock-api.php::get_map_settings()`
(`tile_api_key`).


## Archivos tocados, por repo

**`citykleta-shared-bikes`** (WordPress):
- `includes/admin/class-citykleta-settings.php` — catálogo de tiles reducido, nuevo endpoint
  `map_settings`, pestaña Maps convertida a solo lectura, sanitizadores vueltos `public static`.
- `includes/sync/class-citykleta-map-settings-sync.php` — nueva clase de sync.
- `includes/sync/class-citykleta-bike-sync.php` — ahora también corre `Citykleta_Map_Settings_Sync`.
- `citykleta-shared-bikes.php` — `require_once` de la nueva clase.
- `includes/mock/class-citykleta-mock-api.php` + `includes/mock/map-settings-mock.json`.
- `CLAUDE.md`.

**`citykleta-sharing-api`** (backend):
- `src/constants/constants.ts` — `mapTileStyles`, `mapSettingsDefaults`, `mapSettingsKeys`.
- `src/services/impl/mapSettingsServiceImpl.ts` (nuevo).
- `src/routes/generalSettingsRoutes.ts` — sub-ruta `/map`.
- `src/routes/mapSettingsPublicRoutes.ts` (nuevo).
- `src/app.ts` — monta la ruta pública antes de `verifyAuth`.

**`citykleta-backoffice`** (webapp de staff):
- `src/lib/mapSettings.ts` (nuevo).
- `src/views/MapaSettingsView.vue` (nuevo).
- `src/views/AjustesView.vue` — monta la nueva sección "Mapas".

# Citykleta Shared Bikes — Contexto para Claude Code

## Qué es este proyecto

Plugin de WordPress para **Citykleta**, una plataforma peer-to-peer de alquiler
de bicicletas en La Habana, Cuba. Este plugin gestiona las bicicletas desde el
WordPress y expone sus datos por la REST API para que un prototipo móvil
(HTML/CSS/JS) pueda consumirlos.

## Contexto de desarrollo importante

- El desarrollo se hace en **local con LocalWP** para minimizar el uso de Internet
  (la conexión en Cuba puede ser cara o intermitente).
- Se prueba offline en el WordPress local y solo se sube a GitHub puntualmente.
- **Agrupa cambios** cuando sea posible para reducir idas y vueltas.

## Estructura del código

```
citykleta-shared-bikes.php          → Archivo principal: constantes, carga de clases, hooks de activación.
includes/
  post-types/
    class-bike-post-type.php         → Registra el custom post type "bike".
    class-citykleta-destination-post-type.php → Registra el CPT "destination" ("Vedado", "Playa"...):
                                        creado a mano en wp-admin (submenú de "Bicicletas"), pero la
                                        asignación por bici la calcula Citykleta_Destination_Matcher por
                                        geocerca, no un campo que se elige a mano. Incluye su propia caja
                                        de campos (geocerca + FAQ). Ver sección "Destinos" más abajo.
  taxonomies/
    class-citykleta-bike-type-taxonomy.php → Taxonomía bike_type, sincronizada (ver
                                        class-citykleta-bike-type-sync.php).
    class-citykleta-bike-usage-taxonomy.php → Taxonomía bike_usage, administrada a mano, sin
                                        asignación directa por bici (se deriva de bike_type).
  meta/
    class-bike-meta.php              → Campos personalizados + caja de edición + guardado + REST API.
    class-citykleta-owner-contact.php → El ÚNICO campo de la bici que no se sincroniza: el contacto del
                                        dueño (correo o teléfono), escrito a mano en su propia caja.
                                        Ver "Solicitudes de bicicleta" más abajo.
  requests/
    class-citykleta-bike-request.php  → CPT privado "citykleta_bike_req": una solicitud de alquiler
                                        (bici, fechas, contacto, estado). Calcado de Citykleta_Subscriber.
    class-citykleta-bike-request-rest.php → POST /citykleta/v1/bike-requests, público, valida el payload.
    class-citykleta-bike-request-form.php → El formulario de la página individual + sus assets.
    class-citykleta-bike-request-notifier.php → Los correos (a Citykleta y, si se puede, al dueño).
  sync/
    class-citykleta-bike-type-sync.php → Trae /bike-types y crea/actualiza los términos de la
                                        taxonomía bike_type. Corre primero, dentro de
                                        Citykleta_Bike_Sync::run().
    class-citykleta-map-settings-sync.php → Trae /map-settings de Back Office (estilo de mapa base +
                                        token, tamaño de marcador, comportamiento y colores de
                                        cluster) y sobrescribe las mismas opciones que antes editaba
                                        a mano la pestaña Maps de Settings, ahora de solo lectura. Ver
                                        la sección "Settings page" más abajo. Corre también dentro de
                                        Citykleta_Bike_Sync::run().
    class-citykleta-destination-matcher.php → Asigna cada bici a los destinos cuya geocerca la
                                        contiene. Corre DESPUÉS del loop de sync de bicis (no antes,
                                        como los dos de arriba) — ver sección "Destinos" más abajo.
    class-citykleta-bike-sync.php    → Trae bicis de la API configurada y crea/actualiza posts "bike".
                                        Corre por WP-Cron (frecuencia configurable) o manualmente.
  admin/
    class-citykleta-settings.php     → Página "Settings" (submenú de "Bicicletas") con dos pestañas:
                                        "Sync" (URL de la API, frecuencia, slug) y "Maps" (colores del
                                        mapa de bicis, usados por el widget de Elementor y por
                                        Citykleta_Admin_Map).
    class-citykleta-list-columns.php → Columnas de imagen, marca y ubicación en la lista de bicis de
                                        wp-admin (marca y ubicación son ordenables con un click).
    class-citykleta-admin-map.php    → Mapa con TODAS las bicis que tengan lat/lng, mostrado arriba de la
                                        lista "All Bikes" (fusionado ahí, ya no es su propia página aparte),
                                        para ver de un vistazo dónde están sin salir de wp-admin. Se puede
                                        ocultar por usuario desde el panel "Screen Options" de esa lista.
                                        Reusa Citykleta_Bike_Map_Data y citykleta-map.js.
  map/
    class-citykleta-bike-map-data.php → Helper compartido: consulta bicis con lat/lng y arma el array que
                                         espera citykleta-map.js. Lo usan tanto el widget de Elementor como
                                         la página de wp-admin, para no duplicar la consulta en los dos.
  front/
    class-citykleta-templates.php    → Carga templates/archive-bike.php (grid de tarjetas), permite que
                                        el tema activo lo sobreescriba con su propio archive-bike.php.
  stats/
    class-citykleta-view-stats.php   → Cuenta las vistas de la página individual de cada bici (WordPress
                                          core no cuenta vistas). Al cargar la página, un navigator.sendBeacon()
                                          pega a un endpoint REST que suma la vista al día de hoy en un único
                                          meta por bici (_citykleta_views_by_day, arreglo 'Y-m-d' => cantidad —
                                          una fila por bici, no por vista, así conserva la demanda por día sin
                                          inflar wp_postmeta). Muestra el total en una columna "Views" de la
                                          lista de bicis y el desglose por día en una caja lateral de la
                                          pantalla de edición. Cada bici se cuenta una sola vez por sesión
                                          del navegador (sessionStorage) para no inflar con recargas. Pronto
                                          se moverá a un módulo de stats propio.
    class-citykleta-search-stats.php  → Registra qué ubicaciones se buscan en el archivo /bikes/ y cuáles
                                        no dan resultados (huecos de oferta). Agrega en una sola opción
                                        (citykleta_search_stats) término => {count, zero, label, last}, sin
                                        una fila por búsqueda. Dueña del widget "Citykleta Stats" en el
                                        dashboard de wp-admin (wp_add_dashboard_widget, ya no una página
                                        propia bajo Shared Bikes): arriba el embudo del muro de descarga
                                        (Vistas → Inscripciones → Descargas, con desglose por día), debajo
                                        las dos tablas de búsquedas (más buscadas / sin resultados). El
                                        embudo lee las vistas de Citykleta_View_Stats::site_total() y los
                                        agregados de sign-up/descarga de Citykleta_Subscriber; esta clase
                                        solo los maqueta.
  subscribers/
    class-citykleta-subscriber.php    → CPT privado "citykleta_subscriber": la lista de leads del muro de
                                        descarga (email, nombre, consentimiento + timestamp, source, estado
                                        de sync, downloaded_at). Staging para HubSpot; ver sección "Muro de
                                        descarga de la app" más abajo.
    class-citykleta-subscriber-rest.php → Endpoints REST del muro: POST /citykleta/v1/subscribers (captura el
                                        sign-up) y POST /citykleta/v1/subscribers/{id}/download (marca la
                                        descarga). Públicos, validan payload (mismo criterio que view-stats).
    class-citykleta-app-cta.php       → Shortcode [citykleta_app_download]: botón → formulario de sign-up →
                                        enlaces a las tiendas. URLs de tienda por atributos (ios/android/button).
  elementor/
    class-citykleta-elementor.php     → Registra los widgets de Elementor (no hace nada si Elementor no
                                         está activo) y encola su CSS desde temprano (wp_enqueue_scripts).
    class-citykleta-bike-widget.php   → Widget "Bikes Grid": grid de bicis para arrastrar en cualquier
                                         página armada con Elementor. Secciones Content: Contenido, Query,
                                         Layout. Pestaña Style completa. Reusa bike-cards.css.
    class-citykleta-search-widget.php → Widget "Buscar bicicletas": barra de búsqueda (ubicación, fechas,
                                         cantidad) que envía un GET a /bikes/. Solo la ubicación filtra
                                         resultados de verdad (ver Citykleta_Templates::apply_search_filters());
                                         fechas y cantidad viajan en la URL sin filtrar todavía, no hay
                                         sistema de disponibilidad/reservas detrás.
    class-citykleta-map-widget.php    → Widget "Bikes Map": consulta bicis con WP_Query (mismo patrón que
                                         el widget de grid) y se las pasa como JSON a citykleta-map.js, que
                                         las dibuja en un mapa Leaflet agrupadas en clusters.
    class-citykleta-widget-shortcodes.php → [citykleta_search]/[citykleta_grid]/[citykleta_map]: los tres
                                         widgets de arriba, también como shortcodes (reusan su render()
                                         real, ver la sección "Archivo de bicis" más abajo).
templates/
  archive-bike.php                   → Ya no arma el grid a mano: imprime el contenido de una Page
                                        editable con Elementor (ver "Archivo de bicis" más abajo), armada
                                        por defecto con los tres shortcodes de arriba.
  single-destination.php             → Página de un Destino (/destination/{slug}/): bicis asignadas por
                                        geocerca, precio promedio, artículos relacionados, FAQ. Ver
                                        sección "Destinos" más abajo.
assets/css/
  bike-cards.css                     → Estilos del grid de tarjetas (archivo + widget de bicis).
  bike-request.css                   → Formulario de solicitud dentro de la tarjeta de precio.
  destination.css                    → Encabezado/artículos/FAQ de la página de Destino (el grid de
                                        bicis reusa bike-cards.css tal cual).
  search-bar.css                     → Estilos base de la barra de búsqueda (la mayoría sobreescribible
                                        desde la pestaña Style del widget).
  search-bar-datepicker.css          → Repinta el calendario de Flatpickr con los colores de marca.
  flatpickr.min.css                  → Vendor (Flatpickr), NO está en el repo, hay que descargarlo a mano.
  leaflet.css, MarkerCluster.css,
  MarkerCluster.Default.css          → Vendor (Leaflet + Leaflet.markercluster), NO están en el repo.
  citykleta-map.css                  → Repinta los clusters y estilos propios del mapa (marcador de bici,
                                        punto "tú estás aquí", popup) con los colores de bike-cards.css.
assets/images/
  marker-bike.svg                    → Glyph de bici del marcador por defecto (ver sección "Mapa de bicis").
                                        NO está en el repo — hay que poner un SVG propio ahí a mano.
assets/js/
  citykleta-bike-request.js          → Botón → formulario → confirmación de la solicitud (POST por REST).
  citykleta-search-widget.js         → Inicializa Flatpickr en modo rango sobre el campo de fechas.
  flatpickr.min.js / flatpickr-es.js → Vendor (Flatpickr + locale es), NO están en el repo, hay que
                                        descargarlos a mano (ver sección "Widget de búsqueda").
  leaflet.js, leaflet.markercluster.js → Vendor (Leaflet + Leaflet.markercluster), NO están en el repo,
                                        hay que descargarlos a mano (ver sección "Mapa de bicis").
  citykleta-map.js                   → Módulo reusable: window.CityKletaMap.init( container, bikes, options )
                                        dibuja un array de bicis en un mapa Leaflet agrupadas en clusters.
                                        También se auto-inicializa escaneando .citykleta-map[data-bikes]
                                        (ver sección "Mapa de bicis").
languages/                           → Traducciones (vacío por ahora).
```

## Convenciones

- Prefijo para funciones globales: `citykleta_`
- Prefijo para clases: `Citykleta_`
- Prefijo para meta y opciones: `citykleta_`
- **Todo el código en inglés**: identificadores (claves de post meta, nombres de clases/archivos, rutas
  REST, nombres de funciones/métodos), comentarios/docblocks, y también las etiquetas visibles en wp-admin
  (labels del post type, labels de los campos del meta box, mensajes de error). Ej: post type `bike`, meta
  `price_per_day`, ruta `/bikesPublic/sync`, label en el formulario "Price per day (USD)". Español solo se usa para
  la conversación entre el usuario y Claude Code, nunca dentro del código.
- Text domain: `citykleta-shared-bikes`
- Seguir los **WordPress Coding Standards** (indentación con tabs, espacios dentro de paréntesis).
- Todo campo nuevo que deba ver el móvil debe tener `show_in_rest => true`.

## Campos actuales de la bicicleta

| Campo (meta key) | Tipo    | Label en wp-admin           | Notas                      |
|-------------------|---------|------------------------------|-----------------------------|
| model              | string  | Modelo                      | Modelo de la bici           |
| location           | string  | Ubicación                   | Texto libre por ahora       |
| lat                | number  | Latitude                    | Viene de `coordinates.lat` en la API. Usado por el widget de mapa. |
| lng                | number  | Longitude                   | Viene de `coordinates.lng` en la API. Usado por el widget de mapa. |
| price_per_day      | number  | Precio por día (USD)        | De `daily_rate`. La app cobra **por día** y no tiene tarifa horaria. Se muestra con `Citykleta_Bike_Meta::price_html()` / `format_price()`, único lugar que arma el texto `$900 USD /día`. Reemplazó a `price_per_hour`, que solo existía en el mock (ver abajo). |
| available          | boolean | ¿Disponible?                | Checkbox                    |
| brand              | string  | Marca                       | Marca de la bici            |
| size               | string  | Talla                       | Talla del cuadro            |
| wheel_size         | string  | Tamaño de rueda             | Ej. 26", 27.5", 29", 700c   |
| is_electric        | boolean | ¿Es eléctrica?              | Convencional vs. eléctrica  |
| owner_name         | string  | Nombre del propietario      | Solo nombre, sin perfil completo todavía |
| owner_photo        | string  | Foto del propietario (URL)  | URL directa, no se descarga a la biblioteca de medios (a diferencia de la imagen de la bici) |
| owner_phone        | string  | Teléfono del propietario (WhatsApp) | **Siempre vacío en la práctica.** El sync lo lee de `owner.phone`, pero `/bikesPublic/sync` es un catálogo sin PII y sólo manda `owner: { name, verified }`, así que ese `if` no se cumple nunca. Para contactar al dueño se usa `Citykleta_Owner_Contact` (meta aparte, escrito a mano) — ver "Solicitudes de bicicleta". |

## Arquitectura: WordPress sincroniza desde la app, no es la fuente de verdad

WordPress **no** es donde se escriben los datos de las bicis a mano. La app Bike Sharing
de Citykleta tendrá su propia API REST; este plugin la va a **consumir** y guardar una
copia local sincronizada como posts `bike`. La caja de edición manual en wp-admin queda
como respaldo/override, no como flujo principal.

Como cualquier edición manual se pierde en la siguiente sincronización, `Citykleta_Bike_Meta`
muestra dos avisos en la pantalla de edición de una bici (`render_sync_warning()`, enganchado
a `admin_notices`, más un párrafo dentro de la propia caja de campos) explicando que esos
datos vienen de la app y que editar ahí no se guarda en el origen.

Por la misma razón, `Citykleta_Bike_Post_Type` quita el enlace "Añadir nueva" del menú
lateral y oculta el botón "Añadir bicicleta" de la lista (solo CSS, no toca capacidades de
usuario — la URL `post-new.php?post_type=bike` sigue siendo accesible a mano si alguien la
escribe). `Citykleta_Bike_Type_Taxonomy` hace lo mismo con el formulario "Añadir nuevo tipo",
porque esos términos también se crean solos al sincronizar (`wp_set_object_terms()` en
`Citykleta_Bike_Sync::upsert_bike()`). La taxonomía `bike_usage` ("Tipos de uso") es la
excepción — esa sí la declara el administrador a mano (ver `Citykleta_Bike_Usage_Taxonomy`),
no viene de la API, así que **no** se le oculta nada.

**Esa API real ya existe y es la única fuente: `citykleta-sharing-api`.** En desarrollo corre
en `http://localhost:8001` (ojo: `http://localhost:5175` es el **Back Office**, un dev server de
Vite que responde 200 a cualquier ruta por el fallback del SPA — apuntar el sync ahí parece
funcionar y no sincroniza nada). Esa URL base se configura en Settings → Sync; los paths van
fijos en código (`Citykleta_Settings::PATHS`).

**Ya no hay mock ni datos de prueba.** Existieron una API falsa dentro del plugin
(`includes/mock/`, constante `CITYKLETA_USE_MOCK_API`) y un **Mockoon** en `localhost:3000`
sirviendo el mismo JSON; ambos se borraron en 2026-08 tras conectar el backend real. Se
quitaron porque hacían daño activo: inventaban un contrato (`{ data, meta: { has_more } }`,
`id`, `price_per_hour`) que **no existe en el backend**, y trabajar contra él llevó a escribir
código para una API imaginaria. Si hace falta volver a probar sin backend, levantar un servidor
que imite `GET /bikesPublic/sync` **de verdad** — nunca reintroducir un contrato inventado.

## Settings page (pestañas Sync / Maps)

`Citykleta_Settings` es una sola página en wp-admin (Bicicletas → **Settings**) con seis pestañas
propias (`self::TABS`, controladas por `?tab=` en la URL, patrón `nav-tab-wrapper` estándar de WordPress).
De esas seis, **Sync**, **Communication**, **Destinations** y **Templates** tienen formulario guardable
— **Maps** es de solo lectura desde que su configuración se mudó a Back Office (ver más abajo) y
**Frontend** es documentación (la tabla de shortcodes). **Templates** elige qué plantilla dibuja cada
vista, ver "Varias plantillas por vista" más abajo. **Destinations** guarda
el slug de URL (`citykleta_destination_slug`) y el FAQ por defecto (`citykleta_destination_faq_default`)
— los destinos en sí (geocerca, FAQ propio) se administran como posts del CPT `destination`, ver la
sección "Destinos" más abajo.

**Pestañas extensibles desde otros plugins:** la lista de pestañas se lee por `Citykleta_Settings::tabs()`,
que aplica el filtro `citykleta_settings_tabs` (slug => label) — un plugin aparte puede agregar la suya
sin tocar este código. El cuerpo de una pestaña externa lo dibuja la action
`citykleta_settings_render_tab_{slug}` (el plugin externo imprime ahí su propio `<form>`, con su propio
`OPTION_GROUP` y `settings_fields()`, siguiendo la misma regla de "un option group por formulario"). Lo
usa el conector de HubSpot para colgar su pestaña "HubSpot" acá manteniendo su código separado. Las tres
pestañas internas siguen renderizándose con su rama propia en `render_page()`; el `else` final delega en
esa action para cualquier slug externo.
Cada pestaña registra sus secciones/campos bajo su propio "page" interno (`self::PAGE_SLUG . '-sync'` /
`'-maps'` / `'-comm'`), así que `do_settings_sections()` únicamente muestra los campos de la pestaña
activa — pero eso solo controla qué se *muestra*, no qué se *guarda*. Por eso Sync y Communication (las
dos con formulario) usan `OPTION_GROUP` **distintos** (`OPTION_GROUP_SYNC` / `OPTION_GROUP_COMM`), cada
uno con su propio `settings_fields()` en el `<form>` correspondiente. Maps sigue usando
`add_settings_section()`/`add_settings_field()` para el mismo layout en tabla, pero sin
`register_setting()` ni `<form>` — no tiene nada que guardar, ver más abajo.

**Gotcha ya resuelto, por si se repite en una página nueva con pestañas:** `wp-admin/options.php`
recorre *todas* las opciones registradas bajo el grupo que llega en el formulario (`option_page`), no
solo las que están presentes en ese `$_POST` — a las que falten las pone en blanco. Si dos pestañas
guardables compartieran un solo `OPTION_GROUP`, guardar el formulario de una (que solo trae sus propios
campos en el POST) borraría los valores de la otra, porque WordPress los trataría como "enviados en
blanco" en vez de "no tocados". La regla es: un `OPTION_GROUP` por cada `<form>` que se pueda guardar
por separado, nunca uno compartido entre formularios distintos.

- **Sync** (antes toda la página se llamaba así) — URL base de la API, frecuencia, slug de la URL,
  estado de la última sincronización y el botón "Sync now". **Lo único editable de la conexión es la
  URL base**: el *path* de cada recurso vive en código, en `Citykleta_Settings::PATHS`, y se resuelve
  con `get_endpoint_url( $key )`. Los campos de path por recurso (`citykleta_path_{key}`, la vieja
  constante `ENDPOINTS`) **se eliminaron en 2026-08**, una vez confirmadas las rutas reales contra
  `citykleta-sharing-api`: un typo en un campo de texto no puede romper un sync en silencio.
  - `bikes` → **`/bikesPublic/sync`**, el catálogo sin PII hecho para este plugin. Es público a
    propósito (montado *antes* del `verifyAuth` global de la API), así que no hay sesión ni llave
    compartida de por medio. **No es `/bikes`** — ese es el router de staff y está detrás de auth.
  - `map_settings` → `/map-settings` (ver "Configuración de mapas: mudada a Back Office").

  **Rutas públicas del backend (las montadas ANTES del `verifyAuth` global, `src/app.ts`).** El plugin
  consume dos; las otras están disponibles y sin usar:

  | Ruta | Qué da | ¿La usa el plugin? |
  |---|---|---|
  | `GET /bikesPublic/sync` | catálogo sin PII (array plano) | **sí** |
  | `GET /map-settings` | estilo de mapa + clusters | **sí** |
  | `GET /bikesPublic/` | `listBikes` | no |
  | `GET /reviews/bike/:bikeId` | reseñas de una bici | **no — sin explotar** |
  | `GET /reviews/owner/:userId` | reputación de un dueño | **no — sin explotar** |
  | `POST /contact/reveal` | teléfono del dueño a cambio de un código | **no — sin conectar** |
  | `/auth`, `/health`, `/.well-known/…` | sesión e infraestructura | no |

  Las tres últimas de interés (`/reviews/*` y `/contact/reveal`) están verificadas en vivo: las de
  reseñas responden **200 sin autenticación ninguna**, y `/contact/reveal` da **401** sin la llave del
  plugin, como debe. Reseñas y reputación del dueño son material directo para la ficha de una bici y
  hoy no se muestran en ningún lado.

  No hay entrada `bike_types` a propósito: el `GET /bike-types` de la API está montado después del
  `verifyAuth` y responde **401** sin sesión de staff, así que este plugin no puede leerlo — y no le
  hace falta, porque cada fila de `/bikesPublic/sync` trae `bike_type` (slug) y `bike_type_name`. Ver
  `Citykleta_Bike_Type_Sync` más abajo.
- **Maps** — **de solo lectura desde 2026-08**: esta configuración ahora la administra Back Office
  (ver la nota "Configuración de mapas: mudada a Back Office" más abajo) y llega por sync, no por un
  formulario de wp-admin. La pestaña conserva las mismas cuatro secciones
  (`Citykleta_Settings::SECTION_TILE_STYLE` / `SECTION_MARKER_DESIGN` / `SECTION_CLUSTER_BEHAVIOR` /
  `SECTION_CLUSTER_COLORS`) solo para mostrar los valores vigentes, con un aviso arriba y la fecha de
  la última sincronización:
  - **Base Map** — estilo de tiles vigente (`citykleta_map_tile_style`, resuelto contra
    `Citykleta_Settings::TILE_STYLES` — reducido a solo `osm` y `mapbox`, ver esa constante) y, si el
    estilo elegido lo necesita, el access token vigente (`citykleta_map_tile_api_key`) enmascarado
    (nunca se imprime completo en el HTML de wp-admin).
    `Citykleta_Settings::tile_layer()` sigue armando la URL final agregando `?{key_param}={key}` igual
    que antes — no cambió, solo cambió quién decide el valor guardado.
  - **Marker design** — solo tamaño (`citykleta_map_marker_icon_size`, "Icon Size"). El ícono en sí,
    sus colores por disponibilidad, y la sombra que tenía antes ya no son configurables — ver la
    sección "Mapa de bicis" más abajo.
  - **Cluster Behavior** — "Cluster Radius" (`citykleta_map_cluster_radius`, px) y "Unfold At Zoom"
    (`citykleta_map_cluster_disable_zoom`; 0 = nunca). No es un umbral por *cantidad* de bicis —
    Leaflet.markercluster agrupa por proximidad en píxeles y zoom, no por cuántos marcadores hay.
  - **Cluster Colors** — un color por cada nivel de tamaño que ya usa Leaflet.markercluster (Small/
    Medium/Large), mostrado como swatch + código hex.

  Esos colores de cluster viven en `Citykleta_Settings::MAP_COLORS`. `Citykleta_Settings::map_colors()`
  / `map_color_style_attr()` son genéricos sobre `MAP_COLORS` (cada entrada declara su `'section'`) —
  arman el `style=""` con una variable CSS `--citykleta-map-{key}-color` por cada entrada (agregar un
  color nuevo a la constante no necesita tocar estos dos métodos). Tanto `Citykleta_Map_Widget`
  (Elementor) como `Citykleta_Admin_Map` (wp-admin) llaman a los mismos helpers
  (`map_color_style_attr()`, `tile_layer()`, `marker_icon_size()`), así que **un solo lugar** controla
  el look de todos los mapas del sitio — eso no cambió, sigue siendo la razón de ser de estos helpers.
  El aro translúcido de cada cluster usa `color-mix()` en CSS para diluir ese mismo color sólido en vez
  de necesitar un segundo valor rgba() por nivel.
- **Communication** — una sola opción (`citykleta_whatsapp_message`): la plantilla del mensaje que se
  pre-rellena cuando un visitante toca "Contactar por WhatsApp" en la página individual de una bici.
  Soporta placeholders (`Citykleta_Settings::MESSAGE_PLACEHOLDERS`: `{owner}`, `{bike}`, `{price}`,
  `{url}`). `whatsapp_message( $replacements )` rellena la plantilla para una bici concreta y
  `whatsapp_url( $phone, $message )` arma el enlace `wa.me` (deja solo dígitos del teléfono, devuelve
  `''` si no hay número usable para que el llamador oculte el botón). El texto por defecto
  (`DEFAULT_MESSAGE`) está en español, como el resto del front-end, aunque los identificadores/labels
  sigan en inglés.

`Citykleta_Bike_Type_Sync` **ya no trae nada de la red**: recibe los tipos por parámetro
(`run( array $types )`, slug => nombre) y crea/actualiza los términos de la taxonomía `bike_type` por
`slug`. El motivo es que el `GET /bike-types` de la API es solo de staff (401 sin sesión, ver arriba),
pero cada fila de `/bikesPublic/sync` ya trae `bike_type` y `bike_type_name` — así que
`Citykleta_Bike_Sync::collect_bike_types()` los recoge del payload de bicis que de todos modos acaba
de descargar, y se los pasa. Por eso **corre después del fetch de bicis** pero **antes del loop de
`upsert_bike()`**, que resuelve el tipo de cada bici por slug. Nunca borra términos: si un tipo deja
de aparecer, el término se marca inactivo (`_citykleta_type_active` en term meta, columna "Active" en
wp-admin) para no romper bicis o asignaciones de Uso que ya lo referencien. Un `$types` vacío (catálogo
vacío, o payload sin ningún `bike_type`) no marca nada inactivo — no hay información para hacerlo.

`Citykleta_Map_Settings_Sync` corre justo después, también dentro de `Citykleta_Bike_Sync::run()`:
trae `/map-settings` y sobrescribe con `update_option()` las mismas opciones que antes editaba a mano
la pestaña Maps (`citykleta_map_tile_style`, `citykleta_map_cluster_radius`, etc. — ver
"Configuración de mapas: mudada a Back Office" más abajo). Es independiente de bicis/tipos de bici,
simplemente va agarrado a la misma corrida/frecuencia por simplicidad, no porque dependa de ellos.

### Configuración de mapas: mudada a Back Office

Desde 2026-08, la configuración del mapa (Settings → Maps) dejó de editarse a mano en wp-admin — la
administra **Back Office** (repo aparte) y este plugin solo guarda una **copia de solo lectura**,
igual que ya hacía con bicis y tipos de bici. El patrón es exactamente el mismo que
`Citykleta_Bike_Type_Sync`: una ruta fija más en `Citykleta_Settings::MAP_SETTINGS_PATH`
(`/map-settings` — sin campo editable en wp-admin, ver la sección "Settings page" más arriba) y una clase de sync
(`Citykleta_Map_Settings_Sync`) que lo trae y hace `update_option()` sobre las mismas keys que ya
existían — por eso ningún consumidor (`Citykleta_Settings::tile_layer()` / `map_colors()` /
`cluster_options()` / `marker_icon_size()`, y todo lo que está construido sobre ellos —
`Citykleta_Map_Widget`, `Citykleta_Admin_Map`) tuvo que cambiar: solo cambió *quién escribe* esas
opciones, no cómo se leen.

Contrato del endpoint `/map-settings`: **objeto plano, sin el envelope `{ "data": [...] }`** que sí usan
`/bikes` y `/bike-types` — a diferencia de esos dos, `citykleta-sharing-api` ya lo sirve así de verdad
(confirmado en su propio código al implementar el lado de Back Office, no es una suposición). Ver
la respuesta real de `GET /map-settings` para un ejemplo:

```json
{
  "tile_style": "osm",
  "tile_api_key": "",
  "marker_icon_size": 28,
  "cluster_radius": 40,
  "cluster_disable_zoom": 16,
  "cluster_colors": { "small": "#fed94c", "medium": "#fac114", "large": "#e0960a" }
}
```

`tile_style` debe ser una de las keys de `Citykleta_Settings::TILE_STYLES` (`'osm'` o `'mapbox'`) — un
valor desconocido cae a `'osm'` (`Citykleta_Settings::sanitize_tile_style()`). El catálogo de
proveedores (`TILE_STYLES`, con su `tile_url`/`attribution` completos) **se queda en el código del
plugin a propósito**: Back Office solo elige y sincroniza la *key* del proveedor, nunca manda un
`tile_url` — así ambos lados solo necesitan ponerse de acuerdo en los nombres de las keys, no en
plantillas de URL de tiles. Se redujo el catálogo a solo `osm` (fallback gratis sin key) y `mapbox`
(el proveedor real, Citykleta ya tiene un mapa propio en Mapbox con su access token) — los otros
seis que había antes (CARTO, Esri, MapTiler, Stadia Maps) se borraron por no usarse.

La pestaña **Maps** de Settings (`Citykleta_Settings::render_page()`) sigue existiendo pero ahora es
de solo lectura: sin `register_setting()` ni `<form>`, solo `do_settings_sections()` mostrando el
valor vigente de cada campo (texto plano / swatch de color en vez de `<input>`), un aviso de "esto se
administra desde Back Office" y la fecha de la última sincronización
(`citykleta_map_settings_last_sync`). El preview en vivo de la derecha se mantiene igual que antes.

**Ver `HANDOFF-map-config-backoffice.md`** (raíz del plugin) para el detalle completo del contrato y
las decisiones tomadas (catálogo de tiles fijo en el plugin, pestaña Maps de solo lectura). El lado de
Back Office **ya está implementado** — `GET /map-settings` en `citykleta-sharing-api` (público, sin
sesión, mismo criterio que `/bikesPublic/sync`) más `GET/POST /general_settings/map` (solo staff, solo
admin puede guardar) para la sección "Mapas" dentro de Ajustes en `citykleta-backoffice`.

`Citykleta_Bike_Sync` pide la lista de bicis a `Citykleta_Settings::get_endpoint_url( 'bikes' )` y
crea/actualiza un post `bike` por cada una, usando el meta `external_id` para no duplicar en
sincronizaciones repetidas (cada bici referencia su tipo por `slug`, resuelto contra los términos
que dejó `Citykleta_Bike_Type_Sync`). Corre automáticamente por WP-Cron según
`citykleta_sync_frequency` (cada 15/30 min, hora, etc., configurable en la misma página), o al
toque con el botón "Sincronizar ahora".

### Contrato real de `/bikesPublic/sync` y traducción de campos

El backend describe la bici con claves distintas a las que usa el plugin por dentro, así que
`Citykleta_Bike_Sync::normalize_item()` traduce cada fila **una sola vez, al entrar** (dentro de
`fetch_all_pages()`, no después: el deduplicado por id ya necesita la forma canónica). Clave real a la
izquierda, clave canónica que usa el resto del plugin a la derecha:

| `/bikesPublic/sync` | canónico | nota |
|---|---|---|
| `app_bike_id` | `id` | sin esto **se descartarían todas las bicis** (`empty($item['id'])`) |
| `name` | `title` | solo si no hay `brand`/`model`, ver `build_title()` |
| `daily_rate` (string) | `price_per_day` | **por día, no por hora** — ver abajo |
| `location.address` | `location` | la API lo anida dentro de `location` |
| `location.lat` / `location.long` | `coordinates.lat` / `.lng` | ojo: **`long`**, no `lng` |
| `images[].url` | `images[]` | featured primero, y **resueltas a absolutas** (llegan como `/uploads/…`) |
| `status === 'active'` | `available` | el real no tiene booleano de disponibilidad |
| `bike_type` | `bike_type` | ya es slug en ambos, no necesita nada |

Lo que ya viene en forma canónica se deja intacto. Una fila sin id usable se descarta.

### El precio: por día, en USD, y en un solo sitio

La app cobra **por día** y no existe tarifa horaria (el propio router de la API lo dice: *"the only
rate: renting is priced per day"*). `daily_rate` se guarda en el meta `price_per_day` y se muestra
**siempre** a través de `Citykleta_Bike_Meta`:

- `price_amount( $post_id )` → el número (o `null`), para calcular (ej. el promedio de un destino).
- `format_price( $amount )` → el texto: **`$900 USD /día`**.
- `price_html( $post_id )` → las dos cosas juntas, que es lo que usan las plantillas.

El formato vive en **un único método** a propósito. Antes cada plantilla lo armaba a mano
(`echo $price . ' CUP/hora'` repetido en siete sitios), y por eso las siete siguieron mostrando un
precio horario obsoleto cuando la fuente de datos cambió. La moneda base es **USD**; internacionalizar
la moneda es un trabajo aparte, todavía sin hacer.

**Cuidado con `price_per_hour` si aparece en una base de datos vieja.** Ese meta solo existió en el
mock. La API real nunca manda esa clave, así que el sync **no lo actualizaba ni lo borraba** y se
quedaba congelado: las bicis sincronizadas en la época de Mockoon mostraban un precio inventado con
toda la apariencia de ser correcto (ej. "Pinarello Prima" a 290 CUP/hora, cuando su precio real es
1740 USD/día). Por eso `upsert_bike()` hace un `delete_post_meta( $post_id, 'price_per_hour' )` en
cada bici que toca: es limpieza de una sola vez, y se puede quitar cuando ninguna base de datos
conserve el campo.

### Paginación y retiro de bicis (reconciliación)

El sync no solo crea/actualiza: también **retira** las bicis que dejaron de venir de la API, porque
WP es una copia de solo lectura y una bici que ya no existe en el origen no puede quedarse publicada
para siempre. Las dos cosas van juntas a propósito — el retiro sin paginación sería destructivo.

- **Paginación** (`Citykleta_Bike_Sync::fetch_all_pages()`) — recorre todas las páginas y **tolera tres
  formas de respuesta**, porque en Citykleta conviven las tres y no se van a unificar pronto
  (`extract_items()` / `has_next_page()`):
  1. **Array plano** — lo que `GET /bikesPublic/sync` de `citykleta-sharing-api` devuelve **hoy**:
     el catálogo entero de una vez, ignorando el request (su servicio recibe `_req` y no lo usa).
     Sin señal de paginación = respuesta completa, no primera página.
  2. **`{ items, total, page, limit, pages }`** — el envelope paginado que usa el **resto** del backend
     real (bicis de staff, orders, reservations, reviews, messages, events…), con `?page=N&limit=N` y
     `skip/take` por debajo. Es la forma a la que `/bikesPublic/sync` migraría si algún día pagina.

  El parámetro `?page=N` sí coincide con el backend real. Los ids se deduplican **entre** páginas, no
  solo dentro de una, por si el backend pagina sobre un orden inestable. Si una página no trae ningún
  `id` nuevo, se está sirviendo la página 1 una y otra vez y el recorrido para (`MAX_PAGES` es la misma
  protección contra un contador de páginas que nunca se agota).
- **Retiro** (`retire_missing()`) — pasa a **borrador** (`RETIRED_STATUS`), no a papelera ni borrado
  real: así las vistas acumuladas (`_citykleta_views_by_day`) siguen pegadas al post y una bici que
  vuelve se republica sola, sin código especial (`upsert_bike()` siempre escribe
  `post_status => 'publish'`). Solo toca bicis con `external_id`, o sea las que creó el propio sync —
  una bici hecha a mano en wp-admin no tiene ese meta y nunca se retira, así el override manual sigue
  funcionando. No hace falta limpiar el meta de destinos: todas las queries de mapa/destino filtran
  por `post_status = publish`, así que una bici en borrador sale sola.
- **Dos redes de seguridad, porque equivocarse aquí vacía el sitio:**
  1. Una respuesta **incompleta nunca retira nada**. `fetch_all_pages()` devuelve un flag `complete`;
     si el recorrido se cortó por cualquiera de las protecciones de arriba, `run()` se salta el retiro
     entero — con una lista a la que le pueden faltar páginas no hay forma de distinguir "desapareció
     de la API" de "nunca pedimos la página donde estaba".
  2. Una **caída brusca** tampoco. Si la respuesta cubre menos de `RETIRE_SAFETY_RATIO` (50%) de las
     bicis publicadas con `external_id`, se lee como un backend respondiendo a medias, no como una
     baja masiva: se actualiza lo que llegó y no se retira nada. Equivocarse por este lado cuesta una
     bici desactualizada hasta el próximo sync; por el otro lado deja el sitio vacío.
  Un fallo de red o un HTTP != 200 cortan antes todavía (`return` temprano en `run()`), así que
  tampoco retiran.
- **Qué se ve en wp-admin** — la pestaña Sync reporta cuántas se pasaron a borrador junto al conteo
  de procesadas, y los avisos de las redes de seguridad salen como "Heads up" en
  `citykleta_last_sync_warning`, aparte de `citykleta_last_sync_error`: son corridas que salieron
  bien pero que a propósito hicieron menos de lo normal, no fallos.

**Probar el retiro** ya no es tan cómodo como con el mock (que tenía un `?limit=N` para fingir
bajas): hoy hay que dar de baja una bici de verdad en la app / el Back Office, o apuntar el sync a
un servidor propio que devuelva menos bicis, y sincronizar. La red de seguridad del 50% se dispara
antes si la caída es grande — está pensada exactamente para eso.

**Dos bugs de WordPress ya resueltos aquí, por si se repiten en clases nuevas:**
1. **No engancharse a un hook desde dentro de un callback de ese mismo hook a la misma
   prioridad** — `citykleta_init()` corre en `init`; si una clase llamada desde ahí hace
   `add_action('init', ...)` otra vez, ese callback casi nunca corre en esa misma pasada.
   Solución: si ya estás en `init` (porque `citykleta_init()` te llamó), invoca el método
   directo, no lo vuelvas a enganchar a `init`.
2. **El campo `"meta"` no aparece en las respuestas REST de un CPT a menos que declare
   soporte `'custom-fields'`** en su `supports`, aunque los campos estén bien registrados
   con `register_post_meta(..., 'show_in_rest' => true)`. Ver `class-bike-post-type.php`.

### Imágenes

Cada bici trae sus imágenes en `images` (el backend real manda objetos
`{ url, featured }` con rutas relativas — `normalize_item()` las deja en una lista de
URLs absolutas con la destacada primero, ver la tabla de traducción más arriba).
`Citykleta_Bike_Sync::sync_images()` se queda con las primeras
**`Citykleta_Bike_Meta::MAX_IMAGES` (5)**: la primera va como **imagen destacada** del post
(donde el resto del plugin ya la busca — tarjetas, popups del mapa, REST) y las demás como
lista de IDs de adjunto en el meta `_citykleta_gallery_ids`
(`Citykleta_Bike_Meta::GALLERY_META`). Ese meta se reescribe **siempre**, incluso a vacío:
una foto borrada en la app tiene que desaparecer del sitio, no quedarse de un sync anterior.

Para leerlas, `Citykleta_Bike_Meta::gallery_ids( $post_id )` devuelve la lista completa
(destacada primero), deduplicada y recortada a `MAX_IMAGES` — es lo que usa el carrusel de
`templates/single-bike.php`. El tope se aplica **una sola vez, al entrar**: sin él, una bici
con muchas fotos convertiría un sync en decenas de descargas más sus juegos de miniaturas,
el mismo problema de costo que motivó el adjunto compartido de más abajo.

Ojo: hoy la API real manda **una sola foto por bici** (`images` con un elemento), así que el
caso de 1 foto es el único que se ve en producción — el carrusel de 2+ está construido y
probado de punta a punta (Splide montado, flechas/puntos, ida y vuelta completa) escribiendo a
mano `_citykleta_gallery_ids` de una bici de prueba con adjuntos reales ya en la Media
Library, no contra un payload real de `/bikesPublic/sync` con varias fotos — eso solo se va a
poder probar el día que el backend mande más de una.

**Un adjunto por URL de origen, compartido entre bicis.** El meta
`_citykleta_image_source_url` (`Citykleta_Bike_Sync::IMAGE_SOURCE_META`) se escribe en los
**dos** lados: en la bici, para no volver a descargar una imagen que no cambió; y en el
**adjunto**, que es lo que permite a `find_attachment_by_source()` reutilizarlo cuando otra
bici apunta a la misma URL. No es un detalle menor: cuando las 68 bicis de prueba compartían
un mismo `bike-placeholder.jpg`, descargarlo una vez por bici significaba 68 descargas más 68
juegos de miniaturas — una primera sincronización que **no terminaba en 15 minutos**. Con el
adjunto compartido, esa misma corrida tardaba ~98 s y creaba **1** adjunto. WordPress no tiene
problema en que un adjunto sea la imagen destacada de varios posts.

**Ojo: desde 2026-09 el catálogo ya no comparte imagen.** Cada bici tiene su propia foto
(`citykleta-bicicleta-{marca}-{modelo}-…jpg`, fotos reales de Wikimedia Commons puestas por
`scripts/fetch-bike-photos.ts` en `citykleta-sharing-api`), así que la deduplicación ya no
ahorra nada aquí y la **primera** sincronización sí hace 68 descargas (~6 MB) más sus
miniaturas: va a tardar varios minutos y eso es lo esperado, no un cuelgue. Las siguientes
corridas no vuelven a descargar nada mientras la URL de origen no cambie, que es para lo que
sirve el meta. Antes de eso el catálogo sirvió 68 **SVG** durante un tiempo, que el sync
rechazaba entero — ver la nota sobre SVG más arriba.

**Gotcha grande — por qué el sync NO usa `media_sideload_image()`:** esa función (vía
`download_url()` → `wp_safe_remote_get()`) pasa la URL por `wp_http_validate_url()`, que
**rechaza cualquier URL cuyo puerto no sea 80, 443 u 8080** (salvo que coincida con el host
*y* puerto del propio sitio). El final de esa función es literal:

```php
if ( empty( $parsed_url['port'] ) ) return $url;
$port = $parsed_url['port'];
if ( 80 === $port || 443 === $port || 8080 === $port ) return $url;
// ...mismo host que home y mismo puerto...
return false;
```

Con la API de desarrollo en `http://localhost:8001` eso descarta **todas** las imágenes, y el
error que aparece en Settings viene de muy adentro de `WP_Http` y despista mucho: **"A valid
URL was not provided."**, como si la URL estuviera mal formada, cuando es perfecta y el
archivo se descarga sin problemas con `curl` (200, `image/jpeg`). **No hay filtro que
levante ese chequeo de puerto** — a diferencia del rechazo por host loopback/privado que
hace la misma función unas líneas antes, que sí tiene `http_request_host_is_external`. Se
intentó primero con ese filtro y no alcanzó: arregla la mitad de arriba, no el puerto.

La solución es `Citykleta_Bike_Sync::sideload_image()`: baja el archivo con un
`wp_remote_get()` normal (que no aplica ninguna de esas políticas de URL) y le entrega los
bytes a `media_handle_sideload()` como **archivo local**, que ya no mira la URL.

Como efecto secundario desaparece un gotcha viejo: `media_sideload_image()` deducía el tipo
solo por la **extensión de la URL**, así que una URL sin extensión (ej.
`https://placehold.co/600x400?text=...`) fallaba con "Invalid image URL.". Ahora
`image_filename()` usa la extensión si la hay y, si no, la deriva del **`Content-Type`** de
la respuesta (soporta jpg/png/gif/webp/avif y descarta lo que no sea imagen), así que ese
caso ya funciona.

**SVG no está en esa lista y no debe estarlo.** Es XML ejecutable (admite `<script>`,
`onload=`, `<foreignObject>`), y WordPress no permite subirlo por defecto justo por eso —
aceptarlo aquí metería en la biblioteca de medios, servido desde el propio dominio, un
archivo que viene de un endpoint público sin autenticación. Además WP no genera miniaturas
de SVG, así que `wp_get_attachment_image()` saldría sin dimensiones en las tarjetas. En
2026-09 el catálogo de desarrollo servía las 68 imágenes como `.svg`
(`Content-Type: image/svg+xml`, diagramas generados, no fotos): eso fue un **error al
generar los datos de prueba** — el plan es servir imágenes reales — y el rechazo del plugin
era el comportamiento correcto, no un bug.

**Cómo se reportan los fallos de imagen** (`record_image_failure()` +
`summarise_image_failures()`): se acumulan durante toda la corrida y se resumen **una vez al
final**, separados en dos:

- **Tipo no soportado** → va a `citykleta_last_sync_warning` ("Heads up"). Las bicis se
  sincronizaron bien y solo faltan sus fotos; pintarlo en rojo describiría mal una corrida
  que hizo su trabajo.
- **Fallo real** (red, 404, descarga rota) → sigue en `citykleta_last_image_error`.

Los dos dan un **conteo** más un ejemplo. Antes cada fallo hacía su propio
`update_option( 'citykleta_last_image_error', … )` *dentro* del loop y cada éxito hacía
`delete_option()`, así que lo que mostraba wp-admin no era "falló una imagen" sino **lo que
le pasó a la última bici procesada**: con 68 fallos se veía el 68º, y si la última hubiera
funcionado no se habría visto ninguno. El código de error se compara contra
`Citykleta_Bike_Sync::IMAGE_TYPE_ERROR`, no contra el texto del mensaje.

## Plantillas (Bicicletas → Templates)

Las páginas propias del plugin (resultados y ficha de una bici) **ya no son
plantillas PHP fijas**: cada una dibuja una **plantilla** que el usuario edita con
Elementor. `Citykleta_Template_Post_Type` registra el CPT `citykleta_template`,
privado (sin URL, sin archivo, fuera de búsquedas y menús) bajo el menú
"Bicicletas".

**Funciona con Elementor GRATIS, sin Theme Builder.** Elementor decide si un tipo
de contenido es editable únicamente con `post_type_supports( $tipo, 'elementor' )`
(ver `Elementor\Utils::is_post_type_support()`); declarar ese soporte alcanza —
verificado en vivo con el propio chequeo de Elementor. Lo que Pro agregaría son los
**Dynamic Tags** (atar un widget genérico de Elementor a un campo de la bici), y
eso no hace falta acá porque **el plugin trae sus propias piezas y cada una
resuelve la bici sola**, leyéndola de la query (`get_queried_object_id()` en
`Citykleta_Bike_Part_Widget::bike_id()`). Ese es el truco que hace que **una sola
plantilla sirva para las 68 bicis**.

**El límite real, para no prometer de más:** se pueden acomodar y estilar *las
piezas que trae el plugin*, mezcladas con cualquier elemento normal de Elementor
(títulos, columnas, fondos). Lo que NO se puede sin Pro es atar un widget genérico
a un campo que no esté envuelto en una pieza — para eso hay que agregar el widget.

- Cada plantilla declara qué dibuja con la meta `_citykleta_template_type`
  (`Citykleta_Template_Post_Type::TYPES`: `results` / `single_bike` / `destination`), elegible en
  la caja "Draws" y visible como columna en la lista. Solo cuenta una plantilla
  **publicada**, así que un borrador es un lugar seguro para probar un rediseño.
  Puede haber **varias** por vista; cuál se usa se elige en Settings → Templates
  (ver abajo).
- `ensure_template()` la crea la primera vez que hace falta y **nunca pisa** una
  que ya exista. Los layouts por defecto están en
  `Citykleta_Templates::default_results_content()` /
  `default_single_bike_content()`.
- `render_template()` le pide el diseño a Elementor **directamente**, con
  `get_builder_content_for_display( $id, true )`. Pasarlo por
  `apply_filters( 'the_content', … )` NO sirve: Elementor solo sustituye el
  contenido del post que está en la query principal, y ahí está la *bici*,
  nunca la plantilla — así que el filtro devolvía el `post_content` crudo (el
  respaldo en bloques). Las piezas salían igual y la página se veía bien,
  mientras el diseño hecho en Elementor se ignoraba en silencio; el único
  síntoma era que los `data-id` de la página no eran los de `_elementor_data`.
- **Y esa llamada NO puede hacerse con el `$post` global apuntando a la
  plantilla.** Elementor lee "el post que se está renderizando es el documento
  que me pediste" como recursión y devuelve cadena vacía, otra vez sin avisar:
  el llamador cae al respaldo y todo sigue viéndose casi bien. Por eso
  `render_template()` solo cambia el `$post` global en la rama del respaldo.
  Las piezas tampoco lo necesitan — resuelven su bici desde la query. **Ojo:**
  dentro de una pieza, `get_the_ID()` devolvería la plantilla, no la bici —
  siempre `bike_id()`.
- **El layout por defecto se siembra como documento de Elementor.** Elementor es
  una dependencia asumida de este plugin (decisión del usuario: el sitio real se
  arma con Elementor, y el gratis alcanza — ver arriba), así que una plantilla
  recién creada nace ya diseñada: `ensure_template()` llama a
  `Citykleta_Template_Post_Type::seed_elementor_layout()`, que escribe
  `_elementor_data` (JSON, con `wp_slash()` — sin eso las comillas del JSON se
  pierden al guardar), `_elementor_edit_mode = 'builder'`,
  `_elementor_template_type` y `_elementor_version`. Los árboles vienen de
  `Citykleta_Templates::default_results_layout()` /
  `default_single_bike_layout()`, armados con los helpers `container()`,
  `column()`, `widget()` y `element_id()`.
  - **Se siembra con Containers, no con `section`/`column` legado.** Elementor
    4.2.4 todavía entiende los dos, pero el `elType: 'section'` es el formato
    viejo: el editor lo marca como tal y las opciones de flexbox modernas no
    aplican. Un Container (`elType: 'container'`) es lo que crea Elementor hoy,
    así que la plantilla sembrada se ve y se edita igual que una que el usuario
    hubiera hecho a mano. `column()` es azúcar sobre `container()`:
    `content_width => 'full'` + `width` en % + `flex_direction => 'column'` (el
    `width` solo aplica cuando el ancho es `full`, por eso van juntos).
  - **La siembra solo corre cuando `_elementor_data` NO existe.** Nunca pisa un
    diseño: si alguien ya abrió la plantilla en Elementor y la guardó (aunque
    sea sin cambiarla), esa meta está y la siembra no hace nada. Junto con el
    "`ensure_template()` nunca pisa una plantilla existente" de arriba, son dos
    guardas independientes.
  - `post_content` se sigue escribiendo con el mismo layout en **bloques**
    (`core/columns` + `core/shortcode`, ver `default_*_content()` y el helper
    `shortcode_block()`) — es el respaldo legible si algún día Elementor no está
    activo, y lo que se ve en el editor de bloques. Con Elementor activo y
    `_elementor_edit_mode = 'builder'`, quien manda al renderizar es
    `_elementor_data`.
- **`public => true` no es un descuido.** El CPT usa exactamente los flags del
  `elementor_library` del propio Elementor: `public => true` + `rewrite => false`
  + fuera de búsquedas y de menús. Con `public => false` la plantilla no es
  consultable y **la vista previa da 404** — y el lienzo del editor también, que
  carga el permalink con `?elementor-preview=`.
- **Vista previa:** sin bici en contexto las piezas no dibujarían nada, así que
  cuando lo que se está mirando ES la plantilla (el canvas de Elementor, o el
  post de la plantilla directamente) caen a la bici más reciente
  (`Citykleta_Bike_Part_Widget::bike_id()`). En cualquier otra página, sin bici
  en contexto, siguen sin imprimir nada — para que no aparezca una bici al azar
  donde no corresponde.
- **Migración hecha:** la página de resultados vivía como una Page normal
  (opción `citykleta_bikes_page_id`), mezclada con las páginas reales y con URL
  propia. `Citykleta_Templates::migrate_legacy_bikes_page()` la mueve al CPT una
  sola vez, copiando también la meta `_elementor*` (ahí vive el diseño, no en
  `post_content`) y mandando la Page vieja a la **papelera**, no borrándola.

**Contenedores V4 (flexbox/grid/div-block) y `Citykleta_Templates::declare_template_render()`.**
Si el diseño de una plantilla usa los contenedores nuevos de Elementor 4 (`e-flexbox`, `e-grid`,
`e-div-block`, y sus clases locales `e-xxxxxxx-…` o globales `g-xxxxxxx`), su CSS **no** va en
`post-{id}.css`: lo genera el gestor de estilos atómicos, que solo procesa los IDs que le llegan por la
action `elementor/post/render` *antes* de que Elementor imprima estilos en `<head>` (su callback de
`wp_enqueue_scripts`, prioridad 10). Para una página normal esa action se dispara con el post de la
query; acá la query es la bici/destino y la plantilla se dibuja después, en el body, así que su ID nunca
llegaba. **Síntoma:** todos los widgets salen, pero los contenedores pierden el layout (columnas
angostas, cosas encimadas), mientras los controles Style de los widgets del plugin siguen funcionando
(esos sí van en el `post-{id}.css` clásico) — engaña porque parece un problema del widget. Se resuelve
disparando esa action con el ID de la plantilla activa en `wp_enqueue_scripts` prioridad 5. Para
diagnosticarlo: buscar la clase `e-…` del contenedor en las hojas de la página; si no aparece en ninguna,
es esto (con el arreglo aparece en `css/local-{id}-frontend-desktop.css`).

### Varias plantillas por vista: cuál está activa

Nada impide tener dos plantillas publicadas para la misma vista (el caso típico:
duplicar la ficha de bici para probar un rediseño). **Cuál manda se elige en
Bicicletas → Settings → pestaña `Templates`**, un desplegable por vista, guardado
todo en una sola opción `citykleta_active_templates` (`vista => ID`,
`Citykleta_Template_Post_Type::ACTIVE_OPTION`). Una sola opción y no una por
vista a propósito: agregar una vista a `TYPES` no obliga a tocar
`Citykleta_Settings`, los campos se generan del propio `TYPES`.

Las dos piezas responden preguntas distintas y por eso conviven:
**la caja "Draws" dice para qué sirve una plantilla** (y tiene que quedarse:
`Citykleta_Template_Defaults` la necesita para saber si una captura va a
`single-bike.json` o a `destination.json`), **el desplegable dice cuál de esas
se usa**. De paso, cada desplegable solo lista las plantillas de *su* vista, así
que no se puede elegir por error un layout de Destino para la ficha de una bici.

- **La opción vacía no es "ninguna"**: es *"la primera publicada de esa vista"*,
  o sea exactamente el comportamiento que había antes de que existiera esta
  pantalla. Por eso actualizar no rompe nada y `ensure_template()` sigue creando
  y usando el layout por defecto sin que nadie tenga que entrar a Settings.
- **La elección se revalida en cada `get_template()`**, nunca se confía: la
  plantilla elegida puede haberse borrado, pasado a borrador o reasignado a otra
  vista después de elegirla, y un ID vencido ahí significaría una página en
  blanco. Si no resuelve, cae al fallback de arriba y la pestaña avisa en ámbar.
  Ojo, antes de esto `get_template()` hacía `orderby => ID ASC, posts_per_page => 1`,
  o sea que **ganaba la más vieja en silencio** — publicar el rediseño no cambiaba
  nada y no había ninguna señal de por qué.
- La columna **"Draws"** de la lista de plantillas repite el estado
  (`— Active` / `— Inactive` / `— Inactive (draft)`, vía
  `Citykleta_Template_Post_Type::is_active()`). Es información duplicada a
  propósito: mirando esa lista, "¿esta es la que está usando el sitio?" es la
  primera pregunta, y tener que salir a Settings para responderla anularía la
  columna.

Flujo de rediseño que esto habilita: duplicar la plantilla → editarla con
Elementor tranquilo (nadie la ve) → publicarla → elegirla en Settings → si no
gusta, volver a elegir la vieja. `Mark as ready` sigue funcionando igual sobre
la que sea, esté activa o no.

### Congelar un diseño de Elementor como el default del plugin

El árbol que arman `default_results_layout()` / `default_single_bike_layout()` a
mano en PHP es solo un punto de partida. **El diseño de verdad se hace en
Elementor y se congela desde ahí**: en la lista de plantillas (Bicicletas →
Templates) cada fila tiene dos acciones, `Mark as ready` y `Reset`
(`Citykleta_Template_Defaults`, `includes/front/class-citykleta-template-defaults.php`).

- **`Mark as ready`** copia el `_elementor_data` de esa plantilla a
  `includes/front/layouts/{vista}.json` (`results.json` / `single-bike.json`).
  Desde ese momento `default_*_layout()` devuelve **ese** archivo y el árbol de
  PHP queda solo como fallback para un checkout que todavía no tenga captura.
  El archivo lleva envoltorio: `captured_at`, `plugin_version` y `elements`.
- **`Mark as ready` también está como acción masiva** en el desplegable de la
  lista, para capturar varias plantillas de una vez. Las que no se puedan
  capturar (sin vista asignada, o sin diseño de Elementor todavía) se cuentan
  como *skipped* y no cortan la corrida — una plantilla a medias no tiene por
  qué impedir que se guarden las demás. El aviso final resume
  *"Saved N of M selected templates"*. El nonce del masivo lo pone el core; el
  entorno y la capacidad se revalidan igual dentro del handler.
- **`Reset`** tira el diseño actual y vuelve a sembrar la plantilla
  desde lo que el plugin trae hoy. Es lo único que permite comprobar que una
  captura quedó bien sin borrar y recrear la plantilla. **A propósito NO existe
  en masivo**: tirar varios diseños de un desplegable es demasiado fácil por
  accidente, y a diferencia de una captura eso no se recupera con git.
- Escribe en tu copia de trabajo, así que el cambio aparece en `git status` y
  **lo commiteas tú**. El plugin no se actualiza a sí mismo, y no debe poder
  hacerlo.
- Una columna **"Ready"** en esa misma lista dice en qué estado está cada
  plantilla, con tres valores: `Not captured` (esa vista todavía no tiene
  archivo), la **fecha** de la última captura, o la fecha más *"Edited since"*
  en ámbar — o sea, el diseño en Elementor ya no es el que está en el repo y
  hay que volver a apretar el botón. Ese tercer estado se calcula comparando
  `_elementor_data` contra el `elements` del archivo, ambos decodificados y
  vueltos a codificar, así que compara estructura y no cómo esté escrito el
  JSON. La columna la agrega `Citykleta_Template_Defaults`, no el post type,
  para que toda esta funcionalidad desaparezca junta en producción.

**La puerta es `wp_get_environment_type()`**, que tiene que decir `local` o
`development` (`staging` no: no tiene por qué editar lo que el plugin trae).
Vale la pena saber por qué esa función y no otra cosa: **el core la dejó sin
filtro a propósito**, no hay ajuste en wp-admin para ella, y cachea su valor de
forma estática por petición — o sea que ningún plugin ni tema puede cambiar el
entorno en caliente. Además, cuando nada declara la constante, WordPress cae a
`production`: la puerta **falla cerrada**. En este LocalWP la línea
`define( 'WP_ENVIRONMENT_TYPE', 'local' )` de `wp-config.php` la puso el propio
Local, no nosotros.

Se consideró añadir una segunda señal (que el host terminara en `.local`) por si
un `wp-config.php` de desarrollo terminaba copiado a un servidor real. **Se
descartó**: a producción solo viaja el plugin, nunca la configuración, así que
ese escenario no existe aquí.

Dos decisiones de seguridad que conviene no deshacer:

- **Se guarda JSON, nunca PHP generado.** El archivo capturado se lee con
  `json_decode()`, así que no puede ejecutar nada pase lo que pase. Generar PHP
  sí sería un vector real.
- El nombre del archivo sale de validar la vista contra
  `Citykleta_Template_Post_Type::TYPES`, no de sanear una cadena — nada fuera de
  esa lista fija puede nombrar un archivo.

**Lo que NO viaja a otra instalación**, y hay que tenerlo en cuenta al diseñar:
imágenes de la Media Library (quedan como ID de adjunto + URL del sitio de
desarrollo), colores y fuentes globales propios del kit (los del kit por defecto
sí resuelven), y widgets de Elementor Pro. Las piezas del plugin viajan
perfecto — son lo único que resuelve la bici solo.

**Escribir `_elementor_data` a mano obliga a invalidar lo que Elementor deriva
de ahí.** Cuando el cambio entra por su propio editor, Elementor limpia solo;
un `update_post_meta()` directo como el nuestro, no. Por eso
`seed_elementor_layout()` borra **tres** metas:

- **`_elementor_element_cache`** — la peor de las tres, porque cachea **HTML ya
  renderizado**. Si se queda, la página muestra el layout **viejo** mientras el
  CSS recién generado describe el **nuevo**: el diseño se ve obsoleto sin
  ninguna razón visible, y el único indicio es que los `data-id` de la página
  no coinciden con los de `_elementor_data`. Costó un rato encontrarlo.
- **`_elementor_css`** — para que regenere la hoja de estilos del post. Ojo:
  se borra la **meta**, nunca el **archivo** `post-{id}.css`, que dejaría la
  página apuntando a un stylesheet que da 404.
- **`_elementor_page_assets`** — la lista de assets que la página declara
  necesitar, igual de derivada del dato viejo.

Si aun así tras un reset el estilo se ve viejo, se regenera desde
Elementor → Tools.

### Piezas de la ficha de una bici

`includes/elementor/class-citykleta-bike-part-widgets.php` — nueve widgets (y sus
shortcodes, ver `Citykleta_Widget_Shortcodes::BIKE_PART_SHORTCODES`), todos
extendiendo `Citykleta_Bike_Part_Widget`: galería (`[citykleta_bike_gallery]`),
título (`[citykleta_bike_title]`), ubicación + tags de tipo
(`[citykleta_bike_location_tags]`), propietario, descripción, dirección, ficha
técnica, solicitud, y **Bike Data** (cualquier dato suelto, ver más abajo). El
mapa de recogida no es una pieza propia — es el
widget de mapa en modo "Bike Data", ver la nota de "un widget por trabajo" más
abajo. Van todos en un archivo a propósito: son ~40 líneas cada uno y solo
tienen sentido como conjunto.

**Título y ubicación eran un solo widget ("Bike Title & Location") y se
separaron.** Un rediseño no siempre quiere el título pegado a la ubicación —
poder moverlos, ocultarlos o estilarlos por separado importaba más que
ahorrarse un widget. `citykleta_bike_title` solo imprime el `<h1>`/`<h2>`/`<h3>`;
`citykleta_bike_location_tags` imprime la ubicación y los tags de
`bike_type` (ej. "Hybrid"). La etiqueta **"Eléctrica" se movió de ahí a Bike
Specs** — es un dato de la bici como la marca o la talla, no un badge suelto
junto a la dirección. Ver la nota de `taxonomy_specs()` más abajo sobre por
qué necesita su propia línea de código en vez de sumarse al resto por
`array_filter()`.

**Un widget por trabajo, con un control de origen — no dos widgets parecidos.**
El mapa de recogida de la ficha era `Citykleta_Bike_Location_Widget`, una clase
aparte, y esa duplicación ya había costado algo real: armaba su `data-options`
a mano, así que nunca pasaba por `Citykleta_Settings::tile_layer()` /
`cluster_options()` / `marker_icon_size()` y **ignoraba en silencio la
configuración de mapas que sincroniza Back Office**, mientras el resto de los
mapas del sitio sí la respetaba. Hoy es `Citykleta_Map_Widget` con un control
**Source**: `Query` (bicis que matcheen) o `Bike Data` (la bici de esta página).
Lo mismo pasó con las bicis de un destino: son `Citykleta_Bike_Widget` con
`Source = Destination Bikes`, no una grilla nueva — así el markup de tarjeta y
toda su pestaña Style se comparten en vez de copiarse.

Los shortcodes viejos siguen funcionando: `[citykleta_bike_location]` y
`[citykleta_destination_bikes]` apuntan a esos widgets con el origen ya puesto
(ver `Citykleta_Widget_Shortcodes`).

**Ojo al construir un layout por defecto:** ahí se escribe el literal
(`'source' => 'bike'`), no la constante de la clase del widget. `default_*_layout()`
se alcanza desde `ensure_*_template()` en cualquier petición, incluidas las que
corren antes de que Elementor cargue sus clases de widget — referenciar la
constante ahí es un fatal error, verificado.

**Las piezas NO imprimen el título de su sección.** "Sobre esta bicicleta",
"Ficha técnica" y "Dónde recogerla" son texto **estático** — no cambian de una
bici a otra — así que no tienen por qué vivir dentro de un widget nuestro,
editables solo a través de un control que hubo que escribir. Son **Headings
nativos de Elementor** que el layout por defecto pone delante de la pieza que
les toca (`Citykleta_Template_Post_Type::heading()`, y `heading_block()` para el
respaldo en bloques). Así se estilan, se mueven, se renombran o se borran como
cualquier otro elemento del lienzo.

La regla general que sale de ahí, para cualquier pieza nueva: **el widget del
plugin solo debe resolver lo que es dinámico**. Todo lo que sea texto fijo,
espaciado o decoración es de Elementor. Cuanto menos posea el plugin, más
control tiene quien diseña.

**La dirección de la bici es su propia pieza** (`citykleta_bike_directions`,
"Bike Directions"): era el pie de texto del mini-mapa, y ahí no se podía ni
mover ni estilar aparte. El mapa dibuja el mapa; la dirección es texto.

**Por qué los controles de estilo están escritos a mano y no heredados.** El
Paragraph nativo de Elementor (`e-paragraph`) es un widget **V4 "atómico"**:
declara un *props schema* y saca su panel de estilos de `Atomic_Widget_Base`,
no de `add_control()` con `selectors`. Un widget de terceros no puede heredar
ese panel sin reescribirse sobre esa API, que Elementor todavía marca como
experimental. Por eso los paneles de estas piezas se declaran a la manera
clásica, leídos directo del código fuente del widget nativo correspondiente
(no aproximados) para que un ajuste guardado acá signifique lo mismo que en el
original.

**`citykleta_bike_description` calca el Text Editor nativo entero, menos su
campo de texto** (`elementor/includes/widgets/text-editor.php`):
- **Content**: `Drop Cap` y `Columns`/`Columns Gap` — idénticos, IDs incluidos.
- **Style**: Alignment, Typography, Text Shadow, Paragraph Spacing, y un par
  de pestañas Normal/Hover con Text Color/Link Color (Hover agrega Link Color
  + Transition Duration). Ver `Citykleta_Bike_Part_Widget::register_text_editor_style_section()`.
- **Style → Drop Cap** (solo si `drop_cap = yes`): View (Default/Stacked/
  Framed), Primary/Secondary Color, Text Shadow, Size, Space, Border Radius,
  Border Width, Typography — mismos IDs y condiciones que el original.

**El efecto de la primera letra se hace en PHP, no en JS.** El widget nativo
envuelve la primera letra en `<span class="elementor-drop-cap">…</span>` con
un *frontend handler* de JavaScript (`TextEditor.wrapDropCap()`, corre en
`onInit`/`onElementChange`) porque su texto es un campo WYSIWYG editable en
vivo. Acá el texto ya está resuelto cuando `render()` corre — no hace falta
JS para lograr lo mismo una sola vez — así que
`Citykleta_Bike_Description_Widget::apply_drop_cap()` hace exactamente la
misma regex (`/^ *([^ ] ?)/` sobre el primer `<p>`, mismo guardado si el
contenido empieza con una etiqueta) en el servidor. Ventaja real: funciona
sin JavaScript y no hay que encolar ningún script nuevo. El CSS base de esas
dos clases (`assets/css/single-bike.css`) es una copia línea por línea de
`widget-text-editor.min.css` de Elementor — esa hoja solo se encola para *su*
widget, nunca para el nuestro.

**`citykleta_bike_directions` comparte el panel Style, no el Content.** Es la
dirección de la bici en una sola línea, no un bloque de párrafos, así que
Drop Cap y Columns no aplican — su Content tab sigue siendo el suyo propio
(HTML Tag, Prefix). Lo que comparte con la descripción es
`register_text_editor_style_section()` (Alignment/Typography/Text
Shadow/Paragraph Spacing/colores), un método **estático** que toma el widget
como parámetro en vez de vivir como método de instancia — así una clase
puede llamarlo con `self::` sin que la otra dependa de heredar de ella.

**Gotcha real de esta sesión, para no perseguirlo de nuevo:** instanciar
cualquiera de estos widgets a mano (`new Citykleta_Bike_Description_Widget(['id'=>'x','settings'=>[]], [])`,
fuera de un render de página real o del propio editor de Elementor) y leer
`get_controls()` hace parecer que **la mayoría de los controles con
`selectors` nunca se registraron** — solo sobreviven los que no usan esa
clave (`drop_cap`, `drop_cap_view`). Se investigó a fondo con bisección
(aislando control por control) y **no es un bug del código de este
plugin**: se reproduce igual con un widget mínimo de prueba, sin relación
con Citykleta. Todo apunta a que `get_controls()`/el procesamiento de
`selectors` necesita un Document/CSS-file de Elementor real detrás — algo
que un `new Widget(...)` suelto no tiene, pero que sí existe en cualquier
render de página de verdad. La prueba que importa es la del **render real**
(`curl` a la página, o el editor de Elementor en el navegador), no un script
que instancia el widget a mano — por eso todas las verificaciones de esta
sección se hicieron contra la página real, nunca contra `get_controls()`.

**`citykleta_bike_request` ("Bike Request") tiene pestaña Style propia**, escrita a mano por la
misma razón que la de la descripción (el widget de formulario de Elementor es Pro, y sus controles V4
atómicos no se heredan): Card (fondo, borde, radio, sombra, padding), Form Title, Labels (más
color del texto de ayuda y separación entre campos), Fields, Buttons (tipografía + pestañas
Normal/Hover, borde, radio, padding, sombra, más el botón "Atrás"), Verification Link y Note. Cada
selector apunta a una clase que `bike-request.css` ya define, así que cada control **sobrescribe** un
default real en vez de inventarse uno. El título "Solicitar esta bicicleta" tiene switcher para
apagarlo y ponerlo como Heading nativo, siguiendo la regla general de las piezas.

**Lo que este widget ya NO tiene es "Stick On Scroll".** Existió heredado de cuando la plantilla era
fija en PHP; que la tarjeta se quede pegada al hacer scroll es posicionamiento del **contenedor** que la
envuelve, no del contenido de la pieza. Se quitó el control, la clase y las reglas
`position: sticky` de `single-bike.css`.

**Gotcha de Elementor que costó un switcher roto, y que aplica a cualquier widget del plugin que le
pase sus settings a un helper:** un SWITCHER apagado se guarda como **cadena vacía**, igual que un campo
de texto que nadie llenó. `form_args()` filtraba con un `'' !== $value` (correcto para el texto: vacío
significa "usa el default del formulario"), y eso **borraba** `show_title` cuando estaba en off — después
`array_merge()` en `render()` rellenaba el hueco con el `'yes'` por defecto. El resultado era un switcher
que se movía y no hacía absolutamente nada. Hoy son dos listas separadas: texto (vacío = cae al default)
y switchers (vacío = **es** la decisión, se pasa siempre).

**Tampoco imprime ya el precio** (por eso dejó de llamarse "Bike Price & Request"). El precio es un
dato de la bici como cualquier otro, no una parte del formulario: metido dentro de esta pieza solo podía
estar arriba de la tarjeta, y se estilaba desde una sección Style que existía únicamente para él. Hoy es
`citykleta_bike_data` con `field="price"`, así que se pone donde sea y se estila como cualquier texto.
El layout por defecto ya lo pone encima del formulario, pero **una plantilla que ya existía no se
actualiza sola** (`ensure_template()` nunca pisa un diseño) — hay que arrastrar el widget, o hacer
`Reset` en esa plantilla.

### Bike Data: cualquier dato suelto de la bici

`citykleta_bike_data` ("Bike Data", `[citykleta_bike_data field="…"]`) imprime **un** valor de la bici,
elegido en un desplegable. Es la contraparte de la ficha técnica: aquella imprime la tabla entera, esta
pone un dato donde haga falta — el precio al lado del formulario, la dirección debajo del mapa, las
vistas en una esquina.

Existe porque un dato sin widget propio es un dato que el layout sencillamente **no puede mostrar**, y
escribir una clase por campo serían una docena de clases casi idénticas — el mismo razonamiento que
fusionó los dos widgets de mapa en uno con control de origen. `FIELDS` es la lista: `price`,
`description`, `address`, `owner`, `availability`, `destinations`, `views`, `brand`, `model`, `size`,
`wheel_size`, `electric`, `bike_type`, `bike_usage`. Controles: el campo, etiqueta opcional (con texto
propio o el nombre del campo), etiqueta HTML, y el mismo panel Style compartido
(`register_text_editor_style_section()`) más tipografía/color para la etiqueta.

- **Cada campo lleva dos labels** (`FIELDS` guarda `[admin, front]`): el desplegable de Elementor va en
  inglés por convención de wp-admin, y lo que lee el visitante en español. Es exactamente la lección de
  Bike Specs, donde leer el label de la taxonomía ponía "Bike type" en una tabla que decía "Marca".
- **`availability` y `electric` se comparan contra la cadena vacía**, nunca por truthiness: `"0"` es una
  respuesta real ("no disponible" / "no es eléctrica") y es falsy en PHP. Mismo trampa que ya
  documenta `taxonomy_specs()`.
- **`description` y `owner` no pasan por el control de etiqueta HTML**: la descripción trae sus propios
  `<p>` y el propietario su bloque con foto. El resto sí.
- **`destinations` es la primera pantalla en cualquier lado que muestra la asignación por geocerca** —
  `Citykleta_Destination_Matcher::destinations_for_bike()` la venía escribiendo en cada sync y no tenía
  ningún consumidor hasta ahora.
- Un campo vacío no imprime nada (en el editor deja una nota explicando cuál falta), igual que el resto
  de las piezas.
- Descripción, dirección y propietario **conservan su widget propio**: esos cargan presentación que un
  selector genérico aplanaría (el panel completo de Text Editor con su drop cap, la foto del dueño).
  Acá son el valor pelado, para cuando eso es todo lo que el layout necesita.

**Verificado en vivo** contra una bici real: los 14 campos resuelven (los dos que salieron vacíos,
`views` y `bike_usage`, es porque esa bici no los tiene). Ojo al probar: **no sirve un script que
instancie el widget a mano** — las clases de widget solo se cargan en `elementor/widgets/register`, así
que `class_exists()` da `false` y el shortcode devuelve `''` sin avisar. En un probe hay que forzar ese
registro con `\Elementor\Plugin::$instance->widgets_manager->get_widget_types()`, que es el mismo camino
que toma un render de verdad.

La **ficha técnica** (`citykleta_bike_specs`) tiene columnas responsive
(1–6, más `''` = **Auto**) más separación, estilo de caja para **toda la
tabla** (fondo, borde, radio, sombra, padding — nunca por fila) y
tipografía/color/alineación por separado para etiqueta y valor — una tabla
técnica necesita las dos mitades distintas. También añade la
**categorización** de la bici como filas: `bike_type`, `bike_usage`, y
**"Eléctrica"** (movida acá desde el widget de título/ubicación — ver esa
sección más arriba). **Ojo con esas etiquetas**: se escriben en español a
mano ("Tipo", "Uso") en vez de leerse de `labels->singular_name` de la
taxonomía, porque esas son etiquetas de wp-admin y van en inglés por
convención — leerlas ponía "Bike type" en una tabla que al visitante le dice
"Marca", "Modelo", "Talla".

**"Eléctrica" necesita su propia línea en `taxonomy_specs()`, no
`array_filter()` junto al resto.** `is_electric` guarda `"0"` para "no es
eléctrica" — un dato real, no un campo vacío — pero `"0"` es falsy en PHP, así
que meterlo en el mismo `array_filter()` que usa `render()` para las demás
specs (que sí deben desaparecer cuando están vacías) habría escondido en
silencio el "No" tratándolo como "sin dato". Por eso se compara contra la
cadena vacía explícitamente y se traduce a "Sí"/"No" a mano.

**Columns → Auto**: le da una columna a cada dato que tenga la bici, sin
importar cuántos sean — pensado para cuando la categorización de arriba
suma filas y una cuenta fija de columnas dejaría espacio vacío o cortaría
mal. Elementor no puede resolver esto con una regla CSS fija por instancia
(cuántos specs tiene *esta* bici solo se sabe en `render()`, y cambia de
una bici a otra bajo el mismo ajuste guardado) — por eso `Auto` es la
**opción vacía** del control (Elementor no emite CSS cuando el valor
resuelve vacío) y `render()` llena ese hueco escribiendo
`style="--citykleta-specs-columns: N;"` directo en el `<dl>`, leído por el
`repeat(var(--citykleta-specs-columns, auto-fit), …)` de `single-bike.css`.
Un valor de columnas explícito en cualquier breakpoint nunca pasa por acá:
esa regla vive scoped al widget (dos clases) y gana por especificidad sola.

**El estilo de "Box" va en el contenedor completo (`.citykleta-bike-single__specs`),
nunca en cada fila** — la primera versión lo aplicaba por fila
(`.citykleta-bike-single__spec`) y se veía rota: con las celdas pegadas
(`gap: 0`) el borde de 1px de una fila se sumaba al de su vecina en cada
línea compartida, el doble de grueso que el borde exterior; y las cinco
sombras (una por fila) se apilaban en un halo gris difuso alrededor de toda
la tabla en vez de una sola sombra debajo. Las líneas divisorias entre
celdas ya no son un `border` por celda — son la técnica estándar
"el hueco es la línea": el contenedor tiene `background-color` + `gap: 1px`
+ `padding: 1px`, y cada celda tiene su propio `background-color: #fff`
tapando ese fondo salvo por esa rendija de 1px, que nunca puede duplicarse
sea cual sea la cantidad de filas o columnas. El contenedor lleva
`overflow: hidden` fijo (no solo cuando hay radio puesto) para que ese
mismo Border Radius del panel Box no deje las esquinas cuadradas de las
celdas asomando fuera de la esquina redondeada.

Como consecuencia, `.citykleta-bike-single__section-title` ya no lo imprime
nadie y su regla se borró de `single-bike.css`. Los títulos toman el estilo que
les dé el tema hasta que se estilen en Elementor — es el precio de haber cedido
ese control, no un descuido.

Dos detalles que se repiten del resto del plugin: la altura del mapa se imprime
**inline** (el CSS de `selectors` no existe para un shortcode, y sin altura
explícita Leaflet no dibuja nada), y la descripción se formatea con
`wpautop( do_shortcode() )` en vez de `apply_filters( 'the_content' )` para no
re-entrar en ese filtro estando ya dentro de él.

### Archivo de bicis (grid de tarjetas) — editable como la página "Shop" de WooCommerce

`/bikes/` sigue siendo la URL del archivo del CPT `bike` (nada cambió en el rewrite),
pero **lo que se ve ya no es una plantilla fija**: `templates/archive-bike.php`
(cargado vía `template_include`, mismo patrón de siempre — el tema activo lo puede
sobreescribir con su propio `archive-bike.php`) ahora solo imprime el contenido de
una **Page normal de WordPress**, la que devuelve
`Citykleta_Templates::ensure_bikes_page()` (se crea sola la primera vez que hace
falta, título "Bicicletas (resultados)", su ID vive en la opción
`citykleta_bikes_page_id`; su propia URL nunca la visita nadie, solo se usa su
contenido). Esa Page se edita con **Elementor gratis** — no hace falta Pro, porque
es una Page cualquiera, no una plantilla de tipo de contenido. Exactamente el mismo
mecanismo que usa WooCommerce con su página "Shop": la URL sigue siendo la del tipo
de contenido, pero el contenido que se ve es el de una Page editable.

**La parte dinámica son tres piezas independientes, no un bloque único:**
búsqueda, mapa y grilla — cada una es a la vez un widget de Elementor (ya
existían: `Citykleta_Search_Widget`, `Citykleta_Map_Widget`, `Citykleta_Bike_Widget`)
y un shortcode (`[citykleta_search]`, `[citykleta_map]`, `[citykleta_grid]`,
`Citykleta_Widget_Shortcodes`, `includes/elementor/class-citykleta-widget-shortcodes.php`).
El usuario las mueve, envuelve o reordena por separado en Elementor — se
abandonó a propósito el shortcode combinado que había antes (`[citykleta_bikes]`,
un solo panel con tarjetas/mapa alternables por un botón): ahora el mapa queda
**siempre visible**, sin ese botón, y la grilla es su propia pieza con su propia
paginación.

`Citykleta_Templates::ensure_bikes_page()` pone por defecto:
```
[citykleta_search]
[citykleta_map filters="url" map_height="450"]
[citykleta_grid filters="url"]
```
(migra sola una Page vieja que todavía tuviera el `[citykleta_bikes]` original
sin tocar, ver el propio método — si el usuario ya editó la página, no la toca).

**Cómo un shortcode reusa el `render()` real del widget, sin reimplementarlo:**
`Citykleta_Widget_Shortcodes::render_widget()` instancia la clase del widget
directamente (`new Citykleta_Bike_Widget( ['id' => ..., 'settings' => $atts], [] )`)
y llama a su método público `render_content()` — el mismo que usa el propio
Elementor para pintar un widget guardado de verdad. Cualquier setting que el
shortcode no pase cae al *default* de ese control (igual que un widget guardado
con solo algunos campos personalizados), así que agregar o cambiar un control del
widget funciona automáticamente en su shortcode, sin nada que mantener sincronizado
a mano. **Gotcha de Elementor, no obvio:** `Widget_Base::__construct()` tira una
`Exception` si el segundo argumento (`$args`) es `null` y el primero (`$data`) no
está vacío — hay que pasar `array()`, no `null`, aunque no se use para nada en ese
caso (`Element_Base::__construct()`, `elementor/includes/base/element-base.php`).
Verificado en vivo: sin este arreglo, la página entera daba **Fatal error**.

**Limitación real, no un bug:** los controles de la pestaña **Style** (y en
general cualquier control con `selectors`, esté en Style o no) generan CSS a
través del archivo que Elementor escribe **por página** cuando se guarda en el
editor — eso no existe para un widget instanciado por un shortcode, así que esa
personalización no llega. No importa para esta página (ya usaba el CSS por
defecto del plugin antes de este cambio), pero si alguien usa estos shortcodes en
otro lado esperando ver los colores/tipografía que configuró en un widget
Elementor ya guardado, no los va a ver — solo el look base de
`bike-cards.css`/`search-bar.css`/`citykleta-map.css`. La única excepción que sí
se resolvió: la altura del mapa (`map_height`) se imprime también como
`style="height:...px"` inline en `Citykleta_Map_Widget::render()`, porque sin
altura explícita Leaflet no dibuja nada (0px) — ver el gotcha de siempre en la
sección "Mapa de bicis".

**Grilla y mapa saben responder al filtro de la página** (`filters="url"` en el
shortcode, o el switcher "Filter From Page URL" en el panel de Elementor):
en vez de su ubicación/cantidad fija de siempre, leen
`Citykleta_Templates::request_filters()` — el mismo helper que ya compartían
antes — directo de la URL (`?location=`/`?electric=`), y la grilla además pagina
de verdad (`posts_per_page` propio + `Citykleta_Templates::pagination_links()`,
ver abajo) en vez de pararse en un conteo fijo. Verificado en vivo con
`?location=Vedado`: grilla y mapa mostraron las mismas 8 bicis, ninguna otra.

**Paginación clásica (anterior/siguiente + números), sin JS** — reemplazó la
carga infinita que había antes (`citykleta-infinite-scroll.js`, borrado).
`Citykleta_Templates::pagination_links()` arma esto con `paginate_links()`
usando el patrón documentado para paginar una `WP_Query` propia en vez de la
principal: pide el enlace a una página "imposible"
(`get_pagenum_link( 999999999 )`, que ya arma la URL bonita conservando el
querystring de filtros actual — probado con `?location=Havana`, sigue en cada
número de página) y la convierte en plantilla.

**Se abandonó el diseño anterior de "app-shell"** (la página llenaba la ventana
entera, sin scroll de documento, sin pie de página, con un botón para alternar
tarjetas/mapa — pensada como un showroom a pantalla completa). Ya no tenía
sentido forzarlo desde el plugin: el usuario ahora controla el diseño completo
de esta página, footer incluido, y el mapa siempre visible no necesita ese
botón (`citykleta-view-toggle.js`, borrado). Las reglas de
`assets/css/bike-cards.css` que dependían de esa altura fija se quitaron.

Cada tarjeta sigue siendo solo vista previa: imagen, título, ubicación, precio y
disponibilidad — el resto de los datos van en la página individual de cada bici.

**Pendiente, a propósito, para después:** un cuarto elemento — filtros (marca,
tipo, altura del ciclista, orden…) — también como widget y shortcode. La
barra "Filtros (próximamente)" que había en el shortcode combinado viejo
desapareció junto con él; hoy `Citykleta_Search_Widget` sigue siendo solo
ubicación + fechas + eléctricas, sin más filtros todavía en ningún lado.

**Gotcha:** después de cambiar el `rewrite slug` de un CPT (como pasó al renombrar
`bicicleta` → `bike`), la URL del archivo (`/bikes/`) puede dar 404 hasta que se
haga un flush de rewrite rules — no basta con que el código esté bien. Se puede
forzar visitando Ajustes → Enlaces permanentes en wp-admin (guardar sin cambiar
nada ya lo dispara).

El slug (`bikes` en `/bikes/`) es editable en Bicicletas → Settings → Sync (opción
`citykleta_bike_slug`). Cambiarlo mueve la URL de **todas** las bicicletas. El
flush no puede pasar en la misma petición donde se guarda el cambio (el CPT de esa
petición ya se registró con el slug viejo, antes de que options.php actualice la
opción) — por eso se usa un flag (`citykleta_flush_rewrite_flag`) que se revisa en
`Citykleta_Bike_Post_Type::register()` y dispara el flush en la siguiente carga,
justo después de registrar el CPT con el slug ya actualizado.

**Gotcha grande:** `get_header()`/`get_footer()` **no funcionan bien con temas de
bloques (FSE)** como Twenty Twenty-Five — como esos temas no tienen `header.php`,
WordPress cae en un header de compatibilidad genérico de hace años, sin relación
con el diseño real del tema (se nota hasta en cosas como dónde aparece la barra de
admin). La solución en `archive-bike.php`: detectar con `wp_is_block_theme()` y, si
es un tema de bloques, armar el documento a mano (doctype/head/body) y pedir el
header/footer reales con `block_template_part( 'header' )` / `block_template_part( 'footer' )`
en vez de `get_header()`/`get_footer()`.

### Widget de Elementor

El sitio de producción real usa **Hello Elementor + Elementor Pro** (no un tema de
bloques), así que en vez de pelear con `template_include` para que el archivo se vea
"igual" al tema, se agregó un **widget de Elementor** (`Citykleta_Bike_Widget`) que
el usuario arrastra dentro de cualquier página armada con Elementor — el
header/footer/tema los sigue manejando Elementor normalmente, el plugin solo aporta
el grid de bicis como contenido. No hace falta Elementor Pro para esto: los widgets
propios se registran con la versión gratuita, Pro solo trae widgets adicionales ya
hechos por ellos. En este LocalWP solo está instalado Elementor gratis (sin Pro, por
costo) — el widget en sí funciona igual, lo que puede no verse igual es el
header/footer si en producción están armados con el Theme Builder de Pro.

Controles del widget en la pestaña Content (secciones inspiradas en el widget nativo
"Posts" de Elementor, mismos nombres que usa Elementor para que se sienta familiar):
**Contenido** (cantidad — sin filtro de disponibilidad: una bici no disponible hoy
puede reservarse para más adelante, así que no hay razón para ocultarla ni para
marcarla con un badge), **Query** (ubicación por coincidencia parcial, ordenar por
fecha/precio/nombre, ascendente/descendente) y **Layout** (columnas, **Masonry**
— grid tipo Pinterest con `column-count` en vez de `grid-template-columns`, cada
tarjeta conserva su alto natural en lugar de estirarse a la fila más alta —,
etiqueta HTML del título, mostrar/ocultar ubicación, precio y foto del propietario,
**Image Position** — Top/Left/Right/None, mismo control que Elementor Posts; con
None no se imprime ni el contenedor de imagen ni la foto del propietario, que se
superpone sobre ella — resolución de imagen y proporción de imagen, esta última
solo aplica con Top: en Left/Right el alto de la imagen lo da la fila, no el
control).

Los widgets del plugin aparecen agrupados bajo su propia categoría **"Shared Bikes"**
en el panel de Elementor (no en "General"), registrada en
`Citykleta_Elementor::register_category()`. Cualquier widget nuevo del plugin debe
usar `get_categories() { return array( 'citykleta' ); }` para aparecer ahí también.

El widget también tiene pestaña **Style** con cuatro secciones — Diseño (espacio
entre tarjetas), Tarjeta (color/padding vertical y horizontal/borde/esquinas por
lado/sombra), Contenido (color, tipografía y espaciado propios para título, ubicación
y precio, más colores de las etiquetas de disponibilidad) y Foto del propietario
(tamaño, posición izquierda/centro/derecha, separación del borde) — todo vía el
mecanismo `selectors`/`selectors_dictionary` de Elementor, sin tocar `render()`.

**Nota técnica:** posición y separación del avatar del propietario se combinan con
una variable CSS (`--citykleta-owner-offset`, definida por el control de padding y
consumida por el `selectors_dictionary` de posición) porque Elementor no permite que
un control de tipo CHOOSE incluya el valor de otro control dentro de su
`selectors_dictionary` directamente.

**Gotcha:** encolar el CSS del widget (`wp_enqueue_style`) **dentro de `render()`**
funciona en una carga de página normal, pero no dentro del editor de Elementor — ahí
el widget se vuelve a dibujar por AJAX después de que el `<head>` ya se imprimió, así
que el `<link>` nunca sale y el widget se ve sin estilos (hasta los links se ven
azules/subrayados, el estilo por defecto del navegador). Solución: encolar el CSS
desde temprano en `wp_enqueue_scripts` (ver `Citykleta_Elementor::enqueue_styles()`),
no dentro de `render()`.

### Widget de búsqueda

`Citykleta_Search_Widget` es una barra de búsqueda (ubicación, fechas, cantidad,
eléctricas, botón) que hace un GET al archivo de bicis. Los filtros de **ubicación**
y **eléctricas** funcionan de verdad — `Citykleta_Templates::apply_search_filters()`,
enganchado a `pre_get_posts`,
aplica un `meta_query` LIKE sobre `location` cuando llega `?location=` en
la URL. **Fechas y cantidad no filtran nada** — no existe todavía un sistema de
disponibilidad/reservas por fecha en los datos — solo se capturan como
`from`, `to` y `quantity` en la URL, listos
para conectar el día que exista ese sistema. `archive-bike.php` muestra un aviso
indicándolo si esos parámetros llegan sin uso.

El checkbox de **eléctricas** viene marcado por defecto (se muestran todas). Un
checkbox HTML sin marcar simplemente no se manda en el GET, así que no hay forma de
distinguir "sin marcar" de "el formulario nunca se envió" solo con `isset()`. Por
eso el HTML manda un input oculto con `value="0"` justo antes del checkbox, mismo
`name` — si el checkbox queda sin marcar, ese "0" es el único valor que llega; si
está marcado, el "1" del checkbox lo pisa. Solo se filtra a solo convencionales
cuando llega exactamente `"0"`.

Las fechas usan **Flatpickr** (librería JS externa, ~7KB, sin dependencias) en modo
rango con vista de dos meses, en vez de dos `<input type="date">` nativos —
reemplaza la decisión anterior de "nativo, sin JS propio" porque hacía falta el
look de calendario de rango tipo Airbnb. El campo visible es de solo lectura;
Flatpickr llena dos inputs ocultos (`from`/`to`, formato
`Y-m-d`) que son los que de verdad viajan en el formulario. Archivos:
- `assets/js/flatpickr.min.js` + `assets/js/flatpickr-es.js` (locale) — de
  cdn.jsdelivr.net/npm/flatpickr@4, **no están en el repo** (hay que descargarlos a
  mano, ver abajo), `Citykleta_Elementor::enqueue_scripts()` no los encola si no
  existen (`file_exists()`), así que el resto del sitio no se rompe si faltan.
- `assets/js/citykleta-search-widget.js` — los inicializa en modo rango sobre
  `.citykleta-search-bar__daterange`, incluyendo instancias nuevas que Elementor
  dibuje por AJAX en el editor (hook `frontend/element_ready/citykleta_search.default`).
- `assets/css/flatpickr.min.css` (de la misma CDN, tampoco en el repo) +
  `assets/css/search-bar-datepicker.css` (nuestro, sí está en el repo) — repinta el
  calendario con los colores de marca en vez del estilo genérico de la librería.

Para descargar los archivos que faltan:
`https://cdn.jsdelivr.net/npm/flatpickr@4/dist/flatpickr.min.js` → `assets/js/flatpickr.min.js`,
`https://cdn.jsdelivr.net/npm/flatpickr@4/dist/flatpickr.min.css` → `assets/css/flatpickr.min.css`,
`https://cdn.jsdelivr.net/npm/flatpickr@4/dist/l10n/es.js` → `assets/js/flatpickr-es.js`.

Son opcionales (con su propio switcher): el campo de cantidad, las etiquetas de
texto (Dónde/Fechas/Cantidad, todas juntas con un switcher `show_labels`) y el
ícono de búsqueda. El ícono usa el control `ICONS` nativo de Elementor
(`Icons_Manager::render_icon()`), así que el usuario puede elegir cualquier ícono de
Font Awesome o subir un SVG propio, no está fijo al de lupa.

**Gotcha:** Hello Elementor trae una regla CSS global del tipo
`input[type="date"], input[type="text"], ... { border: 1px solid #666; ... }`. Un
selector de atributo como `input[type="date"]` pesa más en especificidad CSS que una
sola clase (`.citykleta-search-bar__input`), así que "border: none" con solo esa
clase no le ganaba — se veía un doble borde (el nuestro + el del tema). Solución:
encadenar dos clases (`.citykleta-search-bar .citykleta-search-bar__input`) para
subir la especificidad sin necesitar `!important`.

### Mapa de bicis (widget de Elementor + página de wp-admin)

`Citykleta_Bike_Map_Data::query()` (`includes/map/`) es el único lugar que arma
el `WP_Query` de bicis con coordenadas (mismo patrón de `meta_query` que
`Citykleta_Bike_Widget`, más `lat`/`lng` con `compare => 'EXISTS'` — bicis
sincronizadas antes de que existiera este campo, o cuyo origen no traía
`coordinates`, simplemente no aparecen hasta la siguiente sincronización) y
devuelve el array listo para `wp_json_encode()`. Dos consumidores lo usan:

- **`Citykleta_Map_Widget`** (Elementor) — filtra por cantidad/disponibilidad/
  ubicación según los controles del widget, pensado para incrustarse en una
  página pública.
- **`Citykleta_Admin_Map`** (`includes/admin/`) — fusionado (2026) dentro de la
  lista "All Bikes" en vez de ser su propia página: se dibuja justo debajo del
  título "Bikes", enganchado a `admin_notices` (mismo patrón de bajo riesgo
  que usa `Citykleta_Bike_Meta::render_sync_warning()` para inyectar contenido
  en una pantalla específica sin tocar el `WP_List_Table` del core), filtrado
  por `get_current_screen()` a la pantalla de lista del CPT `bike`.
  **Gotcha de posición:** lo que imprime `admin_notices` en `edit.php` sale
  *antes* del `<h1>` propio de la página en el HTML (`admin-header.php`, donde
  se dispara ese hook, se incluye antes de que `edit.php` imprima su propio
  `<div class="wrap"><h1>`) — así que sin más, el bloque aparecería arriba del
  título, no debajo. El JS del propio core (`common.js`) reubica cualquier
  `div.notice` (que no sea `.inline`) justo después de `.wp-header-end` (que
  queda pegado al `<h1>`) al cargar la página — por eso el wrapper del mapa
  lleva la clase `notice` a propósito, solo para activar esa reubicación, con
  su estilo de caja por defecto (borde/fondo/padding) anulado inline para que
  no se vea como un aviso descartable.
  Sin filtros (`count => -1`, todas las bicis),
  con un aviso si hay bicis sin coordenadas (`count_all()` vs. las que sí
  tienen lat/lng). Pensada para que el equipo vea de un vistazo dónde están
  las bicis sin salir del admin — por eso `centerOnUser` va en `false` acá
  (tiene sentido centrar en el visitante en una página pública, no en quien
  esté mirando el dashboard), y el mapa se apoya solo en `fitBounds()`.
  **Se puede ocultar por usuario** desde el panel "Screen Options" de esa
  misma lista (el desplegable con "Columns"/"Pagination"/"View mode"): un
  checkbox "Show bikes map above the list" agregado vía el filtro
  `screen_settings` de `WP_Screen`, guardado como user meta
  (`citykleta_admin_map_visible`) al enviar ese formulario. El guardado se
  engancha a `admin_init` en vez de comprobar la pantalla actual ahí mismo,
  porque `get_current_screen()` todavía no es confiable en `admin_init` (el
  core llama a `set_current_screen()` *después* de disparar `admin_init`, ver
  `wp-admin/admin.php`) — por eso en vez de confiar en la pantalla, se
  comprueba la presencia de un campo oculto marcador que solo existe cuando
  el formulario enviado fue el panel de Screen Options de esta lista.

Ambos imprimen el mismo `<div class="citykleta-map" data-bikes="…" data-options="…">`
sin script inline por instancia — la inicialización la hace citykleta-map.js.

`assets/js/citykleta-map.js` es el módulo reusable (`window.CityKletaMap`):
recibe un array de bicis + opciones y las dibuja en un mapa **Leaflet** con
**Leaflet.markercluster** agrupándolas en círculos. Además de la API directa
(`CityKletaMap.init( container, bikes, options )`), el propio script escanea la
página buscando `.citykleta-map[data-bikes]` y se auto-inicializa — mismo patrón
que `citykleta-search-widget.js` usa para Flatpickr/Tom Select, incluyendo el hook
`frontend/element_ready/citykleta_map.default` para cuando Elementor redibuja el
widget por AJAX en el editor. A diferencia de esos campos, acá cada
reinicialización sí puede pisar una instancia ya existente (Leaflet tira
"Map container is already initialized" si `L.map()` corre dos veces sobre el mismo
nodo) — `init()` guarda la instancia en `el._citykletaMap` y la remueve con
`.remove()` antes de crear una nueva. Para no reiniciar mapas que no cambiaron
(y perder su zoom/paneo) en cada evento `element_ready`, se guarda una "firma"
(los JSON de `data-bikes`/`data-options` concatenados) en el dataset del
contenedor y se compara antes de reinicializar.

Cada bici se dibuja como un círculo sólido — mismo lenguaje visual que las burbujas de
cluster — con el glyph de bici recortado vía CSS `mask-image` sobre
`assets/images/marker-bike.svg` centrado adentro (`.citykleta-map__bike-icon-circle` +
`.citykleta-map__bike-icon-glyph`, anidados, en `citykleta-map.css`). Ni el ícono ni sus
colores son configurables: el color del círculo es fijo por disponibilidad (amarillo
`#FED94C` si `available`, gris `#E5E0D3` si no) y el glyph siempre se pinta de un gris
oscuro fijo (`#151b1d`) elegido para leerse bien sobre los dos. **`marker-bike.svg` NO
viene con el plugin** (a diferencia de otros vendor assets, acá no hay una URL fija para
descargarlo — hay que conseguir/dibujar un SVG de bici propio, un solo `<path>` con
relleno sólido y sin degradados, y ponerlo en esa ruta); mientras no exista, la máscara
no tiene nada que mostrar, pero el círculo de fondo sigue siendo visible (solo falta el
glyph adentro) — una degradación más suave que dejar el marcador completamente
invisible.

Esto reemplazó una versión anterior con ícono personalizable vía Media Library y colores
Available/Unavailable configurables en Settings → Maps → "Marker design" — se simplificó
a un círculo fijo para no mantener dos rutas de estilo (ícono por defecto recoloreable
vs. imagen arbitraria que no se puede recolorear con CSS).

Si `center_on_user` está activo (default), el módulo pide geolocalización al
navegador (`navigator.geolocation`) y, si el visitante la concede, centra el mapa
ahí y agrega un punto azul pulsante ("tú estás aquí" — se dejó fuera de la paleta
amarilla a propósito, para no confundirlo con una bici). Si la rechaza, no está
disponible, o pasan más de 8 segundos, el mapa se queda con la vista que ya se
calculó con `fitBounds()` sobre las bicis (o el centro de La Habana Vieja si no
hay ninguna bici válida).

**Gotcha:** el contenedor del mapa necesita una altura explícita — Leaflet no
dibuja nada (tiles en blanco) si el `<div>` mide 0px de alto porque solo tiene
`width`. `citykleta-map.css` pone `height: 400px` por defecto; el control "Map
Height" del widget lo sobreescribe vía `selectors` (en `Citykleta_Admin_Map` es
un `style="height:400px;width:100%"` fijo en el propio HTML, no hay control que
lo cambie — a todo el ancho de la lista de bicis).

`Citykleta_Admin_Map::enqueue_assets()` está enganchado a `admin_enqueue_scripts`
(que corre en **cada** pantalla de wp-admin) pero corta temprano comparando
`get_current_screen()` contra la pantalla de lista del CPT `bike` — así Leaflet
no se carga de más en el resto del admin, solo en Bicicletas → All Bikes.

Para descargar los archivos que faltan:
`https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js` → `assets/js/leaflet.js`,
`https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css` → `assets/css/leaflet.css`,
`https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js` → `assets/js/leaflet.markercluster.js`,
`https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.css` → `assets/css/MarkerCluster.css`,
`https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css` → `assets/css/MarkerCluster.Default.css`.

## Muro de descarga de la app (Subscribers → HubSpot)

Una de las dos conversiones del sitio es: **muro de sign-up (correo + nombre +
consentimiento) → botón de descarga de la app**. Reemplazó al viejo "Contactar por
WhatsApp" (el botón ya se quitó de `single-bike.php`; la pestaña Communication de
Settings se conserva). De qué bici salió una descarga **no** se rastrea a propósito —
las inscripciones y descargas son globales, solo las *vistas* son por bici (ver
`Citykleta_View_Stats`).

**La otra conversión es la solicitud de una bici concreta** (ver "Solicitudes de
bicicleta" más abajo), y las dos compiten por el mismo visitante: quien solicita por
web ya no necesita instalar la app. Fue una decisión consciente, no un accidente —
pero conviene mirar los dos números juntos antes de empujar más una que la otra. A
diferencia de la descarga, la solicitud **sí** es por bici, y su consentimiento de
marketing es opcional y aparte.

`Citykleta_App_Cta` registra el shortcode `[citykleta_app_download]` (colócalo donde
sea — página, widget, Elementor). Las URLs de tienda van por atributos, así no hace
falta una sección de Settings todavía:
`[citykleta_app_download ios="https://apps.apple.com/…" android="https://play.google.com/…" button="Descargar la app"]`.
Si no se pasa ninguna URL, el shortcode no renderiza nada (en vez de un botón muerto).
Flujo en `assets/js/citykleta-app-cta.js`: botón → formulario → tiendas; el sign-up se
captura por REST y el clic en una tienda reporta la descarga de ese lead. Un visitante
que ya se inscribió (recordado en `localStorage`) salta del botón directo a las tiendas,
y la descarga se reporta una sola vez por navegador. El formulario trae un honeypot
(campo `website` oculto) contra bots.

`Citykleta_Subscriber` es un **CPT privado** `citykleta_subscriber` (no público, sin
front-end, sin REST — el correo es dato personal y solo se ve en wp-admin), listado como
submenú "Subscribers" bajo "Shared Bikes". Campos (meta interno): correo (identidad
única — un sign-up repetido actualiza el registro, no duplica la persona),
consentimiento + timestamp (la base legal que le importa a HubSpot, **no** un correo
verificado), `source`, estado de sync (`pending` → `synced_to_hubspot`) y `downloaded_at`
(mide el paso sign-up → descarga por persona). Columnas y caja de detalle de solo lectura
en wp-admin.

**WP es solo staging; la fuente de verdad de leads es HubSpot** (igual que la app lo es
de las bicis). Por eso WP **nunca crea cuentas** (no `WP_User`) ni **manda correo de
verificación** — el doble opt-in lo hace HubSpot, que tiene mejor entregabilidad (el
envío desde WP en local/Cuba es poco fiable) y en el free es sensible a los rebotes. WP
solo valida el correo con `is_email()`, guarda el consentimiento y marca `pending`. La
sincronización a HubSpot vive en un **plugin aparte** (`citykleta-hubspot-crm-connector`,
carpeta hermana de este plugin), no en este plugin, porque HubSpot es un CRM de toda la
empresa, no algo específico de bicis.

**Contrato con ese conector (lo único que este plugin expone hacia HubSpot):**
- `Citykleta_Subscriber_Rest::capture()` dispara la action `citykleta_subscriber_captured`
  (`$post_id`, `$email`, `$name`, `$source`) tras guardar el lead. Es el único punto de
  acoplamiento; este plugin no sabe que HubSpot existe. La action también se dispara en
  re-inscripciones (el push del conector es idempotente, create-or-update por email).
- `Citykleta_Subscriber` es el dueño de los datos del lead, así que el conector nunca
  toca las meta keys directo: se las pide por métodos estáticos — `get_data()`,
  `get_pending_ids()` (reintentos por cron), `get_synced_before()` (purga), `mark_synced()`
  (setea `STATUS_SYNCED` + el nuevo `META_SYNCED_AT`) y `delete()` (borrado real, sin
  papelera). Regla: si el conector necesita otro dato del suscriptor, se agrega un método
  acá, no un `get_post_meta()` con la key hardcodeada del otro lado.

El conector es **push-only** (WP → HubSpot, nunca al revés), tiempo real con
auto-reparación (push inmediato en la action; si falla, el lead queda `pending` y un
WP-Cron cada 15 min reintenta y sincroniza el backlog viejo), auth por Private App token,
y una purga opcional configurable (30/60/90 días / nunca) que solo borra los ya
sincronizados. Ver el propio plugin para el detalle.

`Citykleta_Subscriber_Rest` expone dos rutas públicas (mismo criterio que `Citykleta_View_Stats`:
un visitante anónimo no puede firmar un nonce, así que se valida el payload):
`POST /wp-json/citykleta/v1/subscribers` (captura: exige correo válido y consentimiento,
descarta el honeypot) y `POST /wp-json/citykleta/v1/subscribers/{id}/download` (marca
`downloaded_at` una sola vez). El abuso máximo es inflar un lead basura o el conteo de
descargas — agregar rate-limit por IP si algún día importa.

## Solicitudes de bicicleta

La página individual tiene un formulario **"Solicitar esta bicicleta"** (periodo de renta, código
de verificación, mensaje opcional). Reemplazó la nota *"La reserva se hace desde la app"* que había
en `single-bike.php`.

**Ya no pide nombre ni correo/teléfono (2026-09).** El código de verificación es el identificador
único de la persona dentro de la plataforma — quien ya se verificó tiene un nombre y un contacto en
algún sitio, así que preguntárselos de nuevo en este formulario era pedir dos veces lo mismo. Ver
`Citykleta_Bike_Request_Rest::resolve_identity()` para cómo (y cuánto de) eso se resuelve hoy.

**Es una SOLICITUD, no una reserva, y la distinción es dura, no un matiz de redacción:**

- `/reservations` y `/orders` de `citykleta-sharing-api` están detrás del `verifyAuth` global y
  responden **401** sin sesión de staff (verificado). WordPress **no puede crear una reserva** en la
  fuente de verdad. Ojo: eso vale para *reservar*, no para todo — hay más rutas públicas de las que
  este documento decía (`/contact/reveal` entre ellas, ver "El contacto del dueño" más abajo).
- **No existe disponibilidad por fecha en ningún lado.** El meta `available` es un booleano suelto
  derivado de `status === 'active'`. Dos personas pueden solicitar la misma bici el mismo día: ambas
  solicitudes entran y una persona resuelve. Inventar un sistema de reservas encima de datos que no
  lo soportan sería peor que no tenerlo.
- **No hay, ni va a haber, pago procesado por esta plataforma.** Citykleta no cobra pagos
  internacionales ni locales — decisión explícita del producto, no una limitación técnica temporal. El
  MVP termina en la solicitud: le llega al dueño y **él** coordina el resto, cobro en efectivo incluido,
  por su cuenta. Por eso el formulario **no** tiene talla/cantidad, hora de recogida/entrega, monto de
  seña, ni logos de método de pago (PayPal/Zelle/etc.) — aunque una referencia de diseño los muestre,
  no corresponden a como funciona (ni va a funcionar) el producto.

Por eso WP hace de **staging**, el mismo papel que ya juega con los leads (ver `Citykleta_Subscriber`,
del que este CPT está calcado): captura la intención, guarda el registro, y alguien confirma a mano.

**Diseño de la página (patrón ListNRide)** — dos columnas desde arriba: a la izquierda, la
galería (un carrusel de una foto a la vez, deslizable — motor **Splide**, ver más abajo;
flechas y puntos propios solo se imprimen con más de una foto, ver la sección "Imágenes" más
abajo) seguida del título, ficha técnica y "Dónde recogerla" (mini-mapa con la ubicación real
de la bici, mismo módulo que el widget Bikes Map — ver `Citykleta_Bike_Map_Data::for_bike()`);
a la derecha, la tarjeta de solicitud, pegada al borde superior de la galería y pegajosa al
hacer scroll. Colapsa a una columna en pantallas angostas. Ver `templates/single-bike.php` y
`assets/css/single-bike.css`.

**La galería usa Splide** (`assets/js/splide.min.js` + `assets/css/splide.min.css`), no un
carrusel hecho a mano — mismo criterio que Flatpickr/Leaflet en el resto del plugin: la
librería resuelve el mecanismo real (touch, drag, transiciones), y solo se hace a mano lo que
es decisión de diseño propia. `assets/js/citykleta-bike-gallery.js` monta Splide con
`arrows: false, pagination: false` y conecta los botones/puntos **propios** del plugin (los
`<button>` que imprime `templates/single-bike.php`, con el look de esta marca) a su API
(`splide.go()`) — Splide no dibuja los suyos. La estructura del HTML es la que Splide exige
para "static HTML" (`.splide > .splide__track > .splide__list > .splide__slide*`), así que sin
JS igual se ve la foto (una etiqueta `<img>` normal dentro de una lista), solo no desliza.
**No viene con el plugin** (mismo criterio de vendor que Flatpickr/Leaflet — no está pensado
para vivir en el repo, ver la nota de conexión cara/intermitente en Cuba al principio de este
documento): `Citykleta_Templates::enqueue_styles()` la encola con `file_exists()`, así que un
checkout sin el vendor sigue mostrando la foto, solo sin el carrusel. Para descargar:
`https://cdn.jsdelivr.net/npm/@splidejs/splide@4/dist/js/splide.min.js` → `assets/js/splide.min.js`,
`https://cdn.jsdelivr.net/npm/@splidejs/splide@4/dist/css/splide.min.css` → `assets/css/splide.min.css`.

- **`Citykleta_Bike_Request`** — CPT privado `citykleta_bike_req` (no público, sin front-end, sin REST:
  el contacto del solicitante es dato personal). Guarda la bici (post ID **y** `external_id`, más el
  título como *snapshot* — el sync puede cambiarlo y la solicitud debe seguir diciendo qué pidió la
  persona), contacto, fechas, mensaje y un estado editable (`new` → `contacted` → `confirmed`/`closed`).
  **Nunca deduplica**: la misma persona pidiendo dos veces son dos solicitudes reales, a diferencia de
  un sign-up repetido que sí hace upsert.
- **`Citykleta_Bike_Request_Rest`** — `POST /citykleta/v1/bike-requests`, público con el mismo criterio
  que el muro y el contador de vistas (un visitante anónimo no puede firmar un nonce, así que se valida
  el payload). Honeypot `website`. La bici tiene que ser un post `bike` **publicado** — el id viaja en
  el HTML y nada impide mandar un número cualquiera.
- **El formulario va en dos pasos, dentro del mismo `<form>`.** Paso 1 = lo de la bici (periodo de
  renta + código de verificación); paso 2 = un mensaje opcional. No es cosmético: el paso 1 es lo que
  vive dentro de la tarjeta de precio, al lado de la bici. **Los pasos son paneles, no peticiones**: no
  se manda nada hasta el submit del paso 2, así que sigue habiendo un solo POST por solicitud.
- **El periodo de renta es el mismo selector que el del buscador**, no otro calendario parecido.
  `initRange()` en `citykleta-search-widget.js` dejó de conocer las clases de la barra de búsqueda: hoy
  el contrato son atributos — el contenedor lleva `data-citykleta-range`, los dos inputs ocultos
  `data-citykleta-date="from"/"to"`, y el input visible la clase `.citykleta-daterange`. Los dos
  componentes imprimen eso y comparten una única implementación.
  - **Gotcha de Flatpickr que esto destapó:** con `altInput: true`, Flatpickr arma la clase del input
    visible que crea como *"las clases del input original" + "form-control input"* — o sea que el clon
    se llevaba también `.citykleta-daterange` y la siguiente pasada de `initAll()` (el hook
    `element_ready` del editor, por ejemplo) le montaba un segundo calendario encima. Por eso ahora se
    le pasa un `altInputClass` explícito: las clases de estilo sin la que dispara la inicialización.
  - **Si Flatpickr no está descargado** (es vendor, no viaja en el repo), el campo cae a los dos
    `<input type="date">` nativos de siempre — `Citykleta_Bike_Request_Form::has_range_picker()`. Una
    solicitud no puede perder sus fechas en silencio por un archivo que falta. Y el formulario encola
    Flatpickr él mismo en vez de confiar en `Citykleta_Elementor`: se puede soltar en una página que no
    tenga ningún buscador.
- **Código de verificación: hoy se captura sin validar — pero la ruta para validarlo YA EXISTE.**
  El paso 1 pide un código y ofrece un enlace "Verificarme ahora" (URL configurable en el widget, abre
  en pestaña nueva con `rel="noopener noreferrer"` para que el formulario a medio llenar siga ahí al
  volver). Se guarda tal cual se escribió (`Citykleta_Bike_Request::META_VERIFICATION_CODE`, visible en
  la ficha de la solicitud) para que lo revise quien la atiende.

  **Corrección (2026-09): este documento afirmaba que `citykleta-sharing-api` "no expone ninguna ruta
  pública para verificar a una persona". Es falso** — existe
  **`POST /contact/reveal`**, montada *antes* del `verifyAuth` global y con este plugin como consumidor
  previsto de primera clase (ver la sección siguiente). Ese código es precisamente lo que consume:
  `identity_verification.contact_code`. Conectarlo cierra el círculo entero — validar el código y
  obtener el teléfono del dueño son **la misma llamada**.
- **`Citykleta_Bike_Request_Rest::resolve_identity( $bike_id, $code )` es donde ese lookup entraría
  (2026-09).** Hoy devuelve siempre `{name:'', contact:'', contact_type:''}` — sin campo de nombre ni
  contacto en el formulario, y sin la llave del plugin (`x-citykleta-plugin-key`) guardada en Settings,
  no hay de dónde sacarlos. Expone el filtro `citykleta_resolve_request_identity` (mismo patrón de seam
  que `citykleta_bike_request_captured`) para que la llamada real a `/contact/reveal` se conecte sin
  tocar la ruta REST otra vez. **Ojo:** esa ruta solo devuelve el *nombre* del solicitante — el teléfono
  que trae es el del **dueño**, no el del solicitante — así que conectarla resuelve la identidad pero
  todavía no da un correo/teléfono al que responderle; sigue sin existir ese dato en ningún lado hasta
  que la app lo exponga.
- **`citykleta_bike_request_captured`** (`$post_id`, `$bike_id`, `$data`) es el punto de acoplamiento,
  igual que `citykleta_subscriber_captured` lo es para HubSpot. `Citykleta_Bike_Request_Notifier` se
  engancha ahí para los correos, y es donde entraría el push de la Fase 2.

### El contacto del dueño: no viaja en el catálogo, pero SÍ hay cómo pedirlo

Dos cosas distintas, y confundirlas costó una función entera de más (ver la corrección al final):

**1. El catálogo no lo trae, y está bien así.** `GET /bikesPublic/sync` manda el dueño como
**`{ name, verified }`** y nada más — sin teléfono, sin correo, sin foto (comprobado en las 68 bicis
del catálogo real). No es un olvido: ese endpoint es público y sin autenticación, y publicar ahí los
teléfonos los haría raspables en bloque.

**Ojo al leer el código:** `Citykleta_Bike_Sync::upsert_bike()` lee `$item['owner']['phone']` y
`['photo']`, pero esas claves **nunca llegan**, así que `owner_phone` y `owner_photo` están vacíos en
todas las bicis. (Es la razón por la que el botón de WhatsApp se quitó de `single-bike.php`: no tenía
número al que apuntar.)

**2. Pero existe una ruta hecha exactamente para esto — `POST /contact/reveal`.**

```
POST http://localhost:8001/contact/reveal
x-citykleta-plugin-key: <WORDPRESS_PLUGIN_KEY>     ← ya configurada en el .env del backend
{ "contact_code": "…", "bike_id": 12 }

→ { ok, requester: { name, verified_at },
    bike: { id, name },
    owner: { name, phone } }
```

- Montada **antes** del `verifyAuth` global, con el middleware **`requireAppOrPlugin`**: acepta la
  llave compartida en el header `x-citykleta-plugin-key`, o una sesión de usuario de la app. El
  "Plugin" de ese nombre es **este plugin** — es un consumidor previsto de primera clase, no un
  aprovechamiento.
- El `contact_code` es el de una `identity_verification` con `status = 'verified'`; un código de una
  cuenta cerrada deja de servir.
- **Límite por dueño, no por bici** (cinco anuncios de la misma persona son un solo teléfono), con
  ventana configurable, y cada llamada deja fila en `contact_reveal` como auditoría — entrega un
  teléfono sin sesión de por medio, así que queda registro de quién pidió qué.

Eso es **el mismo código** que el formulario ya le pide al visitante en el paso 1. Conectarlo cierra
el círculo completo: validar el código y obtener el teléfono del dueño son **una sola llamada**.

**Corrección (2026-09), por si alguien lee código viejo:** este documento afirmaba que WordPress "no
tiene ni puede tener" un canal hacia el dueño, y sobre esa premisa se construyó
**`Citykleta_Owner_Contact`** — una caja en la pantalla de la bici para escribir a mano un correo o
teléfono, en su propio meta `_citykleta_owner_contact` (nunca en `owner_phone`, que es campo
sincronizado y lo pisaría el día que la API mande `phone`). **La premisa era falsa.** La caja sigue
siendo útil como respaldo (un dueño sin verificación aprobada, o un visitante sin código), pero
**no es el camino principal** — el camino principal es `/contact/reveal`, y está sin conectar.

Mientras tanto: con un **correo** el dueño recibe aviso automático; con un **teléfono** no hay nada que
mandar solo (WhatsApp no da API de envío a este plugin), así que la ficha de la solicitud ofrece un
enlace `wa.me` prellenado con la plantilla de Settings → Communication, para que una persona lo apriete.

### Correos

`Citykleta_Bike_Request_Notifier` manda dos: uno a Citykleta (`citykleta_request_notify_email`, nueva
sección de la pestaña **Communication**; si está vacío cae al `admin_email`) y otro al dueño cuando su
contacto es un correo. **El correo es best-effort**: `wp_mail()` desde LocalWP o desde un host cubano
falla a menudo, así que la solicitud se guarda **antes** de intentar nada, y `META_OWNER_NOTIFIED_AT`
sólo se estampa si `wp_mail()` devolvió `true` — la pantalla de wp-admin no puede decir "se le avisó al
dueño" cuando no salió nada del servidor. El canal fiable es wp-admin; el correo es la comodidad encima.

### Fase 2 (pendiente, necesita el backend)

Un `POST /bikesPublic/requests` público en `citykleta-sharing-api` (mismo criterio que
`/bikesPublic/sync`) que reciba la solicitud y **notifique al dueño desde el backend**, que sí conoce su
contacto. WP pasaría de dueño del dato a proxy, y `citykleta_bike_req` quedaría como staging con su
estado de sync — exactamente el patrón Subscriber → HubSpot. Mientras tanto, el contacto manual de
`Citykleta_Owner_Contact` es el puente.

## Destinos

Páginas por lugar (ej. "Vedado", "Playa") creadas a mano en wp-admin, cada una mostrando las bicis
disponibles ahí. `destination` es un **custom post type**, no una taxonomía — se probó como taxonomía
primero y se descartó: un destino necesita un editor de verdad (descripción con bloques, no la
descripción de una línea de un término), imagen destacada, y varios campos propios (geocerca, FAQ), que
es justo lo que un CPT + meta box da gratis y una pantalla de término no. Nace bajo el mismo menú
"Bicicletas" que las bicis (`show_in_menu` apunta al CPT `bike`), no como entrada propia de nivel
superior.

Los **destinos** se crean a mano en wp-admin (Bicicletas → Destinations → Add New), como cualquier post
normal (título = nombre, contenido = descripción, imagen destacada opcional). La **asignación** bici →
destino no la elige nadie: la calcula `Citykleta_Destination_Matcher` por geocerca en cada sync — la
relación vive como post meta repetible en la bici (`_citykleta_destination_id`, una fila por destino
asignado — no un array serializado, así una `WP_Query` con `meta_query` normal encuentra las bicis de
un destino sin trucos), no como término de taxonomía.

- **URL slug editable** — `citykleta_destination_slug` (Settings → Destinations → URL, default
  `destination`), igual que `citykleta_bike_slug` para bicis: al guardarlo se marca
  `citykleta_destination_flush_rewrite_flag`, revisado al **final** de `citykleta_init()` (a
  diferencia del de bicis, revisado dentro de `Citykleta_Bike_Post_Type::register()`, que corre
  ANTES de que el CPT de Destinations y las taxonomías se registren en esa misma carga — un flush ahí
  dejaría esas reglas afuera). **No puede quedar en blanco**: se intentó permitir vaciarlo para que
  los destinos vivan en la raíz del sitio sin prefijo (`/vedado/` en vez de `/destination/vedado/`),
  y se revirtió — `register_post_type()` con `'rewrite' => array( 'slug' => '' )` (o `'/'`) no genera
  ninguna regla de rewrite utilizable para el CPT, verificado en la base de datos (0 reglas para
  `destination`, la página da 404). Lograr URLs de verdad sin prefijo necesitaría una regla de
  rewrite escrita a mano con prioridad alta más manejo de colisión contra Páginas/Posts con el mismo
  slug — no es este campo. Ver `Citykleta_Settings::sanitize_destination_slug()`.
- **Geocerca por destino** — cada destino tiene post meta con centro (`citykleta_destination_lat`/`_lng`)
  y radio en km (`citykleta_destination_radius_km`), editables en su propia caja de campos
  (`Citykleta_Destination_Post_Type::render_meta_box()`). Un radio en `0` (el default de un destino
  nuevo) hace que ese destino se ignore — todavía no tiene geocerca usable.
- **`Citykleta_Destination_Matcher`** corre dentro de `Citykleta_Bike_Sync::run()`, pero **después** del
  loop de `upsert_bike()` (a diferencia de `Citykleta_Bike_Type_Sync`/`Citykleta_Map_Settings_Sync`, que
  corren antes) — necesita el `lat`/`lng` que la bici recién guardó. Para cada bici con coordenadas
  (reusa `Citykleta_Bike_Map_Data::query()`), calcula distancia haversine contra cada destino con
  geocerca y **siempre** reescribe la meta `_citykleta_destination_id` de la bici (`delete_post_meta()`
  + `add_post_meta()` una vez por destino donde cae) — incluso a "ninguno", así una bici que se movió
  fuera de todas las geocercas pierde los destinos viejos en vez de quedarse con una asignación vencida.
  Una bici puede pertenecer a más de un destino si las geocercas se solapan.
  `Citykleta_Destination_Matcher::destinations_for_bike( $bike_id )` es el lookup inverso (qué destinos
  tiene esta bici), por ahora sin usar en ninguna pantalla.
- **Rating agregado — todavía NO implementado a propósito.** El mecanismo de captura (¿estrellas de
  visitantes? ¿nota interna del admin?) sigue sin decidirse; la plantilla no tiene esa sección hasta
  que se resuelva. Ver la memoria de sesión `project_destinos_feature` para el detalle de la decisión
  pendiente.
- **Artículos relacionados** — cualquier post de blog con una **tag** cuyo slug coincide con el del
  destino aparece listado en su página (`get_posts( array( 'tag' => $destination_slug ) )`). Convención
  nueva, no una taxonomía propia — reusa el sistema nativo de tags de WordPress.
- **Precio promedio** — calculado en PHP sobre los resultados de la misma query de bicis del destino
  (`array_sum/count` sobre `Citykleta_Bike_Meta::price_amount()`), no con un `AVG()` en SQL aparte: la lista de bicis de un
  destino es chica (geocerca local), no vale la pena una segunda consulta.
- **FAQ genérico + override por destino** — `Citykleta_Settings` tiene una pestaña nueva **Destinations**
  con un textarea de formato libre ("una pregunta por línea: `Pregunta :: Respuesta`", el mismo truco de
  "textarea plano en vez de repeater JS" que ya usa la plantilla de WhatsApp) que guarda el FAQ por
  defecto (`citykleta_destination_faq_default`, parseado por
  `Citykleta_Destination_Post_Type::parse_faq_text()`). Cada destino tiene un switch ("Custom FAQ") + su
  propio textarea en el mismo formato — apagado por defecto (usa el genérico), encendido reemplaza la
  lista **entera** (nunca se mezclan las dos). `Citykleta_Destination_Post_Type::faq_for( $post_id )`
  resuelve cuál mostrar.
- **El CPT es idéntico a una Página, a propósito.** `supports` no es una lista escrita a mano:
  sale de `array_keys( get_all_post_type_supports( 'page' ) )` más `'elementor'` (ver
  `Citykleta_Destination_Post_Type::page_supports()`). Así hereda lo que una página tenga en
  *esta* instalación —revisiones, extracto, atributos de página, autor, comentarios, lo que
  un tema o plugin le haya añadido— sin que nadie tenga que acordarse de actualizar el
  array cuando el core o un plugin cambien. Es seguro leerlo ahí: el core registra `page` en
  `init` con prioridad 0 y el plugin arranca en la 10. También es `hierarchical => true`,
  como las páginas, así que tiene padre y orden.
- **Plantilla editable**, igual que la ficha de una bici: `templates/single-destination.php`
  ya no arma el diseño, imprime la plantilla de tipo `destination`
  (`Citykleta_Templates::ensure_destination_template()`). Las piezas viven en
  `includes/elementor/class-citykleta-destination-part-widgets.php` — encabezado
  (título + imagen destacada), descripción, precio promedio, artículos y FAQ — y cada una
  resuelve el destino sola desde la query, igual que las piezas de bici. **Las bicis del
  destino no son una pieza propia**: son `Citykleta_Bike_Widget` con
  `Source = Destination Bikes` (ver la nota sobre un widget por trabajo, más arriba). Los
  títulos de sección ("Artículos relacionados", "Preguntas frecuentes") son Headings nativos
  de Elementor puestos por el layout por defecto, no algo que imprima un widget.
  `Citykleta_Destination_Part_Widget::bikes_query_args()` es dueño de la forma de esa
  consulta, para que la grilla y el precio promedio no puedan discrepar sobre qué son "las
  bicis de aquí". Sigue cargándose vía `template_include`, así que el tema activo la puede
  sobreescribir con su propio `single-destination.php`. Estilos en
  `assets/css/destination.css` y `.citykleta-bike-card` de `bike-cards.css`.

## Ideas / próximos pasos posibles

- **Muro de registro sobre la ficha de la bici** — la idea original era enseñar sólo algunos
  datos y esconder el resto tras un sign up, como los periódicos de pago. No se hizo así:
  `single-bike.php` ya existe y muestra la ficha **completa**, y el muro terminó siendo global
  (el de descarga de la app, ver esa sección). Queda como decisión abierta si conviene volver
  a esconder algo, ahora que la conversión también puede ser una solicitud.
- **Conectar `POST /contact/reveal`** — lo de mayor rendimiento por trabajo invertido de esta lista.
  La ruta existe, la llave está configurada en el backend, y el formulario **ya le pide al visitante el
  código que consume**. Conectarlo valida el código (hoy se guarda sin comprobar) y consigue el teléfono
  del dueño en la misma llamada, lo que vuelve opcional el `Citykleta_Owner_Contact` manual. Hace falta
  guardar la llave en Settings (no en código) y decidir el momento: al enviar la solicitud, o al
  confirmarla desde wp-admin — la segunda opción no gasta cupo del límite por dueño en solicitudes que
  nadie va a atender. Ver "El contacto del dueño" más arriba.
- **Reseñas y reputación en la ficha de la bici** — `GET /reviews/bike/:bikeId` y
  `GET /reviews/owner/:userId` son públicas, responden 200 sin autenticación, y no las consume nada.
  En un marketplace P2P la reputación del dueño es de lo que más mueve una conversión, y sería otra
  pieza de Elementor como las demás.
- Endpoint REST propio (ej. `/wp-json/citykleta/v1/bikes`) con formato limpio
  para el móvil, en lugar del endpoint genérico.
- Relación bicicleta ↔ propietario completa (perfil, reputación, más bicis del mismo
  dueño). Por ahora solo existe `owner_name` como campo suelto en la bici — `owner_photo`
  y `owner_phone` existen en el código pero llegan siempre vacíos (ver tabla de campos),
  sin CPT ni perfil propio — nombres de ejemplo: Laura,
  John, María, Alfonso, Gustavo.
- Taxonomía de tipo de bici (montaña, ciudad, paseo, eléctrica).
- Lightbox / ampliar foto en la página individual — el mosaico muestra hasta 5 fotos pero
  no se pueden abrir a pantalla completa (haría falta JS; hoy la plantilla no carga ninguno).
- Sistema de disponibilidad/reservas por fecha — hace falta para que las fechas del
  widget de búsqueda (`Citykleta_Search_Widget`) filtren resultados de verdad; hoy
  solo viajan en la URL sin usarse. También calendario personalizado en vez del
  `<input type="date">` nativo, si se quiere un look más parecido a apps de reservas.
  Es lo mismo que impide que una solicitud sea una reserva de verdad (ver "Solicitudes
  de bicicleta"): sin ocupación por fecha, dos personas pueden pedir la misma bici el
  mismo día y nadie lo detecta hasta que un humano lo mira.
- Embudo de solicitudes en el widget "Citykleta Stats" del dashboard — `Citykleta_Bike_Request`
  ya expone `count()`, `count( $status )` y `requests_by_day()` para eso, pero
  `Citykleta_Search_Stats` todavía no los pinta.

## Cómo probar

1. Con LocalWP corriendo, activar el plugin en WP Admin → Plugins.
2. Aparece el menú "Bicicletas" → Añadir nueva.
3. Rellenar título, descripción, foto destacada y los campos de "Datos de la bicicleta".
4. Comprobar la API en: `http://<tu-sitio-local>/wp-json/wp/v2/bike`
5. Comprobar que el backend real responde: `curl http://localhost:8001/bikesPublic/sync`
   (debe devolver un **array plano** de bicis con `app_bike_id`). `/map-settings` es la otra ruta
   pública que consume el plugin — pero **no son las dos únicas públicas**, ver la tabla en la sección
   "Settings page". Ojo: `/bike-types` da **401**, es solo de staff.
6. Ir a "Bicicletas" → "Settings" (pestaña "Sync") en wp-admin, revisar/guardar la URL de la API y
   la frecuencia, y probar el botón "Sync now". Debe crear/actualizar posts `bike` y, de paso, refrescar
   la pestaña "Maps" (ahora de solo lectura — ver "Configuración de mapas: mudada a Back Office" más
   arriba): "Base Map" (estilo de tiles vigente + access token si el proveedor lo necesita), "Marker
   design" (tamaño), "Cluster Behavior" y "Cluster Colors" (pequeño/mediano/grande), todo sincronizado
   desde `/map-settings`, no editable a mano.
7. El estado de la última sincronización (fecha, cuántas se procesaron, cuántas se retiraron,
   errores y avisos) se ve **solo** en esa misma pestaña Sync. Los endpoints `/sync-status` y
   `/trigger-sync` que permitían mirarlo y dispararlo por `curl` vivían en el mock y ya no existen:
   hoy el disparo manual es el botón "Sync now".
8. Ver el grid de tarjetas en: `http://<tu-sitio-local>/bikes/`
   Si da 404, hacer flush de rewrite rules (ver gotcha en la sección de arquitectura).
   Lo que se ve es el contenido de la Page "Bicicletas (resultados)" (se crea sola
   la primera vez — ver "Archivo de bicis" más arriba); para redecorar esta
   página, editar esa Page con Elementor (gratis, no hace falta Pro) en vez de
   tocar `templates/archive-bike.php`.
9. Con Elementor activo, crear/editar cualquier página con Elementor y buscar el
    widget "Bicicletas" en el panel — se puede arrastrar donde sea.
10. Buscar el widget "Bike Search" y arrastrarlo también. El campo de fechas necesita
    los archivos de Flatpickr descargados a mano (ver sección "Widget de búsqueda")
    para verse como calendario de rango; sin ellos, el campo se ve pero no abre nada.
11. Buscar el widget "Bikes Map" y arrastrarlo también. Necesita: (a) los archivos de
    Leaflet/Leaflet.markercluster descargados a mano (ver sección "Mapa de bicis"), y
    (b) que las bicis tengan `lat`/`lng` — solo las sincronizadas *después* de este
    cambio los van a tener, así que puede hacer falta un "Sincronizar ahora" (paso 6)
    antes de ver algo en el mapa.
12. Abrir la página de una bici y probar **"Solicitar esta bicicleta"**. Debe aparecer en
    Bicicletas → **Requests**. Para probar el aviso al dueño hay que escribirle un correo
    en la caja "Owner contact (WordPress only)" de esa bici — no viene de la API a propósito
    (ver "Solicitudes de bicicleta"). El correo puede no salir desde LocalWP; la solicitud se
    guarda igual, y la columna "Owner told" queda vacía si el envío falló.
    La galería de fotos necesita los archivos de **Splide** descargados a mano (ver sección
    "Diseño de la página" más arriba) para deslizar; sin ellos la foto se ve igual, solo sin
    el carrusel. Y como hoy toda bici real trae una sola foto, no vas a ver flechas ni puntos
    en ninguna — es el comportamiento correcto, no falta nada.
    Por `curl`, sin pasar por el formulario:
    ```
    curl -X POST http://<tu-sitio-local>/wp-json/citykleta/v1/bike-requests \
      -H "Content-Type: application/json" \
      -d '{"bike_id":<ID del post>,"verification_code":"ABC123","from":"2026-09-20","to":"2026-09-23"}'
    ```
    Devuelve `201` con `{"ok":true}` si se guardó, `400` con un mensaje si el payload no vale.
13. En wp-admin, ir a Bicicletas → "All Bikes": arriba de la lista debe verse el mismo
    mapa pero con **todas** las bicis con coordenadas (sin filtros), pensado para
    revisar la cobertura desde el propio admin. Si aparece el aviso de bicis sin
    coordenadas, es la misma causa del paso 11: hace falta un "Sincronizar ahora". Si
    no hay ninguna bici con coordenadas todavía, el mapa no se muestra (no tiene
    sentido un mapa vacío).

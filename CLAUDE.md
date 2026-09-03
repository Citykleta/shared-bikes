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
    class-citykleta-bike-widget.php   → Widget "Bicicletas": grid de bicis para arrastrar en cualquier
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
templates/
  archive-bike.php                   → Grid de tarjetas de vista previa en /bikes/ (imagen, título,
                                        ubicación, precio, disponibilidad). Enlaza a la página individual.
                                        Muestra un aviso "Mostrando resultados para: X" cuando llega
                                        ?location= en la URL (desde el widget de búsqueda).
  single-destination.php             → Página de un Destino (/destination/{slug}/): bicis asignadas por
                                        geocerca, precio promedio, artículos relacionados, FAQ. Ver
                                        sección "Destinos" más abajo.
assets/css/
  bike-cards.css                     → Estilos del grid de tarjetas (archivo + widget de bicis).
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
| owner_phone        | string  | Teléfono del propietario (WhatsApp) | Viene de `owner.phone` en la API. Usado por el botón "Contactar por WhatsApp" de la página individual (ver `single-bike.php` + pestaña Communication de Settings). |

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

`Citykleta_Settings` es una sola página en wp-admin (Bicicletas → **Settings**) con cuatro pestañas
propias (`self::TABS`, controladas por `?tab=` en la URL, patrón `nav-tab-wrapper` estándar de WordPress).
De esas cuatro, **Sync**, **Communication** y **Destinations** tienen formulario guardable — **Maps** es
de solo lectura desde que su configuración se mudó a Back Office (ver más abajo). **Destinations** guarda
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
URLs absolutas con la destacada primero, ver la tabla de traducción más arriba). El sync descarga **la primera** con `media_sideload_image()`
y la deja como imagen destacada del post (no como campo meta).

**Un adjunto por URL de origen, compartido entre bicis.** El meta
`_citykleta_image_source_url` (`Citykleta_Bike_Sync::IMAGE_SOURCE_META`) se escribe en los
**dos** lados: en la bici, para no volver a descargar una imagen que no cambió; y en el
**adjunto**, que es lo que permite a `find_attachment_by_source()` reutilizarlo cuando otra
bici apunta a la misma URL. No es un detalle menor: en el catálogo real las 68 bicis de
prueba comparten un mismo `bike-placeholder.jpg`, y descargarlo una vez por bici significaba
68 descargas más 68 juegos de miniaturas — una primera sincronización que **no terminaba en
15 minutos**. Con el adjunto compartido, esa misma corrida tarda ~98 s y crea **1** adjunto.
WordPress no tiene problema en que un adjunto sea la imagen destacada de varios posts.

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

### Archivo de bicis (grid de tarjetas)

`/bikes/` muestra el grid con `templates/archive-bike.php`, cargado vía el filtro
`template_include` (así el tema activo lo puede sobreescribir con su propio
`archive-bike.php`, patrón usado por WooCommerce y otros). Cada tarjeta es solo
vista previa: imagen, título, ubicación, precio y disponibilidad — el resto de los
datos van en la página individual de cada bici (pendiente de construir, con el
"muro" de registro descrito en la sección de producto).

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
**Contenido** (cantidad, solo disponibles), **Query** (ubicación por coincidencia
parcial, ordenar por fecha/precio/nombre, ascendente/descendente) y **Layout**
(columnas, etiqueta HTML del título, mostrar/ocultar ubicación, precio y foto del
propietario, resolución de imagen, proporción de imagen). La foto de la bici en sí
**siempre se muestra** (no es opcional) — lo único opcional es la foto del
propietario, que se superpone sobre ella.

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

La conversión del sitio es: **muro de sign-up (correo + nombre + consentimiento) →
botón de descarga de la app**. Reemplaza el viejo "Contactar por WhatsApp" (el botón
ya se quitó de `single-bike.php`; la pestaña Communication de Settings se conserva).
De qué bici salió una descarga **no** se rastrea a propósito — las inscripciones y
descargas son globales, solo las *vistas* son por bici (ver `Citykleta_View_Stats`).

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
- **Plantilla** `templates/single-destination.php`, cargada vía `template_include` igual que
  `archive-bike.php`/`single-bike.php` (el tema activo puede sobreescribirla con su propio
  `single-destination.php`) — hookea `is_singular( 'destination' )`, no `is_tax()`. Con footer normal
  (mismo patrón de `single-bike.php`, no el "sin footer" de `archive-bike.php`) — es más una página de
  contenido que un showroom. Reusa `.citykleta-bike-card` de `bike-cards.css` para el grid; estilos
  propios del encabezado/artículos/FAQ en `assets/css/destination.css`.

## Ideas / próximos pasos posibles

- Página individual de bici (`single-bike.php`, mismo patrón de `template_include` que
  el archivo) con el "muro" de registro: solo algunos datos visibles, el resto detrás
  de un botón de sign up, como los artículos pagados de periódicos.
- Endpoint REST propio (ej. `/wp-json/citykleta/v1/bikes`) con formato limpio
  para el móvil, en lugar del endpoint genérico.
- Relación bicicleta ↔ propietario completa (perfil, reputación, más bicis del mismo
  dueño). Por ahora solo existe `owner_name`/`owner_photo` como campos sueltos en la
  bici (ver tabla de campos), sin CPT ni perfil propio — nombres de ejemplo: Laura,
  John, María, Alfonso, Gustavo.
- Taxonomía de tipo de bici (montaña, ciudad, paseo, eléctrica).
- Fotos múltiples por bicicleta (galería) — hoy el sync solo trae una imagen destacada
  (campo `image` de la API), no una galería.
- Sistema de disponibilidad/reservas por fecha — hace falta para que las fechas del
  widget de búsqueda (`Citykleta_Search_Widget`) filtren resultados de verdad; hoy
  solo viajan en la URL sin usarse. También calendario personalizado en vez del
  `<input type="date">` nativo, si se quiere un look más parecido a apps de reservas.

## Cómo probar

1. Con LocalWP corriendo, activar el plugin en WP Admin → Plugins.
2. Aparece el menú "Bicicletas" → Añadir nueva.
3. Rellenar título, descripción, foto destacada y los campos de "Datos de la bicicleta".
4. Comprobar la API en: `http://<tu-sitio-local>/wp-json/wp/v2/bike`
5. Comprobar que el backend real responde: `curl http://localhost:8001/bikesPublic/sync`
   (debe devolver un **array plano** de bicis con `app_bike_id`). `/map-settings` es la otra ruta
   pública que consume el plugin. Ojo: `/bike-types` da **401**, es solo de staff.
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
12. En wp-admin, ir a Bicicletas → "All Bikes": arriba de la lista debe verse el mismo
    mapa pero con **todas** las bicis con coordenadas (sin filtros), pensado para
    revisar la cobertura desde el propio admin. Si aparece el aviso de bicis sin
    coordenadas, es la misma causa del paso 11: hace falta un "Sincronizar ahora". Si
    no hay ninguna bici con coordenadas todavía, el mapa no se muestra (no tiene
    sentido un mapa vacío).

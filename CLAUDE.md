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
  meta/
    class-bike-meta.php              → Campos personalizados + caja de edición + guardado + REST API.
  sync/
    class-citykleta-bike-sync.php    → Trae bicis de la API configurada y crea/actualiza posts "bike".
                                        Corre por WP-Cron (frecuencia configurable) o manualmente.
  admin/
    class-citykleta-settings.php     → Página "Settings" (submenú de "Bicicletas") con dos pestañas:
                                        "Sync" (URL de la API, frecuencia, slug) y "Maps" (colores del
                                        mapa de bicis, usados por el widget de Elementor y por
                                        Citykleta_Admin_Map).
    class-citykleta-list-columns.php → Columnas de imagen, marca y ubicación en la lista de bicis de
                                        wp-admin (marca y ubicación son ordenables con un click).
    class-citykleta-admin-map.php    → Página "Bikes Map" (submenú de "Bicicletas"): mapa con TODAS las bicis
                                        que tengan lat/lng, para ver de un vistazo dónde están sin salir
                                        de wp-admin. Reusa Citykleta_Bike_Map_Data y citykleta-map.js.
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
  mock/
    class-citykleta-mock-api.php     → API falsa TEMPORAL (rutas /wp-json/citykleta-mock/v1/bikes*,
                                        /bikes/{id}, /sync-status, /trigger-sync y /flush-rewrite)
                                        mientras no exista la API real de la app. Borrar cuando exista.
    bikes-mock.json                  → Copia del JSON de prueba para pegar en Mockoon (mismo dato que
                                        devuelve la API falsa de arriba).
templates/
  archive-bike.php                   → Grid de tarjetas de vista previa en /bikes/ (imagen, título,
                                        ubicación, precio, disponibilidad). Enlaza a la página individual.
                                        Muestra un aviso "Mostrando resultados para: X" cuando llega
                                        ?location= en la URL (desde el widget de búsqueda).
assets/css/
  bike-cards.css                     → Estilos del grid de tarjetas (archivo + widget de bicis).
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
  `price_per_hour`, ruta `/bikes`, label en el formulario "Price per hour (CUP)". Español solo se usa para
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
| price_per_hour     | number  | Precio por hora (CUP)       | En CUP                      |
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

Como esa API real todavía no existe, hay una **API falsa temporal** dentro del propio
plugin (`includes/mock/class-citykleta-mock-api.php`, activada por la constante
`CITYKLETA_USE_MOCK_API`) que expone `/wp-json/citykleta-mock/v1/bikes`. También se está
probando con **Mockoon** (app de escritorio) corriendo en `http://localhost:3000/bikes`
con el mismo JSON (ver `includes/mock/bikes-mock.json`). Cuando exista la API real de la
app, borrar `includes/mock/` y apuntar el sync a esa URL.

## Settings page (pestañas Sync / Maps)

`Citykleta_Settings` es una sola página en wp-admin (Bicicletas → **Settings**) con tres pestañas
propias (`self::TABS`, controladas por `?tab=` en la URL, patrón `nav-tab-wrapper` estándar de WordPress).

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
activa — pero eso solo controla qué se *muestra*, no qué se *guarda*. Por eso las tres pestañas usan
`OPTION_GROUP` **distintos** (`OPTION_GROUP_SYNC` / `OPTION_GROUP_MAPS` / `OPTION_GROUP_COMM`), cada uno
con su propio `settings_fields()` en el `<form>` correspondiente.

**Gotcha ya resuelto, por si se repite en una página nueva con pestañas:** `wp-admin/options.php`
recorre *todas* las opciones registradas bajo el grupo que llega en el formulario (`option_page`), no
solo las que están presentes en ese `$_POST` — a las que falten las pone en blanco. Si Sync y Maps
compartieran un solo `OPTION_GROUP`, guardar el formulario de una pestaña (que solo trae sus propios
campos en el POST) borraría los valores de la otra, porque WordPress los trataría como "enviados en
blanco" en vez de "no tocados". La regla es: un `OPTION_GROUP` por cada `<form>` que se pueda guardar
por separado, nunca uno compartido entre formularios distintos.

- **Sync** (antes toda la página se llamaba así) — igual que antes: URL de la API, un campo de path
  por recurso (`citykleta_path_bikes` → `/bikes`, `citykleta_path_bike_types` → `/bike-types`,
  definidos en `Citykleta_Settings::ENDPOINTS`), frecuencia, slug de la URL, estado de la última
  sincronización y el botón "Sync now". `Citykleta_Settings::get_endpoint_url( $key )` arma la URL
  final (base + path) — agregar un recurso nuevo (ej. `/owners`) solo necesita una entrada nueva en
  `ENDPOINTS`, no un campo a mano.
- **Maps** — cuatro secciones (`Citykleta_Settings::SECTION_TILE_STYLE` / `SECTION_MARKER_DESIGN` /
  `SECTION_CLUSTER_BEHAVIOR` / `SECTION_CLUSTER_COLORS`):
  - **Base Map** — el estilo de tiles usado como mapa base (`citykleta_map_tile_style`, dropdown
    sobre `Citykleta_Settings::TILE_STYLES`: OpenStreetMap, tres estilos de CARTO, dos de Esri,
    MapTiler, dos de Stadia Maps, y Mapbox) más un campo "API Key" (`citykleta_map_tile_api_key`)
    que solo se muestra si el estilo elegido lo necesita — JS inline en `enqueue_assets()` oculta la
    fila entera según cuáles entradas de `TILE_STYLES` declaran `'key_param'`.
    `Citykleta_Settings::tile_layer()` arma la URL final agregando `?{key_param}={key}` solo si hay
    una key guardada y el estilo la usa — OpenStreetMap/CARTO/Esri no necesitan key, MapTiler y
    Mapbox fallan sin una desde la primera petición, Stadia Maps funciona sin key para uso liviano.
    Varios de estos proveedores no son aptos para tráfico de producción real sin cuenta paga — ver
    el docblock de `TILE_STYLES` para el detalle de cada uno.
  - **Marker design** — solo tamaño (`citykleta_map_marker_icon_size`, "Icon Size"). El ícono en sí,
    sus colores por disponibilidad, y la sombra que tenía antes ya no son configurables — ver la
    sección "Mapa de bicis" más abajo.
  - **Cluster Behavior** — cuándo se agrupan las bicis cercanas en vez de mostrarse desplegadas:
    "Cluster Radius" (`citykleta_map_cluster_radius`, px — qué tan cerca en pantalla tienen que estar
    para agruparse; default 80, el mismo que trae Leaflet.markercluster de fábrica) y "Unfold At Zoom"
    (`citykleta_map_cluster_disable_zoom` — nivel de zoom a partir del cual se desactiva el
    agrupamiento del todo, sin importar qué tan cerca estén; 0 = nunca). No es un umbral por
    *cantidad* de bicis — Leaflet.markercluster agrupa por proximidad en píxeles y zoom, no por
    cuántos marcadores hay.
  - **Cluster Colors** — un color por cada nivel de tamaño que ya usa Leaflet.markercluster (Small/
    Medium/Large), en vez de un solo color para los tres.

  Esos colores de cluster viven en `Citykleta_Settings::MAP_COLORS`, con el color picker
  nativo de WordPress (`wp-color-picker`, encolado solo en esta página vía el mismo patrón de hook
  suffix que `Citykleta_Admin_Map`). `Citykleta_Settings::map_colors()` / `map_color_style_attr()`
  son genéricos sobre `MAP_COLORS` (cada entrada declara su `'section'`) — arman el `style=""` con
  una variable CSS `--citykleta-map-{key}-color` por cada entrada (agregar un color nuevo a la
  constante no necesita tocar estos dos métodos). Tanto `Citykleta_Map_Widget` (Elementor) como
  `Citykleta_Admin_Map` (wp-admin) llaman a los mismos helpers (`map_color_style_attr()`,
  `tile_layer()`, `marker_icon_size()`), así que **un solo lugar** controla el look de todos los
  mapas del sitio. El aro translúcido de cada cluster usa `color-mix()` en CSS para diluir ese mismo
  color sólido en vez de necesitar un segundo valor rgba() por nivel.
- **Communication** — una sola opción (`citykleta_whatsapp_message`): la plantilla del mensaje que se
  pre-rellena cuando un visitante toca "Contactar por WhatsApp" en la página individual de una bici.
  Soporta placeholders (`Citykleta_Settings::MESSAGE_PLACEHOLDERS`: `{owner}`, `{bike}`, `{price}`,
  `{url}`). `whatsapp_message( $replacements )` rellena la plantilla para una bici concreta y
  `whatsapp_url( $phone, $message )` arma el enlace `wa.me` (deja solo dígitos del teléfono, devuelve
  `''` si no hay número usable para que el llamador oculte el botón). El texto por defecto
  (`DEFAULT_MESSAGE`) está en español, como el resto del front-end, aunque los identificadores/labels
  sigan en inglés.

`Citykleta_Bike_Type_Sync` corre primero en cada sincronización: trae `/bike-types` (la lista
canónica de tipos de bici de la app) y crea/actualiza los términos de la taxonomía `bike_type` por
`slug` — nunca los borra, si un tipo desaparece de la API el término se marca inactivo
(`_citykleta_type_active` en term meta, columna "Active" visible en wp-admin) para no romper bicis
o asignaciones de Uso que ya lo referencien.

`Citykleta_Bike_Sync` pide la lista de bicis a `Citykleta_Settings::get_endpoint_url( 'bikes' )` y
crea/actualiza un post `bike` por cada una, usando el meta `external_id` para no duplicar en
sincronizaciones repetidas (cada bici referencia su tipo por `slug`, resuelto contra los términos
que dejó `Citykleta_Bike_Type_Sync`). Corre automáticamente por WP-Cron según
`citykleta_sync_frequency` (cada 15/30 min, hora, etc., configurable en la misma página), o al
toque con el botón "Sincronizar ahora".

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

Cada bici de la API trae un campo `image` (URL). El sync la descarga con
`media_sideload_image()` y la deja como imagen destacada del post (no como campo
meta). Solo la vuelve a descargar si la URL cambió desde la última vez (se guarda en
el meta interno `_citykleta_image_source_url`), para no gastar ancho de banda de más.

**Gotcha:** `media_sideload_image()` decide el tipo de archivo por la **extensión en la
URL**, no por el `Content-Type` real de la respuesta. Una URL sin extensión (ej.
`https://placehold.co/600x400?text=...`) falla con "Invalid image URL." aunque la
descarga funcione perfectamente. Hay que usar una URL con extensión antes del `?`
(ej. `https://placehold.co/600x400.png?text=...`). Ver `placeholder_image()` en
`class-citykleta-mock-api.php`.

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
nada ya lo dispara), o con un POST a `/wp-json/citykleta-mock/v1/flush-rewrite`.

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
- **`Citykleta_Admin_Map`** (`includes/admin/`) — submenú "Bikes Map" dentro de
  "Bicicletas" en wp-admin (segunda opción, justo después de "All Bikes",
  antes de "Bike types"/"Usages"/"Settings"). Lograrlo con solo la prioridad
  del hook `admin_menu` no alcanza: WordPress arma las entradas de "All
  Bikes" y las taxonomías del CPT (`$submenu['edit.php?post_type=bike'][5]`,
  `[15]`, `[20]`) como código directo dentro de `wp-admin/menu.php`, **antes**
  de que la acción `admin_menu` siquiera se dispare — así que da igual qué
  tan temprano se enganche `add_menu()` a ese hook, esas entradas del core
  ya existen. La solución real es `reposition_menu()`, enganchado a
  `admin_menu` con prioridad `999` (después de que todo — core y plugin —
  ya agregó lo suyo): mueve la entrada de "Bikes Map" a la clave `6` dentro
  de `$submenu['edit.php?post_type=bike']` y llama a `ksort()`, el mismo
  mecanismo que usa `add_submenu_page()` internamente para mantener el
  array ordenado por clave.
  Sin filtros (`count => -1`, todas las bicis),
  con un aviso si hay bicis sin coordenadas (`count_all()` vs. las que sí
  tienen lat/lng). Pensada para que el equipo vea de un vistazo dónde están
  las bicis sin salir del admin — por eso `centerOnUser` va en `false` acá
  (tiene sentido centrar en el visitante en una página pública, no en quien
  esté mirando el dashboard), y el mapa se apoya solo en `fitBounds()`.

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
un `style="height:600px"` fijo en el propio HTML, no hay control que lo cambie).

`Citykleta_Admin_Map::enqueue_assets()` está enganchado a `admin_enqueue_scripts`
(que corre en **cada** pantalla de wp-admin) pero corta temprano comparando el
`$hook_suffix` recibido contra el que devolvió `add_submenu_page()` — así Leaflet
no se carga de más en el resto del admin, solo en Bicicletas → Map.

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
5. Comprobar la API falsa (mock) en: `http://<tu-sitio-local>/wp-json/citykleta-mock/v1/bikes`
6. Ir a "Bicicletas" → "Settings" (pestaña "Sync") en wp-admin, revisar/guardar la URL de la API y
   la frecuencia, y probar el botón "Sync now". Debe crear/actualizar posts `bike`. En la pestaña
   "Maps" de esa misma página se configura el mapa base y sus colores, agrupados en "Base Map"
   (estilo de tiles + API Key si el proveedor la necesita), "Marker design" (solo tamaño — el
   círculo, sus colores por disponibilidad, y la sombra que tenía antes ya no son configurables) y
   "Cluster Colors" (pequeño/mediano/grande).
7. Ver el estado de la última sincronización sin entrar a wp-admin en:
   `http://<tu-sitio-local>/wp-json/citykleta-mock/v1/sync-status`
8. Forzar una sincronización inmediata sin esperar al cron (útil en desarrollo), con un
   POST a: `http://<tu-sitio-local>/wp-json/citykleta-mock/v1/trigger-sync`
   (ej. `curl -X POST http://<tu-sitio-local>/wp-json/citykleta-mock/v1/trigger-sync`)
9. Ver el grid de tarjetas en: `http://<tu-sitio-local>/bikes/`
   Si da 404, hacer flush de rewrite rules (ver gotcha en la sección de arquitectura).
10. Con Elementor activo, crear/editar cualquier página con Elementor y buscar el
    widget "Bicicletas" en el panel — se puede arrastrar donde sea.
11. Buscar el widget "Search Bikes" y arrastrarlo también. El campo de fechas necesita
    los archivos de Flatpickr descargados a mano (ver sección "Widget de búsqueda")
    para verse como calendario de rango; sin ellos, el campo se ve pero no abre nada.
12. Buscar el widget "Bikes Map" y arrastrarlo también. Necesita: (a) los archivos de
    Leaflet/Leaflet.markercluster descargados a mano (ver sección "Mapa de bicis"), y
    (b) que las bicis tengan `lat`/`lng` — solo las sincronizadas *después* de este
    cambio los van a tener, así que puede hacer falta un "Sincronizar ahora" (paso 6)
    antes de ver algo en el mapa.
13. En wp-admin, ir a Bicicletas → "Map" para ver el mismo mapa pero con **todas**
    las bicis con coordenadas (sin filtros), pensado para revisar la cobertura desde
    el propio admin. Si aparece el aviso de bicis sin coordenadas, es la misma causa
    del paso 12: hace falta un "Sincronizar ahora".

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

`Citykleta_Settings` es una sola página en wp-admin (Bicicletas → **Settings**) con dos pestañas
(`self::TABS`, controladas por `?tab=` en la URL, patrón `nav-tab-wrapper` estándar de WordPress).
Cada pestaña registra sus secciones/campos bajo su propio "page" interno (`self::PAGE_SLUG . '-sync'` /
`'-maps'`), así que `do_settings_sections()` únicamente muestra los campos de la pestaña activa — pero
eso solo controla qué se *muestra*, no qué se *guarda*. Por eso las dos pestañas usan `OPTION_GROUP`
**distintos** (`OPTION_GROUP_SYNC` / `OPTION_GROUP_MAPS`), cada uno con su propio `settings_fields()`
en el `<form>` correspondiente.

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
- **Maps** — dos secciones (`Citykleta_Settings::SECTION_MARKER_DESIGN` / `SECTION_CLUSTER_COLORS`):
  - **Marker design** — el ícono propio (`citykleta_map_marker_icon`, un picker de la Media Library
    de WordPress armado a mano con `wp.media()` porque esta página no tiene a Elementor disponible
    para su control `MEDIA`; ver `render_icon_field()` + el JS inline en `enqueue_assets()`) y los
    colores "Available"/"Unavailable" del marcador individual.
  - **Cluster Colors** — un color por cada nivel de tamaño que ya usa Leaflet.markercluster (Small/
    Medium/Large), en vez de un solo color para los tres.

  Todo esto vive en `Citykleta_Settings::MAP_COLORS` (colores) + la opción suelta
  `citykleta_map_marker_icon` (ícono), con el color picker nativo de WordPress (`wp-color-picker`,
  encolado solo en esta página vía el mismo patrón de hook suffix que `Citykleta_Admin_Map`).
  `Citykleta_Settings::map_colors()` / `map_color_style_attr()` son genéricos sobre `MAP_COLORS`
  (cada entrada declara su `'section'`) — arman el `style=""` con una variable CSS
  `--citykleta-map-{key}-color` por cada entrada (agregar un color nuevo a la constante no necesita
  tocar estos dos métodos), y `marker_icon_url()` hace lo mismo para el ícono. Tanto
  `Citykleta_Map_Widget` (Elementor) como `Citykleta_Admin_Map` (wp-admin) llaman a los mismos
  helpers, así que **un solo lugar** controla el look de todos los mapas del sitio. Antes el ícono y
  los colores vivían como controles de Content/Style por instancia en el widget de Elementor; se
  sacaron de ahí para no tener dos fuentes de verdad para lo mismo. El aro translúcido de cada
  cluster usa `color-mix()` en CSS para diluir ese mismo color sólido en vez de necesitar un segundo
  valor rgba() por nivel.

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

Cada bici sin ícono personalizado se dibuja como el glyph de bici solo, sin ningún
círculo de fondo, recortado vía CSS `mask-image` sobre `assets/images/marker-bike.svg`
(`.citykleta-map__bike-icon-circle` en `citykleta-map.css`) y coloreado directamente
con el color de marca (amarillo `#FED94C` si `available`, gris `#E5E0D3` si no, por
defecto — configurable en Settings → Maps → "Marker design"), en vez de un `<img>`.
Por eso el mismo archivo, de un solo color/trazo, sirve para los dos estados sin tener
que guardar una copia por color. **Ese archivo NO viene con el plugin** (a diferencia
de otros vendor assets, acá no hay una URL fija para descargarlo — hay que
conseguir/dibujar un SVG de bici propio, un solo `<path>` con relleno sólido y sin
degradados, y ponerlo en esa ruta); mientras no exista, la máscara no tiene nada que
mostrar y el marcador queda invisible — la misma degradación que usan los demás vendor
files opcionales del plugin, solo que resuelta en CSS puro en vez de con un
`file_exists()` en PHP, porque acá no hay que encolar ningún script/estilo condicional.

Ese mismo tab de Settings permite además subir un ícono propio (Media Library, ver
`Citykleta_Settings::render_icon_field()`); en ese caso se pierde el color por
disponibilidad, porque no se puede recolorear una imagen arbitraria (PNG/JPG) solo
con CSS — la técnica de `mask-image` de arriba solo funciona con el ícono por defecto,
que sabemos que es un SVG de un solo color.

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
   "Maps" de esa misma página se configura el ícono propio y los colores del mapa, agrupados en
   "Marker design" (ícono, disponible/no disponible) y "Cluster Colors" (pequeño/mediano/grande).
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

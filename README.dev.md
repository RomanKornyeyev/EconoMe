# README dev

Notas técnicas de EconoMe para quien toca el código. Para instalar o desplegar, ver [README.md](README.md).

Formato changelog: lo más reciente arriba.

---

## v1.5.7 — Filtro de fechas en Movimientos y recuperación de contraseña

### Movimientos abre el mes en curso

`TransactionController::index` aplica del día 1 al último del mes **solo si la
query no trae ningún parámetro de filtro** (`FILTER_PARAMS`). Basta uno, aunque
venga vacío, para respetarlo. Por eso los enlaces que quieren «todo» llevan
`date_from=` vacío: los dos «Limpiar», la etiqueta de fecha del móvil al
quitarla y el contador «Movimientos» de la tarjeta de cuenta. El drill-down de
categoría (`?category=X`) y el del dashboard ya traen filtro y no se recortan.
`/transaction/count` no aplica el defecto: el panel le manda siempre las fechas.

### Rango de fechas: `date_range_controller.js`

Los filtros de escritorio (un `<script>` suelto en `transaction/index`) y los
del panel móvil (`tx-filters`) montaban cada uno su flatpickr de rango con la
misma lógica copiada. Ahora los dos usan `date-range`, que además pone los
atajos («Este mes», «Mes anterior», «Últimos 3 meses», «Año actual») y
«Limpiar» dentro del calendario. flatpickr no trae nada de esto: se construye en
`onReady` sobre `calendarContainer`.

- `layout: side` (escritorio): mueve los nodos de flatpickr a `.fp-main` y pone
  los atajos en un panel a la izquierda.
- `layout: bottom` (móvil): rejilla de 2 columnas bajo los días. Los botones que
  había encima del campo en el panel se quitan.

Dentro de un `.offcanvas`, el calendario cuelga del panel y se coloca a mano
(ver `#placeInSheet`). `tx-filters` lo maneja por outlet (`clear()` y `reload()`)
y recalcula el recuento con el evento `date-range:change`.

Reparto de flatpickr desde aquí: **fecha suelta** (formularios, modal de
movimiento) → `date_picker_controller.js`; **rango** (filtros) →
`date_range_controller.js`. Ninguno se inicializa desde un `<script>` inline.

### Restablecer contraseña con sesión iniciada

`SecurityController::resetPassword` ya no redirige al inicio si hay sesión: el
token del enlace es la prueba de identidad, no la sesión. Tres casos:

- **Sin sesión**: igual que antes. Al terminar, al login.
- **Sesión de la misma cuenta**: se cambia y se sigue dentro (redirige al perfil).
- **Sesión de otra cuenta**: `$security->logout(false)`, flash y se recarga el
  mismo enlace ya como anónimo. Así nunca se trabaja con una sesión de A sobre la
  cuenta de B.

**Por qué las demás sesiones se cierran solas.** `User` no implementa
`EquatableInterface`, así que en cada petición Symfony compara el hash de la
contraseña guardado en sesión con el de BD; si no coincide, desautentica. La
sesión desde la que se cambia sobrevive porque Doctrine comparte la instancia de
`User` (identity map) entre la sesión y `$token->getUser()`, y al final de la
petición se serializa ya con el hash nuevo. Si algún día se implementa
`EquatableInterface` o se cambia cómo se serializa el usuario, esto deja de ser
gratis: hay que revisarlo.

**«Recuérdame» tras el cambio** (`App\Security\RememberMeRenewer`). La firma de
la cookie incluye el hash, así que también caducaba la del propio dispositivo.
Si la petición trae `REMEMBERME`, se hace `Security::login()` con
`RememberMeBadge` activado para emitirla firmada con la contraseña nueva. Lo usan
el cambio desde el perfil y el reset con sesión.

**Enlace no válido** → `security/reset_password_invalid.html.twig` con motivo:
`invalid` (404), `used` o `expired` (410). Antes un token caducado lanzaba
`AccessDenied`, que para un anónimo acaba en el login sin mensaje. Ojo con el
orden: el token se marca usado y se hace `flush()` **antes** de
`UserService::changePassword()`, porque ese método borra los tokens de reset sin
usar y, si no, el enlace pasaría de «ya usado» a «no válido».

### Enlace de restablecer desde el perfil

`POST /perfil/password/enlace` (`app_profile_password_reset_link`), con CSRF
`account_password_reset_link`. Es un POST a propósito: con un GET, el prefetch
del navegador o un `<img>` en otra web dispararían emails. El `<form>` está fuera
del de cambiar contraseña (no se pueden anidar) y el botón lo envía con el
atributo `form="password-reset-link-form"`. Clase `.btn-inline-link` en
`general.css`: un `<button>` que se lee como enlace dentro de un texto.

**Límite compartido**: `UserService::requestPasswordReset()` consume
`limiter.reset_password_email` (1 por minuto, clave `user_<id>`) tanto desde el
perfil como desde «Recuperar contraseña». Devuelve `null` si envió o el
`DateTimeImmutable` del siguiente intento. El perfil lo enseña; el formulario
público lo ignora y responde lo mismo siempre.

### «Recuperar contraseña» no revela qué emails existen

Antes, un email inexistente daba flash `danger` y se quedaba en la página, y uno
existente daba `success` y redirigía al login. Ahora los dos casos (y el de
límite superado) dan el mismo flash y la misma redirección. Queda una diferencia
de tiempo de respuesta (el envío del email es síncrono); pasarlo a Messenger la
quitaría.

### Tokens hasheados en BD

`user_token.token` guarda `sha256` del token del enlace (`UserToken::hash()`).
El token en claro solo existe en memoria al crearlo: `getPlainToken()` para el
email y lanza `LogicException` en un token cargado de BD. `getToken()` devuelve
el hash. Toda búsqueda por token tiene que pasar por `UserToken::hash()`
(`confirmEmail`, `resetPassword`, `UserTokenRepository::findValidToken`). La
migración `Version20261105120000` hashea los existentes para no romper enlaces ya
enviados. Su `down()` no hace nada y solo avisa: no hay esquema que deshacer y
un hash no se puede revertir, así que no bloquea bajar a migraciones anteriores.
Tras un rollback (con el código anterior), los enlaces pendientes dejan de valer.

Caducidad por tipo en `UserToken::LIFETIMES`: reset de contraseña 3 h, el resto
24 h. Además, `confirmEmail` ahora filtra por `TYPE_REGISTRATION`: antes
cualquier token (p. ej. uno de reset) confirmaba la cuenta.

---

## v1.5.6 — Eliminar en móvil, duración de sesión y límites de intentos

### Los modales de bulk-select salen de la tabla desktop

Borrar un movimiento desde las cards de móvil dejaba la página bloqueada con el
backdrop y sin modal: los forms y modales de `bulk-select` vivían en
`_table_desktop.html.twig`, que se incluye dentro de un `d-none d-md-block`, y un
modal con un padre oculto no se ve aunque Bootstrap lo abra. Ahora están en
`transaction/_table_modals.html.twig`, incluido en el wrapper del controller pero
fuera de los contenedores responsive (dashboard y Movimientos).

### Duración de la sesión

Sin «Recuérdame» la sesión dependía del `php.ini` (24 min de inactividad en
local, y en producción lo que tocase). Ahora `framework.session.gc_maxlifetime`
la fija en 60 min; `session: true` pasa a ser un bloque, pero el resto de
opciones sigue con sus valores por defecto. Es un mínimo, no un corte exacto: la
sesión caducada se borra cuando salta el GC de PHP (`gc_probability` /
`gc_divisor` del `php.ini`), así que puede durar algo más.

Con «Recuérdame» la cookie pasa de 7 a 21 días (`remember_me.lifetime: 1814400`).

### Más margen en los límites de intentos

El login (`login_throttling.max_attempts`) y el cambio de contraseña
(`rate_limiter.change_password.limit`) pasan de 5 a 7 intentos por cada 3 minutos.

---

## v1.5.5 — Mejora UX al crear movimientos

### Un solo botón de guardar, encadenar es un interruptor

Hubo dos botones, «Guardar» y «Guardar y añadir otro», con el segundo como
primario. Se unificaron en un único «Guardar ↵» y un interruptor «Seguir
añadiendo» al lado que decide si el modal se queda abierto o se cierra. Lo normal
es meter un movimiento y salir, así que el interruptor viene apagado en cada
apertura (también al duplicar) y dentro de una tanda se queda como esté.

El interruptor no lleva `name`: no viaja en el envío, solo lo lee el controlador.
Sin JS no hace nada y el formulario se envía como siempre.

Accesibilidad y teclado:

- Enter sobre el interruptor lo conmuta en vez de guardar (patrón *switch* de
  ARIA: Espacio y Enter). Quien va solo con teclado y pulsa Enter ahí quiere
  encenderlo, no guardar y cerrar el modal.
- Lleva una descripción oculta (`aria-describedby`) para lectores de pantalla.
  En PC, además, un tooltip con el mismo texto (sale de ese mismo nodo, target
  `chainHint`), por hover y por foco, con 400 ms de retraso para que no salte al
  cruzar el ratón hacia «Guardar».
- El tooltip no usa `data-bs-toggle` (tooltips.js los crea en todos lados): lo
  crea el controlador solo si `(hover: hover) and (pointer: fine)`. En táctil el
  primer toque abriría el tooltip en vez de conmutar.

### Franja de la tanda: nombre, importe y «Terminar»

La franja quedó en `✓ Alcampo −15,00 € ··· 2 en esta tanda [Terminar]`: fuera
«Registrado», la fecha y el punto de categoría. Es un acuse de recibo, basta con
reconocer el movimiento. El signo es el menos tipográfico (−), como en el
selector de tipo, y la moneda sale de la cuenta. `_added_item.html.twig` ya no
recibe `updated`.

«Terminar» (`btn-outline-success`) cierra el modal con `data-bs-dismiss`; la
recarga de detrás la sigue haciendo `hidden.bs.modal`. Con el interruptor
encendido el modal no se cierra solo, y la X no comunica «ya he acabado».

Se descartó rellenar el botón cuando el formulario está vacío: en una tanda de
N, «Terminar» solo es lo probable tras el último guardado, y el botón
parpadearía en cada alta compitiendo con el pulso de la franja.

La región `aria-live` envuelve solo el texto de la franja, no el botón: si no,
el lector anunciaría «Terminar» en cada alta.

### Sugerencias de nombre propias en vez del autocompletado del navegador

El autocompletado nativo no se puede limitar y repetía cada variante de
mayúsculas («alcampo», «Alcampo», «ALCAMPO»). Ahora el nombre va con
`autocomplete="off"` y, al crear, un `<datalist>` que rellena
`name_suggest_controller.js` desde `GET /transaction/suggest-names`:

- como mucho 2 nombres de la cuenta que empiezan por lo escrito (mínimo 2
  caracteres), los más usados primero y, a igualdad, los más recientes;
- agrupados por la collation de MySQL (insensible a mayúsculas y acentos), igual
  que la sugerencia de categoría; la grafía que sale la elige MySQL;
- sin el nombre ya escrito entero, y con `%`/`_` escapados en el `LIKE`;
- debounce de 200 ms (más corto que los 350 de categoría: tiene que llegar
  mientras se escribe) y `AbortController` para que una respuesta lenta no pise
  a la siguiente.

Sale del historial de la cuenta, no del navegador, así que es igual en cualquier
dispositivo. El desplegable lo sigue pintando el navegador: su aspecto no se
controla. Editando no hay sugerencias.

Importe y descripción también van sin autocompletado: el importe cambia casi
siempre y la descripción es texto libre. El del importe está en
`partials/_amount_type.html.twig`, así que aplica también a recurrentes.

---

## v1.4.0 — Registrar y editar movimientos, mucho más rápido

Esta versión junta dos trabajos que salieron seguidos: exprimir el flujo de meter
movimientos, y aligerar lo que descarga cada página.

Registrar un extracto entero eran N ciclos de abrir modal → rellenar → guardar → **redirect** → reabrir. El coste no estaba en teclear, sino en la ceremonia entre movimiento y movimiento. Este es el "Nivel 0" de `claude/bulk-transaction-entry.md`: exprimir el flujo actual sin tocar el modelo de datos ni meter todavía un importador.

En paralelo, antes casi todo el JS se descargaba en todas las páginas. Ahora cada
página carga solo lo suyo — lo que además hizo posible el resto: sin controllers
bajo demanda, cada pantalla se habría llevado el peso del modal.

### Alta encadenada (`tx_quick_add_controller.js`)

El modal ya no se cierra al guardar. `TransactionController::edit()` detecta XHR en el alta y responde JSON en vez de redirigir:

- válido → `200 {ok: true, item}` con la confirmación ya renderizada para la franja de la tanda;
- inválido → `422 {ok: false, body}` con **solo el cuerpo** del formulario re-renderizado.

Que vuelva solo el cuerpo y no el `<form>` entero es deliberado: el `_token` vive en el `form_start`/`form_end` del modal, así sobrevive al re-render y el siguiente envío sigue siendo válido.

El envío sin JS no cambia: sigue redirigiendo con su flash, y es lo que usa el formulario de página completa.

Al cerrar el modal habiendo añadido algo se recarga la página **una vez**, en lugar de una por movimiento, para que listado y KPIs de detrás no queden obsoletos.

**El acuse de recibo es una franja de una línea que se repinta, no una lista que crece.** La primera versión apilaba cada alta; con 5 movimientos el modal ya no cabía en pantalla. Ahora la altura es constante y el contador («N en esta tanda») cubre lo que aportaba la lista. Lo que hace visible el guardado es el pulso `.tx-added-pulse`, no el texto, que cambia poco entre un alta y la siguiente. Dentro de la franja solo se recorta el nombre: con conceptos de banco largos, el importe es justo lo que no puede desaparecer.

> Es de los pocos controllers **eager**: intercepta un envío.

### Editar también en el modal

Crear abría un modal, editar mandaba a otra pantalla. Mismo formulario, dos
experiencias distintas. Ahora las dos van por el modal.

`TransactionController::edit()` pasa a servir dos clientes según la petición sea
XHR o no. En XHR responde JSON: un `GET` devuelve el cuerpo del formulario más lo
que el modal necesita para reetiquetarse (`action`, `account`), y el `POST` la
confirmación. En navegación normal sigue renderizando `transaction/edit.html.twig`.

Solo se sustituye el **cuerpo**, nunca el `<form>`. El `_token` vive en el
`form_start`/`form_end` del modal y sobrevive al cambio de modo, porque el id del
token es el nombre del formulario (`transaction`) y es el mismo creando y editando.

Editando se ocultan el interruptor de encadenar y el enlace a recurrentes: son
piezas de "meter una tanda", y editar es una acción única.

### Los atajos se anuncian en su propio botón

Había una línea de pistas al pie del modal («Enter guarda y sigue · N abre esta
ventana»). Se cambió por un distintivo `.btn-kbd` dentro del botón que dispara
cada atajo: `↵` en «Guardar», `N` en los dos «Nuevo movimiento».

Sale gratis en mantenimiento: el `↵` vive dentro del botón, así que siempre dice
la verdad sin código extra —antes había que acordarse de ocultar la pista aparte,
y de hecho el controlador tenía un target `hint` solo para eso—. Editando solo
cambia la etiqueta (target `saveLabel`), no el botón entero, para no perder el
distintivo.

El `N` se oculta por debajo de `md` (`d-none d-md-inline-flex`): sin teclado
físico el aviso engaña. Los botones llevan `aria-keyshortcuts` y el distintivo va
`aria-hidden`, que es la forma correcta de anunciar un atajo a un lector de
pantalla sin que lea el adorno.

### El modal abre primero y carga después

Esperar al `fetch` para abrir dejaba ~1 s de nada tras el clic, que se lee como
que la aplicación se ha colgado. Ahora se abre al instante con un hueco de carga
(`.tx-form-loading`, con la altura aproximada del formulario para que no dé un
salto) y el cuerpo se inyecta al llegar. Mismo patrón que `tx_summary_controller.js`.

Abrir antes de tener los datos introduce tres carreras, y las tres están cerradas:

- **Enviar mientras carga.** El cuerpo aún es un spinner, así que un envío mandaría
  un movimiento vacío. `#loading` corta el envío.
- **Enviar al sitio equivocado.** La `action` se fija **antes** del `fetch`: la URL
  del enlace es la misma a la que hay que enviar, así que el formulario nunca
  apunta a crear mientras se edita.
- **Respuesta tardía.** Si cierras y reabres para crear, la respuesta en vuelo
  pisaría el formulario de alta. Cada apertura lleva número (`#loadSeq`) y las
  respuestas obsoletas se descartan.

### El controlador se mudó del `<form>` al `.modal-content`

`tx-quick-add` gobierna también el título y el nombre de cuenta de la cabecera, que
están **fuera** del formulario. Montado en el `<form>` esos targets no existían.
Ahora cuelga del `.modal-content` y el formulario es su target `form`; los
`data-action` siguen en el `<form>` porque Stimulus los resuelve contra el ancestro.

*`category-suggest` se queda en el `<form>`: todos sus targets están dentro.*

### La vista de página completa queda en desuso, no borrada

Nada enlaza ya a `transaction/edit.html.twig`, pero sigue viva: cubre las URLs
guardadas, el uso sin JavaScript y el fallback si el AJAX falla —los «Editar» son
`<a href>` de verdad y el modal solo los intercepta—. Está marcada como tal en la
plantilla y en el docblock del controlador. **Al tocar el formulario hay que mirar
las dos ramas.**

*Los movimientos recurrentes mantienen su flujo de página completa.*

### Flatpickr, de `<script>` suelto a controller

`_form_transaction.html.twig` inicializaba flatpickr con un `<script>` inline. Eso corre una vez y no ve el HTML inyectado —el problema que ya avisaba la nota permanente de abajo—, así que tras un error de validación el calendario quedaba muerto. Ahora es `date_picker_controller.js`, montado sobre el input, y Stimulus lo reconecta solo.

Expone `setDate()` porque con `altInput` escribir en el input original no repinta lo que el usuario ve; quien quiera cambiar la fecha desde fuera tiene que pasar por el controller.

*El formulario de recurrentes sigue con su `<script>` inline: allí no hay re-render por AJAX y su init está acoplado a la previsualización de frecuencia.*

### Fecha pegajosa

`TransactionDraftFactory` construye el borrador aplicando la última fecha usada en esa cuenta, guardada en sesión (`tx.last_date.<accountId>`). Existe como servicio porque el borrador se construye en tres sitios: listado, dashboard y formulario de página completa.

El alcance es la sesión a propósito: es un apaño para la tanda que estás metiendo, no una preferencia. Mañana lo correcto vuelve a ser hoy.

### Duplicar

Los disparadores llevan los datos en `data-tx-*` (macro `partials/macros/_tx_duplicate.html.twig`) y `tx-quick-add` los escucha **por delegación en `document`**, no con `data-action`: viven fuera del controller (tabla, tarjetas de móvil) e incluso en HTML inyectado después (el modal de resumen), donde Stimulus no tendría a quién enganchar.

El importe va con coma decimal y sin separador de miles: es lo que espera el `MoneyType` con locale `es` y lo único que acepta el `pattern` del campo.

**Duplicar copia; no sugiere.** Ningún campo despacha eventos al rellenarse.
`category-suggest` escucha `input` en el nombre **y `change` en el tipo**, y en
cuanto se entera pisa 350 ms después la categoría copiada. La primera versión
esquivaba el `input` pero se dejaba el `change`, y el resultado era un fallo
silencioso: duplicabas un movimiento y su categoría cambiaba sola al segundo. Por
eso el tipo se fija con `#setType()`, que le habla a `amount-type` directamente en
vez de despachar un evento que escuchan los dos.

**La fecha se copia como todo lo demás.** La primera versión la dejaba en la pegajosa de sesión, con el argumento de que duplicar sirve para registrar una ocurrencia nueva. Se descartó al probarlo: hacía que el resultado dependiera de un estado invisible —el mismo clic sobre la misma fila daba un formulario distinto según lo que hubieras hecho antes—. Copiándola, duplicar es función de la fila que ves. El campo se resalta con `cat-autofill-flash` para que la fecha copiada no pase inadvertida al guardar.

### Acciones en bloque: categorizar

`#bulkForm` pasa a servir para las dos acciones; `bulk_select_controller.js` reescribe `action` y `_token` antes de enviar (cada ruta tiene su propio token). Así no hay dos juegos de checkboxes que mantener sincronizados.

Las categorías están tipadas, así que una selección mixta no puede recibir todas la misma: los movimientos cuyo tipo no casa **se omiten y se cuentan** en el flash. Colarlas descuadraría los gráficos; omitirlas en silencio sería mentir.

> **Desde v1.5.2 ya no aplica.** Las categorías dejaron de tener tipo: el tipo es del movimiento y una misma categoría vale para gastos e ingresos, así que categorizar en bloque ya no omite nada. Donde hace falta saber con qué tipos se usa una categoría (agrupar el desplegable, ocultar opciones en los filtros) se deduce del historial con `CategoryUsage`.

### El modal de alta, también en Movimientos

Era la página donde uno se sienta a meter un extracto y era la única que mandaba al formulario de página completa. Salió casi gratis porque el dashboard y el listado ya compartían los mismos partials.

### Stimulus: controllers bajo demanda

`assets/stimulus_bootstrap.js` importaba y registraba los controllers a mano, lo que los hacía *eager*: se descargaban siempre, apareciera o no su `data-controller`. El registro manual sobraba — StimulusBundle ya descubre solo todo `assets/controllers/*_controller.js` y deriva el nombre del fichero (`amount_type_controller.js` → `amount-type`). El bootstrap se ha quedado en una línea.

Con el auto-registro, cada controller puede pedir que solo se descargue cuando su elemento aparece en el DOM:

```js
/* stimulusFetch: 'lazy' */
export default class extends Controller {
```

**Al añadir un controller nuevo: `lazy` por defecto.** Déjalo eager solo si intercepta un envío o bloquea una acción destructiva (`confirm`, `confirm-match`, `friendship`); ahí llegar tarde significaría dejar pasar el submit sin su comprobación.

En la home se pasó de descargar 12 controllers a 3.

### Librerías de terceros por página

`chart.js` y `flatpickr` se cargaban desde `base.html.twig` —o sea, en todas las páginas— y sin `defer`. Los usan 5. Ahora viven en `templates/partials/assets/` y cada página declara lo que necesita:

```twig
{% block page_assets %}
    {% include 'partials/assets/_flatpickr.html.twig' %}
{% endblock %}
```

> ⚠️ El bloque `page_assets` está **en medio del CSS** de `base.html.twig`, no al final del `<head>`, y no conviene moverlo: `general.css` retematiza flatpickr para el modo claro/oscuro (tiene que cargar después), y esos `<script>` van sin `defer` porque las plantillas los invocan desde el body.

### Otros

- `color_mode.js` iba con `defer`, así que el tema se aplicaba después del primer pintado y se colaba un fogonazo en claro al navegar con tema oscuro. Ahora va sin `defer` y por delante del resto de scripts.
- Eliminada `templates/partials/macros/_grafico.html.twig`: macro huérfana y comentada entera.

---

## Notas permanentes

**Turbo está desactivado a propósito** (`assets/controllers.json`). El refresco parcial se hace a mano con `fetch` + Stimulus; ver `friendship_controller.js` (patrón `{html, sections}`) y `tx_summary_controller.js`. Stimulus reconecta solo los `data-controller` del HTML inyectado, pero los scripts sueltos de `public/js/` no: corren una vez y no ven ese HTML nuevo.

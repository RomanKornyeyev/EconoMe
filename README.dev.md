# README dev

Notas técnicas de EconoMe para quien toca el código. Para instalar o desplegar, ver [README.md](README.md).

Formato changelog: lo más reciente arriba.

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

- válido → `200 {ok: true, item}` con la confirmación ya renderizada para la franja «Registrado»;
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

Editando se ocultan encadenar y el enlace a recurrentes: son piezas de "meter una
tanda", y editar es una acción única.

### Los atajos se anuncian en su propio botón

Había una línea de pistas al pie del modal («Enter guarda y sigue · N abre esta
ventana»). Se cambió por un distintivo `.btn-kbd` dentro del botón que dispara
cada atajo: `↵` en «Guardar y añadir otro», `N` en los dos «Nuevo movimiento».

Sale gratis en mantenimiento: el `↵` vive dentro del botón de encadenar, así que
se oculta con él en modo edición sin código extra —antes había que acordarse de
ocultar la pista aparte, y de hecho el controlador tenía un target `hint` solo
para eso—.

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

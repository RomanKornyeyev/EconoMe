# README dev

Notas técnicas de EconoMe para quien toca el código. Para instalar o desplegar, ver [README.md](README.md).

Formato changelog: lo más reciente arriba.

---

## v1.3.3 — Optimización de carga

Antes casi todo el JS se descargaba en todas las páginas. Ahora cada página carga solo lo suyo.

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

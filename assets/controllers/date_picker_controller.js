import { Controller } from '@hotwired/stimulus';

/**
 * Campo de fecha con flatpickr.
 *
 * Sustituye al <script> suelto que antes inicializaba `.flatpickr-date` al
 * cargar la página. Ese patrón corre una sola vez y no ve el HTML que se
 * inyecta después por AJAX (ver la nota permanente del README.dev), así que el
 * alta encadenada dejaba el calendario muerto en cuanto se re-renderizaba el
 * formulario. Como controller, Stimulus lo reconecta solo.
 *
 * Se monta sobre el propio input:
 *   {{ form_row(form.date, { attr: { 'data-controller': 'date-picker' } }) }}
 *
 * flatpickr se carga por página desde `partials/assets/_flatpickr.html.twig`.
 * Si no está presente, el controller se retira y deja un <input type="date">
 * nativo, que sigue siendo perfectamente usable.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  #picker = null;

  connect() {
    if (typeof window.flatpickr !== 'function') {
      return;
    }

    this.#picker = window.flatpickr(this.element, {
      locale: 'es',
      dateFormat: 'Y-m-d',
      altInput: true,
      altFormat: 'd/m/Y',
      altInputClass: this.element.classList.contains('form-control-sm')
        ? 'form-control form-control-sm'
        : 'form-control',
      allowInput: true,
      // Dentro de un modal, el calendario se recorta contra el overflow del
      // diálogo si cuelga del body.
      appendTo: this.element.closest('.modal') || document.body,
    });
  }

  disconnect() {
    // Devuelve el input original al DOM y se lleva el altInput que había
    // insertado al lado; sin esto, cada reconexión dejaría uno huérfano.
    this.#picker?.destroy();
    this.#picker = null;
  }

  /**
   * Fija la fecha desde fuera (alta encadenada, duplicar). Hay que pasar por la
   * API de flatpickr: con `altInput` activo, escribir en el input original no
   * repinta el campo que el usuario ve.
   *
   * @param {string|Date} date  Fecha en formato Y-m-d, o un Date.
   */
  setDate(date) {
    if (this.#picker) {
      this.#picker.setDate(date, false);
    } else {
      this.element.value = date;
    }
  }

  /** Fecha actual del campo en formato Y-m-d, o '' si está vacío. */
  getDate() {
    return this.element.value;
  }

  /**
   * El campo que el usuario ve. Con `altInput`, flatpickr oculta el input real
   * —el que lleva el `name`— y pinta otro al lado; quien quiera resaltar o medir
   * el campo tiene que ir a ese.
   */
  get visibleElement() {
    return this.#picker?.altInput ?? this.element;
  }
}

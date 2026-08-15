import { Controller } from '@hotwired/stimulus';

/**
 * Importe y tipo (gasto/ingreso) de un movimiento.
 *
 * El tipo se elige con un control segmentado en lugar del <select> nativo, cuya
 * lista despliega el sistema operativo y se sale del tema. El <select> se queda
 * oculto como fuente de verdad del formulario: al pulsar un segmento se escribe
 * su value y se lanza un `change` que burbujea, de modo que los controladores
 * enganchados al campo (category-suggest) siguen funcionando sin cambios.
 *
 * Cada cambio de tipo repinta el segmento activo, con las clases que declara
 * `data-amount-type-active-class` para no repetirlas entre la plantilla —que pinta
 * el estado inicial— y este controlador.
 *
 * Al abrir el formulario deja el foco en el importe, que es el campo por el que
 * se empieza siempre.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['select', 'item', 'amount'];
  static classes = ['active'];

  connect() {
    this.sync();

    // Dentro de un modal el campo todavía no es visible al conectar, y además
    // Bootstrap lleva el foco al propio modal al abrirlo; hay que esperar a
    // `shown.bs.modal`, que se dispara justo después, para no pelearse con él.
    this.modalEl = this.element.closest('.modal');
    if (this.modalEl) {
      this.modalEl.addEventListener('shown.bs.modal', this.#focusAmount);
    } else {
      this.#focusAmount();
    }
  }

  disconnect() {
    this.modalEl?.removeEventListener('shown.bs.modal', this.#focusAmount);
  }

  /** Clic en un segmento: escribe en el <select> y avisa al resto. */
  choose(event) {
    this.selectTarget.value = event.currentTarget.dataset.value;
    this.selectTarget.dispatchEvent(new Event('change', { bubbles: true }));
  }

  /** `change` del <select>: cubre tanto los segmentos como cambios externos. */
  sync() {
    for (const item of this.itemTargets) {
      const active = item.dataset.value === this.selectTarget.value;
      item.classList.remove(...this.activeClasses);
      item.setAttribute('aria-pressed', String(active));
      if (active) {
        item.classList.add(...this.activeClasses);
      }
    }
  }

  /**
   * Foco en el importe al abrir el formulario, para poder teclear y guardar sin
   * más pasos. Se selecciona el contenido para que al editar baste con escribir
   * encima del valor anterior.
   */
  #focusAmount = () => {
    this.amountTarget.focus();
    this.amountTarget.select();
  };
}

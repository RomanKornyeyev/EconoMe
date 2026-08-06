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
 * Cada cambio de tipo repinta el segmento activo. El color vive solo ahí: el
 * campo del importe se queda neutro, porque teñir lo que se está tecleando cansa
 * la vista.
 *
 * Las clases de cada tipo viajan en su propio <option> (choice_attr en el
 * FormType), para no repetir aquí la correspondencia.
 *
 * Al abrir el formulario deja el foco en el importe, que es el campo por el que
 * se empieza siempre.
 */
export default class extends Controller {
  static targets = ['select', 'item', 'amount'];

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
    const option = this.selectTarget.selectedOptions[0];
    if (!option) {
      return;
    }

    const tints = this.#family('btn');
    for (const item of this.itemTargets) {
      const active = item.dataset.value === this.selectTarget.value;
      item.classList.remove(...tints);
      item.classList.toggle('fw-semibold', active);
      item.setAttribute('aria-pressed', String(active));
      if (active) {
        item.classList.add(...this.#classes(option.dataset.btn));
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

  /** Todas las clases que cualquier opción declara en `data-<key>`. */
  #family(key) {
    return [...this.selectTarget.options].flatMap((o) => this.#classes(o.dataset[key]));
  }

  /** classList sólo admite clases sueltas: parte la cadena y descarta vacíos. */
  #classes(value) {
    return (value ?? '').split(' ').filter(Boolean);
  }
}

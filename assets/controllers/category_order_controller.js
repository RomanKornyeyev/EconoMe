import { Controller } from '@hotwired/stimulus';

/**
 * Agrupa el <select> de categoría de un movimiento según el tipo elegido.
 *
 * Las categorías no tienen tipo propio: una misma puede llevar gastos e
 * ingresos. Lo que sí se sabe es con qué tipos se ha usado cada una, y el
 * servidor lo pinta en `data-used-as` de cada opción ("expense", "income",
 * "expense income" o vacío si aún no tiene movimientos).
 *
 * Con eso, al elegir «Gasto» se ven primero las categorías usadas en gastos y
 * después el resto; al cambiar a «Ingreso» se reordena. Si todas caen en el
 * mismo lado, la lista se queda plana: un único grupo no aporta nada. Sin JS,
 * la lista sale plana y alfabética, que también vale.
 *
 * El tipo se lee del campo hermano `[type]` del mismo formulario, igual que
 * tx-quick-add localiza sus campos, para no obligar a las plantillas a envolver
 * los dos campos en un mismo elemento. Ese campo lanza `change` al pulsar el
 * segmentado (amount-type); quien cambie el tipo sin evento —duplicar en el
 * alta rápida— llama a `reorder()` directamente.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static LABELS = {
    expense: 'Usadas en gastos',
    income: 'Usadas en ingresos',
  };

  #typeField = null;
  #options = [];

  connect() {
    this.#typeField = this.element.form?.querySelector('[name$="[type]"]') ?? null;
    // Orden alfabético del servidor; se respeta dentro de cada grupo.
    this.#options = [...this.element.querySelectorAll('option[data-used-as]')];
    this.#typeField?.addEventListener('change', this.reorder);
    this.reorder();
  }

  disconnect() {
    this.#typeField?.removeEventListener('change', this.reorder);
  }

  reorder = () => {
    const type = this.#typeField?.value ?? '';
    const label = this.constructor.LABELS[type];
    const value = this.element.value;

    const used = [];
    const rest = [];
    for (const option of this.#options) {
      (option.dataset.usedAs.split(' ').includes(type) ? used : rest).push(option);
    }

    // Las opciones se mueven, no se clonan: conservan su estado y lo que otros
    // controladores (category-suggest) tengan apuntado sobre el campo.
    this.element.querySelectorAll('optgroup').forEach(group => group.remove());
    if (label && used.length > 0 && rest.length > 0) {
      this.element.append(this.#group(label, used), this.#group('Otras categorías', rest));
    } else {
      this.element.append(...this.#options);
    }

    this.element.value = value;
  };

  #group(label, options) {
    const group = document.createElement('optgroup');
    group.label = label;
    group.append(...options);
    return group;
  }
}

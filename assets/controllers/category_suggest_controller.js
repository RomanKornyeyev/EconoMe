import { Controller } from '@hotwired/stimulus';

/**
 * Sugerencia semiautomática de categoría al crear un movimiento.
 *
 * Al escribir el nombre (o cambiar el tipo), pide al servidor la categoría que
 * el usuario suele asignar a movimientos con ese nombre y:
 *   · muestra un badge (borde verde, informativo) con esa categoría;
 *   · aplica SIEMPRE esa categoría en el campo, con un resaltado breve — el
 *     "humano perezoso" no hace nada;
 *   · en la línea 2 marca "✓ Rellenada automáticamente" si la sugerida es la que
 *     está puesta, o el enlace "Aplicar" si el usuario cambió a otra.
 *
 * El cambio manual es SIEMPRE libre y nunca desactiva el automático: cada nueva
 * sugerencia (nombre/tipo distinto) se vuelve a aplicar. Solo deshacemos lo que
 * pusimos nosotros; una categoría elegida a mano no se borra sola.
 *
 * Sigue el patrón de user-search: fetch con debounce e inyección de HTML.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['name', 'type', 'category', 'hint'];
  static values  = { url: String };

  #timer = null;
  #lastQuery = null;
  #autofilledId = null;   // categoría que rellenamos nosotros (para poder revertir)

  connect() {
    this.request();
  }

  /** input en nombre / change en tipo → refresca la sugerencia (con debounce). */
  request() {
    clearTimeout(this.#timer);
    const name = this.nameTarget.value.trim();
    if (name.length < 2) {
      this.#clearHint();
      return;
    }
    this.#timer = setTimeout(() => this.#fetch(name), 350);
  }

  /** change del <select>: el usuario elige a mano → solo refresca el estado
   *  (check / aplicar). No desactiva el autorrelleno de futuras sugerencias. */
  onCategoryChange() {
    this.#updateSelectedFlag();
  }

  /** Clic en "Aplicar": poner la categoría sugerida en el campo. */
  apply() {
    const suggestedId = this.#suggestedId();
    if (suggestedId === null) {
      return;
    }
    this.categoryTarget.value = suggestedId;
    this.#autofilledId = suggestedId;
    this.#flashField();
    this.#updateSelectedFlag();
  }

  async #fetch(name) {
    const type = this.hasTypeTarget ? this.typeTarget.value : '';
    const key = `${type} ${name}`;
    if (key === this.#lastQuery) {
      return;
    }
    this.#lastQuery = key;

    const url = new URL(this.urlValue, window.location.origin);
    url.searchParams.set('name', name);
    url.searchParams.set('type', type);

    try {
      const response = await fetch(url.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!response.ok) {
        return; // 403/otros: fallo silencioso, se reintenta al escribir
      }
      this.hintTarget.innerHTML = (await response.text()).trim();
      this.#present();
    } catch {
      // Fallo de red silencioso
    }
  }

  /** Aplica la sugerencia actual (siempre) y refresca el estado de la línea 2. */
  #present() {
    const suggestedId = this.#suggestedId();

    if (suggestedId !== null) {
      // Aplica siempre la sugerencia, aunque el usuario hubiera cambiado antes.
      if (this.categoryTarget.value !== suggestedId) {
        this.categoryTarget.value = suggestedId;
        this.#flashField();
      }
      this.#autofilledId = suggestedId;
    } else {
      // Sin sugerencia: deshaz solo lo que pusimos nosotros; respeta lo manual.
      if (this.#autofilledId !== null && this.categoryTarget.value === this.#autofilledId) {
        this.categoryTarget.value = '';
      }
      this.#autofilledId = null;
    }

    this.#updateSelectedFlag();
  }

  #clearHint() {
    this.#lastQuery = null;
    this.hintTarget.innerHTML = '';
    // Si lo nuestro seguía puesto y el nombre ya no da señal, deshacerlo.
    if (this.#autofilledId !== null && this.categoryTarget.value === this.#autofilledId) {
      this.categoryTarget.value = '';
    }
    this.#autofilledId = null;
  }

  /**
   * Línea 2 del chip: "✓ Rellenada automáticamente" si la categoría puesta es la
   * sugerida; si no, el enlace "Aplicar". Alterna d-none/d-inline-flex (ambas
   * !important) en lugar del atributo hidden, que pierde ante el !important de
   * las utilidades de Bootstrap.
   */
  #updateSelectedFlag() {
    const suggestedId = this.#suggestedId();
    const selected = suggestedId !== null && this.categoryTarget.value === suggestedId;
    this.#toggleInline('.cat-suggest-selected', selected);
    this.#toggleInline('.cat-suggest-apply', suggestedId !== null && !selected);
  }

  #toggleInline(selector, visible) {
    const el = this.hintTarget.querySelector(selector);
    if (el) {
      el.classList.toggle('d-none', !visible);
      el.classList.toggle('d-inline-flex', visible);
    }
  }

  /** Id de la categoría sugerida en el chip actual, o null si no hay chip. */
  #suggestedId() {
    const wrapper = this.hintTarget.querySelector('[data-category-id]');
    return wrapper ? wrapper.dataset.categoryId : null;
  }

  /** Resalta brevemente el select para que se vea que se rellenó solo. */
  #flashField() {
    const el = this.categoryTarget;
    el.classList.remove('cat-autofill-flash');
    void el.offsetWidth; // reinicia la animación
    el.classList.add('cat-autofill-flash');
    el.addEventListener('animationend', () => el.classList.remove('cat-autofill-flash'), { once: true });
  }
}

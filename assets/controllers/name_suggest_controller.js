import { Controller } from '@hotwired/stimulus';

/**
 * Sugerencias de nombre al crear un movimiento: como mucho dos nombres ya
 * usados en la cuenta, en el <datalist> del campo.
 *
 * Sustituye al autocompletado del navegador (el campo va con
 * autocomplete="off"), que no se puede limitar y enseñaba cada variante de
 * mayúsculas como una sugerencia distinta. Aquí la lista la decide el servidor
 * ({@see TransactionController::suggestNames}): agrupada, sin el nombre ya
 * escrito y salida del historial de la cuenta, no del de este navegador.
 *
 * El desplegable lo sigue pintando el navegador: su aspecto no se controla.
 *
 * Montado en el <form>, como category-suggest. Editando, el campo no lleva los
 * targets y el controlador se queda quieto.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['input', 'list'];
  static values  = { url: String };

  #timer = null;
  #lastQuery = null;
  #abort = null;

  disconnect() {
    clearTimeout(this.#timer);
    this.#abort?.abort();
  }

  /** input en el nombre → refresca la lista (con debounce). */
  request() {
    if (!this.hasInputTarget || !this.hasListTarget) return;

    clearTimeout(this.#timer);
    const q = this.inputTarget.value.trim();
    if (q.length < 2) {
      this.#render([]);
      return;
    }
    this.#timer = setTimeout(() => this.#fetch(q), 200);
  }

  async #fetch(q) {
    if (q === this.#lastQuery) return;

    // Una respuesta lenta no puede pisar la de lo que se ha escrito después
    this.#abort?.abort();
    this.#abort = new AbortController();

    const url = new URL(this.urlValue, window.location.origin);
    url.searchParams.set('q', q);

    try {
      const response = await fetch(url.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: this.#abort.signal,
      });
      if (!response.ok) return; // fallo silencioso, se reintenta al escribir

      this.#lastQuery = q;
      this.#render(await response.json());
    } catch {
      // Abortada o fallo de red: silencioso
    }
  }

  #render(names) {
    if (names.length === 0) this.#lastQuery = null;

    this.listTarget.replaceChildren(...names.map((name) => {
      const option = document.createElement('option');
      option.value = name;
      return option;
    }));
  }
}

import { Controller } from '@hotwired/stimulus';

/**
 * Filtros del listado de movimientos en móvil: panel inferior con tipo,
 * fechas, categoría e importe (transaction/_filters_mobile.html.twig).
 *
 * Aplicar sigue siendo enviar el formulario GET, como en escritorio: la URL
 * guarda los filtros y la paginación sigue funcionando. Lo dinámico es el
 * botón del panel, que mientras se toca anuncia cuántos movimientos saldrían
 * («Ver 38 movimientos») preguntando a /transaction/count.
 *
 * Cerrar el panel sin aplicar descarta lo tocado: al volver a abrirlo se ve lo
 * que está aplicado de verdad, que es lo que dicen las etiquetas.
 *
 * Las fechas las lleva el controller date-range (calendario con atajos y
 * «Limpiar»), compartido con escritorio. Aquí solo se le escucha
 * (`date-range:change` → recuento) y se le pide limpiar o restaurar.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['sheet', 'type', 'category', 'submit'];
  static values  = { countUrl: String, count: Number };
  static outlets = ['date-range'];

  /** Píxeles que hay que arrastrar el panel hacia abajo para cerrarlo. */
  static DRAG_CLOSE = 80;

  #timer = null;
  #request = null;
  #applying = false;
  #initial = new Map();
  #dragStartY = null;
  #dragY = 0;

  connect() {
    // Lo aplicado, para restaurarlo si se cierra sin aplicar. Se guarda a mano
    // y no se tira de defaultValue: en un input hidden, asignar value pisa
    // también el atributo, y con él el valor por defecto.
    for (const field of this.#fields()) {
      this.#initial.set(field, field.value);
    }

    this.#filterCategories();

    this.onHidden = () => this.#discard();
    this.sheetTarget.addEventListener('hidden.bs.offcanvas', this.onHidden);
  }

  disconnect() {
    this.sheetTarget.removeEventListener('hidden.bs.offcanvas', this.onHidden);
    clearTimeout(this.#timer);
    this.#request?.abort();
  }

  // ── Acciones ──────────────────────────────────────────────────────────

  /** Cualquier cambio dentro del panel recalcula el recuento. */
  refresh(event) {
    if (!this.sheetTarget.contains(event.target)) {
      return;
    }
    if (event.target === this.typeTarget) {
      this.#filterCategories();
    }
    this.#scheduleCount();
  }

  submit() {
    // El panel se cierra al navegar: que no restaure nada por el camino.
    this.#applying = true;
  }

  /** «Limpiar todo»: vacía el panel, pero no aplica hasta pulsar «Ver». */
  clear() {
    this.typeTarget.value = '';
    this.categoryTarget.value = '';
    if (this.hasDateRangeOutlet) {
      this.dateRangeOutlet.clear();
    }
    for (const input of this.sheetTarget.querySelectorAll('input[type="number"]')) {
      input.value = '';
    }
    this.#filterCategories();
    this.#scheduleCount();
  }

  // ── Arrastrar para cerrar ─────────────────────────────────────────────

  dragStart(event) {
    this.#dragStartY = event.touches[0].clientY;
    this.#dragY = 0;
    // Mientras se arrastra, el panel sigue al dedo sin animación.
    this.sheetTarget.style.transition = 'none';
  }

  dragMove(event) {
    if (this.#dragStartY === null) {
      return;
    }
    this.#dragY = Math.max(0, event.touches[0].clientY - this.#dragStartY);
    this.sheetTarget.style.transform = `translateY(${this.#dragY}px)`;
  }

  dragEnd() {
    if (this.#dragStartY === null) {
      return;
    }
    this.#dragStartY = null;

    // Se sueltan los estilos en línea en el mismo fotograma en que se decide:
    // la transición de Bootstrap arranca desde donde quedó el dedo, ya sea
    // para recolocar el panel o para sacarlo de la pantalla.
    this.sheetTarget.style.transition = '';
    this.sheetTarget.style.transform = '';
    if (this.#dragY > this.constructor.DRAG_CLOSE) {
      window.bootstrap?.Offcanvas.getOrCreateInstance(this.sheetTarget).hide();
    }
  }

  // ── Recuento ──────────────────────────────────────────────────────────

  #scheduleCount() {
    clearTimeout(this.#timer);
    this.#timer = setTimeout(() => this.#fetchCount(), 250);
  }

  async #fetchCount() {
    // Solo cuenta la última petición: si el usuario sigue tocando, la anterior
    // ya no describe lo que hay en pantalla.
    this.#request?.abort();
    this.#request = new AbortController();

    const url = new URL(this.countUrlValue, window.location.origin);
    for (const [key, value] of new FormData(this.element)) {
      if (value !== '') {
        url.searchParams.set(key, value);
      }
    }

    this.submitTarget.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        signal: this.#request.signal,
      });
      if (response.ok) {
        const { count } = await response.json();
        this.#renderCount(count);
      }
    } catch {
      // Abortada o sin red: el botón se queda con la última cifra buena, y
      // aplicar sigue funcionando porque es un envío normal del formulario.
    } finally {
      this.submitTarget.removeAttribute('aria-busy');
    }
  }

  #renderCount(count) {
    const formatted = String(count).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    this.submitTarget.textContent = `Ver ${formatted} ${count === 1 ? 'movimiento' : 'movimientos'}`;
  }

  // ── Restaurar al cerrar ───────────────────────────────────────────────

  #discard() {
    if (this.#applying) {
      return;
    }
    clearTimeout(this.#timer);
    this.#request?.abort();

    for (const [field, value] of this.#initial) {
      field.value = value;
    }
    // Los hidden de las fechas ya están restaurados con el resto; falta
    // que el calendario los vuelva a pintar.
    if (this.hasDateRangeOutlet) {
      this.dateRangeOutlet.reload();
    }
    this.#filterCategories();
    this.#renderCount(this.countValue);
  }

  /** Campos del panel que viajan con el formulario. */
  #fields() {
    return [...this.sheetTarget.querySelectorAll('input[name], select[name]')];
  }

  // ── Categorías según el tipo ──────────────────────────────────────────

  /**
   * Con un tipo elegido, oculta las categorías sin ningún movimiento de ese
   * tipo: filtrar por ellas daría siempre cero resultados. `data-used-as` lo
   * pinta el servidor a partir del historial (ver CategoryUsage).
   */
  #filterCategories() {
    const type = this.typeTarget.value;
    for (const option of this.categoryTarget.querySelectorAll('option[data-used-as]')) {
      const matches = !type || option.dataset.usedAs.split(' ').includes(type);
      option.hidden = !matches;
      option.disabled = !matches;
    }
    const selected = this.categoryTarget.selectedOptions[0];
    if (selected?.disabled) {
      this.categoryTarget.value = '';
    }
  }
}

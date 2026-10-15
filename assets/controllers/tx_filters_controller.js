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
 * Fechas con flatpickr en modo rango (partials/assets/_flatpickr.html.twig).
 * Si no está cargado, el campo de rango se queda inerte y siguen valiendo los
 * atajos «Este mes», «Mes pasado» y «Últimos 3 meses».
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['sheet', 'type', 'category', 'range', 'dateFrom', 'dateTo', 'preset', 'submit'];
  static values  = { countUrl: String, count: Number };

  /** Píxeles que hay que arrastrar el panel hacia abajo para cerrarlo. */
  static DRAG_CLOSE = 80;

  #picker = null;
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

    this.#initPicker();
    this.#filterCategories();
    this.#markPreset();

    this.onHidden = () => this.#discard();
    this.sheetTarget.addEventListener('hidden.bs.offcanvas', this.onHidden);
  }

  disconnect() {
    this.sheetTarget.removeEventListener('hidden.bs.offcanvas', this.onHidden);
    clearTimeout(this.#timer);
    this.#request?.abort();
    this.#picker?.destroy();
    this.#picker = null;
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
    this.#setRange([]);
    for (const input of this.sheetTarget.querySelectorAll('input[type="number"]')) {
      input.value = '';
    }
    this.#filterCategories();
    this.#scheduleCount();
  }

  /** Atajo de fechas. Pulsar el que ya está marcado lo desmarca. */
  preset({ params: { range }, currentTarget }) {
    if (currentTarget.classList.contains('active')) {
      this.#setRange([]);
    } else {
      this.#setRange(this.#presetDates(range));
    }
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
    this.#picker?.setDate(this.#hiddenDates(), false);
    this.#tidyDisplay();
    this.#filterCategories();
    this.#markPreset();
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

  // ── Fechas ────────────────────────────────────────────────────────────

  #initPicker() {
    if (typeof window.flatpickr !== 'function') {
      this.rangeTarget.disabled = true;
      return;
    }

    this.#picker = window.flatpickr(this.rangeTarget, {
      mode: 'range',
      locale: 'es',
      dateFormat: 'Y-m-d',
      altInput: true,
      altFormat: 'd/m/Y',
      altInputClass: 'form-control',
      defaultDate: this.#hiddenDates(),
      // Dentro del panel y no en el body: el offcanvas atrapa el foco, y desde
      // fuera no se podría ni cambiar de mes en el desplegable.
      appendTo: this.sheetTarget,
      position: (fp) => this.#placeCalendar(fp),
      onChange: (dates) => {
        this.#syncHidden(dates);
        this.#markPreset();
      },
      onClose: (dates, dateStr, fp) => {
        // Rango a medias (una sola fecha): se consolida como día único.
        if (dates.length === 1) {
          fp.setDate([dates[0], dates[0]], false);
        }
        this.#syncHidden(fp.selectedDates);
        this.#tidyDisplay();
        this.#markPreset();
        this.#scheduleCount();
      },
      onReady: () => this.#tidyDisplay(),
    });
  }

  /**
   * Coloca el calendario respecto al campo. El panel es `position: fixed`, así
   * que las coordenadas de documento que calcula flatpickr no valen dentro de
   * él. Va encima del campo si cabe —el panel está pegado al fondo de la
   * pantalla— y debajo si no.
   */
  #placeCalendar(fp) {
    const calendar = fp.calendarContainer;
    const field = (fp.altInput || fp.input).getBoundingClientRect();
    const sheet = this.sheetTarget.getBoundingClientRect();
    const height = calendar.offsetHeight;
    const above = field.top - height - 4 >= 0;

    const top = above ? field.top - sheet.top - height - 4 : field.bottom - sheet.top + 4;
    const maxLeft = sheet.width - calendar.offsetWidth - 8;
    const left = Math.max(8, Math.min(field.left - sheet.left, maxLeft));

    calendar.style.top = `${top}px`;
    calendar.style.left = `${left}px`;
    calendar.style.right = 'auto';
    calendar.classList.toggle('arrowBottom', above);
    calendar.classList.toggle('arrowTop', !above);
  }

  #setRange(dates) {
    if (this.#picker) {
      this.#picker.setDate(dates, false);
      this.#tidyDisplay();
    }
    this.#syncHidden(dates);
    this.#markPreset();
  }

  #syncHidden(dates) {
    const from = dates[0] ? this.#ymd(dates[0]) : '';
    // Con un solo día seleccionado se filtra exactamente ese día.
    const to = dates[1] ? this.#ymd(dates[1]) : from;
    this.dateFromTarget.value = from;
    this.dateToTarget.value = to;
  }

  #hiddenDates() {
    return [this.dateFromTarget.value, this.dateToTarget.value].filter(Boolean);
  }

  /** Si inicio y fin coinciden, muestra la fecha una vez en vez de «X a X». */
  #tidyDisplay() {
    const fp = this.#picker;
    const dates = fp?.selectedDates ?? [];
    if (dates.length === 2 && dates[0].getTime() === dates[1].getTime()) {
      fp.altInput.value = fp.formatDate(dates[0], 'd/m/Y');
    }
  }

  /** Marca el atajo cuyo rango coincide con el elegido, si alguno. */
  #markPreset() {
    const from = this.dateFromTarget.value;
    const to = this.dateToTarget.value;
    for (const button of this.presetTargets) {
      const [start, end] = this.#presetDates(button.dataset.txFiltersRangeParam).map((d) => this.#ymd(d));
      const active = from !== '' && from === start && to === end;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', String(active));
    }
  }

  #presetDates(range) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const y = today.getFullYear();
    const m = today.getMonth();

    switch (range) {
      case 'month':
        return [new Date(y, m, 1), today];
      case 'lastMonth':
        // Día 0 del mes en curso = último día del anterior.
        return [new Date(y, m - 1, 1), new Date(y, m, 0)];
      case 'last3': {
        // Mismo día de hace tres meses. Si ese mes es más corto (31 de mayo →
        // 31 de febrero), se queda en su último día en vez de desbordar.
        const start = new Date(y, m - 3, 1);
        const lastDay = new Date(y, m - 2, 0).getDate();
        start.setDate(Math.min(today.getDate(), lastDay));
        return [start, today];
      }
      default:
        return [];
    }
  }

  #ymd(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }
}

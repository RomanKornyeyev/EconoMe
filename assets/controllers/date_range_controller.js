import { Controller } from '@hotwired/stimulus';

/**
 * Selector de rango de fechas con flatpickr, con atajos («Este mes», «Mes
 * anterior»…) y «Limpiar» dentro del propio calendario.
 *
 * Lo usan los filtros del listado de movimientos, en escritorio
 * (transaction/index) y en el panel del móvil (transaction/_filters_mobile).
 * La fecha suelta de los formularios es otra cosa: date_picker_controller.js.
 *
 * Marcado: un campo visible y dos hidden, que son los que viajan al backend.
 *
 *   <div data-controller="date-range" data-date-range-layout-value="side">
 *     <input type="text" data-date-range-target="input">
 *     <input type="hidden" name="date_from" data-date-range-target="from">
 *     <input type="hidden" name="date_to" data-date-range-target="to">
 *   </div>
 *
 * Layout de los atajos:
 *   · side   — panel lateral a la izquierda del calendario (escritorio).
 *   · bottom — rejilla de botones debajo (móvil, donde no cabe al lado).
 *
 * Cada cambio hecho por el usuario (cerrar el calendario, un atajo, limpiar)
 * emite `date-range:change`, que burbujea: así tx-filters recalcula su
 * recuento. Elegir no envía el formulario; eso sigue siendo cosa del botón.
 *
 * flatpickr se carga por página desde partials/assets/_flatpickr.html.twig. Sin
 * él, el campo visible se queda inerte y los hidden conservan lo aplicado.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['input', 'from', 'to'];
  static values  = { layout: { type: String, default: 'side' } };

  static PRESETS = [
    ['month', 'Este mes'],
    ['lastMonth', 'Mes anterior'],
    ['last3', 'Últimos 3 meses'],
    ['year', 'Año actual'],
  ];

  #picker = null;

  connect() {
    if (typeof window.flatpickr !== 'function') {
      this.inputTarget.disabled = true;
      return;
    }

    // Dentro de un offcanvas el calendario cuelga del propio panel: el
    // offcanvas atrapa el foco, y desde el body no se podría ni cambiar de mes.
    const sheet = this.element.closest('.offcanvas');

    this.#picker = window.flatpickr(this.inputTarget, {
      mode: 'range',
      locale: 'es',
      dateFormat: 'Y-m-d',
      altInput: true,
      altFormat: 'd/m/Y',
      altInputClass: this.inputTarget.className,
      defaultDate: this.#hiddenDates(),
      ...(sheet && { appendTo: sheet, position: (fp) => this.#placeInSheet(fp, sheet) }),
      onChange: (dates) => {
        this.#syncHidden(dates);
        this.#markPreset();
      },
      onClose: (dates, dateStr, fp) => {
        // Rango a medias (una sola fecha): se consolida como día único.
        if (dates.length === 1) {
          fp.setDate([dates[0], dates[0]], false);
        }
        this.#commit();
      },
      onReady: (dates, dateStr, fp) => {
        // flatpickr() llama a onReady antes de devolver la instancia: sin esto,
        // #picker sigue a null y ni se marca el atajo del rango que ya viene
        // aplicado (p. ej. el mes en curso por defecto) ni se ordena el campo.
        this.#picker = fp;
        this.#buildPresets(fp);
        this.#markPreset();
        this.#tidyDisplay();
      },
    });
  }

  disconnect() {
    // Devuelve el input original y se lleva el altInput y el calendario.
    this.#picker?.destroy();
    this.#picker = null;
  }

  // ── API para otros controllers (outlet desde tx-filters) ──────────────

  /** Vacía el rango. No emite `change`: quien lo pide ya sabe que cambió. */
  clear() {
    this.#picker?.clear(false);
    this.#syncHidden([]);
    this.#markPreset();
  }

  /** Vuelve a pintar el calendario a partir de los hidden (p. ej. tras restaurarlos). */
  reload() {
    this.#picker?.setDate(this.#hiddenDates(), false);
    this.#tidyDisplay();
    this.#markPreset();
  }

  // ── Atajos ────────────────────────────────────────────────────────────

  #buildPresets(fp) {
    const side = this.layoutValue === 'side';
    const panel = document.createElement('div');
    panel.className = `fp-presets fp-presets--${side ? 'side' : 'bottom'}`;

    if (side) {
      const title = document.createElement('div');
      title.className = 'fp-presets__title';
      title.textContent = 'Atajos';
      panel.append(title);
    }

    const list = document.createElement('div');
    list.className = 'fp-presets__list';
    for (const [range, label] of this.constructor.PRESETS) {
      const button = this.#button(label, 'fp-presets__item', () => this.#apply(range));
      button.dataset.range = range;
      button.setAttribute('aria-pressed', 'false');
      list.append(button);
    }
    panel.append(list);

    const clear = this.#button('Limpiar', 'fp-presets__clear', () => this.#clearFromCalendar());
    clear.insertAdjacentHTML('afterbegin', '<i class="fa-solid fa-eraser" aria-hidden="true"></i>');
    panel.append(clear);

    if (side) {
      // El calendario de flatpickr pasa a la columna derecha y los atajos a la
      // izquierda. Mover sus nodos no rompe nada: guarda referencias, no rutas.
      const main = document.createElement('div');
      main.className = 'fp-main';
      main.append(...fp.calendarContainer.children);
      const layout = document.createElement('div');
      layout.className = 'fp-layout';
      layout.append(panel, main);
      fp.calendarContainer.append(layout);
      fp.calendarContainer.classList.add('fp-has-side');
    } else {
      fp.calendarContainer.append(panel);
    }
  }

  #button(label, className, onClick) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = className;
    button.textContent = label;
    button.addEventListener('click', onClick);
    return button;
  }

  #apply(range) {
    this.#picker.setDate(this.#presetDates(range), false);
    this.#syncHidden(this.#picker.selectedDates);
    this.#picker.close();
  }

  #clearFromCalendar() {
    this.clear();
    this.#picker.close();
  }

  /** Marca el atajo cuyo rango coincide con el elegido, si alguno. */
  #markPreset() {
    const container = this.#picker?.calendarContainer;
    if (!container) {
      return;
    }
    const from = this.fromTarget.value;
    const to = this.toTarget.value;
    for (const button of container.querySelectorAll('.fp-presets__item')) {
      const [start, end] = this.#presetDates(button.dataset.range).map((d) => this.#ymd(d));
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
        // El mes entero, como el que abre el listado por defecto
        // (TransactionController::index): así el atajo sale marcado.
        return [new Date(y, m, 1), new Date(y, m + 1, 0)];
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
      case 'year':
        return [new Date(y, 0, 1), new Date(y, 11, 31)];
      default:
        return [];
    }
  }

  // ── Sincronía con los hidden ──────────────────────────────────────────

  /** Cierre del calendario: deja los hidden y el campo al día y lo anuncia. */
  #commit() {
    this.#syncHidden(this.#picker.selectedDates);
    this.#tidyDisplay();
    this.#markPreset();
    this.dispatch('change', { detail: { from: this.fromTarget.value, to: this.toTarget.value } });
  }

  #syncHidden(dates) {
    const from = dates[0] ? this.#ymd(dates[0]) : '';
    // Con un solo día seleccionado se filtra exactamente ese día.
    const to = dates[1] ? this.#ymd(dates[1]) : from;
    this.fromTarget.value = from;
    this.toTarget.value = to;
  }

  #hiddenDates() {
    return [this.fromTarget.value, this.toTarget.value].filter(Boolean);
  }

  /** Si inicio y fin coinciden, muestra la fecha una vez en vez de «X a X». */
  #tidyDisplay() {
    const fp = this.#picker;
    const dates = fp?.selectedDates ?? [];
    if (dates.length === 2 && dates[0].getTime() === dates[1].getTime()) {
      fp.altInput.value = fp.formatDate(dates[0], 'd/m/Y');
    }
  }

  // ── Posición dentro de un offcanvas ───────────────────────────────────

  /**
   * El panel es `position: fixed`, así que las coordenadas de documento que
   * calcula flatpickr no valen dentro de él. Va encima del campo si cabe —el
   * panel está pegado al fondo de la pantalla— y debajo si no.
   */
  #placeInSheet(fp, sheet) {
    const calendar = fp.calendarContainer;
    const field = (fp.altInput || fp.input).getBoundingClientRect();
    const box = sheet.getBoundingClientRect();
    const height = calendar.offsetHeight;
    const above = field.top - height - 4 >= 0;

    const top = above ? field.top - box.top - height - 4 : field.bottom - box.top + 4;
    const maxLeft = box.width - calendar.offsetWidth - 8;
    const left = Math.max(8, Math.min(field.left - box.left, maxLeft));

    calendar.style.top = `${top}px`;
    calendar.style.left = `${left}px`;
    calendar.style.right = 'auto';
    calendar.classList.toggle('arrowBottom', above);
    calendar.classList.toggle('arrowTop', !above);
  }

  #ymd(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }
}

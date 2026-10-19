import { Controller } from '@hotwired/stimulus';

/**
 * Buscador en cliente sobre las filas de una lista ya pintada.
 *
 * Oculta las filas cuyo texto no contiene lo escrito, sin distinguir mayúsculas
 * ni acentos («nomina» encuentra «Nómina»). El texto de cada fila sale de su
 * `data-filter-text` o, si no lo tiene, de su contenido.
 *
 * Admite varios buscadores y varias listas a la vez (p. ej. la lista de móvil y
 * la tabla de escritorio de una misma página): los buscadores se mantienen
 * sincronizados y cada lista tiene su propio aviso de «sin resultados».
 *
 * Uso:
 *   <div data-controller="table-filter">
 *     <input data-table-filter-target="input"
 *            data-action="input->table-filter#filter keydown.esc->table-filter#clear">
 *     <tr data-table-filter-target="row" data-filter-text="Alimentación">…</tr>
 *     <tr data-table-filter-target="empty" hidden>Sin resultados</tr>  (opcional)
 *   </div>
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['input', 'row', 'empty'];

  filter(event) {
    const value = event?.target?.value ?? '';
    for (const input of this.inputTargets) {
      if (input !== event?.target) input.value = value;
    }

    const query = normalize(value.trim());
    for (const row of this.rowTargets) {
      row.hidden = query !== '' && !normalize(row.dataset.filterText ?? row.textContent).includes(query);
    }

    // Cada aviso mira solo las filas de su propia lista
    for (const empty of this.emptyTargets) {
      const rows = this.rowTargets.filter(row => row.parentElement === empty.parentElement);
      empty.hidden = rows.some(row => !row.hidden);
    }
  }

  /** Escape: vacía la búsqueda y vuelve a enseñarlo todo. */
  clear(event) {
    event.target.value = '';
    this.filter(event);
  }
}

function normalize(text) {
  return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
}

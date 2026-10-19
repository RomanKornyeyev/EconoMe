import { Controller } from '@hotwired/stimulus';

/**
 * Buscador en cliente sobre las filas de una lista ya pintada.
 *
 * Oculta las filas cuyo texto no contiene lo escrito, sin distinguir mayúsculas
 * ni acentos («nomina» encuentra «Nómina»). El texto de cada fila sale de su
 * `data-filter-text` o, si no lo tiene, de su contenido.
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

  filter() {
    const query = normalize(this.inputTarget.value.trim());
    let visible = 0;

    for (const row of this.rowTargets) {
      const match = query === '' || normalize(row.dataset.filterText ?? row.textContent).includes(query);
      row.hidden = !match;
      if (match) visible++;
    }

    if (this.hasEmptyTarget) {
      this.emptyTarget.hidden = visible > 0;
    }
  }

  /** Escape: vacía la búsqueda y vuelve a enseñarlo todo. */
  clear() {
    this.inputTarget.value = '';
    this.filter();
  }
}

function normalize(text) {
  return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
}

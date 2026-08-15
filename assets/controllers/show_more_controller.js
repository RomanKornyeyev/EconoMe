import { Controller } from '@hotwired/stimulus';

/**
 * Revela una lista larga por lotes ("Mostrar más").
 *
 * Los elementos que superan el lote inicial se renderizan ya ocultos (con
 * d-none) y marcados como target "item"; cada clic en el botón muestra los
 * siguientes `batch`. Cuando no queda ninguno oculto, el botón se esconde.
 *
 * Uso:
 *   <div data-controller="show-more" data-show-more-batch-value="5">
 *     ...items visibles...
 *     <div class="d-none" data-show-more-target="item">...</div>
 *     <button data-show-more-target="button" data-action="show-more#more">Mostrar más</button>
 *   </div>
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['item', 'button'];
  static values  = { batch: { type: Number, default: 5 } };

  more() {
    const hidden = this.itemTargets.filter((el) => el.classList.contains('d-none'));
    hidden.slice(0, this.batchValue).forEach((el) => el.classList.remove('d-none'));

    // Si con este lote se agotan los ocultos, retira el botón.
    if (this.hasButtonTarget && hidden.length <= this.batchValue) {
      this.buttonTarget.classList.add('d-none');
    }
  }
}

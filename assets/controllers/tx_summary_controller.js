import { Controller } from '@hotwired/stimulus';

/**
 * Resumen de movimiento en modal, con contenido cargado por AJAX.
 *
 * Se monta sobre el elemento que dispara la apertura (fila de tabla,
 * item de dropdown...). El servidor devuelve el partial _summary.html.twig
 * ya renderizado (incluidos los botones según permisos) y se inyecta en
 * #txSummaryModal (incluido una vez por página vía _modal_summary.html.twig).
 *
 * Uso en una fila (ignora clicks sobre elementos interactivos):
 *   <tr data-controller="tx-summary"
 *       data-tx-summary-url-value="{{ path('transaction_summary', {id: tx.id}) }}"
 *       data-action="click->tx-summary#open">
 *
 * Uso en un disparador explícito (botón/If de dropdown):
 *   data-action="click->tx-summary#show"
 */
export default class extends Controller {
  static values = { url: String };

  open(event) {
    // No abrir el resumen si el click fue sobre algo interactivo de la fila
    if (event.target.closest('a, button, input, label, form')) return;
    this.#show();
  }

  show() {
    this.#show();
  }

  async #show() {
    const modalEl = document.getElementById('txSummaryModal');
    if (!modalEl) return;

    // El partial devuelve modal-body + modal-footer ya renderizados
    const content = modalEl.querySelector('[data-tx-summary-content]');
    content.innerHTML = '<div class="modal-body p-4 text-center"><span class="spinner-border spinner-border-sm" role="status"></span></div>';
    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();

    // Pasamos la URL actual para que "Eliminar" vuelva aquí (filtros/página intactos)
    const url = new URL(this.urlValue, window.location.origin);
    url.searchParams.set('redirect', window.location.pathname + window.location.search);

    try {
      const response = await fetch(url.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      content.innerHTML = await response.text();
    } catch {
      content.innerHTML = '<div class="modal-body p-4"><p class="text-danger small text-center mb-0">No se pudo cargar el resumen.</p></div>';
    }
  }
}

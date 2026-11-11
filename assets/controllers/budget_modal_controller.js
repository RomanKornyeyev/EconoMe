import { Controller } from '@hotwired/stimulus';

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };
const SPINNER = '<div class="modal-body p-5 text-center"><span class="spinner-border spinner-border-sm" role="status"></span></div>';

/** Retardo del resumen «Qué va a pasar» mientras se escribe el importe. */
const PREVIEW_DELAY = 250;

/**
 * Modal de la página de presupuestos: alta y cambio de importe.
 *
 * Un único #budgetModal cuyo contenido entero (cabecera incluida) pinta el
 * servidor: GET con XHR a budget_new o budget_edit, que responden JSON {ok, body}
 * (ver BudgetController::modalResponse()). Mismo esquema que category-modal:
 *   data-action="budget-modal#open" data-budget-modal-url-param="…"
 *
 * Al guardar no se pinta nada: el servidor deja el flash y se recarga la página.
 * Un 422 devuelve el formulario con sus errores.
 *
 * En el cambio de importe, además:
 *   · «Qué va a pasar» se pide al servidor (budget_preview) al escribir o al
 *     cambiar el alcance o el periodo: la lógica de tramos vive solo en PHP;
 *   · «Quitar» un cambio del historial se guarda al momento y repinta el modal.
 *     Como la lista de detrás queda obsoleta, la página se recarga al cerrar;
 *   · finalizar y eliminar se confirman en el propio pie (showPane): Bootstrap no
 *     apila modales, así que no se puede abrir #confirm-modal encima.
 *
 * No hay vista de página completa. Si falla la carga se avisa dentro del modal;
 * si falla el envío, se rescata con un envío nativo, que el servidor resuelve
 * volviendo a la lista con un flash.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['modal', 'content'];

  #seq = 0;         // invalida una carga en vuelo si se pide otra antes
  #previewSeq = 0;  // ídem para el resumen
  #previewTimer = null;
  #dirty = false;   // algo se guardó sin recargar (Quitar): recargar al cerrar

  connect() {
    this.modalTarget.addEventListener('hidden.bs.modal', this.#onHidden);
  }

  disconnect() {
    this.modalTarget.removeEventListener('hidden.bs.modal', this.#onHidden);
    clearTimeout(this.#previewTimer);
  }

  /** Botón de alta o de cambiar importe: abre el formulario en el modal. */
  async open({ params }) {
    const seq = ++this.#seq;
    this.contentTarget.innerHTML = SPINNER;
    window.bootstrap.Modal.getOrCreateInstance(this.modalTarget).show();

    try {
      const response = await fetch(params.url, { headers: XHR });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const { body } = await response.json();
      if (seq !== this.#seq) return;
      this.#render(body);
    } catch {
      if (seq === this.#seq) this.#error('No se pudo cargar el formulario.');
    }
  }

  /** Envío del formulario del modal (alta o cambio de importe). */
  async submit(event) {
    event.preventDefault();
    const form = event.target;

    if (!form.checkValidity()) {
      form.classList.add('was-validated');
      return;
    }

    const buttons = this.contentTarget.querySelectorAll('[type="submit"]');
    buttons.forEach(button => { button.disabled = true; });

    let data;
    try {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: XHR });
      data = await response.json();
    } catch {
      // Respuesta inesperada (500, sesión caducada…): el envío normal la resuelve.
      form.submit();
      return;
    }

    if (data.body) {
      this.#render(data.body);
      return;
    }

    window.location.reload();
  }

  // ── Alta ──────────────────────────────────────────────────────────────

  /** Ámbito: la categoría solo hace falta con «Una categoría». */
  toggleScope() {
    const scope = this.contentTarget.querySelector('input[name$="[scope]"]:checked')?.value;
    const row = this.contentTarget.querySelector('[data-budget-category-row]');
    if (row) row.hidden = scope !== 'category';
  }

  /**
   * Frecuencia: inicio y fin se eligen en periodos de esa frecuencia («semana
   * del 9 nov», «oct–dic 2026»). Las etiquetas de todas vienen en
   * data-budget-labels de cada desplegable (BudgetType).
   */
  relabel(event) {
    const frequency = event.target.value;
    for (const select of this.contentTarget.querySelectorAll('select[data-budget-labels]')) {
      const labels = JSON.parse(select.dataset.budgetLabels)[frequency];
      if (!labels) continue;
      for (const option of select.options) {
        if (option.value !== '' && labels[option.value] !== undefined) {
          option.textContent = labels[option.value];
        }
      }
    }
  }

  // ── Cambio de importe ─────────────────────────────────────────────────

  /** Alcance: «Todo el historial» no lleva periodo; «solo» y «desde» lo rotulan distinto. */
  toggleApply() {
    const scope = this.contentTarget.querySelector('input[name$="[scope]"]:checked')?.value;
    const row = this.contentTarget.querySelector('[data-budget-period-row]');
    if (row) {
      row.hidden = scope === 'all';
      const label = row.querySelector('label');
      if (label) label.textContent = scope === 'once' ? 'Periodo' : 'A partir de';
    }
  }

  /** «Qué va a pasar»: lo calcula el servidor con el formulario tal cual. */
  preview() {
    const form = this.contentTarget.querySelector('form[data-budget-preview-url]');
    if (!form) return;

    clearTimeout(this.#previewTimer);
    this.#previewTimer = setTimeout(() => this.#loadPreview(form), PREVIEW_DELAY);
  }

  /** Quitar un cambio del historial: se guarda ya y se repinta el modal. */
  async removeChange({ params }) {
    const body = new FormData();
    body.append('_token', params.token);

    try {
      const response = await fetch(params.url, { method: 'POST', body, headers: XHR });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      this.#dirty = true;
      this.#render(data.body);
    } catch {
      window.location.reload();
    }
  }

  /** Pie del modal: acciones, o la confirmación de finalizar o eliminar. */
  showPane({ params }) {
    for (const pane of this.contentTarget.querySelectorAll('[data-budget-pane]')) {
      pane.hidden = pane.dataset.budgetPane !== params.pane;
    }
  }

  // ── Interno ───────────────────────────────────────────────────────────

  async #loadPreview(form) {
    const target = this.contentTarget.querySelector('[data-budget-preview]');
    if (!target) return;
    const seq = ++this.#previewSeq;

    try {
      const response = await fetch(form.dataset.budgetPreviewUrl, { method: 'POST', body: new FormData(form), headers: XHR });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const html = await response.text();
      if (seq === this.#previewSeq) target.innerHTML = html;
    } catch {
      // Es una ayuda: si falla, se queda el resumen anterior y se puede guardar igual.
    }
  }

  #render(html) {
    this.contentTarget.innerHTML = html;
    this.toggleScope();
    // Tras un 422 el formulario vuelve con importe: que el resumen lo refleje.
    if (this.contentTarget.querySelector('form[data-budget-preview-url] input[name$="[amount]"]')?.value) {
      this.preview();
    }
    if (window.matchMedia('(min-width: 768px)').matches) {
      this.contentTarget.querySelector('input[name$="[amount]"]')?.focus();
    }
  }

  #error(message) {
    this.contentTarget.innerHTML = `
      <div class="modal-header">
        <h5 class="modal-title" id="budgetModalLabel">Presupuesto</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-4"><p class="text-danger text-center mb-0"></p></div>`;
    this.contentTarget.querySelector('p').textContent = message;
  }

  #onHidden = () => {
    if (this.#dirty) window.location.reload();
  };
}

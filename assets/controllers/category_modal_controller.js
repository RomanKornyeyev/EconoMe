import { Controller } from '@hotwired/stimulus';

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };
const SPINNER = '<div class="modal-body p-5 text-center"><span class="spinner-border spinner-border-sm" role="status"></span></div>';

/**
 * Modal de la página de categorías: ver el resumen, crear y editar.
 *
 * Un único #categoryModal cuyo contenido entero (cabecera incluida) pinta el
 * servidor, así que títulos y botones según permisos se deciden allí:
 *   · view → GET category_summary, HTML (category/_summary.html.twig);
 *   · form → GET de la ruta de crear/editar con XHR, JSON {ok, body}
 *     (category/_form.html.twig). Ver CategoryController::handleForm().
 *
 * Los dos se disparan desde botones con la URL en un parámetro de acción:
 *   data-action="category-modal#form" data-category-modal-url-param="…"
 *
 * El «Editar» del resumen también llama a `form`: se cambia el contenido del
 * mismo modal, sin cerrar y abrir otro.
 *
 * Al guardar no se pinta nada: el servidor deja el flash y se recarga la página,
 * que así trae la tabla al día. Un 422 devuelve el formulario con sus errores.
 *
 * En móvil el mismo modal se ve como panel inferior (CSS de .category-modal) y
 * se cierra arrastrando el asa, igual que el panel de filtros de movimientos.
 *
 * No hay vista de página completa: el formulario solo vive en este modal. Si
 * falla la carga se avisa dentro del propio modal; si falla el envío, se rescata
 * con un envío nativo, que el servidor resuelve volviendo al listado con un
 * flash (p. ej. la sesión caducada acaba en el login).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['modal', 'dialog', 'content'];

  /** Px que hay que arrastrar el panel hacia abajo para cerrarlo (móvil). */
  static DRAG_CLOSE = 80;

  #seq = 0; // invalida una carga en vuelo si se pide otra antes de que llegue
  #dragStartY = null;
  #dragY = 0;

  connect() {
    // Bootstrap lleva el foco al modal al terminar de abrirlo; el campo de
    // nombre se enfoca después, si para entonces el formulario ya ha llegado.
    this.modalTarget.addEventListener('shown.bs.modal', this.#focusName);
  }

  disconnect() {
    this.modalTarget.removeEventListener('shown.bs.modal', this.#focusName);
  }

  /** Botón «Ver»: resumen de la categoría. */
  view({ params }) {
    this.#load(
      params.url,
      response => response.text(),
      () => this.#error('No se pudo cargar la categoría.'),
    );
  }

  /** Botón de crear/editar: abre el formulario en el modal. */
  form({ params }) {
    this.#load(
      params.url,
      async response => (await response.json()).body,
      () => this.#error('No se pudo cargar el formulario.'),
    );
  }

  /** Vista previa del avatar del formulario: inicial del nombre y color. */
  preview() {
    const avatar = this.contentTarget.querySelector('[data-category-preview] .category-avatar');
    if (!avatar) return;

    const name = this.contentTarget.querySelector('input[type="text"]')?.value.trim() ?? '';
    const color = this.contentTarget.querySelector('input[type="color"]')?.value ?? '';

    avatar.textContent = ([...name][0] ?? '').toUpperCase();
    if (color) {
      avatar.style.backgroundColor = color;
      avatar.style.color = contrastText(color);
    }
  }

  // ── Arrastrar para cerrar (móvil) ─────────────────────────────────────

  dragStart(event) {
    this.#dragStartY = event.touches[0].clientY;
    this.#dragY = 0;
    // Mientras se arrastra, el panel sigue al dedo sin animación.
    this.dialogTarget.style.transition = 'none';
  }

  dragMove(event) {
    if (this.#dragStartY === null) return;
    this.#dragY = Math.max(0, event.touches[0].clientY - this.#dragStartY);
    this.dialogTarget.style.transform = `translateY(${this.#dragY}px)`;
  }

  dragEnd() {
    if (this.#dragStartY === null) return;
    this.#dragStartY = null;

    // Se sueltan los estilos en línea en el mismo fotograma en que se decide:
    // la transición arranca desde donde quedó el dedo, ya sea para recolocar
    // el panel o para sacarlo de la pantalla.
    this.dialogTarget.style.transition = '';
    this.dialogTarget.style.transform = '';
    if (this.#dragY > this.constructor.DRAG_CLOSE) {
      window.bootstrap.Modal.getOrCreateInstance(this.modalTarget).hide();
    }
  }

  // ── Envío ─────────────────────────────────────────────────────────────

  /** Envío del formulario del modal. */
  async submit(event) {
    event.preventDefault();
    const form = event.target;

    if (!form.checkValidity()) {
      form.classList.add('was-validated');
      return;
    }

    const button = form.querySelector('[type="submit"]');
    button.disabled = true;

    let data;
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: XHR,
      });
      data = await response.json();
    } catch {
      // Respuesta que no es la esperada (500, sesión caducada…): el envío
      // normal la resuelve como en la página completa.
      form.submit();
      return;
    }

    if (data.body) {
      this.contentTarget.innerHTML = data.body;
      this.#focusName();
      return;
    }

    window.location.reload();
  }

  async #load(url, extract, onError) {
    const seq = ++this.#seq;
    this.contentTarget.innerHTML = SPINNER;
    window.bootstrap.Modal.getOrCreateInstance(this.modalTarget).show();

    try {
      const response = await fetch(url, { headers: XHR });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const html = await extract(response);
      if (seq !== this.#seq) return;
      this.contentTarget.innerHTML = html;
      this.#focusName();
    } catch {
      if (seq === this.#seq) onError();
    }
  }

  #error(message) {
    this.contentTarget.innerHTML = `
      <div class="modal-header">
        <h5 class="modal-title" id="categoryModalLabel">Categoría</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-4"><p class="text-danger text-center mb-0"></p></div>`;
    this.contentTarget.querySelector('p').textContent = message;
  }

  /**
   * Foco en el nombre, solo en escritorio: en móvil sacaría el teclado nada más
   * abrir y taparía medio panel.
   */
  #focusName = () => {
    if (!window.matchMedia('(min-width: 768px)').matches) return;
    this.contentTarget.querySelector('input[type="text"]')?.focus();
  };
}

/**
 * Color de texto legible sobre un fondo hex: oscuro o blanco según la
 * luminancia percibida (YIQ). Mismo cálculo que el filtro Twig contrast_text
 * (ColorExtension), que pinta el avatar en servidor.
 */
function contrastText(hex) {
  let value = hex.replace('#', '');
  if (value.length === 3) value = [...value].map(c => c + c).join('');
  if (!/^[0-9a-f]{6}$/i.test(value)) return '';

  const [r, g, b] = [0, 2, 4].map(i => parseInt(value.slice(i, i + 2), 16));
  return (r * 299 + g * 587 + b * 114) / 1000 >= 128 ? '#212529' : '#fff';
}

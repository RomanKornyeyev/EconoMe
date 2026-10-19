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
 * No hay vista de página completa: el formulario solo vive en este modal. Si
 * falla la carga se avisa dentro del propio modal; si falla el envío, se rescata
 * con un envío nativo, que el servidor resuelve volviendo al listado con un
 * flash (p. ej. la sesión caducada acaba en el login).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['modal', 'content'];

  #seq = 0; // invalida una carga en vuelo si se pide otra antes de que llegue

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

  #focusName = () => {
    this.contentTarget.querySelector('input[type="text"]')?.focus();
  };
}

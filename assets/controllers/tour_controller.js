import { Controller } from '@hotwired/stimulus';
import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';

/**
 * Tour de onboarding con driver.js.
 *
 * Los pasos se declaran en la plantilla marcando elementos con:
 *   data-tour-step="1"                 {# orden del paso #}
 *   data-tour-title="Título"
 *   data-tour-text="Descripción del paso."
 *   data-tour-side="bottom"            {# opcional: top|right|bottom|left #}
 *
 * Los elementos ocultos en el momento de lanzar el tour (d-none, colapsados
 * en móvil, sin datos…) se omiten automáticamente, así que los pasos no
 * necesitan lógica condicional propia.
 *
 * Uso (normalmente vía la macro tour.attrs() de _tour.html.twig):
 *   <div data-controller="tour"
 *        data-tour-name-value="dashboard"
 *        data-tour-auto-value="true"    {# true = 1ª visita: se lanza solo #}
 *        data-tour-complete-url-value="/tour/dashboard/complete"
 *        data-tour-csrf-value="…">
 *
 * Relanzado manual: cualquier elemento dentro del scope con
 *   data-action="click->tour#restart"
 */
export default class extends Controller {
  static values = {
    name: String,
    auto: { type: Boolean, default: false },
    completeUrl: String,
    csrf: String,
  };

  connect() {
    if (this.autoValue && this.#steps().length > 0) {
      // Pequeño margen para que gráficos y layout terminen de asentarse
      this.autoTimeout = setTimeout(() => this.#start(), 600);
    }
  }

  disconnect() {
    clearTimeout(this.autoTimeout);
    this.driver?.destroy();
  }

  restart() {
    this.#start();
  }

  #start() {
    const steps = this.#steps();
    if (steps.length === 0) return;

    this.driver = driver({
      popoverClass: 'econome-tour',
      showProgress: steps.length > 1,
      progressText: '{{current}} de {{total}}',
      nextBtnText: 'Siguiente',
      prevBtnText: 'Anterior',
      doneBtnText: 'Terminar',
      smoothScroll: true,
      steps,
      onDestroyed: () => this.#markCompleted(),
    });
    this.driver.drive();
  }

  /** Recolecta los pasos visibles del DOM, ordenados por data-tour-step. */
  #steps() {
    return [...this.element.querySelectorAll('[data-tour-step]')]
      .filter((el) => el.getClientRects().length > 0)
      .sort((a, b) => Number(a.dataset.tourStep) - Number(b.dataset.tourStep))
      .map((el) => ({
        element: el,
        popover: {
          title: el.dataset.tourTitle || '',
          description: el.dataset.tourText || '',
          side: el.dataset.tourSide || 'bottom',
        },
      }));
  }

  /**
   * Marca el tour como visto (terminado O saltado). Solo la primera vez:
   * en relanzados manuales ya no hay nada que persistir.
   */
  #markCompleted() {
    if (!this.autoValue || !this.completeUrlValue) return;
    this.autoValue = false;

    fetch(this.completeUrlValue, {
      method: 'POST',
      headers: { 'X-CSRF-Token': this.csrfValue },
    }).catch(() => {
      // Sin red no pasa nada grave: el tour volverá a salir la próxima vez
    });
  }
}

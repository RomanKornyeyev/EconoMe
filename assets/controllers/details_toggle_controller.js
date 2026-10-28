import { Controller } from '@hotwired/stimulus';

/**
 * <details> que se despliega y repliega con una animación suave de altura.
 *
 * Con CSS no hay forma fiable de animar hasta una altura desconocida (auto) en
 * todos los navegadores, así que se mide el contenido justo antes de animar y
 * se anima en píxeles con la Web Animations API. El <details> sigue siendo el
 * nativo: sin JS, o con movimiento reducido, se abre y cierra de golpe.
 *
 * Al cerrar, el <details> sigue [open] hasta que acaba la animación (si no, el
 * contenido desaparecería de golpe); mientras, lleva la clase is-closing para
 * que el CSS pueda girar ya el chevron. Un clic a mitad de animación la invierte
 * desde la altura en la que esté.
 *
 * El target content envuelve todo lo que hay bajo el <summary> y tiene que ir
 * sin padding ni borde verticales: con border-box la caja no baja de ellos, así
 * que la animación se quedaría en esos píxeles y saltarían al cerrar. Tampoco
 * margen superior en su primer hijo, que se sale de la caja animada. Cualquier
 * separación, como padding de un div interior.
 *
 * Uso:
 *   <details data-controller="details-toggle">
 *     <summary data-action="click->details-toggle#toggle">...</summary>
 *     <div data-details-toggle-target="content">
 *       <div class="pt-2">...</div>
 *     </div>
 *   </details>
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['content'];
  static values = { duration: { type: Number, default: 280 } };

  disconnect() {
    this.animation?.cancel();
  }

  toggle(event) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    event.preventDefault();
    const isOpening = !this.element.open || this.element.classList.contains('is-closing');
    isOpening ? this.#open() : this.#close();
  }

  #open() {
    const from = this.#currentHeight();
    this.element.classList.remove('is-closing');
    this.element.open = true;
    this.#animate(from, this.contentTarget.scrollHeight);
  }

  #close() {
    this.element.classList.add('is-closing');
    this.#animate(this.#currentHeight(), 0, () => {
      this.element.open = false;
      this.element.classList.remove('is-closing');
    });
  }

  /** Altura visible ahora mismo, también a mitad de una animación. */
  #currentHeight() {
    return this.element.open ? this.contentTarget.getBoundingClientRect().height : 0;
  }

  #animate(from, to, onFinish = () => {}) {
    this.animation?.cancel();
    this.contentTarget.style.overflow = 'hidden';

    this.animation = this.contentTarget.animate(
      [
        { height: `${from}px`, opacity: from > 0 ? 1 : 0 },
        { height: `${to}px`, opacity: to > 0 ? 1 : 0 },
      ],
      // ease-out «estándar»: arranca con decisión y frena suave al final
      { duration: this.durationValue, easing: 'cubic-bezier(0.2, 0, 0, 1)' },
    );

    this.animation.onfinish = () => {
      this.animation = null;
      this.contentTarget.style.overflow = '';
      onFinish();
    };
  }
}

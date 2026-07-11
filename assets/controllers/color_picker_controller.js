import { Controller } from '@hotwired/stimulus';

/**
 * Selector de color reutilizable a base de "swatches" + opción personalizada.
 *
 * Oculta el <input type="color"> original (que sigue siendo la fuente de verdad
 * del formulario) y muestra una paleta de círculos de color. El botón de
 * "personalizado" abre el picker nativo para cualquier color fuera de la paleta.
 *
 * Uso (con Symfony form):
 *   'row_attr' => ['data-controller' => 'color-picker'],
 *   'attr'     => ['data-color-picker-target' => 'input'],
 *
 * La paleta se puede sobreescribir con:
 *   'row_attr' => ['data-color-picker-palette-value' => '["#ff0000", ...]']
 */
export default class extends Controller {
  static targets = ['input'];
  static values = {
    palette: {
      type: Array,
      default: [
        '#94a3b8', // gris azulado
        '#38bdf8', // azul cian claro
        '#a78bfa', // morado claro
        '#34d399', // verde menta
        '#fbbf24', // ámbar suave
        '#fb7185', // rosa coral
      ],
    },
  };

  connect() {
    this.#build();
  }

  #build() {
    this.swatchesEl = document.createElement('div');
    this.swatchesEl.className = 'color-picker-swatches';

    for (const color of this.paletteValue) {
      this.swatchesEl.appendChild(this.#swatch(color));
    }

    // Botón "personalizado" → abre el picker nativo
    const custom = document.createElement('button');
    custom.type = 'button';
    custom.className = 'color-picker-swatch color-picker-custom';
    custom.title = 'Color personalizado';
    custom.innerHTML = '<i class="fa-solid fa-eye-dropper"></i>';
    custom.addEventListener('click', () => this.inputTarget.click());
    this.swatchesEl.appendChild(custom);

    // Ocultar el input nativo y colocar la paleta al final del row
    this.inputTarget.classList.add('visually-hidden');
    this.element.appendChild(this.swatchesEl);

    // Cualquier cambio del input (incluido el picker nativo) re-sincroniza
    this.inputTarget.addEventListener('input', () => this.#sync());

    this.#sync();
  }

  #swatch(color) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'color-picker-swatch';
    btn.style.setProperty('--swatch', color);
    btn.dataset.color = color.toLowerCase();
    btn.title = color;
    btn.addEventListener('click', () => {
      this.inputTarget.value = color;
      this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
    });
    return btn;
  }

  #sync() {
    const current = (this.inputTarget.value || '').toLowerCase();
    let matched = false;

    this.swatchesEl.querySelectorAll('[data-color]').forEach((el) => {
      const active = el.dataset.color === current;
      el.classList.toggle('is-selected', active);
      if (active) matched = true;
    });

    // Marca "personalizado" cuando el color no está en la paleta
    const custom = this.swatchesEl.querySelector('.color-picker-custom');
    custom.classList.toggle('is-selected', !matched && current !== '');
    custom.style.setProperty('--swatch', matched ? 'transparent' : current);
  }
}

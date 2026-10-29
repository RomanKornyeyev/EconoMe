import { Controller } from '@hotwired/stimulus';

/**
 * Descripción plegable del formulario de movimientos.
 *
 * Casi ningún movimiento lleva descripción, así que en reposo el campo es solo
 * un enlace «+ Añadir descripción» en el sitio que ocuparía. Al pulsarlo se
 * despliega el campo con el foco dentro; la X lo vacía y lo vuelve a plegar,
 * sin confirmación: lo escrito es una nota, no merece un diálogo.
 *
 * El estado inicial lo decide Twig (abierto si ya hay texto, p. ej. al editar),
 * así que el controlador puede ser lazy: no tiene nada que corregir al conectar.
 *
 * Teclado: desde Nombre, Tab llega al enlace y Enter lo abre (tx-quick-add deja
 * pasar el Enter de los botones). Al cerrar, el foco vuelve al enlace para no
 * perderse en el <body>.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['link', 'field', 'input'];

  open() {
    this.#show(true);
    this.inputTarget.focus();
  }

  close() {
    this.inputTarget.value = '';
    this.#show(false);
    this.linkTarget.focus();
  }

  /**
   * Abre o pliega según haya texto. Para quien cambia el valor por código sin
   * pasar por aquí (tx-quick-add al duplicar o al reiniciar la tanda).
   */
  sync() {
    this.#show(this.inputTarget.value.trim() !== '');
  }

  #show(open) {
    this.fieldTarget.classList.toggle('d-none', !open);
    this.linkTarget.classList.toggle('d-none', open);
  }
}

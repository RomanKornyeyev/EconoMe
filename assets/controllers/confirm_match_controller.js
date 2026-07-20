import { Controller } from '@hotwired/stimulus';

/**
 * Confirmación por escritura (estilo GitHub): el botón de envío permanece
 * deshabilitado hasta que el usuario teclea exactamente el texto esperado.
 *
 * La comprobación es solo una ayuda de UX; la validación real sigue en el
 * servidor (que compara el nombre y hace trim).
 *
 * Uso:
 *   <form data-controller="confirm-match"
 *         data-confirm-match-expected-value="{{ account.name }}">
 *       <input data-confirm-match-target="input"
 *              data-action="input->confirm-match#check">
 *       <button type="submit" data-confirm-match-target="submit">Eliminar</button>
 *   </form>
 */
export default class extends Controller {
  static targets = ['input', 'submit'];
  static values = { expected: String };

  connect() {
    this.inputTarget.value = '';
    this.check();
  }

  check() {
    // Se compara con trim para reflejar exactamente lo que aceptará el backend.
    const matches = this.inputTarget.value.trim() === this.expectedValue.trim();
    this.submitTarget.disabled = !matches;
  }
}

import { Controller } from '@hotwired/stimulus';

/**
 * Formulario de crear/editar cuenta: el filete superior de la tarjeta
 * (--account-color de .account-card) se repinta al elegir color en el
 * color-picker.
 *
 * Sin carga diferida a propósito: el color-picker sí es lazy y, en una cuenta
 * nueva, avisa con un evento input del color que preselecciona al conectar.
 * Este controlador tiene que estar ya escuchando para no perderse ese aviso.
 *
 * Uso:
 *   <div class="card account-card" data-controller="account-form"
 *        data-action="input->account-form#preview">...formulario...</div>
 */
export default class extends Controller {
  preview(event) {
    if (event.target.type !== 'color') return;
    this.element.style.setProperty('--account-color', event.target.value);
  }
}

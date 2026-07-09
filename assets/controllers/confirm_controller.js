import { Controller } from '@hotwired/stimulus';

/**
 * Confirmación genérica con el modal Bootstrap #confirm-modal.
 *
 * Opt-in: solo actúa sobre los formularios que lo declaran explícitamente.
 * Pensado para formularios normales (con redirección del servidor), a
 * diferencia de friendship_controller que trabaja sobre respuestas AJAX.
 *
 * Uso:
 *   <form data-controller="confirm"
 *         data-action="submit->confirm#submit"
 *         data-confirm-message-value="Esta acción no se puede deshacer."
 *         data-confirm-text-value="Eliminar">   {# opcional: texto del botón #}
 *
 * Intercepta el submit, muestra el modal y solo si el usuario confirma
 * envía el formulario de forma nativa.
 */
export default class extends Controller {
  static values = {
    message: String,
    text: { type: String, default: 'Confirmar' },
    danger: { type: Boolean, default: true },
  };

  submit(event) {
    const el = document.getElementById('confirm-modal');

    // Sin modal en el DOM: enviamos directamente (fallback)
    if (!el) return;

    event.preventDefault();

    el.querySelector('[data-modal-body]').textContent = this.messageValue || '¿Estás seguro?';

    const confirmBtn = el.querySelector('[data-modal-confirm]');
    confirmBtn.textContent = this.textValue;
    confirmBtn.classList.toggle('btn-danger', this.dangerValue);
    confirmBtn.classList.toggle('btn-primary', !this.dangerValue);

    const bsModal = window.bootstrap.Modal.getOrCreateInstance(el);

    const onConfirm = () => {
      // submit() nativo: no vuelve a disparar el evento submit → sin bucle
      this.element.submit();
    };

    confirmBtn.addEventListener('click', onConfirm, { once: true });
    el.addEventListener('hide.bs.modal', () => {
      confirmBtn.removeEventListener('click', onConfirm);
    }, { once: true });

    bsModal.show();
  }
}

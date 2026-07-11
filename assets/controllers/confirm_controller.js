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
 *         data-confirm-text-value="Eliminar"                {# opcional: texto del botón #}
 *         data-confirm-checkbox-label-value="Borrar también…" {# opcional: muestra un checkbox #}
 *         data-confirm-checkbox-name-value="delete_extra">    {# nombre del campo POST del checkbox #}
 *
 * Intercepta el submit, muestra el modal y solo si el usuario confirma
 * envía el formulario de forma nativa. Si se declara checkbox-label y el
 * usuario lo marca, se añade un hidden input {checkbox-name}=1 al form.
 */
export default class extends Controller {
  static values = {
    message: String,
    text: { type: String, default: 'Confirmar' },
    danger: { type: Boolean, default: true },
    checkboxLabel: { type: String, default: '' },
    checkboxName: { type: String, default: 'confirm_extra' },
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

    // Checkbox opcional (siempre desmarcado al abrir)
    const checkboxWrap = el.querySelector('[data-modal-checkbox-wrap]');
    const checkbox = el.querySelector('[data-modal-checkbox]');
    const hasCheckbox = this.checkboxLabelValue !== '' && checkboxWrap && checkbox;
    if (checkboxWrap && checkbox) {
      checkboxWrap.classList.toggle('d-none', !hasCheckbox);
      checkbox.checked = false;
      if (hasCheckbox) {
        el.querySelector('[data-modal-checkbox-label]').textContent = this.checkboxLabelValue;
      }
    }

    const bsModal = window.bootstrap.Modal.getOrCreateInstance(el);

    const onConfirm = () => {
      if (hasCheckbox && checkbox.checked) {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = this.checkboxNameValue;
        hidden.value = '1';
        this.element.appendChild(hidden);
      }
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

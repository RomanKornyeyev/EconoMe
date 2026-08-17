import { Controller } from '@hotwired/stimulus';

/**
 * Alta y edición de movimientos en el modal.
 *
 * Montado sobre el `.modal-content`, no sobre el <form>: además del formulario
 * gobierna el título y el nombre de cuenta de la cabecera, que quedan fuera de
 * él. El formulario es el target `form`, y sus `data-action` llegan igual porque
 * el controlador está en un ancestro.
 *
 * Envía el formulario por fetch y, si se ha pedido encadenar, deja el modal
 * abierto: limpia importe, nombre y descripción, conserva fecha y tipo, y
 * devuelve el foco al importe. Así meter un extracto entero son N tabuladas en
 * vez de N recargas de página.
 *
 * El servidor responde `{ok: true, item}` con la confirmación a pintar en la
 * franja «Registrado», o `{ok: false, body}` con el cuerpo del formulario
 * re-renderizado con errores (solo el cuerpo: el _token vive fuera y así
 * sobrevive).
 *
 * En modo edición el cuerpo del formulario se pide por AJAX y se sustituye, y
 * se ocultan las piezas que solo valen creando (encadenar y enlace a
 * recurrentes). Al volver a crear se restaura el cuerpo original, que se
 * guarda tal cual llegó del servidor en connect().
 *
 * Al cerrar el modal después de haber guardado algo, se recarga la página una
 * vez, para que listado y KPIs de detrás dejen de estar obsoletos.
 *
 * NO es lazy a propósito: intercepta un envío, y llegar tarde significaría dejar
 * pasar el submit nativo con recarga incluida (ver README.dev.md).
 */
export default class extends Controller {
  static targets = [
    'form', 'body', 'addedPanel', 'addedBar', 'addedLast', 'addedCount',
    'title', 'accountName', 'saveButton', 'chainButton', 'recurring',
  ];

  #added = 0;
  #modalEl = null;
  #sending = false;
  #prefilled = false;
  #editing = false;
  #dirty = false;
  #loading = false;
  // Cada apertura de edición lleva número: sirve para descartar una respuesta
  // que llegue tarde, cuando el usuario ya ha cerrado o abierto otra cosa.
  #loadSeq = 0;

  // Estado de partida del modo alta, para poder volver a él tras editar
  #createBody = null;
  #createAction = null;
  #createAccount = null;

  connect() {
    // Se captura antes de que nadie toque el DOM: los controladores hijos
    // (date-picker) son lazy, así que aquí el cuerpo sigue siendo el que
    // renderizó Twig, sin el altInput que flatpickr inserta después.
    this.#createBody = this.bodyTarget.innerHTML;
    this.#createAction = this.formTarget.action;
    this.#createAccount = this.hasAccountNameTarget ? this.accountNameTarget.textContent : '';

    this.#modalEl = this.element.closest('.modal');  // el propio elemento ya es el .modal-content
    this.#modalEl?.addEventListener('show.bs.modal', this.#onModalShow);
    this.#modalEl?.addEventListener('hidden.bs.modal', this.#onModalHidden);
    document.addEventListener('keydown', this.#onGlobalKeydown);
    document.addEventListener('click', this.#onDocumentClick);
  }

  disconnect() {
    this.#modalEl?.removeEventListener('show.bs.modal', this.#onModalShow);
    this.#modalEl?.removeEventListener('hidden.bs.modal', this.#onModalHidden);
    document.removeEventListener('keydown', this.#onGlobalKeydown);
    document.removeEventListener('click', this.#onDocumentClick);
  }

  // ── Envío ─────────────────────────────────────────────────────────────────

  /** submit del formulario, venga del botón que venga. */
  submit(event) {
    event.preventDefault();
    // `data-tx-quick-add-chain` distingue «Guardar y añadir otro» de «Guardar».
    // Sin submitter (envío implícito) se encadena, que es el caso frecuente.
    const chain = event.submitter ? event.submitter.hasAttribute('data-tx-quick-add-chain') : true;
    this.#send(chain, event.submitter);
  }

  /**
   * Enter guarda y sigue, sin tener que llegar al botón. Se excluye el textarea
   * de descripción, donde Enter tiene que seguir siendo un salto de línea.
   *
   * En vez de llamar a #send directamente, se pulsa el botón de encadenar: así
   * el teclado y el ratón recorren exactamente el mismo camino (un único
   * `submit`, con su `submitter`) en lugar de dos rutas parecidas que pueden
   * divergir. También deja que el resto de listeners del submit —el de
   * `needs-validation` de app.js— se enteren igual que con un clic.
   */
  onKeydown(event) {
    if (event.key !== 'Enter' || event.shiftKey || event.altKey) return;
    if (event.target.tagName === 'TEXTAREA') return;
    // Con el foco en un botón o enlace, Enter tiene que hacer lo que ponga ahí
    // («Guardar», «Cerrar»…), no encadenar por nuestra cuenta.
    if (['BUTTON', 'A'].includes(event.target.tagName)) return;
    // Si alguien ya se ha quedado con este Enter (flatpickr con el calendario
    // abierto, un desplegable…), es suyo: no se le roba para guardar.
    if (event.defaultPrevented) return;

    event.preventDefault();
    // Editando no hay botón de encadenar: Enter guarda y cierra, como el botón
    (this.#editing ? this.saveButtonTarget : this.#chainButton())?.click();
  }

  async #send(chain, button) {
    // Con el modal siempre abierto es fácil disparar dos veces seguidas
    if (this.#sending) return;
    // Editando, el formulario aún puede ser el hueco de carga: enviarlo mandaría
    // un movimiento vacío.
    if (this.#loading) return;

    // La validación nativa la comprobamos aquí y no delegamos en el listener de
    // app.js: el orden entre los dos listeners de submit no está garantizado.
    if (!this.formTarget.checkValidity()) {
      this.formTarget.classList.add('was-validated');
      return;
    }

    this.#sending = true;
    const restore = this.#lockButtons(button);

    try {
      const response = await fetch(this.formTarget.action, {
        method: 'POST',
        body: new FormData(this.formTarget),
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });

      const data = await response.json();

      if (!response.ok || !data.ok) {
        // 422: el cuerpo vuelve renderizado con los errores. Stimulus reconecta
        // solo los data-controller del HTML nuevo (date-picker, amount-type).
        if (data?.body) {
          this.bodyTarget.innerHTML = data.body;
        }
        return;
      }

      this.#dirty = true;

      // Editar es una acción única: no hay tanda que contar ni que encadenar.
      if (this.#editing) {
        window.bootstrap.Modal.getOrCreateInstance(this.#modalEl).hide();
        return;
      }

      this.#pushAdded(data.item);

      if (chain) {
        this.#resetForNext();
      } else {
        window.bootstrap.Modal.getOrCreateInstance(this.#modalEl).hide();
      }
    } catch {
      // Fallo de red: se deja el formulario tal cual para poder reintentar
    } finally {
      restore();
      this.#sending = false;
    }
  }

  // ── Duplicar ──────────────────────────────────────────────────────────────

  /**
   * Clic en cualquier «Duplicar» de la página. Se escucha por delegación en vez
   * de con un data-action porque los disparadores viven fuera de este controlador
   * (tabla, tarjetas de móvil) e incluso en HTML inyectado después por AJAX (el
   * modal de resumen), donde Stimulus no tendría a quién enganchar.
   */
  #onDocumentClick = (event) => {
    if (!this.#modalEl) return;

    const dup = event.target.closest('[data-tx-duplicate]');
    if (dup) {
      event.preventDefault();
      this.#toCreateMode();
      this.#fillFrom(dup.dataset);
      this.#openOverAnyModal();
      return;
    }

    // «Editar» es un <a> de verdad a la vista de página completa: si esto no
    // llega a ejecutarse (sin JS, o sin modal en la página), el enlace navega
    // y el usuario acaba en el mismo formulario, solo que en otra pantalla.
    const edit = event.target.closest('[data-tx-edit]');
    if (edit) {
      event.preventDefault();
      this.#openForEdit(edit.href);
    }
  };

  /**
   * Abre el modal, cerrando antes cualquier otro que estuviera abierto (el de
   * resumen). Encadenar los dos a la vez deja el backdrop y el scroll-lock
   * pegados, así que hay que esperar a que el primero termine de irse.
   */
  #openOverAnyModal() {
    const open = document.querySelector('.modal.show');
    if (open && open !== this.#modalEl) {
      open.addEventListener('hidden.bs.modal', () => this.#show(), { once: true });
      window.bootstrap.Modal.getOrCreateInstance(open).hide();
      return;
    }
    this.#show();
  }

  // ── Edición ───────────────────────────────────────────────────────────────

  /**
   * Abre el modal inmediatamente y trae el formulario después, como hace
   * tx-summary. Esperar al fetch para abrir dejaba un segundo de nada tras el
   * clic, que se siente como que la aplicación se ha quedado colgada.
   *
   * La `action` se puede fijar ya: la URL del enlace es la misma a la que hay
   * que enviar. Así, si algo va mal a mitad, el formulario nunca apunta a crear.
   */
  async #openForEdit(url) {
    const seq = ++this.#loadSeq;

    this.#toEditMode(url);
    this.#loading = true;
    this.bodyTarget.innerHTML = this.#spinner();
    this.#openOverAnyModal();

    try {
      const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();

      // Respuesta obsoleta: entre medias se cerró el modal o se abrió otra cosa
      if (seq !== this.#loadSeq) return;

      this.bodyTarget.innerHTML = data.body;
      this.formTarget.action = data.action;
      this.accountNameTarget.textContent = data.account ?? this.#createAccount;
    } catch {
      // Con el modal ya abierto, dejarlo en un error sin salida sería peor que
      // llevar al usuario a la vista de página completa, que hace el mismo trabajo.
      if (seq === this.#loadSeq) window.location.assign(url);
    } finally {
      if (seq === this.#loadSeq) this.#loading = false;
    }
  }

  /** Hueco de carga con la altura aproximada del formulario, para no dar saltos. */
  #spinner() {
    return '<div class="tx-form-loading d-flex align-items-center justify-content-center">'
         + '<span class="spinner-border text-secondary" role="status">'
         + '<span class="visually-hidden">Cargando…</span></span></div>';
  }

  /**
   * Vuelca en el formulario los datos que el disparador lleva en data-tx-*. No
   * hace falta ir al servidor a por algo que ya está pintado en la página.
   *
   * La fecha se copia como todo lo demás. La alternativa —dejar la pegajosa de
   * sesión— hacía que el resultado de duplicar dependiera de un estado invisible:
   * el mismo clic sobre la misma fila daba un formulario distinto según lo que
   * hubieras hecho antes. Copiándola, duplicar es función de la fila que ves.
   */
  #fillFrom(data) {
    this.#setField('amount', data.txAmount ?? '');
    this.#setField('name', data.txName ?? '');
    this.#setField('description', data.txDescription ?? '');
    this.#setField('category', data.txCategory ?? '');

    if (data.txDate) {
      this.#setField('date', data.txDate);
      // Es el campo que más se cambia al duplicar: se resalta para que se vea
      // que también viene copiado y no pase inadvertido al guardar.
      this.#flashDate();
    }

    if (data.txType) {
      this.#setType(data.txType);
    }

    // Ningún campo despacha eventos: category-suggest escucha `input` en el
    // nombre y `change` en el tipo, y en cuanto se entera pisa 350 ms después la
    // categoría que acabamos de copiar. Duplicar tiene que copiar, no sugerir.
    this.formTarget.classList.remove('was-validated');
    this.#prefilled = true;
  }

  /**
   * Cambia el tipo y repinta el control segmentado.
   *
   * Se avisa a amount-type llamándole directamente en vez de despachando
   * `change`, porque ese evento lo escuchan los dos controladores del campo y
   * category-suggest se traería su sugerencia por delante de la categoría
   * copiada. Fue justo el fallo que se coló en la primera versión de duplicar.
   */
  #setType(value) {
    const select = this.#field('type');
    if (!select) return;

    select.value = value;

    const group = select.closest('[data-controller~="amount-type"]');
    const segmented = group && this.application.getControllerForElementAndIdentifier(group, 'amount-type');

    if (segmented) {
      segmented.sync();
      return;
    }

    // amount-type todavía sin cargar (es lazy). Prácticamente inalcanzable: su
    // elemento está en el DOM desde el primer render. Aun así, más vale una
    // categoría pisada que un segmentado mintiendo sobre el tipo que se guarda.
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  #show() {
    window.bootstrap.Modal.getOrCreateInstance(this.#modalEl).show();
  }

  /**
   * Pasa el modal a edición: cuerpo del movimiento, destino del envío y fuera
   * todo lo que solo tiene sentido creando. Editar es una acción única, así que
   * encadenar, el atajo que lo anuncia y el enlace a recurrentes sobran.
   */
  #toEditMode(action) {
    this.#editing = true;
    this.#prefilled = true; // que #onModalShow no lo reinicie al abrirse

    this.formTarget.action = action;
    this.titleTarget.textContent = 'Editar movimiento';
    this.saveButtonTarget.textContent = 'Guardar cambios';
    this.saveButtonTarget.classList.replace('btn-outline-primary', 'btn-primary');

    // El atajo de Enter se anuncia dentro del propio botón de encadenar, así que
    // se va con él sin tener que ocultarlo aparte.
    this.#toggle(this.chainButtonTarget, false);
    this.#toggle(this.recurringTarget, false);
    // La franja es un contador de tanda; editando no hay tanda que contar
    this.#toggle(this.addedPanelTarget, false);
    this.formTarget.classList.remove('was-validated');
  }

  /** Devuelve el modal a su estado de alta. No hace nada si ya lo está. */
  #toCreateMode() {
    if (!this.#editing) return;
    this.#editing = false;
    this.#loadSeq++; // invalida una carga de edición que siguiera en vuelo
    this.#loading = false;

    this.bodyTarget.innerHTML = this.#createBody;
    this.formTarget.action = this.#createAction;

    this.titleTarget.textContent = 'Nuevo movimiento';
    this.accountNameTarget.textContent = this.#createAccount;
    this.saveButtonTarget.textContent = 'Guardar';
    this.saveButtonTarget.classList.replace('btn-primary', 'btn-outline-primary');

    this.#toggle(this.chainButtonTarget, true);
    this.#toggle(this.recurringTarget, true);
    this.formTarget.classList.remove('was-validated');
  }

  #toggle(el, visible) {
    el?.classList.toggle('d-none', !visible);
  }

  // ── Internals ─────────────────────────────────────────────────────────────

  /**
   * Deja el formulario listo para el siguiente movimiento de la tanda.
   *
   * @param {{focus?: boolean}} options  Al abrir el modal el foco lo pone
   *        amount-type en `shown.bs.modal`; adelantarse aquí sería pelearse con él.
   */
  #resetForNext({ focus = true } = {}) {
    // Fecha y tipo se conservan: en una tanda de extracto son lo que se repite.
    this.#setField('amount', '');
    this.#setField('name', '');
    this.#setField('description', '');
    this.#setField('category', '');

    // Que category-suggest se entere de que el nombre está vacío y retire su
    // chip; es su propio `request()` quien limpia también el estado interno.
    this.#field('name')?.dispatchEvent(new Event('input', { bubbles: true }));

    // Sin esto, los campos recién vaciados se pintarían en rojo
    this.formTarget.classList.remove('was-validated');
    this.#clearServerErrors();

    if (focus) {
      const amount = this.#field('amount');
      amount?.focus();
      amount?.select();
    }
  }

  /**
   * Borra las marcas de error que vinieron del servidor en un 422.
   *
   * El tema bootstrap_5 pinta el campo con `is-invalid` y le cuelga un
   * `.invalid-feedback`. Bootstrap muestra ese mensaje con `.is-invalid ~
   * .invalid-feedback`, **sin depender de `was-validated`**, así que quitar esa
   * clase del formulario no basta: sin esto, corregir el error y guardar dejaba
   * el campo en rojo con el mensaje viejo durante el resto de la tanda.
   *
   * El caso llega de verdad: un importe `0` pasa el `pattern` del navegador y lo
   * rechaza el `Assert\Positive` del servidor.
   */
  #clearServerErrors() {
    this.bodyTarget.querySelectorAll('.is-invalid')
      .forEach(el => el.classList.remove('is-invalid'));
    this.bodyTarget.querySelectorAll('.invalid-feedback')
      .forEach(el => el.remove());
  }

  /**
   * Pinta la confirmación del último alta y actualiza el contador de la tanda.
   *
   * La franja se repinta en vez de acumular líneas: el modal tiene que medir lo
   * mismo con el primer movimiento que con el vigésimo. El pulso de la animación
   * es lo que hace visible que ha pasado algo, ya que el texto cambia poco entre
   * un alta y la siguiente.
   */
  #pushAdded(itemHtml) {
    this.#added += 1;
    this.addedLastTarget.innerHTML = itemHtml;
    this.addedCountTarget.textContent = String(this.#added);
    this.addedPanelTarget.classList.remove('d-none');

    const bar = this.addedBarTarget;
    bar.classList.remove('tx-added-pulse');
    void bar.offsetWidth; // reinicia la animación
    bar.classList.add('tx-added-pulse');
    bar.addEventListener('animationend', () => bar.classList.remove('tx-added-pulse'), { once: true });
  }

  /**
   * Abrir «Nuevo movimiento» tiene que dar un formulario en blanco, aunque la
   * vez anterior se cerrara a medias o con un duplicado dentro. Se exceptúa la
   * apertura que viene de duplicar, que acaba de rellenarlo a propósito.
   */
  #onModalShow = () => {
    // Duplicar y editar dejan el formulario listo antes de abrir; no se toca.
    if (this.#prefilled) {
      this.#prefilled = false;
      return;
    }
    this.#toCreateMode();
    this.#resetForNext({ focus: false });
  };

  /** Recarga una sola vez al cerrar, no una por movimiento guardado. */
  #onModalHidden = () => {
    if (this.#dirty) {
      window.location.reload();
    }
  };

  /** `N` abre el modal desde cualquier punto de la página. */
  #onGlobalKeydown = (event) => {
    if (event.key !== 'n' && event.key !== 'N') return;
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (!this.#modalEl) return;

    // No robar la tecla mientras se escribe, ni con otro modal por delante
    const el = event.target;
    if (el.isContentEditable) return;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
    if (document.querySelector('.modal.show')) return;

    event.preventDefault();
    window.bootstrap.Modal.getOrCreateInstance(this.#modalEl).show();
  };

  /**
   * Campo del formulario por su nombre lógico. Se busca por el sufijo del
   * atributo name (`transaction[amount]`) en lugar de declarar targets: importe
   * y tipo los pinta partials/_amount_type.html.twig, que comparten los
   * formularios de recurrentes y no debería saber de este controlador.
   */
  #field(name) {
    return this.formTarget.querySelector(`[name$="[${name}]"]`);
  }

  #setField(name, value) {
    const el = this.#field(name);
    if (!el) return;

    // La fecha la lleva flatpickr con altInput: escribir en el input original
    // no repinta lo que el usuario ve, hay que pasar por su controlador.
    const picker = this.application.getControllerForElementAndIdentifier(el, 'date-picker');
    if (picker) {
      picker.setDate(value);
      return;
    }

    el.value = value;
  }

  #chainButton() {
    return this.formTarget.querySelector('[data-tx-quick-add-chain]');
  }

  /**
   * Resalta la fecha con la misma animación que usa category-suggest al
   * autorrellenar. Hay que pintar el campo que el usuario ve: con flatpickr, el
   * input con `name` está oculto y quien se ve es su altInput.
   */
  #flashDate() {
    const input = this.#field('date');
    if (!input) return;

    const picker = this.application.getControllerForElementAndIdentifier(input, 'date-picker');
    const el = picker?.visibleElement ?? input;

    el.classList.remove('cat-autofill-flash');
    void el.offsetWidth; // reinicia la animación
    el.classList.add('cat-autofill-flash');
    el.addEventListener('animationend', () => el.classList.remove('cat-autofill-flash'), { once: true });
  }

  /** Spinner en el botón pulsado y bloqueo del resto mientras va la petición. */
  #lockButtons(button) {
    const buttons = [...this.formTarget.querySelectorAll('[type="submit"]')];
    const original = button?.innerHTML;

    buttons.forEach(b => (b.disabled = true));
    if (button) {
      button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
    }

    return () => {
      buttons.forEach(b => (b.disabled = false));
      if (button && original !== undefined) {
        button.innerHTML = original;
      }
    };
  }
}

import { Controller } from '@hotwired/stimulus';

/**
 * bulk-select
 *
 * Gestiona la selección múltiple y el borrado individual de transacciones.
 *
 * Targets en el card-header de cada vista:
 *   normalBar           — contenido normal del header
 *   bulkBar             — barra de acciones bulk (_table_bulk_bar.html.twig)
 *   selectionCount      — texto "N seleccionados" dentro de bulkBar
 *
 * Targets en _table_desktop.html.twig:
 *   checkbox            — cada checkbox de fila
 *   selectAll           — checkbox del thead
 *   bulkForm            — form de bulk delete
 *   confirmCount        — texto "N movimientos" en el modal de confirmación bulk
 *   singleDeleteModal   — el modal de confirmación de borrado individual
 *   singleDeleteForm    — form del modal individual (action se inyecta dinámicamente)
 *   singleDeleteToken   — input hidden _token del form individual
 *   singleDeleteName    — texto con el nombre del movimiento en el modal individual
 */
export default class extends Controller {
    static targets = [
        'checkbox',
        'selectAll',
        'normalBar',
        'bulkBar',
        'selectionCount',
        'bulkForm',
        'confirmCount',
        'singleDeleteModal',
        'singleDeleteForm',
        'singleDeleteToken',
        'singleDeleteName',
    ];

    connect() {
        this._update();
    }

    // ── Bulk select ──────────────────────────────────────────────────────────

    toggle() {
        this._update();
    }

    toggleAll() {
        const checked = this.selectAllTarget.checked;
        this.checkboxTargets.forEach(cb => (cb.checked = checked));
        this._update();
    }

    clearAll() {
        this.checkboxTargets.forEach(cb => (cb.checked = false));
        if (this.hasSelectAllTarget) {
            this.selectAllTarget.checked = false;
            this.selectAllTarget.indeterminate = false;
        }
        this._update();
    }

    openConfirm() {
        if (this.hasConfirmCountTarget) {
            const n = this._checkedCount();
            this.confirmCountTarget.textContent = n === 1 ? '1 movimiento' : `${n} movimientos`;
        }
    }

    submitBulk() {
        this.bulkFormTarget.submit();
    }

    // ── Single delete ────────────────────────────────────────────────────────

    confirmSingleDelete(event) {
        const btn = event.currentTarget;

        this.singleDeleteFormTarget.action = btn.dataset.url;
        this.singleDeleteTokenTarget.value = btn.dataset.token;

        if (this.hasSingleDeleteNameTarget) {
            this.singleDeleteNameTarget.textContent = btn.dataset.name;
        }

        bootstrap.Modal.getOrCreateInstance(this.singleDeleteModalTarget).show();
    }

    submitSingleDelete() {
        this.singleDeleteFormTarget.submit();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    _checkedCount() {
        return this.checkboxTargets.filter(cb => cb.checked).length;
    }

    _update() {
        const n = this._checkedCount();
        const total = this.checkboxTargets.length;
        const active = n > 0;

        if (this.hasNormalBarTarget) {
            this.normalBarTarget.classList.toggle('d-none', active);
        }
        if (this.hasBulkBarTarget) {
            this.bulkBarTarget.classList.toggle('d-none', !active);
            this.bulkBarTarget.classList.toggle('d-flex', active);
        }

        if (this.hasSelectionCountTarget) {
            this.selectionCountTarget.textContent =
                n === 1 ? '1 seleccionado' : `${n} seleccionados`;
        }

        if (this.hasSelectAllTarget) {
            this.selectAllTarget.checked = active && n === total;
            this.selectAllTarget.indeterminate = active && n < total;
        }
    }
}

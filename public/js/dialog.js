// Lightweight reusable dialog component with HTMX integration
// Usage:
// - Attribute API for confirmations: add data-dialog-confirm="Message" to any element that has hx-* attributes.
//   Optional data-dialog-title, data-dialog-variant (default|danger), data-dialog-confirm-label, data-dialog-cancel-label.
// - Programmatic API: Dialog.open({ title, message, confirmLabel, cancelLabel, variant, onConfirm })
(function () {
    const doc = document;

    /** Elements (created in layout.twig) */
    const backdrop = doc.getElementById('modalBackdrop');
    const modal = doc.getElementById('modalRoot');
    const titleEl = doc.getElementById('modalTitle');
    const bodyEl = doc.getElementById('modalBody');
    const confirmBtn = doc.getElementById('modalConfirm');
    const cancelBtn = doc.getElementById('modalCancel');

    if (!backdrop || !modal || !titleEl || !bodyEl || !confirmBtn || !cancelBtn) {
        // Layout not loaded yet; do nothing
        return;
    }

    let lastActive = null;
    let currentOnConfirm = null;

    function setVariant(variant) {
        modal.dataset.variant = variant || 'default';
    }

    function trapFocus(e) {
        if (!modal.classList.contains('is-open')) return;
        const focusable = [confirmBtn, cancelBtn];
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.key === 'Tab') {
            if (e.shiftKey) {
                if (doc.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                }
            } else {
                if (doc.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        } else if (e.key === 'Escape') {
            Dialog.close();
        }
    }

    function open(opts) {
        const {
            title = 'Confirmation',
            message = '',
            confirmLabel = 'Confirmer',
            cancelLabel = 'Annuler',
            variant = 'default',
            onConfirm = null,
        } = opts || {};

        lastActive = doc.activeElement;
        titleEl.textContent = title;
        bodyEl.textContent = message;
        confirmBtn.textContent = confirmLabel;
        cancelBtn.textContent = cancelLabel;
        setVariant(variant);
        currentOnConfirm = typeof onConfirm === 'function' ? onConfirm : null;

        backdrop.classList.add('is-visible');
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        doc.body.classList.add('modal-open');

        // Focus management
        setTimeout(() => confirmBtn.focus(), 0);
        doc.addEventListener('keydown', trapFocus, true);
        modal.dispatchEvent(new CustomEvent('dialog:open', {bubbles: true}));
    }

    function close() {
        backdrop.classList.remove('is-visible');
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        doc.body.classList.remove('modal-open');
        doc.removeEventListener('keydown', trapFocus, true);
        if (lastActive && typeof lastActive.focus === 'function') {
            setTimeout(() => lastActive.focus(), 0);
        }
        modal.dispatchEvent(new CustomEvent('dialog:close', {bubbles: true}));
    }

    // Public API
    const Dialog = {
        open,
        close,
    };
    window.Dialog = Dialog;

    // Wire buttons
    cancelBtn.addEventListener('click', () => close());
    backdrop.addEventListener('click', () => close());
    doc.getElementById('modalClose')?.addEventListener('click', () => close());
    // Ensure the modal is closed BEFORE executing the confirmed action,
    // so that any global guards (e.g., htmx:beforeRequest) don't cancel the request.
    confirmBtn.addEventListener('click', () => {
        const fn = currentOnConfirm;
        currentOnConfirm = null;
        close();
        if (typeof fn === 'function') {
            // Defer to next tick to let the DOM/state settle
            setTimeout(() => fn(), 0);
        }
    });

    // Attribute-based integration for HTMX actions
    doc.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-dialog-confirm]');
        if (!trigger) return;
        // Intercept the triggering click so HTMX (or other handlers) doesn't fire the request yet
        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();

        const message = trigger.getAttribute('data-dialog-confirm') || 'Êtes-vous sûr ?';
        const title = trigger.getAttribute('data-dialog-title') || 'Confirmer l’action';
        const variant = trigger.getAttribute('data-dialog-variant') || 'default';
        const confirmLabel = trigger.getAttribute('data-dialog-confirm-label') || 'Confirmer';
        const cancelLabel = trigger.getAttribute('data-dialog-cancel-label') || 'Annuler';

        open({
            title, message, variant, confirmLabel, cancelLabel,
            onConfirm: function () {
                executeHtmx(trigger);
            },
        });
    }, true);

    // Extra safety: if a request is about to fire while the dialog is open for a
    // data-dialog-confirm element, cancel it. This avoids accidental requests
    // when some environment ignores preventDefault on the original click.
    doc.addEventListener('htmx:beforeRequest', function (event) {
        try {
            const elt = event.detail && event.detail.elt ? event.detail.elt : null;
            if (!elt) return;
            if (modal.classList.contains('is-open') && elt.closest('[data-dialog-confirm]')) {
                event.preventDefault();
            }
        } catch (_) {
            // no-op
        }
    });

    function executeHtmx(el) {
        if (!window.htmx) {
            // fallback: simulate native confirm flow by re-clicking
            temporarily(el, () => el.click());
            return;
        }
        const methodAttr = ['hx-delete', 'hx-post', 'hx-put', 'hx-patch', 'hx-get'].find(a => el.hasAttribute(a));
        if (!methodAttr) {
            temporarily(el, () => el.click());
            return;
        }
        const url = el.getAttribute(methodAttr);
        const method = methodAttr.replace('hx-', '').toUpperCase();
        // IMPORTANT: rely on htmx to resolve hx-target/hx-swap from the source element.
        // Passing values like "closest li" as opts.target causes htmx:targetError,
        // because the programmatic API expects a real element or a plain selector.
        const opts = {source: el};
        // preserve headers and other HTMX attributes if present
        const headers = el.getAttribute('hx-headers');
        if (headers) opts.headers = JSON.parse(headers);

        window.htmx.ajax(method, url, opts);
    }

    function temporarily(el, fn) {
        // temporarily remove the attribute to avoid recursion
        const attr = 'data-dialog-confirm';
        const val = el.getAttribute(attr);
        el.removeAttribute(attr);
        try {
            fn();
        } finally {
            el.setAttribute(attr, val || '');
        }
    }

    // Global hook: after a successful HTMX request triggered by the history delete button,
    // refresh both the counter badge and (if needed) the history list panel so that the
    // empty-state is rendered when the last item is removed.
    doc.addEventListener('htmx:afterRequest', function (event) {
        try {
            const detail = event.detail || {};
            const elt = detail.requestConfig.elt || null;
            if (!elt || !detail.successful) return;

            // Only handle deletions coming from the history delete button
            if (!elt.classList || !elt.classList.contains('history-item__delete')) return;

            // After the <li> has been swapped out, if the history panel is open and now empty,
            // reload the list fragment from the server to render the proper empty-state.
            const badge = doc.getElementById('historyCountBadge');
            const toggle = doc.getElementById('historyToggle');
            const panel = doc.getElementById('historyList');
            if (!toggle || !panel || !badge || !window.htmx)
                return;

            // Defer to the next tick to let HTMX perform the swap on the <li>
            setTimeout(function () {
                window.htmx.ajax('GET', '/history/count', {target: '#historyCountBadge', swap: 'innerHTML'});
                window.htmx.ajax('GET', '/history/list', {target: '#historyList', swap: 'innerHTML'});
            }, 0);
        } catch (_) {
            // no-op: fail safely
        }
    });
})();

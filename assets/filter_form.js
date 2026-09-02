/*
 * Shared interaction for the filter forms — the desktop chip sidebar and the
 * mobile dropdown bar both use [data-autosubmit]:
 *   - submit the form on any checkbox/radio/select change,
 *   - activating an already-active single-select radio (click or Space)
 *     deselects it,
 *   - toggle the optional custom-date panel,
 *   - open/close the mobile <details data-dd> dropdowns (one at a time,
 *     close on outside click).
 */

function bindForm(form) {
    if (form.dataset.bound) return;
    form.dataset.bound = '1';

    // A "von" without "bis" doesn't submit right away — the reload would close
    // the mobile dropdown before "bis" can be entered. It submits once the
    // date fields lose focus (or "bis" is filled in).
    let pendingDate = false;

    form.addEventListener('change', (e) => {
        const t = e.target;
        // Custom date: clear the presets so they don't conflict, then submit.
        if (t.matches('input[type=date]')) {
            if (t.value) {
                form.querySelectorAll('input[name="zeitraum"]').forEach((r) => { r.checked = false; });
            }
            const bis = form.querySelector('input[type=date][name="bis"]');
            if (t.name === 'von' && t.value && bis && !bis.value) {
                pendingDate = true;
                return;
            }
            pendingDate = false;
            form.requestSubmit();
            return;
        }
        // Picking a preset clears any custom date.
        if (t.matches('input[name="zeitraum"]')) {
            form.querySelectorAll('input[type=date]').forEach((d) => { d.value = ''; });
        }
        if (t.matches('input[type=checkbox], input[type=radio], select')) {
            form.requestSubmit();
        }
    });

    // Submit a pending "von"-only range once focus leaves the date fields
    // (checked a tick later, when the newly focused element is known).
    form.addEventListener('focusout', () => {
        if (!pendingDate) return;
        window.setTimeout(() => {
            if (!pendingDate) return;
            const a = document.activeElement;
            if (a && a.matches('input[type=date]') && form.contains(a)) return;
            pendingDate = false;
            form.requestSubmit();
        }, 0);
    });

    // Custom date panel toggle (desktop sidebar). The button's aria-expanded
    // mirrors the panel so screen readers announce the disclosure state.
    const dateToggle = form.querySelector('[data-date-toggle]');
    const datePanel = form.querySelector('[data-date-panel]');
    if (dateToggle && datePanel) {
        dateToggle.addEventListener('click', () => {
            const open = !datePanel.classList.toggle('hidden');
            dateToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    // Re-activating an active single-select radio (click or Space) clears it.
    // The pre-activation checked state is captured on pointerdown/keydown,
    // because inside the click handler the radio is already checked.
    form.querySelectorAll('label > input[type=radio]').forEach((radio) => {
        const label = radio.closest('label');
        const remember = () => { radio._was = radio.checked; };
        label.addEventListener('pointerdown', remember);
        radio.addEventListener('keydown', (e) => { if (e.key === ' ') remember(); });
        label.addEventListener('click', (e) => {
            const was = radio._was;
            radio._was = false;
            if (was) {
                e.preventDefault();
                radio.checked = false;
                form.requestSubmit();
            }
        });
    });
}

function initFilterForms() {
    document.querySelectorAll('form[data-autosubmit]').forEach(bindForm);

    // Mobile dropdowns: only one open at a time; close when clicking outside.
    const dds = document.querySelectorAll('details[data-dd]');
    if (dds.length === 0) return;
    dds.forEach((d) => {
        d.addEventListener('toggle', () => {
            if (d.open) {
                dds.forEach((o) => { if (o !== d) o.open = false; });
            }
        });
    });
    document.addEventListener('click', (e) => {
        dds.forEach((d) => {
            if (d.open && !d.contains(e.target)) d.open = false;
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFilterForms);
} else {
    initFilterForms();
}

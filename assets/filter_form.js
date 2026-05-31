/*
 * Shared interaction for the filter forms — the desktop chip sidebar and the
 * mobile dropdown bar both use [data-autosubmit]:
 *   - submit the form on any checkbox/radio/select change,
 *   - clicking an already-active single-select radio deselects it,
 *   - toggle the optional custom-date panel,
 *   - open/close the mobile <details data-dd> dropdowns (one at a time,
 *     close on outside click).
 */

function bindForm(form) {
    if (form.dataset.bound) return;
    form.dataset.bound = '1';

    form.addEventListener('change', (e) => {
        const t = e.target;
        // "Meine Events" is handled in saved_events.js (it syncs IDs first).
        if (t.matches('[data-meine]')) return;
        // Custom date: clear the presets so they don't conflict, then submit.
        if (t.matches('input[type=date]')) {
            if (t.value) {
                form.querySelectorAll('input[name="zeitraum"]').forEach((r) => { r.checked = false; });
            }
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

    // Custom date panel toggle (desktop sidebar).
    const dateToggle = form.querySelector('[data-date-toggle]');
    const datePanel = form.querySelector('[data-date-panel]');
    if (dateToggle && datePanel) {
        dateToggle.addEventListener('click', () => datePanel.classList.toggle('hidden'));
    }

    // Re-click an active single-select radio to clear it.
    form.querySelectorAll('label > input[type=radio]').forEach((radio) => {
        const label = radio.closest('label');
        label.addEventListener('mousedown', () => { radio._was = radio.checked; });
        label.addEventListener('click', (e) => {
            if (radio._was) {
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

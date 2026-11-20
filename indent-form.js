(() => {
    const form = document.querySelector('.frontier-indent-form');
    if (!form) return;

    const catalog = JSON.parse(form.querySelector('.frontier-indent-data').textContent);
    const site = form.querySelector('select[name="site"]');
    const tbody = form.querySelector('tbody');
    const add = form.querySelector('.frontier-indent-add');
    const submit = form.querySelector('.frontier-indent-submit');
    const message = form.querySelector('.frontier-indent-message');
    const payload = form.querySelector('[name="indent_lines"]');
    const rows = [];
    let items = new Map();

    const round = (value) => Math.round(value * 1000) / 1000;
    function refresh() {
        const counts = new Map();
        for (const row of rows) {
            if (row.item.value) counts.set(row.item.value, (counts.get(row.item.value) || 0) + 1);
        }

        let valid = 0;
        let excessRows = 0;
        rows.forEach((row, index) => {
            const item = items.get(row.item.value);
            row.insert.disabled = !items.size || rows.length >= 100;
            row.number.textContent = index + 1;
            const available = item ? Math.max(0, round(item.qty - item.used)) : 0;
            const details = item ? [item.unit, item.qty, item.used, available] : ['', '', '', ''];
            row.details.forEach((cell, position) => { cell.textContent = details[position]; });
            const duplicate = (counts.get(row.item.value) || 0) > 1;
            const qty = Number(row.qty.value);
            const excess = item ? round(qty - available) : 0;
            row.warning.textContent = duplicate ? 'Item already selected' : (
                excess > 0 ? 'Exceeds pending quantity by ' + excess + ' ' + item.unit : ''
            );
            row.element.classList.toggle('frontier-indent-warning', duplicate || excess > 0);
            if (excess > 0) excessRows++;
            if (item && !duplicate && row.qty.validity.valid && qty > 0) valid++;
        });
        add.disabled = !items.size || rows.length >= 100;
        submit.disabled = !rows.length || valid !== rows.length;
        message.textContent = !site.value ? '' :
            !items.size ? 'No BOQ items are available for this site.' :
            excessRows ? excessRows + ' item row(s) exceed the pending quantity and will be flagged for approval.' : '';
    }

    function addRow(index = rows.length) {
        if (!items.size || rows.length >= 100) return;
        const element = tbody.insertRow(index);
        const number = element.insertCell();
        const item = document.createElement('select');
        item.setAttribute('aria-label', 'Item Name');
        item.required = true;
        item.add(new Option('Select Item', ''));
        for (const entry of items.values()) {
            item.add(new Option(entry.name + ' · BOQ ' + entry.number + '/' + entry.line, entry.id));
        }
        element.insertCell().append(item);
        const details = Array.from({ length: 4 }, () => element.insertCell());
        const qty = document.createElement('input');
        qty.type = 'number';
        qty.required = true;
        qty.min = '0.001';
        qty.step = '0.001';
        qty.setAttribute('aria-label', 'Indent Now');
        const warning = document.createElement('small');
        warning.className = 'frontier-indent-excess';
        element.insertCell().append(qty, warning);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button frontier-action-icon frontier-action-delete';
        remove.setAttribute('aria-label', 'Remove indent item');
        remove.title = 'Remove indent item';
        const actions = document.createElement('div');
        actions.className = 'frontier-row-actions';
        const insert = document.createElement('button');
        insert.type = 'button';
        insert.className = 'button frontier-action-icon';
        insert.textContent = '+';
        insert.title = 'Add row below';
        insert.setAttribute('aria-label', 'Add row below');
        insert.addEventListener('click', () => addRow(rows.indexOf(row) + 1));
        actions.append(remove, insert);
        element.insertCell().append(actions);
        const row = { element, number, item, details, qty, warning, insert };
        rows.splice(index, 0, row);
        remove.addEventListener('click', () => {
            rows.splice(rows.indexOf(row), 1);
            element.remove();
            refresh();
        });
        item.addEventListener('change', refresh);
        qty.addEventListener('input', refresh);
        item.dispatchEvent(new CustomEvent('frontier:enhance-dropdown', { bubbles: true }));
        refresh();
    }

    function loadSite() {
        tbody.replaceChildren();
        rows.length = 0;
        items = new Map((catalog[site.value] || []).map((item) => [String(item.id), item]));
        if (items.size) addRow();
        refresh();
    }

    site.addEventListener('change', loadSite);
    add.addEventListener('click', () => addRow());
    form.addEventListener('submit', (event) => {
        if (submit.disabled) {
            event.preventDefault();
            return;
        }
        payload.value = JSON.stringify(rows.map((row) => ({ boq: Number(row.item.value), qty: row.qty.value })));
        submit.disabled = true;
    });
    loadSite();
})();

document.addEventListener('click', (event) => {
    const dialog = event.target.closest('.frontier-indent-dialog');
    if (dialog) {
        if (event.target.closest('[data-indent-close]')) dialog.close();
        return;
    }

    const row = event.target.closest('[data-indent-row]');
    if (!row) return;

    let control = event.target.closest('[data-indent-toggle]');
    if (!control) {
        if (event.target.closest('a, button, input, select, textarea, label, form, summary')) return;
        control = row.querySelector('[data-indent-toggle="items"]');
    }
    if (!control) return;

    const panel = document.getElementById(control.getAttribute('aria-controls'));
    if (control.dataset.indentToggle === 'update') {
        if (!panel.open) {
            panel.showModal();
            control.setAttribute('aria-expanded', 'true');
            panel.addEventListener('close', () => {
                control.setAttribute('aria-expanded', 'false');
                control.focus();
            }, { once: true });
        }
        return;
    }
    panel.hidden = !panel.hidden;
    control.setAttribute('aria-expanded', String(!panel.hidden));
});

(() => {
    const form = document.querySelector('.frontier-quotation-form');
    if (!form) return;
    const { groups, suppliers } = JSON.parse(form.querySelector('.frontier-quotation-data').textContent);
    const group = form.querySelector('select[name="quotation_group"]');
    const table = form.querySelector('tbody');
    const list = form.querySelector('datalist');
    const message = form.querySelector('.frontier-quotation-message');
    const save = form.querySelector('.frontier-quotation-save');
    const payload = form.querySelector('[name="quotation_lines"]');
    const normalize = (value) => value.trim().toLocaleLowerCase();
    const ids = new Map();
    const labels = new Map();
    const options = document.createDocumentFragment();
    for (const supplier of suppliers) {
        ids.set(normalize(supplier.label), supplier.id);
        labels.set(supplier.id, supplier.label);
        options.append(new Option(supplier.label, supplier.label));
    }
    list.append(options);

    let entries = [];

    function collect() {
        const lines = [];
        let error = '';
        for (const entry of entries) {
            const seen = new Set();
            for (const pair of entry.pairs) {
                const name = pair.name.value.trim();
                const rate = pair.rate.value.trim();
                if (!name && !rate && !pair.rate.validity.badInput) continue;
                const supplier = ids.get(normalize(name));
                if (!supplier || !rate || !pair.rate.validity.valid || seen.has(supplier)) {
                    error = 'Choose distinct suppliers and enter a valid rate for item ' + entry.number + '.';
                    continue;
                }
                seen.add(supplier);
                lines.push({ indent: entry.id, supplier, rate });
            }
        }
        message.classList.toggle('frontier-quotation-error', !!error);
        message.textContent = error || (!group.value ? 'Select an indent to load its approved items.' : '');
        save.disabled = !!error || lines.length === 0;
        return lines;
    }

    function render() {
        table.replaceChildren();
        entries = [];
        const indent = groups[group.value];
        if (!indent) {
            collect();
            return;
        }
        for (const item of indent.items) {
            const row = table.insertRow();
            for (const value of [item.line, item.name, item.qty]) {
                row.insertCell().textContent = value;
            }
            const pairs = [];
            for (let i = 0; i < 3; i++) {
                const existing = item.quotes[i];
                if (item.locked) {
                    row.insertCell().textContent = existing ? (labels.get(existing.supplier) || '') : '';
                    row.insertCell().textContent = existing ? existing.rate : '';
                    continue;
                }
                const name = document.createElement('input');
                name.type = 'text';
                name.autocomplete = 'off';
                name.setAttribute('list', list.id);
                name.setAttribute('aria-label', 'Supplier ' + (i + 1) + ' for item ' + item.number);
                name.placeholder = 'Search supplier';
                name.value = existing ? (labels.get(existing.supplier) || '') : '';
                const rate = document.createElement('input');
                rate.type = 'number';
                rate.min = '0';
                rate.step = '0.001';
                rate.setAttribute('aria-label', 'Supplier ' + (i + 1) + ' rate for item ' + item.number);
                rate.value = existing ? existing.rate : '';
                row.insertCell().append(name);
                row.insertCell().append(rate);
                pairs.push({ name, rate });
            }
            entries.push({ id: item.id, number: item.number, pairs });
        }
        collect();
        if (entries.length && entries.every((entry) => !entry.pairs.length)) {
            message.textContent = 'All approved items already have a finalized supplier.';
        }
    }

    group.addEventListener('change', render);
    table.addEventListener('input', collect);
    form.addEventListener('submit', (event) => {
        const lines = collect();
        if (save.disabled) {
            event.preventDefault();
            return;
        }
        payload.value = JSON.stringify(lines);
        save.disabled = true;
    });
    render();
})();

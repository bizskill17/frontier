const boqForm = document.querySelector('.frontier-boq-form');
if (boqForm) {
    const data = JSON.parse(boqForm.querySelector('.frontier-boq-data').textContent);
    const table = boqForm.querySelector('tbody');
    const list = boqForm.querySelector('datalist');
    const file = boqForm.querySelector('.frontier-boq-file');
    const message = boqForm.querySelector('.frontier-boq-message');
    const save = boqForm.querySelector('.frontier-boq-save');
    const payload = boqForm.querySelector('input[name="boq_lines"]');
    const normalize = (value) => String(value).trim().replace(/\s+/gu, ' ').toLowerCase();
    const byName = new Map();
    const byLabel = new Map();
    data.items.forEach((item) => {
        const key = normalize(item.name);
        if (!byName.has(key)) byName.set(key, []);
        byName.get(key).push(item);
    });
    const options = document.createDocumentFragment();
    for (const entries of byName.values()) {
        entries.forEach((item) => {
            const label = entries.length === 1 ? item.name : item.name + ' — ' + item.category + ' / ' + item.type + ' (#' + item.id + ')';
            byLabel.set(normalize(label), item);
            options.append(new Option(label, label));
        });
    }
    list.append(options);

    let rows = [];
    let uploading = false;

    function match(name) {
        return byLabel.get(normalize(name));
    }

    function sync() {
        let unresolved = 0;
        rows.forEach((row, index) => {
            row.number.textContent = index + 1;
            const item = match(row.name.value);
            const unmatched = row.name.value.trim() !== '' && !item;
            row.element.classList.toggle('frontier-boq-unmatched', unmatched);
            const ambiguous = unmatched && byName.has(normalize(row.name.value));
            row.status.textContent = item ? '✓ Matched' : (ambiguous ? '⚠ Multiple matches' : (unmatched ? '⚠ Not in Item Master' : ''));
            row.selectItem.hidden = !unmatched;
            row.status.classList.toggle('frontier-boq-matched', !!item);
            if (!item) unresolved++;
        });
        save.disabled = uploading || !rows.length || unresolved > 0;
        message.textContent = unresolved ? unresolved + ' item row(s) need a match before saving.' : rows.length + ' item row(s) matched.';
    }

    function addRow(values = {}) {
        const element = table.insertRow();
        const number = element.insertCell();
        const inputs = {};
        for (const [key, title] of [['name', 'Item Name'], ['qty', 'Quantity'], ['unit', 'Unit']]) {
            const input = document.createElement('input');
            input.type = key === 'qty' ? 'number' : 'text';
            input.required = true;
            input.setAttribute('aria-label', title);
            if (key === 'name') {
                input.setAttribute('list', list.id);
                input.autocomplete = 'off';
            } else if (key === 'qty') {
                input.min = '0.001';
                input.step = '0.001';
            } else {
                input.maxLength = 200;
            }
            input.value = values[key] ?? '';
            element.insertCell().append(input);
            inputs[key] = input;
        }
        const status = element.insertCell();
        const actions = element.insertCell();
        const selectItem = document.createElement('button');
        selectItem.type = 'button';
        selectItem.textContent = 'Select Item';
        selectItem.addEventListener('click', () => {
            inputs.name.focus();
            inputs.name.select();
        });
        actions.append(selectItem);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = 'Remove';
        actions.append(' ', remove);
        const row = { element, number, status, selectItem, ...inputs };
        remove.addEventListener('click', () => {
            element.remove();
            rows = rows.filter((entry) => entry !== row);
            sync();
        });
        inputs.name.addEventListener('input', sync);
        rows.push(row);
    }

    boqForm.querySelector('.frontier-boq-add').addEventListener('click', () => {
        if (rows.length >= 500) {
            message.textContent = 'A BOQ can contain up to 500 item rows.';
            return;
        }
        addRow();
        sync();
        rows[rows.length - 1].name.focus();
    });

    file.addEventListener('change', async () => {
        if (!file.files.length || uploading) return;
        uploading = true;
        file.disabled = true;
        sync();
        message.textContent = 'Reading Excel workbook…';
        const body = new FormData();
        body.append('workbook', file.files[0]);
        let errorMessage = '';
        try {
            const response = await fetch(file.dataset.endpoint, { method: 'POST', body, credentials: 'same-origin' });
            const result = await response.json();
            if (!response.ok || !result.success) {
                errorMessage = result.data && result.data.message ? result.data.message : 'The workbook could not be imported.';
            } else {
                const retained = rows.filter((row) => row.name.value || row.qty.value || row.unit.value);
                if (retained.length + result.data.rows.length > 500) {
                    errorMessage = 'A BOQ can contain up to 500 item rows.';
                } else {
                    rows.filter((row) => !retained.includes(row)).forEach((row) => row.element.remove());
                    rows = retained;
                    result.data.rows.forEach(addRow);
                }
            }
        } catch (error) {
            errorMessage = 'The workbook could not be imported.';
        } finally {
            uploading = false;
            file.disabled = false;
            file.value = '';
            sync();
            if (errorMessage) message.textContent = errorMessage;
        }
    });

    boqForm.addEventListener('submit', (event) => {
        if (save.disabled) {
            event.preventDefault();
            return;
        }
        payload.value = JSON.stringify(rows.map((row) => ({
            item: match(row.name.value).id,
            qty: row.qty.value,
            unit: row.unit.value,
        })));
        save.disabled = true;
    });
    addRow();
    sync();
}

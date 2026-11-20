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
    const byId = new Map();
    data.items.forEach((item) => {
        const key = normalize(item.name);
        if (!byName.has(key)) byName.set(key, []);
        byName.get(key).push(item);
        byId.set(item.id, item);
    });
    const options = document.createDocumentFragment();
    for (const entries of byName.values()) {
        const name = entries[0].name;
        options.append(new Option(name, name));
    }
    list.append(options);

    const addDialog = document.getElementById('frontier-boq-add-item');
    let addTarget;
    let rows = [];
    let uploading = false;

    function itemForRow(row) {
        if (row.selectedItem) return row.selectedItem;
        const entries = byName.get(normalize(row.name.value)) || [];
        return entries.length === 1 ? entries[0] : undefined;
    }

    function sync() {
        let unresolved = 0;
        rows.forEach((row, index) => {
            row.number.textContent = index + 1;
            row.namePreview.textContent = row.name.value;
            row.namePreview.parentElement.classList.toggle('is-empty', !row.name.value);
            const item = itemForRow(row);
            const unmatched = row.name.value.trim() !== '' && !item;
            row.element.classList.toggle('frontier-boq-unmatched', unmatched);
            const ambiguous = unmatched && byName.has(normalize(row.name.value));
            row.status.textContent = item ? '✓ Matched' : (ambiguous ? '⚠ Multiple matches' : (unmatched ? '⚠ Not in Item Master' : ''));
            row.addItem.style.display = unmatched && !ambiguous && !row.locked && addDialog ? '' : 'none';
            if (ambiguous && !row.locked) row.status.append(' ', row.selectItem);
            if (row.locked) row.status.textContent = 'Used in indent';
            row.status.classList.toggle('frontier-boq-matched', !!item);
            const quantityValid = /^\d{1,10}(?:\.\d{1,3})?$/.test(row.qty.value) && Number(row.qty.value) > 0;
            const fields = [[row.name, !!item], [row.qty, quantityValid], [row.unit, !!row.unit.value]];
            for (const [field, valid] of fields) {
                field.closest('td').classList.toggle('frontier-boq-invalid', !valid);
                field.setAttribute('aria-invalid', String(!valid));
            }
            if (fields.some(([, valid]) => !valid)) unresolved++;
        });
        save.disabled = uploading || !rows.length || unresolved > 0;
        message.textContent = '';
    }

    function addRow(values = {}, index = rows.length) {
        const element = table.insertRow(index);
        const number = element.insertCell();
        const inputs = {};
        const namePreview = document.createElement('span');
        namePreview.setAttribute('aria-hidden', 'true');
        const initialName = byId.get(values.item)?.name || values.name || '';
        for (const [key, title] of [['name', 'Item Name'], ['qty', 'Quantity'], ['unit', 'Unit']]) {
            const input = document.createElement(key === 'unit' ? 'select' : 'input');
            if (key !== 'unit') input.type = key === 'qty' ? 'number' : 'text';
            input.required = true;
            input.setAttribute('aria-label', title);
            if (key === 'name') {
                input.setAttribute('list', list.id);
                input.autocomplete = 'off';
            } else if (key === 'qty') {
                input.min = '0.001';
                input.step = '0.001';
            } else {
                input.append(new Option('Select Unit', ''));
                data.units.forEach((unit) => input.append(new Option(unit, unit)));
                const importedUnit = String(values.unit ?? '');
                const match = data.units.find((unit) => normalize(unit) === normalize(importedUnit));
                if (values.id && importedUnit && !data.units.includes(importedUnit)) {
                    input.append(new Option(importedUnit, importedUnit));
                }
                input.value = values.id ? importedUnit : (match || '');
                input.disabled = !!values.locked;
            }
            if (key !== 'unit') {
                input.value = key === 'name' ? initialName : (values[key] ?? '');
                input.readOnly = !!values.locked;
            }
            const cell = element.insertCell();
            if (key === 'name') {
                const wrapper = document.createElement('div');
                wrapper.className = 'frontier-boq-name is-empty';
                wrapper.append(input, namePreview);
                cell.append(wrapper);
            } else {
                cell.append(input);
            }
            inputs[key] = input;
        }
        const status = element.insertCell();
        const actionCell = element.insertCell();
        const actions = document.createElement('div');
        actions.className = 'frontier-row-actions';
        actionCell.append(actions);
        const selectItem = document.createElement('button');
        selectItem.type = 'button';
        selectItem.textContent = 'Choose match';
        selectItem.addEventListener('click', () => {
            const entries = byName.get(normalize(inputs.name.value));
            if (!entries) {
                inputs.name.focus();
                inputs.name.select();
                return;
            }
            const dialog = document.createElement('dialog');
            dialog.className = 'frontier-indent-dialog';
            dialog.setAttribute('aria-label', 'Select Item');
            const heading = document.createElement('h2');
            heading.textContent = 'Select Item';
            dialog.append(heading);
            for (const item of entries) {
                const line = document.createElement('p');
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'button';
                option.textContent = item.name + ' (Item ID ' + item.id + ')';
                option.addEventListener('click', () => {
                    row.selectedItem = item;
                    inputs.name.value = item.name;
                    dialog.close();
                    sync();
                    inputs.name.focus();
                });
                line.append(option);
                dialog.append(line);
            }
            const back = document.createElement('button');
            back.type = 'button';
            back.className = 'button';
            back.textContent = 'Back';
            back.addEventListener('click', () => dialog.close());
            dialog.append(back);
            dialog.addEventListener('close', () => dialog.remove(), { once: true });
            boqForm.append(dialog);
            dialog.showModal();
        });
        const addItem = document.createElement('button');
        addItem.type = 'button';
        addItem.className = 'button';
        addItem.textContent = 'Add Item';
        addItem.style.display = 'none';
        addItem.addEventListener('click', () => {
            addTarget = row;
            addDialog.querySelector('[name="name"]').value = row.name.value.trim();
            addDialog.querySelector('[role="alert"]').textContent = '';
            addDialog.showModal();
        });
        actions.append(addItem);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button frontier-action-icon frontier-action-delete';
        remove.setAttribute('aria-label', 'Remove BOQ item');
        remove.title = 'Remove BOQ item';
        if (values.locked) {
            actions.textContent = 'Locked';
        } else {
            actions.append(' ', remove);
        }
        const insert = document.createElement('button');
        insert.type = 'button';
        insert.className = 'button frontier-action-icon';
        insert.textContent = '+';
        insert.setAttribute('aria-label', 'Add row below');
        insert.title = 'Add row below';
        insert.addEventListener('click', () => insertRow(rows.indexOf(row) + 1));
        actions.append(insert);
        const row = { element, number, status, namePreview, selectItem, addItem, id: values.id || 0,
            selectedItem: values.item ? { id: values.item } : undefined, locked: !!values.locked, ...inputs };
        remove.addEventListener('click', () => {
            element.remove();
            rows = rows.filter((entry) => entry !== row);
            sync();
        });
        inputs.qty.addEventListener('input', sync);
        inputs.unit.addEventListener('change', sync);
        inputs.name.addEventListener('input', () => {
            row.selectedItem = undefined;
            sync();
        });
        rows.splice(index, 0, row);
        return row;
    }

    if (addDialog) {
        const form = addDialog.querySelector('form');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submit = form.querySelector('[type="submit"]');
            if (submit.disabled) return;
            const target = addTarget;
            const alert = form.querySelector('[role="alert"]');
            alert.textContent = '';
            submit.disabled = true;
            try {
                const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.data?.message || 'Could not save the item.');
                const item = result.data.item;
                const key = normalize(item.name);
                byName.set(key, [item]);
                byId.set(item.id, item);
                list.append(new Option(item.name, item.name));
                target.selectedItem = item;
                target.name.value = item.name;
                addDialog.close();
                sync();
                target.name.focus();
            } catch (error) {
                alert.textContent = error.message || 'Could not save the item.';
            } finally {
                submit.disabled = false;
            }
        });
    }

    function insertRow(index) {
        if (rows.length >= 500) {
            message.textContent = 'A BOQ can contain up to 500 item rows.';
            return;
        }
        const row = addRow({}, index);
        sync();
        row.name.focus();
    }

    boqForm.querySelector('.frontier-boq-add').addEventListener('click', () => insertRow(rows.length));

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
                    result.data.rows.forEach((values) => addRow(values));
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
            id: row.id,
            item: itemForRow(row).id,
            qty: row.qty.value,
            unit: row.unit.value,
        })));
        save.disabled = true;
    });
    if (data.lines.length) {
        data.lines.forEach((values) => addRow(values));
    } else {
        addRow();
    }
    sync();
}

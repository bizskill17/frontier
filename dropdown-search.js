document.querySelectorAll('.frontier-pages select:not([multiple])').forEach((select, index) => {
    if (select.disabled) return;
    const options = Array.from(select.options).filter((option) => !option.disabled && (option.value || !select.required));
    if (!options.some((option) => option.value)) return;

    const label = select.closest('label');
    const name = label ? label.firstChild.textContent.replace(/\s*\*$/, '').trim() : select.name;
    const root = document.createElement('span');
    root.className = 'frontier-combobox';
    const input = document.createElement('input');
    input.type = 'text';
    input.autocomplete = 'off';
    input.placeholder = 'Select ' + name;
    input.required = select.required;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-label', name);
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');

    const list = document.createElement('span');
    list.id = 'frontier-dropdown-' + index;
    list.className = 'frontier-combobox-list';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', name);
    list.hidden = true;
    input.setAttribute('aria-controls', list.id);

    const searchIcon = document.createElement('span');
    searchIcon.className = 'frontier-combobox-search';
    searchIcon.setAttribute('aria-hidden', 'true');
    const arrow = document.createElement('span');
    arrow.className = 'frontier-combobox-arrow';
    arrow.setAttribute('aria-hidden', 'true');
    root.append(input, searchIcon, arrow, list);
    select.before(root);
    select.hidden = true;
    select.required = false;

    let matches = [];
    let active = -1;

    function syncSelection() {
        input.value = select.value ? select.selectedOptions[0].textContent : '';
        input.setCustomValidity(input.required && !select.value ? 'Select ' + name + '.' : '');
    }

    function highlight(next) {
        if (active >= 0) list.children[active].classList.remove('is-active');
        active = next;
        input.removeAttribute('aria-activedescendant');
        if (active < 0) return;
        const row = list.children[active];
        row.classList.add('is-active');
        input.setAttribute('aria-activedescendant', row.id);
    }

    function show(query) {
        const search = query.trim().toLocaleLowerCase();
        matches = options.filter((option) => option.textContent.toLocaleLowerCase().includes(search));
        list.replaceChildren();
        active = -1;
        matches.forEach((option, position) => {
            const row = document.createElement('span');
            row.id = list.id + '-' + position;
            row.className = 'frontier-combobox-option';
            row.setAttribute('role', 'option');
            row.setAttribute('aria-selected', String(option.selected));
            row.textContent = option.textContent;
            row.dataset.position = position;
            list.append(row);
        });
        if (!matches.length) {
            const empty = document.createElement('span');
            empty.className = 'frontier-combobox-empty';
            empty.setAttribute('role', 'status');
            empty.textContent = 'No matching options';
            list.append(empty);
        }
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        highlight(matches.findIndex((option) => option.selected));
    }

    function close() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        syncSelection();
    }

    function choose(position) {
        const option = matches[position];
        if (!option) return;
        select.value = option.value;
        close();
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function open() {
        if (!list.hidden) return;
        show('');
        input.select();
    }

    syncSelection();
    input.addEventListener('focus', open);
    input.addEventListener('click', open);
    input.addEventListener('input', () => show(input.value));
    input.addEventListener('blur', close);
    select.addEventListener('change', close);
    if (select.form) select.form.addEventListener('reset', () => setTimeout(close));
    arrow.addEventListener('mousedown', (event) => event.preventDefault());
    arrow.addEventListener('click', () => {
        if (list.hidden) {
            input.focus();
            open();
        } else {
            close();
        }
    });
    list.addEventListener('mousedown', (event) => event.preventDefault());
    list.addEventListener('click', (event) => {
        const option = event.target.closest('[data-position]');
        if (option) choose(Number(option.dataset.position));
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !list.hidden) {
            event.preventDefault();
            close();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (list.hidden) open();
            if (!matches.length) return;
            const step = event.key === 'ArrowDown' ? 1 : -1;
            highlight(active < 0 && step < 0 ? matches.length - 1 : Math.max(0, Math.min(matches.length - 1, active + step)));
            list.children[active].scrollIntoView({ block: 'nearest' });
        } else if (event.key === 'Enter' && !list.hidden) {
            event.preventDefault();
            if (active >= 0 || matches.length === 1) choose(Math.max(0, active));
        }
    });
});

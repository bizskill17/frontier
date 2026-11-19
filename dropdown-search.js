document.querySelectorAll('.frontier-pages select:not([multiple])').forEach((select) => {
    const options = Array.from(select.options);
    if (!options.some((option) => option.value !== '')) return;

    const fieldLabel = select.closest('label');
    const name = fieldLabel ? fieldLabel.firstChild.textContent.trim() : select.name;
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search ' + name;
    search.setAttribute('aria-label', 'Search ' + name);
    search.autocomplete = 'off';
    const status = document.createElement('small');
    status.setAttribute('role', 'status');
    status.hidden = true;
    select.before(search);
    select.after(status);

    function filterOptions() {
        const query = search.value.trim().toLocaleLowerCase();
        let matches = 0;
        options.forEach((option) => {
            const matchesQuery = option.textContent.toLocaleLowerCase().includes(query);
            if (option.value !== '' && matchesQuery) matches++;
            option.hidden = !matchesQuery && !option.selected && option.value !== '';
        });
        status.hidden = matches !== 0;
        status.textContent = matches ? '' : 'No matching options';
    }

    search.addEventListener('input', filterOptions);
    select.addEventListener('change', () => {
        search.value = '';
        filterOptions();
    });
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === 'ArrowDown') {
            event.preventDefault();
            select.focus();
            if (typeof select.showPicker === 'function') {
                try { select.showPicker(); } catch (error) { /* Select remains focused. */ }
            }
        }
    });
});

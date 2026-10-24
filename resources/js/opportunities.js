document.querySelectorAll('[data-opportunity-editor]').forEach(form => {
    const button = form.querySelector('[data-add-question]');
    const list = form.querySelector('[data-question-list]');
    if (!button || !list) return;
    button.hidden = false;
    button.addEventListener('click', () => {
        const rows = list.querySelectorAll('[data-question-row]');
        if (rows.length >= 12) return;
        const row = rows[0].cloneNode(true);
        row.querySelector('legend').textContent = 'Question ' + (rows.length + 1);
        row.querySelectorAll('[name]').forEach(input => {
            input.name = input.name.replace(/questions\[\d+\]/, 'questions[' + rows.length + ']');
            if (input.type === 'checkbox') input.checked = false;
            else if (input.tagName === 'SELECT') input.selectedIndex = 0;
            else input.value = '';
        });
        list.append(row);
        row.querySelector('input').focus();
        button.disabled = rows.length + 1 >= 12;
    });
});
document.querySelectorAll('[data-group-application]').forEach(form => {
    const select = form.querySelector('[data-application-group]');
    if (!select) return;
    const render = () => form.querySelectorAll('[data-participant-group]').forEach(fieldset => {
        const active = fieldset.dataset.participantGroup === select.value;
        fieldset.hidden = !active;
        fieldset.disabled = !active;
    });
    select.addEventListener('change', render);
    render();
});


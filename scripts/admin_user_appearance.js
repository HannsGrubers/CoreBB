(() => {
    const form = document.getElementById('appearance-editor');
    if (!form) return;

    const samples = form.closest('section.admin-title-editor').querySelectorAll('[data-name-sample]');
    const pickers = form.querySelectorAll('[data-color-for]');

    function hex(value) {
        const match = /^#?([a-f0-9]{3}|[a-f0-9]{6})$/i.exec(value.trim());
        if (!match) return '';
        const digits = match[1];
        return '#' + (digits.length === 3 ? [...digits].map(digit => digit + digit).join('') : digits);
    }

    function update() {
        const fields = form.elements;
        for (const picker of pickers) {
            const color = hex(fields[picker.dataset.colorFor].value);
            picker.value = color || (picker.dataset.colorFor === 'vip_bg_color' ? '#333333' : '#ffffff');
        }
        for (const sample of samples) {
            if (fields.preset_name) sample.textContent = fields.preset_name.value.trim() || 'Username';
            const style = sample.style;
            style.color = hex(fields.vip_text_color.value);
            style.backgroundColor = hex(fields.vip_bg_color.value);
            style.fontWeight = fields.vip_bold.checked ? 'bold' : '';
            style.fontStyle = fields.vip_italic.checked ? 'italic' : '';
            style.textDecoration = fields.vip_strike.checked ? 'line-through' : '';
            style.border = fields.vip_border.checked ? '1px solid ' + (hex(fields.vip_border_color.value) || 'currentColor') : '';
            style.padding = fields.vip_border.checked ? '0 2px' : '';
        }
    }

    for (const picker of pickers) {
        picker.hidden = false;
        const choose = () => { form.elements[picker.dataset.colorFor].value = picker.value; };
        picker.addEventListener('input', choose);
        picker.addEventListener('change', choose);
    }
    for (const button of form.querySelectorAll('[data-clear-for]')) {
        button.hidden = false;
        button.addEventListener('click', () => {
            const field = form.elements[button.dataset.clearFor];
            field.value = '';
            field.focus();
            update();
        });
    }
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
})();

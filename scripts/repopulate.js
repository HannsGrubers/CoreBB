// Optional autosave for the message field on legacy compose forms.
document.addEventListener('DOMContentLoaded', () => {
    const box = document.querySelector('textarea[name="message"], textarea[name="message_body"]');
    if (!box || !box.form) return;
    const key = 'corebb:draft:' + location.pathname + location.search;
    try {
        const saved = sessionStorage.getItem(key);
        if (saved && !box.value && window.confirm('Restore your draft?')) box.value = saved;
        box.addEventListener('input', () => sessionStorage.setItem(key, box.value));
        box.form.addEventListener('submit', () => sessionStorage.removeItem(key));
    } catch (_) { /* Private browsing may disable storage. */ }
});

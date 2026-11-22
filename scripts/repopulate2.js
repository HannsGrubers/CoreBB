// Legacy composer hook. Keep unsent text in this tab, outside request cookies.
(function () {
    const script = document.currentScript;
    const key = 'corebb:composer:' + new URL('.', script.src).pathname + location.pathname + location.search;
    const field = () => document.getElementById('MessageBox') || document.getElementById('message_body');
    window.savedata = function () {
        const box = field();
        if (box) {
            try { sessionStorage.setItem(key, box.value); } catch (_) { /* Storage is optional. */ }
        }
    };
    document.addEventListener('DOMContentLoaded', () => {
        const box = field();
        if (!box) return;
        try {
            if (!box.value) box.value = sessionStorage.getItem(key) || '';
            box.form?.addEventListener('submit', () => sessionStorage.removeItem(key));
        } catch (_) { /* Storage is optional. */ }
    });
})();

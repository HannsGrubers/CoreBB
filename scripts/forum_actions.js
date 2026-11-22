// Explicitly supported controls for the server-rendered forum and admin pages.
(() => {
    const toolbar = new Set([
        'fnMarkupBold', 'fnMarkupCodePrompt', 'fnMarkupColorPrompt', 'fnMarkupHighlightPrompt',
        'fnMarkupImage', 'fnMarkupItalic', 'fnMarkupLink', 'fnMarkupListItem', 'fnMarkupOverline',
        'fnMarkupSizePrompt', 'fnMarkupUnderline',
    ]);
    document.addEventListener('click', event => {
        const el = event.target.closest('[data-forum-action],[data-forum-nav],[data-confirm-click]');
        if (!el) return;
        if (el.hasAttribute('data-confirm-click') && !window.confirm(el.dataset.confirmClick)) {
            event.preventDefault();
            return;
        }
        if (el.hasAttribute('data-forum-nav')) {
            const url = new URL(el.dataset.forumNav, location.href);
            if (url.origin === location.origin) location.assign(url.href);
            return;
        }
        const action = el.dataset.forumAction;
        if (toolbar.has(action)) {
            window[action](el);
            return;
        }
        switch (action) {
            case 'select': el.select(); break;
            case 'caret': window.pst_fnSaveCaret(el); break;
            case 'faces': window.hideShowBelow('divFacesMenu', el); break;
            case 'face': event.preventDefault(); window.fnMarkupFace(el.dataset.face); window.hideElement('divFacesMenu'); break;
            case 'close-faces': event.preventDefault(); window.hideElement('divFacesMenu'); break;
            case 'post-id': event.preventDefault(); window.open(el.href, 'ViewPostID', 'width=200,height=50,scrollbars=no,resizable=no'); break;
            case 'upload-image': event.preventDefault(); window.wbPostImageUploadAndInsert(); break;
            case 'poll-choice': event.preventDefault(); window.wbPostPollAddChoice(); break;
            case 'poll-toggle': event.preventDefault(); window.wbPostPollToggle(); break;
        }
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (form.hasAttribute('data-confirm-submit') && !window.confirm(form.dataset.confirmSubmit)) event.preventDefault();
        if (form.dataset.forumSubmit === 'post' && !window.SubmitTheForm(form)) event.preventDefault();
        if (form.dataset.forumSubmit === 'pm' && !window.wbPmSubmitGuard(form)) event.preventDefault();
    });
    for (const type of ['keyup', 'select']) {
        document.addEventListener(type, event => {
            if (event.target.matches('[data-forum-caret]')) window.pst_fnSaveCaret(event.target);
        }, true);
    }
    document.addEventListener('change', event => {
        if (event.target.matches('[data-forum-image]')) window.wbPostImageResetInsertedFlag();
    });
})();

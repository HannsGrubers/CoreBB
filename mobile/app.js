(function () {
    'use strict';

    const app = document.getElementById('mobileApp');
    const nav = document.getElementById('mobileNav');
    const sessionBar = document.getElementById('mobileSession');
    let viewer = null;
    let csrf = '';

    function params() {
        return new URLSearchParams(window.location.search);
    }

    function currentScreen() {
        return params().get('screen') || document.body.dataset.initialScreen || 'index';
    }

    function param(name, fallback) {
        const value = params().get(name);
        return value === null || value === '' ? fallback : value;
    }

    function currentQueryString() {
        return window.location.search || '?screen=index&view=mobile';
    }

    function esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setLoading() {
        app.innerHTML = '<div class="loading">Loading CoreBB...</div>';
    }

    function errorBox(message) {
        app.innerHTML = `<div class="notice error">${esc(message || 'Unable to load this page.')}</div>`;
    }

    async function api(path, options) {
        // Same-origin cookies carry the normal CoreBB login state.
        const response = await fetch(`/api/v1/${path}`, Object.assign({
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }, options || {}));
        const payload = await response.json().catch(() => ({ ok: false, error: { message: 'Invalid JSON response.' } }));
        if (!response.ok || !payload.ok) {
            const err = new Error(payload.error?.message || payload.message || 'API request failed.');
            err.payload = payload;
            err.status = response.status;
            throw err;
        }
        return payload.data || {};
    }

    async function csrfToken() {
        if (csrf) {
            return csrf;
        }
        const data = await api('auth/csrf');
        csrf = data.csrfToken || '';
        return csrf;
    }

    async function postJson(path, body) {
        // Match desktop forms while also supporting the API CSRF header.
        const token = await csrfToken();
        return api(path, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CoreBB-CSRF': token
            },
            body: JSON.stringify(Object.assign({ corebb_csrf_token: token }, body || {}))
        });
    }

    function go(screen, nextParams) {
        // Mobile navigation is client-side, but URLs remain shareable/bookmarkable
        // through the screen/id/page query parameters.
        const query = new URLSearchParams(nextParams || {});
        query.set('screen', screen);
        query.set('view', 'mobile');
        history.pushState(null, '', `/mobile/?${query.toString()}`);
        render();
    }

    function replaceWithQuery(queryString) {
        const query = queryString && queryString.startsWith('?') ? queryString : '?screen=index&view=mobile';
        history.replaceState(null, '', `/mobile/${query}`);
        render();
    }

    function threadIdFromResult(result) {
        for (const link of result.links || []) {
            const href = String(link.href || link.web || '');
            const pretty = href.match(/\/topic\/(\d+)/i);
            if (pretty) {
                return Number(pretty[1]);
            }
            const controller = href.match(/(?:controllers\/)?forum\.php\?[^#]*\baction=thread\b[^#]*\bid=(\d+)/i);
            if (controller) {
                return Number(controller[1]);
            }
        }
        return 0;
    }

    function resultScreen(message, buttons) {
        app.innerHTML = `
            <div class="notice">${esc(message || 'Done.')}</div>
            <div class="result-actions">${buttons.join('')}</div>
        `;
    }

    function scrollTargetFromParams() {
        const target = param('jump', '');
        if (!target) {
            return;
        }
        window.setTimeout(() => {
            if (target === 'top') {
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }
            if (target === 'bottom') {
                window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
                return;
            }
            if (target === 'poll') {
                document.getElementById('poll')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }
            if (/^post-\d+$/.test(target)) {
                document.getElementById(target)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 80);
    }

    function renderNav(active) {
        const authed = viewer && viewer.authenticated;
        const items = [
            ['index', 'Boards'],
            ['pm', 'PM'],
            [authed ? 'logout' : 'login', authed ? 'Logout' : 'Login'],
            ['register', 'Register']
        ];
        nav.innerHTML = items.map(([screen, label]) => (
            `<button type="button" class="${screen === active ? 'active' : ''}" data-go="${screen}">${esc(label)}</button>`
        )).join('');
        if (sessionBar) {
            sessionBar.innerHTML = authed
                ? `Logged in as <button type="button" data-go="profile" data-id="${Number(viewer.user?.id || 0)}">${esc(viewer.user?.username || 'User')}</button>`
                : 'Browsing as Guest';
        }
    }

    function pager(total, page, screen, extra, compact) {
        if (total <= 1) {
            return '';
        }
        const buttons = [];
        if (page > 1 && !compact) {
            buttons.push(`<button class="button" data-go="${screen}" data-params='${esc(JSON.stringify(Object.assign({}, extra, { page: 1 })))}'>First</button>`);
        }
        if (page > 1) {
            buttons.push(`<button class="button" data-go="${screen}" data-params='${esc(JSON.stringify(Object.assign({}, extra, { page: page - 1 })))}'>Prev</button>`);
        }
        buttons.push(`<span class="page-chip">Page ${page} of ${total}</span>`);
        if (page < total) {
            buttons.push(`<button class="button" data-go="${screen}" data-params='${esc(JSON.stringify(Object.assign({}, extra, { page: page + 1 })))}'>Next</button>`);
        }
        if (page < total && !compact) {
            buttons.push(`<button class="button" data-go="${screen}" data-params='${esc(JSON.stringify(Object.assign({}, extra, { page: total, jump: 'bottom' })))}'>Latest</button>`);
        }
        return `<div class="toolbar pager">${buttons.join('')}</div>`;
    }

    async function loadMe() {
        try {
            viewer = await api('me');
        } catch (e) {
            viewer = { authenticated: false, user: null };
        }
    }

    async function renderIndex() {
        const data = await api('index');
        const favorites = (data.favorites || []).map(forumItem).join('');
        const categories = (data.categories || []).map((category) => `
            <h2 class="section-title">${esc(category.name)}</h2>
            ${(category.forums || []).map(forumItem).join('') || '<div class="notice">No visible boards.</div>'}
        `).join('');
        app.innerHTML = `
            ${favorites ? '<h2 class="section-title">Favorites</h2>' + favorites : ''}
            ${categories || '<div class="notice">No boards are visible.</div>'}
        `;
    }

    function forumItem(forum) {
        return `
            <button type="button" class="item" data-go="board" data-id="${Number(forum.id || 0)}">
                <span class="item-title">${esc(forum.name)}</span>
                <span>${esc(forum.description)}</span>
                <span class="meta">
                    <span>${Number(forum.topicCount || 0)} topics</span>
                    <span>${Number(forum.postCount || 0)} posts</span>
                    <span>${esc(forum.lastPost?.display || '')}</span>
                </span>
            </button>
        `;
    }

    async function renderBoard() {
        const id = Number(param('id', 0));
        const page = Number(param('page', 1));
        const data = await api(`boards/${id}?page=${page}`);
        const currentPage = Number(data.pagination?.currentPage || page || 1);
        const totalPages = Number(data.pagination?.totalPages || 1);
        app.innerHTML = `
            <h1 class="section-title">${esc(data.name)}</h1>
            ${data.description ? `<div class="notice">${esc(data.description)}</div>` : ''}
            ${(data.topics || []).map(topicItem).join('') || '<div class="notice">No topics found.</div>'}
            <div class="bottom-page-label">Page ${currentPage} of ${totalPages}</div>
            <div class="board-bottom-controls">
                ${currentPage > 1 ? `<button class="button" data-go="board" data-id="${id}" data-page="1">First</button>` : ''}
                ${currentPage > 1 ? `<button class="button" data-go="board" data-id="${id}" data-page="${currentPage - 1}">Prev</button>` : ''}
                ${currentPage < totalPages ? `<button class="button" data-go="board" data-id="${id}" data-page="${currentPage + 1}">Next</button>` : ''}
                ${currentPage < totalPages ? `<button class="button" data-go="board" data-id="${id}" data-page="${totalPages}">Latest</button>` : ''}
                ${data.permissions?.canPost ? `<button class="button primary" data-go="post-new" data-id="${id}">New Topic</button>` : ''}
            </div>
        `;
    }

    function topicItem(topic) {
        const badges = [
            topic.isSticky ? 'Sticky' : '',
            topic.isLocked ? 'Locked' : '',
            topic.hasPoll ? 'Poll' : ''
        ].filter(Boolean).join(' / ');
        return `
            <button type="button" class="item" data-go="thread" data-id="${Number(topic.id || 0)}">
                <span class="item-title">${esc(topic.title)}</span>
                <span class="meta">
                    <span>${Number(topic.replyCount || 0)} replies</span>
                    <span>${esc(topic.poster?.username || '')}</span>
                    ${badges ? `<span>${esc(badges)}</span>` : ''}
                </span>
            </button>
        `;
    }

    async function renderThread() {
        const id = Number(param('id', 0));
        const rawPage = param('page', '1');
        const page = rawPage === 'latest' ? 500 : Number(rawPage || 1);
        const data = await api(`threads/${id}?page=${page}`);
        const currentPage = Number(data.pagination?.currentPage || page || 1);
        const totalPages = Number(data.pagination?.totalPages || 1);
        const canReply = !!data.permissions?.canReply;
        const canModerate = !!data.permissions?.canModerate;
        app.innerHTML = `
            <div class="thread-toolbar">
                <button class="button board-return-button" data-go="board" data-id="${Number(data.boardId || 0)}"><span aria-hidden="true">&lsaquo;</span> ${esc(data.boardName || 'Board')}</button>
                ${canReply ? `<button class="button primary" data-go="post-reply" data-id="${id}" data-board-id="${Number(data.boardId || 0)}">Reply</button>` : ''}
                <button class="button" data-go="thread" data-id="${id}" data-page="latest" data-jump="bottom">Latest</button>
                ${data.poll?.exists ? `<button class="button" data-action="jump" data-target="poll">Poll</button>` : ''}
                ${canModerate ? modTopicButtons(data) : ''}
            </div>
            <h1 class="section-title">${esc(data.title)}</h1>
            ${pollHtml(data.poll)}
            ${(data.posts || []).map((post) => postHtml(post, data)).join('') || '<div class="notice">No posts found.</div>'}
            <div class="bottom-page-label">Page ${currentPage} of ${totalPages}</div>
            <div class="thread-bottom-controls">
                ${currentPage > 1 ? `<button class="button" data-go="thread" data-id="${id}" data-page="1" data-jump="top">First</button>` : ''}
                ${currentPage > 1 ? `<button class="button" data-go="thread" data-id="${id}" data-page="${currentPage - 1}">Prev</button>` : ''}
                ${currentPage < totalPages ? `<button class="button" data-go="thread" data-id="${id}" data-page="${currentPage + 1}">Next</button>` : ''}
                ${currentPage < totalPages ? `<button class="button" data-go="thread" data-id="${id}" data-page="${totalPages}" data-jump="bottom">Latest</button>` : ''}
                ${canReply ? `<button class="button primary" data-go="post-reply" data-id="${id}" data-board-id="${Number(data.boardId || 0)}">Reply</button>` : ''}
            </div>
        `;
        scrollTargetFromParams();
    }

    function modTopicButtons(thread) {
        const action = thread.isLocked ? 'unlock' : 'lock';
        const label = thread.isLocked ? 'Unlock' : 'Lock';
        return `<button class="button warn" data-mod-topic="${action}" data-id="${Number(thread.id || 0)}">${label}</button>`;
    }

    function pollHtml(poll) {
        if (!poll || !poll.exists) {
            return '';
        }
        // Keep option colors deterministic so mobile poll bars match the
        // classic poll/result visual rhythm without needing server-side styles.
        const colors = ['#0048ff', '#f01420', '#101010', '#14922e', '#f09a00', '#7a2fd1', '#00a2a8', '#e056b0', '#80633a', '#6c757d'];
        const canVote = !!poll.canVote;
        const options = (poll.options || []).map((option, idx) => `
            <label class="poll-option ${option.isUserVote ? 'selected' : ''}">
                <span class="poll-choice">
                    ${canVote ? `<input type="radio" name="optionId" value="${Number(option.id || 0)}" required>` : ''}
                    <b>${esc(option.text)}</b> with ${Number(option.votes || 0)} vote${Number(option.votes || 0) === 1 ? '' : 's'}
                    ${option.isUserVote ? '<span class="subtle">Your vote</span>' : ''}
                </span>
                <span class="poll-bar"><span class="poll-fill" style="width:${Math.max(0, Math.min(100, Number(option.percent || 0)))}%;background:${colors[idx % colors.length]}"></span></span>
                <span class="poll-percent">${Math.round(Number(option.percent || 0))}%</span>
            </label>
        `).join('');
        let status = '';
        if (poll.archiveReadOnly) {
            status = 'Secure Archive poll voting is read-only.';
        } else if (poll.hasVoted) {
            status = 'You have already voted in this poll.';
        } else if (poll.isClosed) {
            status = 'This poll is closed.';
        } else if (!viewer?.authenticated) {
            status = 'Log in to vote.';
        }
        return `
            <section class="poll-box" id="poll">
                <form data-form="poll-vote" data-topic-id="${Number(poll.topicId || 0)}">
                    <b>${esc(poll.question)}</b>
                    ${options}
                    <div class="poll-footer">
                        <span>Total Votes: ${Number(poll.totalVotes || 0)} (Days Left: ${Number(poll.daysLeft || 0)})</span>
                        ${canVote ? '<button class="button primary" type="submit">Vote</button>' : ''}
                        ${!canVote && status ? `<span class="subtle">${esc(status)}</span>` : ''}
                    </div>
                </form>
            </section>
        `;
    }

    function postHtml(post, thread) {
        const postId = Number(post.id || 0);
        const threadId = Number(thread.id || post.topicId || 0);
        const boardId = Number(thread.boardId || post.boardId || 0);
        const currentPage = Number(thread.pagination?.currentPage || param('page', 1) || 1);
        const authorName = esc(post.author?.username || 'Unknown');
        const authorId = Number(post.author?.id || 0);
        return `
            <article class="post" id="post-${postId}">
                <div class="post-head">
                    ${authorId > 0 ? `<button class="post-author" data-go="profile" data-id="${authorId}">${authorName}</button>` : authorName}
                    <span class="subtle">${esc(post.postedAt?.display || '')}</span>
                </div>
                <div class="post-body">${post.body?.html || ''}</div>
                <div class="post-action-rail">
                    <div class="post-menu" id="post-menu-${postId}" data-open="false" aria-hidden="true">
                        ${thread.permissions?.canReply ? `<button class="button" data-go="post-reply" data-id="${threadId}" data-board-id="${boardId}" data-quote-id="${postId}">Quote</button>` : ''}
                        ${post.permissions?.canEdit ? `<button class="button" data-go="post-edit" data-id="${postId}" data-return-page="${currentPage}">Edit</button>` : ''}
                        ${thread.permissions?.canModerate ? `<button class="button warn" data-mod-post="remove" data-id="${postId}" data-board-id="${boardId}">Remove</button>` : ''}
                    </div>
                    <button class="button post-options-button" data-action="toggle-post-menu" data-target="post-menu-${postId}" aria-expanded="false">...</button>
                </div>
            </article>
        `;
    }

    async function renderProfile() {
        const id = Number(param('id', 0));
        const data = await api(`profiles/${id}`);
        const fields = (data.fields || []).filter((field) => field.value).map((field) => `
            <div class="item"><b>${esc(field.label)}</b><br>${esc(field.value)}</div>
        `).join('');
        app.innerHTML = `
            <h1 class="section-title">${esc(data.username)}</h1>
            <div class="notice">${esc(data.level || '')} &middot; ${esc(data.postCount || '0')} posts${data.isBanned ? ' &middot; Banned' : ''}</div>
            ${data.permissions?.canModerate && Number(viewer?.user?.id || 0) !== Number(data.id || 0) ? `
                <div class="toolbar">
                    <button class="button warn" data-mod-user="${data.isBanned ? 'unban' : 'ban'}" data-id="${Number(data.id || 0)}">${data.isBanned ? 'Unban User' : 'Ban User'}</button>
                </div>
            ` : ''}
            ${fields}
            ${data.bio?.html ? `<h2 class="section-title">Bio</h2><div class="item">${data.bio.html}</div>` : ''}
        `;
    }

    function renderLogin(message) {
        app.innerHTML = `
            <h1 class="section-title">Login</h1>
            ${message ? `<div class="notice">${esc(message)}</div>` : ''}
            <form data-form="login">
                <div class="form-row"><label class="label">Username</label><input name="username" autocomplete="username" required></div>
                <div class="form-row"><label class="label">Password</label><input name="password" type="password" autocomplete="current-password" required></div>
                <div class="toolbar"><button class="button primary" type="submit">Login</button></div>
            </form>
        `;
    }

    function renderRegister() {
        app.innerHTML = `
            <h1 class="section-title">Register</h1>
            <form data-form="register">
                <div class="form-row"><label class="label">Username</label><input name="username" autocomplete="username" required></div>
                <div class="form-row"><label class="label">Email</label><input name="email" type="email" autocomplete="email" required></div>
                <div class="form-row"><label class="label">Password</label><input name="password" type="password" autocomplete="new-password" required></div>
                <div class="form-row"><label class="label">Confirm Password</label><input name="passwordConfirm" type="password" autocomplete="new-password" required></div>
                <div class="form-row"><label><input name="agreeTos" type="checkbox" required> I agree to the Terms Of Service</label></div>
                <div class="form-row"><label><input name="confirmAge13" type="checkbox" required> I am at least 13 years old</label></div>
                <div class="toolbar"><button class="button primary" type="submit">Register</button></div>
            </form>
        `;
    }

    async function renderPm() {
        const folder = param('folder', 'inbox');
        const data = await api(`pm/${folder}`);
        app.innerHTML = `
            <h1 class="section-title">${esc(data.title || 'Private Messages')}</h1>
            <div class="toolbar">
                <button class="button" data-go="pm" data-folder="inbox">Inbox (${Number(data.counts?.unread || 0)})</button>
                <button class="button" data-go="pm" data-folder="read">Read (${Number(data.counts?.read || 0)})</button>
                <button class="button" data-go="pm" data-folder="sent">Sent (${Number(data.counts?.sent || 0)})</button>
                <button class="button primary" data-go="pm-send">Send</button>
            </div>
            ${(data.messages || []).map((message) => `
                <button type="button" class="item" data-go="pm-message" data-id="${Number(message.id || 0)}" data-folder="${esc(message.method || folder)}">
                    <span class="item-title">${esc(message.title)}</span>
                    <span class="meta"><span>${esc(message.otherUser?.username || '')}</span><span>${esc(message.sentAt?.display || '')}</span></span>
                </button>
            `).join('') || '<div class="notice">No messages found.</div>'}
        `;
    }

    async function renderPmMessage() {
        const id = Number(param('id', 0));
        const folder = param('folder', 'read');
        const data = await api(`pm/messages/${id}?folder=${encodeURIComponent(folder)}`);
        app.innerHTML = `
            <h1 class="section-title">${esc(data.title)}</h1>
            <div class="notice">${esc(data.otherUser?.label || '')}: ${esc(data.otherUser?.username || '')}<br>${esc(data.sentAt?.display || '')}</div>
            <div class="item pm-body">${data.body?.html || ''}</div>
            <div class="toolbar">
                ${data.permissions?.canReply ? `<button class="button primary" data-go="pm-send" data-to="${esc(data.otherUser?.username || '')}" data-subject="${esc(data.title || '')}">Reply</button>` : ''}
            </div>
        `;
    }

    function renderPmSend() {
        const subject = param('subject', '');
        app.innerHTML = `
            <h1 class="section-title">Send Message</h1>
            <form data-form="pm-send">
                <div class="form-row"><label class="label">To</label><input name="to" value="${esc(param('to', ''))}" required></div>
                <div class="form-row"><label class="label">Subject</label><input name="subject" value="${esc(subject && !/^re:/i.test(subject) ? 'RE: ' + subject : subject)}" required></div>
                <div class="form-row"><label class="label">Message</label><textarea name="body" required></textarea></div>
                <div class="toolbar"><button class="button primary" type="submit">Send</button></div>
            </form>
        `;
    }

    async function renderPostForm(mode) {
        const id = Number(param('id', 0));
        const boardId = Number(param('boardId', 0));
        const quoteId = Number(param('quoteId', 0));
        const returnPage = param('returnPage', '');
        let path = '';
        if (mode === 'post-new') {
            path = `post/new/${id || boardId}`;
        } else if (mode === 'post-edit') {
            path = `post/edit/${id}`;
        } else {
            const query = new URLSearchParams();
            if (boardId) {
                query.set('board_id', boardId);
            }
            if (quoteId) {
                query.set('quote_id', quoteId);
            }
            path = `post/reply/${id}${query.toString() ? '?' + query.toString() : ''}`;
        }
        const data = await api(path);
        app.innerHTML = `
            <h1 class="section-title">${esc(data.submitLabel || 'Post')}</h1>
            <form data-form="${esc(mode)}" data-board-id="${Number(data.context?.boardId || boardId || id)}" data-thread-id="${Number(data.context?.threadId || id)}" data-post-id="${id}" data-return-page="${esc(returnPage)}">
                <div class="form-row"><label class="label">Subject</label><input name="subject" value="${esc(data.subject || '')}"></div>
                <div class="form-row"><label class="label">Message</label><textarea name="body" required>${esc(data.body || '')}</textarea></div>
                <div class="form-actions"><button class="button primary" type="submit">${esc(data.submitLabel || 'Post')}</button></div>
            </form>
        `;
    }

    async function renderLogout() {
        await postJson('auth/logout', {});
        viewer = { authenticated: false, user: null };
        go('index');
    }

    async function render() {
        setLoading();
        await loadMe();
        const screen = currentScreen();
        renderNav(screen);
        try {
            if (screen === 'index') return await renderIndex();
            if (screen === 'board') return await renderBoard();
            if (screen === 'thread') return await renderThread();
            if (screen === 'profile') return await renderProfile();
            if (screen === 'login') return renderLogin();
            if (screen === 'register') return renderRegister();
            if (screen === 'logout') return await renderLogout();
            if (screen === 'pm') return await renderPm();
            if (screen === 'pm-message') return await renderPmMessage();
            if (screen === 'pm-send') return renderPmSend();
            if (screen === 'post-new' || screen === 'post-reply' || screen === 'post-edit') return await renderPostForm(screen);
            return await renderIndex();
        } catch (e) {
            if (e.status === 401) {
                sessionStorage.setItem('corebbMobileAfterLogin', currentQueryString());
                return renderLogin('Log in to continue.');
            }
            errorBox(e.message);
        }
    }

    nav.addEventListener('click', (event) => {
        const button = event.target.closest('[data-go]');
        if (!button) return;
        const next = {};
        if (button.dataset.id) next.id = button.dataset.id;
        if (button.dataset.page) next.page = button.dataset.page;
        if (button.dataset.jump) next.jump = button.dataset.jump;
        if (button.dataset.folder) next.folder = button.dataset.folder;
        if (button.dataset.to) next.to = button.dataset.to;
        if (button.dataset.subject) next.subject = button.dataset.subject;
        if (button.dataset.quoteId) next.quoteId = button.dataset.quoteId;
        if (button.dataset.returnPage) next.returnPage = button.dataset.returnPage;
        if (button.dataset.params) {
            Object.assign(next, JSON.parse(button.dataset.params));
        }
        go(button.dataset.go, next);
    });

    sessionBar?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-go]');
        if (!button) return;
        go(button.dataset.go, { id: button.dataset.id || '' });
    });

    app.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-go], [data-action], [data-mod-topic], [data-mod-post], [data-mod-user]');
        if (!button) return;
        if (button.dataset.action === 'rerender') {
            render();
            return;
        }
        if (button.dataset.action === 'back') {
            history.back();
            return;
        }
        if (button.dataset.action === 'jump') {
            const target = button.dataset.target || 'top';
            if (target === 'top') {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else if (target === 'bottom') {
                window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
            } else {
                document.getElementById(target)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            return;
        }
        if (button.dataset.action === 'toggle-post-menu') {
            // The post option rail is absolutely positioned so hidden actions do
            // not reserve vertical space or make posts jump when expanded.
            const menu = document.getElementById(button.dataset.target || '');
            if (!menu) {
                return;
            }
            const isClosed = menu.dataset.open !== 'true';
            document.querySelectorAll('.post-menu').forEach((openMenu) => {
                if (openMenu !== menu) {
                    openMenu.dataset.open = 'false';
                    openMenu.setAttribute('aria-hidden', 'true');
                }
            });
            document.querySelectorAll('.post-options-button[aria-expanded="true"]').forEach((openButton) => {
                if (openButton !== button) {
                    openButton.setAttribute('aria-expanded', 'false');
                }
            });
            if (isClosed) {
                menu.dataset.open = 'true';
                menu.setAttribute('aria-hidden', 'false');
                button.setAttribute('aria-expanded', 'true');
            } else {
                menu.dataset.open = 'false';
                menu.setAttribute('aria-hidden', 'true');
                button.setAttribute('aria-expanded', 'false');
            }
            return;
        }
        if (button.dataset.modTopic) {
            try {
                await postJson(`mod/topics/${button.dataset.id}/${button.dataset.modTopic}`, {});
                await render();
            } catch (e) {
                errorBox(e.message);
            }
            return;
        }
        if (button.dataset.modPost) {
            if (!window.confirm('Remove this post?')) {
                return;
            }
            try {
                const result = await postJson(`mod/posts/${button.dataset.id}/remove`, {});
                resultScreen(result.message || 'Post removed.', [
                    '<button class="button primary" data-action="rerender">Refresh Thread</button>',
                    button.dataset.boardId ? `<button class="button" data-go="board" data-id="${esc(button.dataset.boardId)}">Back to Board</button>` : ''
                ].filter(Boolean));
            } catch (e) {
                errorBox(e.message);
            }
            return;
        }
        if (button.dataset.modUser) {
            const label = button.dataset.modUser === 'ban' ? 'Ban this user?' : 'Unban this user?';
            if (!window.confirm(label)) {
                return;
            }
            try {
                const result = await postJson(`mod/users/${button.dataset.id}/${button.dataset.modUser}`, {});
                resultScreen(result.message || 'User updated.', [
                    `<button class="button" data-go="profile" data-id="${esc(button.dataset.id)}">View Profile</button>`
                ]);
            } catch (e) {
                errorBox(e.message);
            }
            return;
        }
        const next = {};
        if (button.dataset.id) next.id = button.dataset.id;
        if (button.dataset.page) next.page = button.dataset.page;
        if (button.dataset.jump) next.jump = button.dataset.jump;
        if (button.dataset.boardId) next.boardId = button.dataset.boardId;
        if (button.dataset.folder) next.folder = button.dataset.folder;
        if (button.dataset.to) next.to = button.dataset.to;
        if (button.dataset.subject) next.subject = button.dataset.subject;
        if (button.dataset.quoteId) next.quoteId = button.dataset.quoteId;
        if (button.dataset.returnPage) next.returnPage = button.dataset.returnPage;
        if (button.dataset.params) {
            Object.assign(next, JSON.parse(button.dataset.params));
        }
        go(button.dataset.go, next);
    });

    app.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-form]');
        if (!form) return;
        event.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            if (form.dataset.form === 'login') {
                await postJson('auth/login', data);
                csrf = '';
                const after = sessionStorage.getItem('corebbMobileAfterLogin');
                sessionStorage.removeItem('corebbMobileAfterLogin');
                if (after) {
                    return replaceWithQuery(after);
                }
                return go('index');
            }
            if (form.dataset.form === 'register') {
                const result = await postJson('auth/register', Object.assign(data, {
                    agreeTos: data.agreeTos === 'on',
                    confirmAge13: data.confirmAge13 === 'on'
                }));
                app.innerHTML = `<div class="notice">${esc(result.message || 'Account created.')}</div>`;
                return;
            }
            if (form.dataset.form === 'pm-send') {
                const result = await postJson('pm/send', data);
                resultScreen(result.message || 'Message sent.', [
                    '<button class="button primary" data-go="pm" data-folder="inbox">Inbox</button>',
                    '<button class="button" data-go="pm" data-folder="sent">Sent Items</button>'
                ]);
                return;
            }
            if (form.dataset.form === 'poll-vote') {
                const result = await postJson(`polls/${Number(form.dataset.topicId || 0)}/vote`, {
                    optionId: Number(data.optionId || 0)
                });
                resultScreen(result.message || 'Your vote has been recorded.', [
                    `<button class="button primary" data-go="thread" data-id="${Number(form.dataset.topicId || 0)}">View Updated Poll</button>`
                ]);
                return;
            }
            if (form.dataset.form === 'post-new') {
                // Topic/reply/edit submissions all return desktop-style links;
                // extract the thread id so the mobile confirmation can stay in
                // the mobile flow.
                const result = await postJson('post/new', {
                    boardId: Number(form.dataset.boardId || 0),
                    subject: data.subject || '',
                    body: data.body || ''
                });
                const threadId = threadIdFromResult(result);
                resultScreen(result.message || 'Topic posted.', [
                    threadId ? `<button class="button primary" data-go="thread" data-id="${threadId}" data-page="latest" data-jump="bottom">View Topic</button>` : '',
                    `<button class="button" data-go="board" data-id="${Number(form.dataset.boardId || 0)}">Back to Board</button>`
                ].filter(Boolean));
                return;
            }
            if (form.dataset.form === 'post-edit') {
                const result = await postJson('post/edit', {
                    postId: Number(form.dataset.postId || 0),
                    subject: data.subject || '',
                    body: data.body || ''
                });
                const threadId = threadIdFromResult(result);
                const returnPage = Number(form.dataset.returnPage || 0);
                resultScreen(result.message || 'Post updated.', [
                    threadId ? `<button class="button primary" data-go="thread" data-id="${threadId}" ${returnPage > 0 ? `data-page="${returnPage}"` : ''} data-jump="post-${Number(form.dataset.postId || 0)}">View Topic</button>` : '',
                    '<button class="button" data-action="back">Back</button>'
                ].filter(Boolean));
                return;
            }
            const result = await postJson('post/reply', {
                threadId: Number(form.dataset.threadId || 0),
                boardId: Number(form.dataset.boardId || 0),
                subject: data.subject || '',
                body: data.body || ''
            });
            const threadId = threadIdFromResult(result) || Number(form.dataset.threadId || 0);
            resultScreen(result.message || 'Reply posted.', [
                `<button class="button primary" data-go="thread" data-id="${threadId}" data-page="latest" data-jump="bottom">View Topic</button>`,
                `<button class="button" data-go="post-reply" data-id="${threadId}" data-board-id="${Number(form.dataset.boardId || 0)}">Reply Again</button>`
            ]);
        } catch (e) {
            errorBox(e.message);
        }
    });

    document.querySelector('[data-action="back"]').addEventListener('click', () => {
        if (history.length > 1) {
            history.back();
        } else {
            go('index');
        }
    });

    window.addEventListener('popstate', render);
    render();
}());

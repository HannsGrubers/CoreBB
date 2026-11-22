(() => {
    'use strict';
    const sidebar = document.querySelector('.corebb-admin-sidebar');
    if (!sidebar) return;
    const tools = sidebar.querySelector('.admin-tools');
    const search = sidebar.querySelector('#admin-tool-search');
    const groups = sidebar.querySelectorAll('.corebb-admin-nav-group');
    const empty = sidebar.querySelector('.admin-tools-empty');
    const narrow = window.matchMedia('(max-width: 760px)');
    function fitTools() { tools.open = !narrow.matches; }
    function filterTools() {
        const term = search.value.trim().toLowerCase();
        let visible = 0;
        for (const group of groups) {
            let matches = 0;
            for (const link of group.querySelectorAll('.corebb-admin-nav-link')) {
                link.hidden = !link.querySelector('span').textContent.toLowerCase().includes(term);
                if (!link.hidden) matches++;
            }
            group.hidden = matches === 0;
            visible += matches;
        }
        empty.hidden = visible > 0;
    }
    fitTools();
    narrow.addEventListener('change', fitTools);
    sidebar.querySelector('.admin-tool-search').hidden = false;
    search.addEventListener('input', filterTools);
    filterTools();

    const role = document.getElementById('admin-activity-role');
    if (role) {
        const feed = role.closest('.admin-activity');
        const rows = feed.querySelectorAll('[data-activity-level]');
        const filterActivity = () => {
            let visible = 0;
            for (const row of rows) {
                row.hidden = role.value !== '' && row.dataset.activityLevel !== role.value;
                if (!row.hidden) visible++;
            }
            feed.querySelector('.admin-activity-list').hidden = visible === 0;
            feed.querySelector('.admin-activity-empty').hidden = visible > 0;
        };
        role.closest('label').hidden = false;
        role.addEventListener('change', filterActivity);
        filterActivity();
    }
})();

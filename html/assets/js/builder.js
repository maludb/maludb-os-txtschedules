/* The week builder's drag (screen `builder`): SortableJS moves a shift block between cells; the drop POSTS THE SAME ACTION THE BUTTONS POST
   (shift_update — the day, and the person when it changed; shift_assign — the person only) with the session's CSRF token, answered as JSON.
   A refused drop springs the block back and says the sentence in #flash. Without JavaScript the grid is read-only and "Move to…" does the same. */
(function () {
    'use strict';
    var waiting = null;
    function loadSortable(then) {
        if (window.Sortable) { then(); return; }
        if (waiting) { waiting.push(then); return; }
        waiting = [then];
        var s = document.createElement('script');
        s.src = '/assets/vendors/sortablejs/Sortable.min.js';
        s.onload = function () { var q = waiting; waiting = null; q.forEach(function (f) { f(); }); };
        document.head.appendChild(s);
    }
    function csrf() { var m = document.getElementById('csrf-token-meta'); return m ? m.content : ''; }
    function say(text, kind) {
        var f = document.getElementById('flash');
        if (!f) { return; }
        f.innerHTML = '<div class="alert alert-' + kind + ' m-3" role="alert" id="builder-drop-message"></div>';
        f.firstChild.textContent = text;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function post(path, data) {
        return fetch(path, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf(), 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }, function () { return { ok: false, body: {} }; }); });
    }
    function refresh() {
        if (window.htmx) { htmx.ajax('GET', location.pathname + location.search, { target: '#page-content', swap: 'innerHTML' }); }
        else { location.reload(); }
    }
    function onEnd(evt) {
        var to = evt.to, from = evt.from, item = evt.item;
        if (to === from) { return; }
        var dayChanged = to.dataset.day !== from.dataset.day;
        var personChanged = to.dataset.member !== from.dataset.member;
        var path, data = { shift: item.dataset.shift };
        if (dayChanged) {
            path = '/shifts/save.php'; data.date = to.dataset.day;
            if (personChanged) { data.assignee = to.dataset.member; }
        } else if (personChanged) {
            path = '/shifts/assign.php'; data.assignee = to.dataset.member;
        } else { return; }
        item.classList.add('is-saving');
        post(path, data).then(function (res) {
            item.classList.remove('is-saving');
            if (res.ok) { refresh(); return; }
            from.insertBefore(item, from.children[evt.oldIndex] || null);
            var m = res.body && res.body.error && res.body.error.message;
            say(m || 'That could not be saved.', 'danger');
        }, function () {
            item.classList.remove('is-saving');
            from.insertBefore(item, from.children[evt.oldIndex] || null);
            say('That could not be saved. Try again.', 'danger');
        });
    }
    function init() {
        var grid = document.querySelector('#builder-grid[data-sortable]');
        if (!grid || grid.dataset.ready) { return; }
        grid.dataset.ready = '1';
        loadSortable(function () {
            grid.querySelectorAll('.grid-cell').forEach(function (cell) {
                new Sortable(cell, {
                    group: cell.dataset.group, draggable: '.shift-block[data-draggable]', animation: 120,
                    delay: 200, delayOnTouchOnly: true, ghostClass: 'sortable-ghost', filter: '.grid-add', preventOnFilter: false, onEnd: onEnd
                });
            });
        });
    }
    document.addEventListener('DOMContentLoaded', init);
    document.body && document.body.addEventListener('htmx:afterSwap', init);
    init();
})();

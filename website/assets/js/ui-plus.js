/* PDS UI v2 -- loaded after app.js. Upgrades the shared message windows
   (toasts, confirm dialog, flash alerts, modals) and adds a command palette
   (Ctrl/Cmd+K), a theme + accent menu, scroll progress and back-to-top. */
(function () {
    'use strict';
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var store = {
        get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
    };
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }

    /* ------------------------------------------------------ theme */
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;
    function applyTheme() {
        var mode = store.get('theme') || 'dark';
        var resolved = mode === 'auto' ? (media && media.matches ? 'light' : 'dark') : mode;
        document.documentElement.setAttribute('data-theme', resolved);
        var accent = store.get('accent') || 'violet';
        if (accent === 'violet') document.documentElement.removeAttribute('data-accent');
        else document.documentElement.setAttribute('data-accent', accent);
    }
    applyTheme();
    if (media && media.addEventListener) media.addEventListener('change', function () { if (store.get('theme') === 'auto') applyTheme(); });
    function toggleTheme() {
        var cur = document.documentElement.getAttribute('data-theme');
        store.set('theme', cur === 'light' ? 'dark' : 'light');
        applyTheme();
    }

    // app.js defines its own showToast/showConfirm/openModal inside a
    // DOMContentLoaded handler, which runs after this file loads -- so ours
    // are installed from boot() (registered later, so it runs after app.js's).
    function installOverrides() {
    window.toggleTheme = toggleTheme;

    /* ----------------------------------------------------- toasts */
    var TITLES = { success: 'Done', error: 'Something went wrong', warning: 'Heads up', info: 'Note' };
    var ICONS = { success: 'fas fa-check', error: 'fas fa-exclamation', warning: 'fas fa-exclamation-triangle', info: 'fas fa-info' };
    window.showToast = function (message, type, title) {
        type = type || 'success';
        var box = document.getElementById('toastContainer');
        if (!box) { box = document.createElement('div'); box.id = 'toastContainer'; box.className = 'toast-container'; document.body.appendChild(box); }
        while (box.children.length >= 4) box.firstElementChild.remove();
        var ms = type === 'error' ? 8000 : 4500;
        var el = document.createElement('div');
        el.className = 'toast toast-' + type;
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.innerHTML = '<i class="toast-icon ' + (ICONS[type] || ICONS.info) + '"></i>'
            + '<div class="toast-body"><span class="toast-title">' + esc(title || TITLES[type] || TITLES.info) + '</span>'
            + '<span class="toast-message">' + message + '</span></div>'
            + '<button class="toast-close" aria-label="Dismiss">&times;</button>'
            + '<span class="toast-progress"></span>';
        box.appendChild(el);
        var bar = el.querySelector('.toast-progress');
        var anim = bar.animate ? bar.animate([{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }], { duration: ms, easing: 'linear', fill: 'forwards' }) : null;
        var close = function () { el.style.opacity = '0'; el.style.transform = 'translateY(10px) scale(.97)'; setTimeout(function () { el.remove(); }, 280); };
        var timer = setTimeout(close, ms), left = ms, started = Date.now();
        el.addEventListener('mouseenter', function () { clearTimeout(timer); left -= Date.now() - started; if (anim) anim.pause(); });
        el.addEventListener('mouseleave', function () { started = Date.now(); timer = setTimeout(close, Math.max(800, left)); if (anim) anim.play(); });
        el.querySelector('.toast-close').onclick = close;
    };

    /* --------------------------------------------- confirm dialog */
    window.showConfirm = function (title, message, onConfirm, opts) {
        opts = opts || {};
        var type = opts.type || 'danger';
        var icon = opts.icon || (type === 'danger' ? 'fa-trash-alt' : type === 'warning' ? 'fa-exclamation-triangle' : 'fa-question');
        var old = document.getElementById('confirmModal'); if (old) old.remove();
        var overlay = document.getElementById('modalOverlay');
        var modal = document.createElement('div');
        modal.className = 'modal active';
        modal.id = 'confirmModal';
        modal.setAttribute('role', 'alertdialog');
        modal.innerHTML = '<div class="modal-body"><div class="confirm-dialog">'
            + '<div class="confirm-icon ' + type + '"><i class="fas ' + icon + '"></i></div>'
            + '<h3>' + title + '</h3><p>' + message + '</p>'
            + '<div class="confirm-actions"><button class="btn btn-secondary" data-cancel>' + esc(opts.cancelText || 'Cancel') + '</button>'
            + '<button class="btn ' + (type === 'danger' ? 'btn-danger' : 'btn-primary') + '" data-ok>' + esc(opts.confirmText || (type === 'danger' ? 'Delete' : 'Confirm')) + '</button></div>'
            + '</div></div>';
        document.body.appendChild(modal);
        if (overlay) overlay.classList.add('active');
        var ok = modal.querySelector('[data-ok]');
        setTimeout(function () { ok.focus(); }, 30);
        modal.querySelector('[data-cancel]').onclick = window.closeConfirm;
        ok.onclick = function () { window.closeConfirm(); if (typeof onConfirm === 'function') onConfirm(); };
        modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') window.closeConfirm(); });
    };
    window.closeConfirm = function () {
        var m = document.getElementById('confirmModal'); if (m) m.remove();
        var overlay = document.getElementById('modalOverlay');
        if (overlay && !$('.modal.active')) overlay.classList.remove('active');
        document.body.style.overflow = '';
    };

    /* --------------------------------- modal: focus the first field */
    var origOpen = window.openModal;
    if (typeof origOpen === 'function') {
        window.openModal = function (id) {
            origOpen.apply(this, arguments);
            var m = document.getElementById(id);
            if (!m) return;
            setTimeout(function () {
                var f = m.querySelector('input:not([type=hidden]):not([disabled]), select, textarea, button.btn-primary');
                if (f && window.innerWidth > 768) f.focus();
            }, 120);
        };
    }
    } // installOverrides

    /* -------------------------------------------------- flash alerts */
    function armAlerts() {
        $$('.content-area > .alert').forEach(function (a) {
            if (a.dataset.armed) return;
            a.dataset.armed = '1';
            if (a.classList.contains('alert-error') || a.classList.contains('alert-danger')) return; // errors stay
            var ms = 7000;
            var bar = document.createElement('span');
            bar.className = 'alert-progress';
            bar.style.animationDuration = ms + 'ms';
            a.appendChild(bar);
            var t = setTimeout(function () { a.style.transition = 'opacity .3s, transform .3s'; a.style.opacity = '0'; a.style.transform = 'translateY(-6px)'; setTimeout(function () { a.remove(); }, 320); }, ms);
            a.addEventListener('mouseenter', function () { clearTimeout(t); bar.style.animationPlayState = 'paused'; });
        });
    }

    /* -------------------------------------------------- top buttons */
    var ACCENTS = [['violet', '#7b5cff', '#c056f5'], ['ocean', '#2563eb', '#06b6d4'], ['emerald', '#059669', '#22c55e'],
                   ['sunset', '#f97316', '#ef4444'], ['rose', '#ec4899', '#8b5cf6'], ['amber', '#f59e0b', '#f43f5e']];
    function closePops() { $$('.ui-pop').forEach(function (p) { p.remove(); }); }
    function themeMenu(anchor) {
        if ($('.ui-pop')) { closePops(); return; }
        var r = anchor.getBoundingClientRect();
        var pop = document.createElement('div');
        pop.className = 'ui-pop';
        pop.style.top = (r.bottom + 8) + 'px';
        pop.style.right = Math.max(12, window.innerWidth - r.right) + 'px';
        var mode = store.get('theme') || 'dark', accent = store.get('accent') || 'violet';
        pop.innerHTML = '<h5>Theme</h5><div class="ui-seg">'
            + [['light', 'fa-sun', 'Light'], ['dark', 'fa-moon', 'Dark'], ['auto', 'fa-circle-half-stroke', 'Auto']].map(function (m) {
                return '<button data-mode="' + m[0] + '" class="' + (mode === m[0] ? 'on' : '') + '"><i class="fas ' + m[1] + '"></i> ' + m[2] + '</button>';
            }).join('') + '</div><h5>Accent colour</h5><div class="ui-swatches">'
            + ACCENTS.map(function (a) {
                return '<button class="ui-swatch' + (accent === a[0] ? ' on' : '') + '" title="' + a[0] + '" data-accent="' + a[0] + '" style="background:linear-gradient(135deg,' + a[1] + ',' + a[2] + ')">' + (accent === a[0] ? '<i class="fas fa-check"></i>' : '') + '</button>';
            }).join('') + '</div>';
        document.body.appendChild(pop);
        pop.addEventListener('click', function (e) {
            var m = e.target.closest('[data-mode]'), a = e.target.closest('[data-accent]');
            if (m) { store.set('theme', m.dataset.mode); applyTheme(); closePops(); themeMenu(anchor); }
            if (a) { store.set('accent', a.dataset.accent); applyTheme(); closePops(); themeMenu(anchor); }
            e.stopPropagation();
        });
    }
    function addTopButtons() {
        var actions = $('.topbar-actions');
        if (!actions || $('#uiPaletteBtn')) return;
        var mac = /Mac|iPhone|iPad/.test(navigator.platform);
        var pal = document.createElement('button');
        pal.id = 'uiPaletteBtn'; pal.className = 'ui-top-btn'; pal.type = 'button'; pal.title = 'Quick actions';
        pal.innerHTML = '<i class="fas fa-bolt"></i><span class="lbl">Go to</span><kbd>' + (mac ? '⌘K' : 'Ctrl K') + '</kbd>';
        pal.onclick = openPalette;
        var th = document.createElement('button');
        th.id = 'uiThemeBtn'; th.className = 'ui-top-btn'; th.type = 'button'; th.title = 'Theme & colours';
        th.innerHTML = '<i class="fas fa-palette"></i>';
        th.onclick = function (e) { e.stopPropagation(); themeMenu(th); };
        var bell = $('#notificationBell', actions);
        actions.insertBefore(pal, bell || null);
        actions.insertBefore(th, bell || null);
        document.addEventListener('click', function (e) { if (!e.target.closest('.ui-pop')) closePops(); });
    }

    /* ---------------------------------------------- command palette */
    function paletteItems() {
        var items = [];
        $$('.sidebar-nav .nav-link').forEach(function (a) {
            var label = (a.textContent || '').trim();
            var ic = a.querySelector('i');
            if (label) items.push({ group: 'Pages', label: label, icon: ic ? ic.className : 'fas fa-file', run: function () { location.href = a.href; } });
        });
        var base = (window.BASE_PATH || '') + '/';
        [['Notifications · Send test message', 'fas fa-paper-plane', 'notifications.php?tab=send'],
         ['Notifications · History', 'fas fa-clock-rotate-left', 'notifications.php?tab=history'],
         ['Notifications · Templates', 'fas fa-scroll', 'notifications.php?tab=templates'],
         ['Add a custom field', 'fas fa-plus', 'custom-fields.php'],
         ['Savings dashboard', 'fas fa-chart-pie', 'savings-dashboard.php']].forEach(function (x) {
            items.push({ group: 'Shortcuts', label: x[0], icon: x[1], run: function () { location.href = base + x[2]; } });
        });
        items.push({ group: 'Actions', label: 'Switch light / dark theme', icon: 'fas fa-circle-half-stroke', run: toggleTheme });
        items.push({ group: 'Actions', label: 'Theme & accent colour…', icon: 'fas fa-palette', run: function () { var b = $('#uiThemeBtn'); if (b) setTimeout(function () { themeMenu(b); }, 50); } });
        items.push({ group: 'Actions', label: 'Search records', icon: 'fas fa-search', hint: '/', run: function () { var s = $('#globalSearch'); if (s) s.focus(); } });
        items.push({ group: 'Actions', label: 'Back to top', icon: 'fas fa-arrow-up', run: function () { window.scrollTo({ top: 0, behavior: 'smooth' }); } });
        var lo = $('.logout-btn');
        if (lo) items.push({ group: 'Actions', label: 'Log out', icon: 'fas fa-sign-out-alt', run: function () { location.href = lo.href; } });
        return items;
    }
    function score(label, q) {
        label = label.toLowerCase();
        if (!q) return 1;
        if (label.indexOf(q) === 0) return 3;
        if (label.indexOf(q) !== -1) return 2;
        var i = 0; for (var c = 0; c < label.length && i < q.length; c++) if (label[c] === q[i]) i++;
        return i === q.length ? 1 : 0;
    }
    function openPalette() {
        if ($('.ui-palette-overlay')) return;
        var all = paletteItems(), sel = 0, shown = [];
        var ov = document.createElement('div');
        ov.className = 'ui-palette-overlay';
        ov.innerHTML = '<div class="ui-palette" role="dialog" aria-label="Quick actions"><div class="ui-palette-input"><i class="fas fa-bolt"></i>'
            + '<input type="text" placeholder="Go to a page or run an action…" autocomplete="off"></div>'
            + '<div class="ui-palette-list"></div><div class="ui-palette-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> move</span><span><kbd>Enter</kbd> open</span><span><kbd>Esc</kbd> close</span></div></div>';
        document.body.appendChild(ov);
        var input = $('input', ov), list = $('.ui-palette-list', ov);
        function render() {
            var q = input.value.trim().toLowerCase();
            shown = all.map(function (it) { return { it: it, s: score(it.label, q) }; }).filter(function (x) { return x.s > 0; })
                .sort(function (a, b) { return b.s - a.s; }).map(function (x) { return x.it; });
            sel = Math.min(sel, Math.max(0, shown.length - 1));
            var html = '', group = null;
            shown.forEach(function (it, i) {
                if (it.group !== group) { group = it.group; html += '<div class="ui-palette-group">' + esc(group) + '</div>'; }
                html += '<div class="ui-palette-item' + (i === sel ? ' sel' : '') + '" data-i="' + i + '"><span class="p-ic"><i class="' + esc(it.icon) + '"></i></span>'
                    + esc(it.label) + (it.hint ? '<span class="p-hint">' + esc(it.hint) + '</span>' : '') + '</div>';
            });
            list.innerHTML = html || '<div class="ui-palette-group" style="text-transform:none;letter-spacing:0">No matches</div>';
            var s = $('.sel', list); if (s) s.scrollIntoView({ block: 'nearest' });
        }
        function run(i) { var it = shown[i]; close(); if (it) it.run(); }
        function close() { ov.remove(); }
        input.addEventListener('input', function () { sel = 0; render(); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { sel = Math.min(shown.length - 1, sel + 1); render(); e.preventDefault(); }
            else if (e.key === 'ArrowUp') { sel = Math.max(0, sel - 1); render(); e.preventDefault(); }
            else if (e.key === 'Enter') { run(sel); e.preventDefault(); }
            else if (e.key === 'Escape') close();
        });
        list.addEventListener('click', function (e) { var it = e.target.closest('[data-i]'); if (it) run(+it.dataset.i); });
        ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
        render();
        setTimeout(function () { input.focus(); }, 20);
    }
    window.openCommandPalette = openPalette;

    /* ------------------------------------ progress + back to top */
    function scrollUi() {
        var bar = document.createElement('div'); bar.className = 'ui-progress'; document.body.appendChild(bar);
        var top = document.createElement('button'); top.className = 'ui-totop'; top.type = 'button'; top.title = 'Back to top';
        top.innerHTML = '<i class="fas fa-arrow-up"></i>';
        top.onclick = function () { window.scrollTo({ top: 0, behavior: 'smooth' }); };
        document.body.appendChild(top);
        var onScroll = function () {
            var h = document.documentElement.scrollHeight - window.innerHeight;
            var y = window.scrollY || document.documentElement.scrollTop;
            bar.style.width = (h > 0 ? (y / h) * 100 : 0) + '%';
            top.classList.toggle('show', y > 500);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* --------------------------------------------------- keyboard */
    document.addEventListener('keydown', function (e) {
        var typing = /input|textarea|select/i.test((document.activeElement || {}).tagName || '') || (document.activeElement || {}).isContentEditable;
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openPalette(); return; }
        if (!typing && e.key === '/') { var s = $('#globalSearch'); if (s) { e.preventDefault(); s.focus(); } }
    });

    function boot() { installOverrides(); addTopButtons(); armAlerts(); scrollUi(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();

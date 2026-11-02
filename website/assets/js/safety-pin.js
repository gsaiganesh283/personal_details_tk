/**
 * Safety PIN prompt (see includes/safety_pin.php).
 *
 * <body data-safety-pin="locked|unlocked|off">. While "locked", any click on
 * an edit/delete link, or any form that edits/deletes, is held back and the
 * PIN is asked first; after a correct PIN the same click / submit is
 * repeated (so the page's own "Are you sure?" still appears). The server
 * enforces the same rule (spGuardRequest), so this is convenience, not the
 * only check.
 */
(function () {
    'use strict';

    var EXEMPT_PAGES = ['profile.php', 'login.php', 'logout.php', 'register.php', 'forgot-password.php',
        'reset-password.php', 'setup.php', 'setup-2fa.php', 'verify-2fa.php', 'setup-fingerprint.php',
        'oauth-login.php', 'oauth-callback.php', 'setup-google-drive.php', 'groww-settings.php'];
    var ACTION_KEYS = ['action', 'folder_action', 'record_action', 'map_action', 'fix_action'];
    var CHANGE_RE = /(delete|update|edit|rename|remove|toggle|move|reorder)/i;

    function state() { return document.body ? document.body.getAttribute('data-safety-pin') : 'off'; }
    function setState(value) { document.body.setAttribute('data-safety-pin', value); }
    function base() { return window.BASE_PATH || ''; }

    function pageOf(url) {
        try { return new URL(url, location.href).pathname.split('/').pop() || ''; } catch (e) { return ''; }
    }

    function isProtectedUrl(href) {
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return false;
        var url;
        try { url = new URL(href, location.href); } catch (e) { return false; }
        if (url.origin !== location.origin || EXEMPT_PAGES.indexOf(pageOf(url.href)) !== -1) return false;
        return ['edit', 'delete', 'edit_id'].some(function (key) {
            var v = url.searchParams.get(key);
            return v !== null && v !== '' && v !== '0';
        });
    }

    function isProtectedForm(form, submitter) {
        var action = form.getAttribute('action') || location.href;
        if (EXEMPT_PAGES.indexOf(pageOf(action)) !== -1) return false;
        var data;
        try { data = submitter ? new FormData(form, submitter) : new FormData(form); }
        catch (e) { data = new FormData(form); }
        var editId = data.get('edit_id');
        if (editId !== null && editId !== '' && editId !== '0') return true;
        if ((form.getAttribute('method') || 'get').toLowerCase() === 'get' && isProtectedUrl(action)) return true;
        return ACTION_KEYS.some(function (key) {
            var v = data.get(key);
            return typeof v === 'string' && CHANGE_RE.test(v);
        });
    }

    // ------------------------------------------------------------------ modal

    var modal = null;

    function buildModal() {
        var overlay = document.createElement('div');
        overlay.id = 'safetyPinModal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:100000;display:none;align-items:center;' +
            'justify-content:center;background:rgba(10,10,25,.6);backdrop-filter:blur(3px);padding:16px;';
        overlay.innerHTML =
            '<div style="width:100%;max-width:360px;background:var(--card-bg,#1f1f2e);color:var(--text-primary,#eee);' +
            'border-radius:16px;padding:26px 24px;box-shadow:0 20px 60px rgba(0,0,0,.45);text-align:center;' +
            'border:1px solid var(--border-color,rgba(255,255,255,.08));">' +
            '<div style="font-size:30px;color:#f59e0b;margin-bottom:6px;"><i class="fas fa-lock"></i></div>' +
            '<h3 style="margin:0 0 4px;font-size:18px;">Enter your Safety PIN</h3>' +
            '<p style="margin:0 0 14px;font-size:13px;opacity:.7;">Needed once to edit or delete. ' +
            'Stays unlocked until you log out.</p>' +
            '<input id="safetyPinInput" type="password" inputmode="numeric" autocomplete="off" maxlength="8" ' +
            'style="width:100%;box-sizing:border-box;text-align:center;font-size:22px;letter-spacing:8px;padding:10px;' +
            'border-radius:10px;border:1px solid var(--border-color,#444);background:var(--input-bg,#151522);' +
            'color:inherit;">' +
            '<div id="safetyPinError" style="min-height:20px;margin-top:8px;font-size:13px;color:#ef4444;"></div>' +
            '<div style="display:flex;gap:10px;margin-top:10px;">' +
            '<button type="button" id="safetyPinCancel" class="btn btn-secondary" style="flex:1;">Cancel</button>' +
            '<button type="button" id="safetyPinOk" class="btn btn-primary" style="flex:1;">Unlock</button>' +
            '</div></div>';
        document.body.appendChild(overlay);
        var input = overlay.querySelector('#safetyPinInput');
        overlay.querySelector('#safetyPinCancel').addEventListener('click', close);
        overlay.querySelector('#safetyPinOk').addEventListener('click', submit);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); submit(); }
            if (e.key === 'Escape') { close(); }
        });
        input.addEventListener('input', function () { input.value = input.value.replace(/\D/g, ''); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        return { overlay: overlay, input: input, error: overlay.querySelector('#safetyPinError'),
                 ok: overlay.querySelector('#safetyPinOk'), onDone: null };
    }

    function open(onDone) {
        if (!modal) modal = buildModal();
        modal.onDone = onDone || null;
        modal.error.textContent = '';
        modal.input.value = '';
        modal.overlay.style.display = 'flex';
        setTimeout(function () { modal.input.focus(); }, 30);
    }

    function close() {
        if (modal) { modal.overlay.style.display = 'none'; modal.onDone = null; }
    }

    function submit() {
        var pin = modal.input.value.trim();
        if (!pin) { modal.error.textContent = 'Type your PIN.'; return; }
        modal.ok.disabled = true;
        nativeFetch(base() + '/api-safety-pin.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json',
                       'X-CSRF-Token': document.body.getAttribute('data-csrf') || '' },
            body: JSON.stringify({ action: 'unlock', pin: pin })
        }).then(function (r) { return r.json(); }).then(function (data) {
            modal.ok.disabled = false;
            if (!data.success) {
                modal.error.textContent = data.error || 'Wrong PIN.';
                modal.input.value = '';
                modal.input.focus();
                return;
            }
            setState('unlocked');
            var done = modal.onDone;
            close();
            if (done) { done(); return; }
            if (data.return_url) { location.href = data.return_url; }
        }).catch(function () {
            modal.ok.disabled = false;
            modal.error.textContent = 'Could not check the PIN. Check your connection.';
        });
    }

    // ------------------------------------------------------------- intercepts

    document.addEventListener('click', function (e) {
        if (state() !== 'locked') return;
        var link = e.target.closest ? e.target.closest('a[href]') : null;
        if (!link || !isProtectedUrl(link.getAttribute('href'))) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        open(function () { link.click(); });
    }, true);

    document.addEventListener('submit', function (e) {
        if (state() !== 'locked') return;
        var form = e.target;
        var submitter = e.submitter || null;
        if (!(form instanceof HTMLFormElement) || !isProtectedForm(form, submitter)) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        open(function () {
            if (form.requestSubmit) { form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); }
            else { form.submit(); }
        });
    }, true);

    // fetch() calls the server refused for want of the PIN (HTTP 423): ask, then retry once.
    var nativeFetch = window.fetch ? window.fetch.bind(window) : null;
    if (nativeFetch) {
        window.fetch = function (input, init) {
            return nativeFetch(input, init).then(function (response) {
                if (response.status !== 423) return response;
                return new Promise(function (resolve) {
                    open(function () { resolve(nativeFetch(input, init)); });
                    var watch = setInterval(function () {
                        if (!modal || modal.overlay.style.display === 'none') {
                            clearInterval(watch);
                            if (state() !== 'unlocked') resolve(response);
                        }
                    }, 300);
                });
            });
        };
    }

    // The server sent us back here because an edit/delete needed the PIN.
    document.addEventListener('DOMContentLoaded', function () {
        var params = new URLSearchParams(location.search);
        if (params.get('safety_pin') === '1' && state() === 'locked') {
            params.delete('safety_pin');
            var clean = location.pathname + (params.toString() ? '?' + params.toString() : '');
            history.replaceState(null, '', clean);
            open(null);
        }
    });

    window.SafetyPin = { open: open, state: state };
})();

/**
 * Recursive "Action Buttons" modal controller (see
 * includes/action_buttons_helper.php + action-button-records.php). Any page
 * that calls renderActionButtonsInline() gets this for free via one
 * delegated click listener -- no page-specific JS needed anywhere.
 *
 * Drilling into a nested button (one defined on a child record's own
 * module) pushes another frame onto `stack` and reloads the SAME modal in
 * place, with a breadcrumb -- rather than stacking a second overlay -- so
 * nesting has no practical depth limit.
 */
(function () {
    'use strict';

    var stack = []; // [{buttonId, recordId, label}]

    function currentFrame() {
        return stack[stack.length - 1];
    }

    function ensureModal() {
        var modal = document.getElementById('actionButtonModal');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.className = 'modal active';
        modal.id = 'actionButtonModal';
        modal.style.maxWidth = '760px';
        modal.innerHTML =
            '<div class="modal-header">' +
            '  <h3 id="actionButtonModalTitle"><i class="fas fa-layer-group" style="margin-right: 8px; color: var(--primary);"></i></h3>' +
            '  <button class="modal-close" onclick="closeActionButtonModal()"><i class="fas fa-times"></i></button>' +
            '</div>' +
            '<div class="modal-body">' +
            '  <div id="actionButtonBreadcrumb" style="margin-bottom: 12px; color: var(--text-secondary); font-size: 0.85rem;"></div>' +
            '  <div id="actionButtonBody"></div>' +
            '</div>';
        document.body.appendChild(modal);

        var overlay = document.getElementById('modalOverlay');
        if (overlay) overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        return modal;
    }

    function renderBreadcrumb() {
        var el = document.getElementById('actionButtonBreadcrumb');
        if (!el) return;
        var parts = stack.map(function (frame, i) {
            if (i === stack.length - 1) return '<strong>' + frame.label + '</strong>';
            return '<a href="javascript:void(0)" data-ab-crumb="' + i + '">' + frame.label + '</a>';
        });
        el.innerHTML = parts.join(' <i class="fas fa-chevron-right" style="font-size: 0.7em; margin: 0 4px;"></i> ');
    }

    function loadList() {
        var frame = currentFrame();
        if (!frame) return;
        document.getElementById('actionButtonModalTitle').innerHTML =
            '<i class="fas fa-layer-group" style="margin-right: 8px; color: var(--primary);"></i>' + frame.label;
        renderBreadcrumb();

        var body = document.getElementById('actionButtonBody');
        body.innerHTML = '<p style="color: var(--text-secondary);">Loading…</p>';
        ajaxRequest(
            '/action-button-records.php?action=list&button_id=' + encodeURIComponent(frame.buttonId) +
            '&record_id=' + encodeURIComponent(frame.recordId),
            'GET', null,
            function (res) {
                if (!res || !res.success) {
                    body.innerHTML = '<p style="color: var(--danger);">' + ((res && res.error) || 'Failed to load') + '</p>';
                    return;
                }
                body.innerHTML = res.html;
                initAbFilters();
            }
        );
    }

    // --- Client-side Filters & Dashboard for an Action Button's records list.
    // Mirrors savings-ledger.php's filter script, but driven here because the
    // list HTML is injected via innerHTML (its own <script> would never run).
    function initAbFilters() {
        var table = document.getElementById('abRecordsTable');
        if (!table || table.getAttribute('data-filters') !== '1') return;
        var tbody = document.getElementById('abRecordsBody');
        var cols = parseInt(table.getAttribute('data-cols'), 10) || 1;
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.ab-rec-row'));
        var el = function (id) { return document.getElementById(id); };
        var cfFilters = Array.prototype.slice.call(document.querySelectorAll('.ab-cf-flt'));
        var f = {
            search: el('abFltSearch'), type: el('abFltType'), month: el('abFltMonth'),
            from: el('abFltFrom'), to: el('abFltTo'), min: el('abFltMin'), max: el('abFltMax'),
            group: el('abFltGroup'), preset: el('abFltPreset')
        };
        var money = function (n) { return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
        var val = function (c) { return c ? c.value : ''; };

        function monthLabel(mk) {
            var p = mk.split('-');
            if (p.length < 2) return mk || '(no date)';
            var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, 1);
            return d.toLocaleString('en-US', { month: 'short', year: 'numeric' });
        }

        function matches(r) {
            var s = (val(f.search) || '').trim().toLowerCase();
            if (s && r.getAttribute('data-desc').indexOf(s) === -1) return false;
            if (val(f.type) && r.getAttribute('data-type') !== f.type.value) return false;
            if (val(f.month) && r.getAttribute('data-month') !== f.month.value) return false;
            var date = r.getAttribute('data-date') || '';
            if (val(f.from) && date < f.from.value) return false;
            if (val(f.to) && date > f.to.value) return false;
            var amt = parseFloat(r.getAttribute('data-amount')) || 0;
            if (f.min && f.min.value !== '' && amt < parseFloat(f.min.value)) return false;
            if (f.max && f.max.value !== '' && amt > parseFloat(f.max.value)) return false;
            for (var i = 0; i < cfFilters.length; i++) {
                var needle = cfFilters[i].value.trim().toLowerCase();
                if (needle) {
                    var hay = r.getAttribute('data-cf' + cfFilters[i].getAttribute('data-fid')) || '';
                    if (hay.indexOf(needle) === -1) return false;
                }
            }
            return true;
        }

        function apply() {
            Array.prototype.slice.call(tbody.querySelectorAll('tr.ab-month-header')).forEach(function (h) { h.remove(); });
            var visible = rows.filter(matches);
            var totalIn = 0, totalOut = 0;
            visible.forEach(function (r) {
                var amt = parseFloat(r.getAttribute('data-amount')) || 0;
                if (r.getAttribute('data-out') === '1') totalOut += amt; else totalIn += amt;
            });
            rows.forEach(function (r) { r.style.display = 'none'; });

            if (f.group && f.group.checked) {
                var groups = {};
                visible.forEach(function (r) {
                    var mk = r.getAttribute('data-month') || '';
                    (groups[mk] = groups[mk] || []).push(r);
                });
                Object.keys(groups).sort().reverse().forEach(function (mk) {
                    var members = groups[mk], net = 0;
                    members.forEach(function (r) {
                        var amt = parseFloat(r.getAttribute('data-amount')) || 0;
                        net += (r.getAttribute('data-out') === '1' ? -amt : amt);
                    });
                    var hdr = document.createElement('tr');
                    hdr.className = 'ab-month-header';
                    hdr.innerHTML = '<td colspan="' + cols + '" style="background: var(--bg-secondary, rgba(127,127,127,.12)); font-weight:700;">'
                        + monthLabel(mk) + ' &middot; Net ' + money(net) + ' &middot; ' + members.length + ' rec</td>';
                    tbody.appendChild(hdr);
                    members.forEach(function (r) { r.style.display = ''; tbody.appendChild(r); });
                });
            } else {
                visible.forEach(function (r) { r.style.display = ''; });
            }

            if (el('abStatCount')) el('abStatCount').textContent = visible.length;
            if (el('abStatIn')) el('abStatIn').textContent = money(totalIn);
            if (el('abStatOut')) el('abStatOut').textContent = money(totalOut);
            if (el('abStatNet')) el('abStatNet').textContent = money(totalIn - totalOut);
        }

        function gatherState() {
            return {
                search: val(f.search), type: val(f.type), month: val(f.month),
                date_from: val(f.from), date_to: val(f.to),
                amount_min: val(f.min), amount_max: val(f.max),
                group: !!(f.group && f.group.checked)
            };
        }
        function applyState(st) {
            st = st || {};
            if (f.search) f.search.value = st.search || '';
            if (f.type) f.type.value = st.type || '';
            if (f.month) f.month.value = st.month || '';
            if (f.from) f.from.value = st.date_from || '';
            if (f.to) f.to.value = st.date_to || '';
            if (f.min) f.min.value = st.amount_min || '';
            if (f.max) f.max.value = st.amount_max || '';
            if (f.group) f.group.checked = !!st.group;
            apply();
        }
        window._abGatherFilterState = gatherState;

        ['input', 'change'].forEach(function (ev) {
            Object.keys(f).forEach(function (k) { if (f[k]) f[k].addEventListener(ev, apply); });
            cfFilters.forEach(function (c) { c.addEventListener(ev, apply); });
        });
        var clearBtn = el('abFltClear');
        if (clearBtn) clearBtn.addEventListener('click', function () {
            applyState({});
            cfFilters.forEach(function (c) { c.value = ''; });
            apply();
        });
        if (f.preset) f.preset.addEventListener('change', function () {
            var dataEl = document.getElementById('abPresetData');
            if (!dataEl || f.preset.value === '') return;
            try {
                var presets = JSON.parse(dataEl.textContent || '[]');
                applyState(presets[parseInt(f.preset.value, 10)]);
            } catch (e) { /* ignore malformed preset data */ }
        });
        apply();
    }

    function loadFilterConfig() {
        var frame = currentFrame();
        if (!frame) return;
        var body = document.getElementById('actionButtonBody');
        body.innerHTML = '<p style="color: var(--text-secondary);">Loading…</p>';
        ajaxRequest(
            '/action-button-records.php?action=filter-config&button_id=' + encodeURIComponent(frame.buttonId),
            'GET', null,
            function (res) {
                if (!res || !res.success) {
                    body.innerHTML = '<p style="color: var(--danger);">' + ((res && res.error) || 'Failed to load') + '</p>';
                    return;
                }
                body.innerHTML =
                    '<form id="abFilterConfigForm">' + res.html + '</form>' +
                    '<div class="modal-footer" style="padding: 16px 0 0; border-top: none;">' +
                    '  <button type="button" class="btn btn-secondary" data-ab-cancel="1"><i class="fas fa-arrow-left"></i> Back</button>' +
                    '  <button type="button" class="btn btn-primary" data-ab-config-save="1"><i class="fas fa-save"></i> Save</button>' +
                    '</div>';
            }
        );
    }

    function saveFilterConfig() {
        var form = document.getElementById('abFilterConfigForm');
        if (!form) return;
        var fd = new FormData(form);
        ajaxRequest('/action-button-records.php?action=filter-config-save', 'POST', fd, function (res) {
            if (!res || !res.success) {
                showToast((res && res.error) || 'Save failed', 'error');
                return;
            }
            showToast('Saved', 'success');
            loadList();
        });
    }

    function savePreset(buttonId) {
        var state = window._abGatherFilterState ? window._abGatherFilterState() : {};
        var name = window.prompt('Name this filter preset:');
        if (!name) return;
        var fd = new FormData();
        fd.append('button_id', buttonId);
        fd.append('name', name);
        fd.append('state', JSON.stringify(state));
        ajaxRequest('/action-button-records.php?action=preset-save', 'POST', fd, function (res) {
            if (!res || !res.success) { showToast((res && res.error) || 'Save failed', 'error'); return; }
            showToast('Preset saved', 'success');
            loadList();
        });
    }

    function loadForm(editId) {
        var frame = currentFrame();
        if (!frame) return;
        var body = document.getElementById('actionButtonBody');
        body.innerHTML = '<p style="color: var(--text-secondary);">Loading…</p>';

        var url = '/action-button-records.php?action=form&button_id=' + encodeURIComponent(frame.buttonId) +
            '&record_id=' + encodeURIComponent(frame.recordId) + (editId ? '&edit_id=' + encodeURIComponent(editId) : '');
        ajaxRequest(url, 'GET', null, function (res) {
            if (!res || !res.success) {
                body.innerHTML = '<p style="color: var(--danger);">' + ((res && res.error) || 'Failed to load') + '</p>';
                return;
            }
            body.innerHTML =
                '<form id="actionButtonForm" enctype="multipart/form-data">' + res.html + '</form>' +
                '<div class="modal-footer" style="padding: 16px 0 0; border-top: none;">' +
                '  <button type="button" class="btn btn-secondary" data-ab-cancel="1">Cancel</button>' +
                '  <button type="button" class="btn btn-primary" data-ab-save="1"><i class="fas fa-save"></i> Save</button>' +
                '</div>';
        });
    }

    function loadView(viewId) {
        var frame = currentFrame();
        if (!frame) return;
        var body = document.getElementById('actionButtonBody');
        body.innerHTML = '<p style="color: var(--text-secondary);">Loading…</p>';

        var url = '/action-button-records.php?action=view&button_id=' + encodeURIComponent(frame.buttonId) +
            '&view_id=' + encodeURIComponent(viewId);
        ajaxRequest(url, 'GET', null, function (res) {
            if (!res || !res.success) {
                body.innerHTML = '<p style="color: var(--danger);">' + ((res && res.error) || 'Failed to load') + '</p>';
                return;
            }
            body.innerHTML = res.html +
                '<div class="modal-footer" style="padding: 16px 0 0; border-top: none;">' +
                '  <button type="button" class="btn btn-secondary" data-ab-cancel="1"><i class="fas fa-arrow-left"></i> Back</button>' +
                '  <button type="button" class="btn btn-primary" data-ab-edit="' + encodeURIComponent(viewId) + '"><i class="fas fa-pen"></i> Edit</button>' +
                '</div>';
        });
    }

    function saveForm() {
        var form = document.getElementById('actionButtonForm');
        if (!form) return;
        var fd = new FormData(form);
        ajaxRequest('/action-button-records.php?action=save', 'POST', fd, function (res) {
            if (!res || !res.success) {
                showToast((res && res.error) || 'Save failed', 'error');
                return;
            }
            showToast('Saved', 'success');
            loadList();
        });
    }

    function deleteRecord(id) {
        showConfirm(
            'Delete Record',
            'Delete this record? This also deletes any nested records/fields under it.',
            function () {
                var fd = new FormData();
                fd.append('edit_id', id);
                ajaxRequest('/action-button-records.php?action=delete', 'POST', fd, function (res) {
                    if (!res || !res.success) {
                        showToast((res && res.error) || 'Delete failed', 'error');
                        return;
                    }
                    showToast('Deleted', 'success');
                    // closeConfirm() (called by showConfirm's own flow) always
                    // clears the shared #modalOverlay's active state -- restore
                    // it since this modal is still open underneath.
                    var overlay = document.getElementById('modalOverlay');
                    if (overlay) overlay.classList.add('active');
                    loadList();
                });
            }
        );
    }

    window.openActionButtonModal = function (buttonId, recordId, label) {
        ensureModal();
        stack.push({ buttonId: buttonId, recordId: recordId, label: label });
        loadList();
    };

    window.closeActionButtonModal = function () {
        var modal = document.getElementById('actionButtonModal');
        if (modal) modal.remove();
        var overlay = document.getElementById('modalOverlay');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
        stack = [];
    };

    document.addEventListener('click', function (e) {
        var addBtn = e.target.closest('[data-ab-add]');
        if (addBtn) { loadForm(null); return; }

        var viewBtn = e.target.closest('[data-ab-view]');
        if (viewBtn) { loadView(viewBtn.getAttribute('data-ab-view')); return; }

        var editBtn = e.target.closest('[data-ab-edit]');
        if (editBtn) { loadForm(editBtn.getAttribute('data-ab-edit')); return; }

        var delBtn = e.target.closest('[data-ab-delete]');
        if (delBtn) { deleteRecord(delBtn.getAttribute('data-ab-delete')); return; }

        var cancelBtn = e.target.closest('[data-ab-cancel]');
        if (cancelBtn) { loadList(); return; }

        var saveBtn = e.target.closest('[data-ab-save]');
        if (saveBtn) { saveForm(); return; }

        var configBtn = e.target.closest('[data-ab-config]');
        if (configBtn) { loadFilterConfig(); return; }

        var configSaveBtn = e.target.closest('[data-ab-config-save]');
        if (configSaveBtn) { saveFilterConfig(); return; }

        var savePresetBtn = e.target.closest('[data-ab-save-preset]');
        if (savePresetBtn) { savePreset(savePresetBtn.getAttribute('data-ab-save-preset')); return; }

        var crumb = e.target.closest('[data-ab-crumb]');
        if (crumb) {
            var idx = parseInt(crumb.getAttribute('data-ab-crumb'), 10);
            stack = stack.slice(0, idx + 1);
            loadList();
            return;
        }

        // The recursion trigger: any renderActionButtonsInline() button,
        // wherever it was rendered -- a real page, or inside this very
        // modal's own list fragment -- drills one level deeper into the
        // SAME modal instead of stacking a second overlay.
        var actionBtn = e.target.closest('[data-action-btn]');
        if (actionBtn) {
            window.openActionButtonModal(
                actionBtn.getAttribute('data-button-id'),
                actionBtn.getAttribute('data-record-id'),
                actionBtn.getAttribute('data-label')
            );
        }
    });
})();

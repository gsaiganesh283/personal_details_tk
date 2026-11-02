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
            }
        );
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

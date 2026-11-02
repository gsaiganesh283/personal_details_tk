/**
 * Personal Details Saver - Main JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {

    // =============================================
    // URL HELPER FOR SUBDIRECTORY SUPPORT
    // =============================================
    window.getUrl = function(path) {
        path = path.replace(/^\/+/, ''); // Remove leading slashes
        if (window.BASE_PATH) {
            return window.BASE_PATH + '/' + path;
        }
        return '/' + path;
    };

    // =============================================
    // SIDEBAR TOGGLE
    // =============================================
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const menuToggle = document.getElementById('menuToggle');
    const mainContent = document.getElementById('mainContent');
    const modalOverlay = document.getElementById('modalOverlay');

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        });
    }

    // NOTE: menuToggle open/close and overlay click are handled via
    // inline onclick attributes in header.php for maximum reliability
    // (works even if this JS file is blocked by hosting provider).
    // app.js only handles click-outside-to-close below.

    document.addEventListener('click', function (e) {
        if (window.innerWidth <= 1024 && sidebar && sidebar.classList.contains('open')) {
            if (!sidebar.contains(e.target) && !(menuToggle && menuToggle.contains(e.target))) {
                sidebar.classList.remove('open');
                var ov = document.getElementById('sidebarOverlay');
                if (ov) ov.classList.remove('active');
            }
        }
    });

    // Restore sidebar state
    if (localStorage.getItem('sidebarCollapsed') === 'true' && sidebar) {
        sidebar.classList.add('collapsed');
    }

    // =============================================
    // PASSWORD TOGGLE
    // =============================================
    document.querySelectorAll('.toggle-password').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = this.closest('.input-wrapper').querySelector('input');
            const icon = this.querySelector('i') || this;
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });

    // =============================================
    // PASSWORD STRENGTH METER
    // =============================================
    const passwordInput = document.getElementById('password');
    if (passwordInput) {
        passwordInput.addEventListener('input', function () {
            const password = this.value;
            let strength = 0;

            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;

            const segments = document.querySelectorAll('.strength-segment');
            const strengthText = document.querySelector('.strength-text');

            segments.forEach(function (seg, index) {
                seg.classList.remove('active', 'weak', 'medium', 'strong');

                if (strength <= 2 && index < strength) {
                    seg.classList.add('active', 'weak');
                } else if (strength <= 4 && index < strength) {
                    seg.classList.add('active', 'medium');
                } else if (strength > 4 && index < Math.min(strength, segments.length)) {
                    seg.classList.add('active', 'strong');
                }
            });

            if (strengthText) {
                if (password.length === 0) strengthText.textContent = '';
                else if (strength <= 2) strengthText.textContent = 'Weak password';
                else if (strength <= 4) strengthText.textContent = 'Medium strength';
                else strengthText.textContent = 'Strong password';
            }
        });
    }

    // =============================================
    // FORM VALIDATION
    // =============================================
    window.validateForm = function (formId) {
        const form = document.getElementById(formId);
        if (!form) return true;

        let isValid = true;
        const requiredFields = form.querySelectorAll('[required]');

        // Clear previous errors
        form.querySelectorAll('.form-group').forEach(function (g) {
            g.classList.remove('error');
        });
        form.querySelectorAll('.form-error').forEach(function (e) {
            e.remove();
        });

        requiredFields.forEach(function (field) {
            const group = field.closest('.form-group');
            if (!field.value.trim()) {
                isValid = false;
                if (group) {
                    group.classList.add('error');
                    const error = document.createElement('div');
                    error.className = 'form-error';
                    error.innerHTML = '<i class="fas fa-exclamation-circle"></i> This field is required';
                    group.appendChild(error);
                }
            }

            // Email validation
            if (field.type === 'email' && field.value.trim()) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(field.value)) {
                    isValid = false;
                    if (group) {
                        group.classList.add('error');
                        const error = document.createElement('div');
                        error.className = 'form-error';
                        error.innerHTML = '<i class="fas fa-exclamation-circle"></i> Invalid email address';
                        group.appendChild(error);
                    }
                }
            }
        });

        if (!isValid) {
            const firstError = form.querySelector('.form-group.error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        return isValid;
    };

    // =============================================
    // TOAST NOTIFICATIONS
    // =============================================
    window.showToast = function (message, type) {
        type = type || 'success';
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };

        const toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.innerHTML =
            '<i class="toast-icon ' + (icons[type] || icons.info) + '"></i>' +
            '<span class="toast-message">' + message + '</span>' +
            '<button class="toast-close" onclick="this.parentElement.remove()">&times;</button>';

        container.appendChild(toast);

        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(function () { toast.remove(); }, 300);
        }, 4000);
    };

    // =============================================
    // MODAL MANAGEMENT
    // =============================================
    window.openModal = function (modalId) {
        const modal = document.getElementById(modalId);
        const overlay = document.getElementById('modalOverlay');
        if (modal && overlay) {
            overlay.classList.add('active');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    };

    window.closeModal = function (modalId) {
        const modal = document.getElementById(modalId);
        const overlay = document.getElementById('modalOverlay');
        if (modal && overlay) {
            overlay.classList.remove('active');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    };

    // Close modal on overlay click
    if (modalOverlay) {
        modalOverlay.addEventListener('click', function () {
            document.querySelectorAll('.modal.active').forEach(function (m) {
                m.classList.remove('active');
            });
            this.classList.remove('active');
            document.body.style.overflow = '';
        });
    }

    // Close modal on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.active').forEach(function (m) {
                m.classList.remove('active');
            });
            if (modalOverlay) {
                modalOverlay.classList.remove('active');
            }
            document.body.style.overflow = '';
        }
    });

    // =============================================
    // CONFIRM DIALOG
    // =============================================
    window.showConfirm = function (title, message, onConfirm) {
        const existingDialog = document.getElementById('confirmModal');
        if (existingDialog) existingDialog.remove();

        const modal = document.createElement('div');
        modal.className = 'modal active';
        modal.id = 'confirmModal';
        modal.style.maxWidth = '420px';
        modal.innerHTML =
            '<div class="modal-body">' +
            '  <div class="confirm-dialog">' +
            '    <div class="confirm-icon danger">' +
            '      <i class="fas fa-trash-alt"></i>' +
            '    </div>' +
            '    <h3>' + title + '</h3>' +
            '    <p>' + message + '</p>' +
            '    <div class="confirm-actions">' +
            '      <button class="btn btn-secondary" onclick="closeConfirm()">Cancel</button>' +
            '      <button class="btn btn-danger" id="confirmBtn">Delete</button>' +
            '    </div>' +
            '  </div>' +
            '</div>';

        document.body.appendChild(modal);
        if (modalOverlay) modalOverlay.classList.add('active');

        document.getElementById('confirmBtn').addEventListener('click', function () {
            onConfirm();
            closeConfirm();
        });
    };

    window.closeConfirm = function () {
        const modal = document.getElementById('confirmModal');
        if (modal) modal.remove();
        if (modalOverlay) modalOverlay.classList.remove('active');
        document.body.style.overflow = '';
    };

    // =============================================
    // TABS
    // =============================================
    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const tabGroup = this.closest('.tabs');
            const targetId = this.dataset.tab;

            tabGroup.querySelectorAll('.tab-btn').forEach(function (b) {
                b.classList.remove('active');
            });
            this.classList.add('active');

            // Find associated tab contents
            const parent = tabGroup.parentElement;
            parent.querySelectorAll('.tab-content').forEach(function (content) {
                content.classList.remove('active');
            });
            const target = parent.querySelector('#' + targetId);
            if (target) target.classList.add('active');
        });
    });

    // =============================================
    // SORTABLE TABLE COLUMNS (applies to every .data-table)
    // =============================================
    document.querySelectorAll('table.data-table').forEach(function (table, tableIndex) {
        var headerRow = table.querySelector('thead tr');
        var tbody = table.querySelector('tbody');
        if (!headerRow || !tbody) return;

        var headers = Array.from(headerRow.children);
        // Keyed by page + position on the page, since most of these tables
        // have no id of their own -- stable as long as the page's table
        // layout doesn't change, which is the same assumption the rest of
        // this block already makes about column indexes.
        var storageKey = 'sortPref::' + window.location.pathname + '::' + tableIndex;
        var sortableIndexes = [];

        headers.forEach(function (th, index) {
            if (th.classList.contains('no-sort') || th.hasAttribute('colspan')) return;

            var label = th.textContent.replace(/\s+/g, ' ').trim();
            if (!label || /^actions?$/i.test(label)) return;

            // Skip columns whose cells hold interactive controls rather than plain data
            var sampleCell = tbody.querySelector('tr > *:nth-child(' + (index + 1) + ')');
            if (sampleCell && sampleCell.querySelector('button, select, input, form, a.btn')) return;

            sortableIndexes.push(index);

            th.classList.add('sortable');
            th.setAttribute('role', 'button');
            th.setAttribute('tabindex', '0');
            th.title = 'Click to sort';

            var icon = document.createElement('i');
            icon.className = 'fas fa-sort sort-icon';
            th.appendChild(icon);

            function doSort() {
                sortTableByColumn(table, index, th, headers, null, storageKey);
            }
            th.addEventListener('click', doSort);
            th.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    doSort();
                }
            });
        });

        // Re-apply whatever sort was last picked for this table, if that
        // column is still sortable (layout may have changed since).
        var saved = null;
        try {
            saved = JSON.parse(window.localStorage.getItem(storageKey));
        } catch (e) { saved = null; }
        if (saved && sortableIndexes.indexOf(saved.index) !== -1 && (saved.dir === 'asc' || saved.dir === 'desc')) {
            sortTableByColumn(table, saved.index, headers[saved.index], headers, saved.dir, storageKey);
        }
    });

    function getSortValue(cell) {
        var text = cell.textContent.replace(/\s+/g, ' ').trim();
        if (!text || text === '-') return { num: null, str: '' };

        // Plain number, optionally with currency symbol / thousands separators / % sign
        var cleaned = text.replace(/[₹$,]/g, '').replace(/^\+/, '');
        if (/^-?\d+(\.\d+)?%?$/.test(cleaned)) {
            return { num: parseFloat(cleaned), str: text.toLowerCase() };
        }

        // Date-like strings (must contain a year or a d/m separator to avoid misreading plain text)
        if (/\d{4}|\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}/.test(text)) {
            var parsed = Date.parse(text);
            if (!isNaN(parsed)) {
                return { num: parsed, str: text.toLowerCase() };
            }
        }

        return { num: null, str: text.toLowerCase() };
    }

    function sortTableByColumn(table, index, th, headers, forceDirection, storageKey) {
        var tbody = table.querySelector('tbody');
        var rows = Array.from(tbody.querySelectorAll(':scope > tr'));
        if (rows.length < 2) return;

        // Bail out on placeholder rows (e.g. an empty-state row spanning all columns)
        if (rows.some(function (r) { return r.children.length <= index; })) return;

        // forceDirection is used when restoring a saved sort (asc/desc explicitly,
        // not toggled); a plain click toggles off whatever this header was last set to.
        var direction = forceDirection || (th.dataset.sortDir === 'asc' ? 'desc' : 'asc');

        headers.forEach(function (h) {
            h.classList.remove('sort-asc', 'sort-desc');
            delete h.dataset.sortDir;
            var ic = h.querySelector('.sort-icon');
            if (ic) ic.className = 'fas fa-sort sort-icon';
        });

        th.dataset.sortDir = direction;
        th.classList.add(direction === 'asc' ? 'sort-asc' : 'sort-desc');
        var activeIcon = th.querySelector('.sort-icon');
        if (activeIcon) activeIcon.className = 'fas ' + (direction === 'asc' ? 'fa-sort-up' : 'fa-sort-down') + ' sort-icon';

        var mult = direction === 'asc' ? 1 : -1;
        var decorated = rows.map(function (row, i) {
            return { row: row, value: getSortValue(row.children[index]), i: i };
        });

        decorated.sort(function (a, b) {
            var result;
            if (a.value.num !== null && b.value.num !== null) {
                result = a.value.num - b.value.num;
            } else {
                result = a.value.str.localeCompare(b.value.str, undefined, { numeric: true, sensitivity: 'base' });
            }
            if (result === 0) result = a.i - b.i; // stable sort
            return result * mult;
        });

        var frag = document.createDocumentFragment();
        decorated.forEach(function (d) { frag.appendChild(d.row); });
        tbody.appendChild(frag);

        if (storageKey) {
            try {
                window.localStorage.setItem(storageKey, JSON.stringify({ index: index, dir: direction }));
            } catch (e) { /* localStorage unavailable (e.g. private browsing) -- sort still applies, just won't persist */ }
        }
    }

    // =============================================
    // AJAX HELPER
    // =============================================
    
    /**
     * Build URL-encoded POST body from a plain object.
     */
    window.buildPostBody = function(obj) {
        var parts = [];
        for (var key in obj) {
            if (obj.hasOwnProperty(key) && obj[key] !== undefined && obj[key] !== null) {
                parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(obj[key]));
            }
        }
        return parts.join('&');
    };

    /**
     * Convert a plain object to FormData.
     * Uses multipart/form-data which is more compatible with shared hosting WAFs.
     */
    window.objectToFormData = function(obj) {
        var fd = new FormData();
        for (var key in obj) {
            if (obj.hasOwnProperty(key) && obj[key] !== undefined && obj[key] !== null) {
                fd.append(key, obj[key]);
            }
        }
        return fd;
    };

    window.ajaxRequest = function (url, method, data, callback) {
        // Prepend BASE_PATH to relative URLs starting with /
        var finalUrl = url;
        if (url.charAt(0) === '/' && window.BASE_PATH) {
            finalUrl = window.BASE_PATH + url;
        } else if (url.charAt(0) === '/') {
            finalUrl = url;
        }
        
        const xhr = new XMLHttpRequest();
        xhr.open(method, finalUrl, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                let response;
                try {
                    response = JSON.parse(xhr.responseText);
                } catch (e) {
                    response = { error: 'Invalid response from server', raw: xhr.responseText ? xhr.responseText.substring(0, 200) : '' };
                }
                callback(response, xhr.status);
            }
        };

        if (data instanceof FormData) {
            // Already FormData — send as multipart/form-data (browser sets boundary automatically)
            xhr.send(data);
        } else if (data && typeof data === 'object') {
            // Convert plain objects to FormData for maximum hosting compatibility
            // multipart/form-data is not blocked by shared hosting WAFs
            xhr.send(objectToFormData(data));
        } else if (data) {
            xhr.send(data);
        } else {
            xhr.send();
        }
    };

    // =============================================
    // LOADING BUTTON STATE
    // =============================================
    window.setButtonLoading = function (btn, loading) {
        if (loading) {
            btn.classList.add('loading');
            btn.disabled = true;
        } else {
            btn.classList.remove('loading');
            btn.disabled = false;
        }
    };

    // =============================================
    // SEARCH FUNCTIONALITY (global + local)
    // =============================================
    const globalSearch = document.getElementById('globalSearch');
    const searchDropdown = document.getElementById('searchResultsDropdown');
    const searchResultsList = document.getElementById('searchResultsList');

    if (globalSearch && searchDropdown && searchResultsList) {
        let searchTimeout;

        // Local page filtering
        function filterPageContent(query) {
            document.querySelectorAll('.detail-card').forEach(function (card) {
                var text = card.textContent.toLowerCase();
                card.style.display = text.includes(query) ? '' : 'none';
            });
            document.querySelectorAll('.data-table tbody tr').forEach(function (row) {
                var text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        }

        // Global API search
        function doGlobalSearch(query) {
            var url = window.getUrl('api-search.php?q=' + encodeURIComponent(query));
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            renderSearchResults(data.results, query);
                        } catch (e) {
                            searchResultsList.innerHTML = '<div class="search-no-results"><i class="fas fa-exclamation-circle"></i> Search error</div>';
                        }
                    }
                }
            };
            xhr.send();
        }

        function renderSearchResults(results, query) {
            if (!results || results.length === 0) {
                searchResultsList.innerHTML = '<div class="search-no-results"><i class="fas fa-search"></i> No results for "' + escapeHtml(query) + '"</div>';
                searchDropdown.classList.add('active');
                return;
            }

            var html = '';
            results.forEach(function (r) {
                html += '<a href="' + r.url + '" class="search-result-item">' +
                    '<div class="search-result-icon" style="color:' + r.color + ';"><i class="' + r.icon + '"></i></div>' +
                    '<div class="search-result-text">' +
                    '<div class="search-result-title">' + r.title + '</div>' +
                    '<div class="search-result-sub">' +
                    '<span class="search-result-module">' + r.module + '</span>' +
                    (r.subtitle ? ' &middot; ' + r.subtitle : '') +
                    '</div></div></a>';
            });
            searchResultsList.innerHTML = html;
            searchDropdown.classList.add('active');
        }

        function escapeHtml(text) {
            var d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }

        globalSearch.addEventListener('input', function () {
            clearTimeout(searchTimeout);
            var query = this.value.trim().toLowerCase();

            if (query.length < 2) {
                searchDropdown.classList.remove('active');
                // Reset local filters
                filterPageContent('');
                return;
            }

            searchTimeout = setTimeout(function () {
                filterPageContent(query);
                doGlobalSearch(query);
            }, 300);
        });

        globalSearch.addEventListener('focus', function () {
            if (this.value.trim().length >= 2 && searchResultsList.innerHTML) {
                searchDropdown.classList.add('active');
            }
        });

        // Close dropdown on click outside
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#searchBox')) {
                searchDropdown.classList.remove('active');
            }
        });

        // Close on Escape
        globalSearch.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                searchDropdown.classList.remove('active');
                this.blur();
            }
        });
    }

    // =============================================
    // NOTIFICATION BELL
    // =============================================
    var notifBell = document.getElementById('notificationBell');
    var notifDropdown = document.getElementById('notificationDropdown');
    var notifBadge = document.getElementById('notificationBadge');
    var notifList = document.getElementById('notificationList');
    var markAllReadBtn = document.getElementById('markAllRead');

    if (notifBell && notifDropdown) {
        // Toggle dropdown
        notifBell.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = notifDropdown.classList.contains('active');
            if (isOpen) {
                notifDropdown.classList.remove('active');
            } else {
                notifDropdown.classList.add('active');
                loadNotifications();
            }
        });

        // Prevent dropdown close when clicking inside it  
        notifDropdown.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        // Close on outside click
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#notificationBell')) {
                notifDropdown.classList.remove('active');
            }
        });

        // Mark all read
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function () {
                var url = window.getUrl('api-notifications.php?action=mark_read');
                var xhr = new XMLHttpRequest();
                xhr.open('GET', url, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        if (notifBadge) {
                            notifBadge.style.display = 'none';
                            notifBadge.textContent = '0';
                        }
                        // Remove unread styling
                        notifList.querySelectorAll('.notif-item.unread').forEach(function (el) {
                            el.classList.remove('unread');
                        });
                    }
                };
                xhr.send();
            });
        }

        function loadNotifications() {
            var url = window.getUrl('api-notifications.php?action=list');
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        renderNotifications(data.notifications);
                        updateBadge(data.unreadCount);
                    } catch (e) {
                        notifList.innerHTML = '<div class="notification-empty"><i class="fas fa-exclamation-circle"></i><p>Failed to load</p></div>';
                    }
                }
            };
            xhr.send();
        }

        function renderNotifications(items) {
            if (!items || items.length === 0) {
                notifList.innerHTML = '<div class="notification-empty"><i class="fas fa-bell-slash"></i><p>No notifications yet</p></div>';
                return;
            }

            var html = '';
            items.forEach(function (n) {
                html += '<div class="notif-item' + (n.unread ? ' unread' : '') + '">' +
                    '<div class="notif-icon" style="color:' + n.color + ';background:' + n.color + '20;"><i class="' + n.icon + '"></i></div>' +
                    '<div class="notif-content">' +
                    '<p class="notif-text">' + n.description + '</p>' +
                    '<span class="notif-time">' + n.time + '</span>' +
                    '</div></div>';
            });
            notifList.innerHTML = html;
        }

        function updateBadge(count) {
            if (notifBadge) {
                if (count > 0) {
                    notifBadge.textContent = count > 99 ? '99+' : count;
                    notifBadge.style.display = 'flex';
                } else {
                    notifBadge.style.display = 'none';
                }
            }
        }

        // Load initial unread count
        function loadUnreadCount() {
            var url = window.getUrl('api-notifications.php?action=count');
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        updateBadge(data.unreadCount);
                    } catch (e) { /* ignore */ }
                }
            };
            xhr.send();
        }

        loadUnreadCount();
        // Poll for new notifications every 60 seconds
        setInterval(loadUnreadCount, 60000);
    }

    // =============================================
    // PWA INSTALL PROMPT
    // =============================================
    (function () {
        var installBanner = document.getElementById('installBanner');
        if (!installBanner) return;

        var installBtn = document.getElementById('installBannerInstallBtn');
        var dismissBtn = document.getElementById('installBannerDismissBtn');
        var hintText = document.getElementById('installBannerHint');
        var DISMISS_KEY = 'pds_install_dismissed';
        var deferredInstallPrompt = null;

        function isStandalone() {
            return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        }

        function isIOS() {
            return /iphone|ipad|ipod/i.test(navigator.userAgent);
        }

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredInstallPrompt = e;
            if (localStorage.getItem(DISMISS_KEY) === '1' || isStandalone()) return;
            installBanner.classList.add('active');
        });

        // Chromium fires beforeinstallprompt; iOS Safari never does (no
        // programmatic install API), so show a manual-instructions variant
        // there instead once the page has settled.
        if (isIOS() && !isStandalone() && localStorage.getItem(DISMISS_KEY) !== '1') {
            if (installBtn) installBtn.style.display = 'none';
            if (hintText) hintText.textContent = 'Tap Share, then "Add to Home Screen".';
            installBanner.classList.add('active');
        }

        if (installBtn) {
            installBtn.addEventListener('click', function () {
                if (!deferredInstallPrompt) return;
                deferredInstallPrompt.prompt();
                deferredInstallPrompt.userChoice.finally(function () {
                    deferredInstallPrompt = null;
                    installBanner.classList.remove('active');
                });
            });
        }

        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () {
                localStorage.setItem(DISMISS_KEY, '1');
                installBanner.classList.remove('active');
            });
        }

        window.addEventListener('appinstalled', function () {
            localStorage.setItem(DISMISS_KEY, '1');
            installBanner.classList.remove('active');
        });
    })();

    // =============================================
    // SMOOTH SCROLLING
    // =============================================
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

    // =============================================
    // AUTO-DISMISS ALERTS
    // =============================================
    document.querySelectorAll('.alert').forEach(function (alert) {
        setTimeout(function () {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function () { alert.remove(); }, 300);
        }, 5000);
    });

    // =============================================
    // INPUT ANIMATIONS
    // =============================================
    document.querySelectorAll('.input-wrapper input, .input-wrapper textarea').forEach(function (input) {
        input.addEventListener('focus', function () {
            this.closest('.form-group')?.classList.add('focused');
        });
        input.addEventListener('blur', function () {
            this.closest('.form-group')?.classList.remove('focused');
        });
    });

    // =============================================
    // THEME TOGGLE (bonus)
    // =============================================
    window.toggleTheme = function () {
        var currentTheme = document.documentElement.getAttribute('data-theme');
        var newTheme = currentTheme === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
    };

    // Restore saved theme
    var savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
        document.documentElement.setAttribute('data-theme', savedTheme);
    }

    // =============================================
    // FORM PREVIEW / REVIEW BEFORE SUBMIT
    // =============================================

    /**
     * Show a preview modal for a form before submission.
     * @param {string} formId - The ID of the form to preview
     */
    window.showFormPreview = function (formId) {
        var form = document.getElementById(formId);
        if (!form) return;

        // Run validation first
        if (typeof window.validateForm === 'function' && !window.validateForm(formId)) {
            return;
        }

        // Remove existing preview modal if any
        var existing = document.getElementById('previewModal');
        if (existing) existing.remove();

        // Collect form fields
        var fields = collectFormFields(form);
        var customFields = collectCustomFields(form);

        // Build modal HTML
        var html = '<div class="preview-modal active" id="previewModal">';
        html += '<div class="preview-modal-header">';
        html += '<h3><i class="fas fa-eye"></i> Review Details</h3>';
        html += '<button type="button" class="preview-modal-close" onclick="closePreviewModal()"><i class="fas fa-times"></i></button>';
        html += '</div>';
        html += '<div class="preview-modal-body">';

        // Standard fields section
        if (fields.length > 0) {
            html += '<div class="preview-section">';
            html += '<div class="preview-section-title"><i class="fas fa-info-circle"></i> Details</div>';
            html += '<div class="preview-fields-grid">';
            fields.forEach(function (f) {
                var isLong = f.value && f.value.length > 60;
                html += '<div class="preview-field' + (isLong || f.type === 'textarea' ? ' full-width' : '') + '">';
                html += '<div class="preview-field-label">' + escapeHtmlPreview(f.label) + '</div>';
                html += '<div class="preview-field-value' + (!f.value ? ' empty' : '') + '">' + formatPreviewValue(f) + '</div>';
                html += '</div>';
            });
            html += '</div></div>';
        }

        // Custom fields section
        if (customFields.length > 0) {
            html += '<div class="preview-section">';
            html += '<div class="preview-section-title"><i class="fas fa-puzzle-piece"></i> Custom Fields</div>';
            html += '<div class="preview-fields-grid">';
            customFields.forEach(function (f) {
                var isLong = f.value && f.value.length > 60;
                html += '<div class="preview-field' + (isLong || f.type === 'textarea' ? ' full-width' : '') + '">';
                html += '<div class="preview-field-label">' + escapeHtmlPreview(f.label) + '</div>';
                html += '<div class="preview-field-value' + (!f.value ? ' empty' : '') + '">' + formatPreviewValue(f) + '</div>';
                html += '</div>';
            });
            html += '</div></div>';
        }

        html += '</div>'; // end body

        html += '<div class="preview-modal-footer">';
        html += '<button type="button" class="btn btn-ghost" onclick="closePreviewModal()"><i class="fas fa-edit"></i> Go Back & Edit</button>';
        html += '<button type="button" class="btn btn-primary" onclick="submitFromPreview(\'' + formId + '\')"><i class="fas fa-check-circle"></i> Confirm & Submit</button>';
        html += '</div>';

        html += '</div>'; // end modal

        document.body.appendChild(createElementFromHTML(html));

        // Show overlay
        var overlay = document.getElementById('modalOverlay');
        if (overlay) overlay.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Close on Escape
        document.addEventListener('keydown', previewEscHandler);
    };

    function previewEscHandler(e) {
        if (e.key === 'Escape') {
            closePreviewModal();
        }
    }

    window.closePreviewModal = function () {
        var modal = document.getElementById('previewModal');
        if (modal) {
            modal.classList.remove('active');
            setTimeout(function () { modal.remove(); }, 200);
        }
        var overlay = document.getElementById('modalOverlay');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
        document.removeEventListener('keydown', previewEscHandler);
    };

    window.submitFromPreview = function (formId) {
        var form = document.getElementById(formId);
        if (form) {
            closePreviewModal();
            // Mark as confirmed to bypass preview on native submit
            form.setAttribute('data-preview-confirmed', '1');
            form.submit();
        }
    };

    function collectFormFields(form) {
        var fields = [];
        var seen = {};

        // Get all input/select/textarea elements (skip hidden, csrf, action fields)
        var elements = form.querySelectorAll('input, select, textarea');
        elements.forEach(function (el) {
            var name = el.name;
            if (!name) return;
            // Skip hidden inputs, CSRF tokens, action fields, custom field entries
            if (el.type === 'hidden') return;
            if (name === 'csrf_token' || name === 'action' || name === 'id' || name === 'record_id') return;
            if (name.indexOf('custom_field') === 0 || name.indexOf('cf_') === 0) return;
            // Skip if already seen (for radio groups etc)
            if (seen[name]) return;

            var label = getFieldLabel(el);
            var value = getFieldValue(el, form);
            var type = el.type || el.tagName.toLowerCase();

            fields.push({ name: name, label: label, value: value, type: type, element: el });
            seen[name] = true;
        });

        return fields;
    }

    function collectCustomFields(form) {
        var fields = [];
        // Custom field containers have inputs with name patterns like custom_field[...] or cf_...
        var customEls = form.querySelectorAll('[name^="custom_field"], [name^="cf_"]');
        var seen = {};

        customEls.forEach(function (el) {
            var name = el.name;
            if (!name || seen[name]) return;
            if (el.type === 'hidden') return;

            var label = getFieldLabel(el);
            var value = getFieldValue(el, form);
            var type = el.type || el.tagName.toLowerCase();

            fields.push({ name: name, label: label, value: value, type: type, element: el });
            seen[name] = true;
        });

        return fields;
    }

    function getFieldLabel(el) {
        // Try explicit label
        if (el.id) {
            var label = document.querySelector('label[for="' + el.id + '"]');
            if (label) return label.textContent.replace(/\*$/, '').trim();
        }
        // Try closest form-group label
        var group = el.closest('.form-group');
        if (group) {
            var lbl = group.querySelector('label');
            if (lbl) return lbl.textContent.replace(/\*$/, '').trim();
        }
        // Fallback: humanize field name
        return humanizeName(el.name);
    }

    function getFieldValue(el, form) {
        var type = el.type || '';

        if (type === 'checkbox') {
            return el.checked ? 'Yes' : 'No';
        }
        if (type === 'radio') {
            var checked = form.querySelector('input[name="' + el.name + '"]:checked');
            if (checked) {
                // Try to find label for the checked radio
                var lbl = checked.parentElement.querySelector('label') ||
                          document.querySelector('label[for="' + checked.id + '"]');
                return lbl ? lbl.textContent.trim() : checked.value;
            }
            return '';
        }
        if (type === 'file') {
            if (el.files && el.files.length > 0) {
                return Array.from(el.files).map(function (f) { return f.name; }).join(', ');
            }
            return '';
        }
        if (el.tagName === 'SELECT') {
            var selected = el.options[el.selectedIndex];
            return selected ? selected.text : '';
        }
        return el.value || '';
    }

    function formatPreviewValue(field) {
        if (!field.value) return '<span style="color: var(--text-muted); font-style: italic;">Not provided</span>';

        if (field.type === 'checkbox') {
            var cls = field.value === 'Yes' ? 'badge-yes' : 'badge-no';
            return '<span class="badge ' + cls + '">' + field.value + '</span>';
        }
        if (field.type === 'file') {
            return '<span class="file-badge"><i class="fas fa-paperclip"></i> ' + escapeHtmlPreview(field.value) + '</span>';
        }
        if (field.type === 'password') {
            var id = 'pw_' + Math.random().toString(36).substr(2, 9);
            return '<span style="display:inline-flex;align-items:center;gap:8px;width:100%;">' +
                '<span id="' + id + '_masked" style="letter-spacing:3px;">••••••••</span>' +
                '<span id="' + id + '_plain" style="display:none;">' + escapeHtmlPreview(field.value) + '</span>' +
                '<button type="button" onclick="togglePreviewPassword(\'' + id + '\', this)" ' +
                'style="background:none;border:none;color:var(--text-muted);cursor:pointer;padding:2px 4px;margin-left:auto;font-size:0.9rem;" title="Show password">' +
                '<i class="fas fa-eye"></i></button></span>';
        }
        return escapeHtmlPreview(field.value);
    }

    // Password toggle for preview modal
    window.togglePreviewPassword = function(id, btn) {
        var masked = document.getElementById(id + '_masked');
        var plain = document.getElementById(id + '_plain');
        if (masked.style.display !== 'none') {
            masked.style.display = 'none';
            plain.style.display = '';
            btn.innerHTML = '<i class="fas fa-eye-slash"></i>';
            btn.title = 'Hide password';
        } else {
            masked.style.display = '';
            plain.style.display = 'none';
            btn.innerHTML = '<i class="fas fa-eye"></i>';
            btn.title = 'Show password';
        }
    };

    function humanizeName(name) {
        // Remove custom_field[], cf_ prefixes, array brackets
        name = name.replace(/^custom_field\[|\]$/g, '').replace(/^cf_/, '');
        // Convert snake_case/kebab-case to words
        return name.replace(/[_\-\[\]]+/g, ' ')
                    .replace(/([a-z])([A-Z])/g, '$1 $2')
                    .replace(/\b\w/g, function (c) { return c.toUpperCase(); })
                    .trim();
    }

    function escapeHtmlPreview(text) {
        var d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    function createElementFromHTML(htmlString) {
        var div = document.createElement('div');
        div.innerHTML = htmlString.trim();
        return div.firstChild;
    }

    // =========================================================================
    // AUTO-LOGOUT ON INACTIVITY (10 minutes)
    // Pauses the timer while file uploads are in progress.
    // =========================================================================
    (function() {
        var IDLE_TIMEOUT   = 60 * 60 * 1000; // 60 minutes in ms
        var WARNING_BEFORE = 30 * 1000;      // Show warning 30s before logout
        var idleTimer      = null;
        var warningTimer   = null;
        var warningShown   = false;
        var activeUploads  = 0;              // Count of in-progress uploads

        // --- Track active uploads ---
        // 1. Monkey-patch XMLHttpRequest to detect file uploads
        var OrigXHR = window.XMLHttpRequest;
        window.XMLHttpRequest = function() {
            var xhr = new OrigXHR();
            var origSend = xhr.send;
            xhr.send = function(body) {
                // Detect if body contains file data (FormData with files or Blob)
                var isFileUpload = false;
                if (body instanceof FormData) {
                    // Check if any entry is a File/Blob
                    if (typeof body.entries === 'function') {
                        var iter = body.entries();
                        var entry;
                        while (!(entry = iter.next()).done) {
                            if (entry.value[1] instanceof Blob) {
                                isFileUpload = true;
                                break;
                            }
                        }
                    } else {
                        // Older browsers: assume FormData might have files
                        isFileUpload = true;
                    }
                }
                if (isFileUpload) {
                    activeUploads++;
                    var done = false;
                    function onDone() {
                        if (!done) {
                            done = true;
                            activeUploads = Math.max(0, activeUploads - 1);
                        }
                    }
                    xhr.addEventListener('load', onDone);
                    xhr.addEventListener('error', onDone);
                    xhr.addEventListener('abort', onDone);
                    xhr.addEventListener('timeout', onDone);
                }
                return origSend.apply(this, arguments);
            };
            return xhr;
        };
        // Preserve static properties
        window.XMLHttpRequest.UNSENT = 0;
        window.XMLHttpRequest.OPENED = 1;
        window.XMLHttpRequest.HEADERS_RECEIVED = 2;
        window.XMLHttpRequest.LOADING = 3;
        window.XMLHttpRequest.DONE = 4;

        // 2. Track form submissions with file inputs
        document.addEventListener('submit', function(e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') return;
            var fileInputs = form.querySelectorAll('input[type="file"]');
            var hasFiles = false;
            for (var i = 0; i < fileInputs.length; i++) {
                if (fileInputs[i].files && fileInputs[i].files.length > 0) {
                    hasFiles = true;
                    break;
                }
            }
            if (hasFiles) {
                activeUploads++;
                // Form submission navigates away, so decrement after a generous delay
                // (if the page doesn't navigate, e.g. target="_blank", clean up)
                setTimeout(function() {
                    activeUploads = Math.max(0, activeUploads - 1);
                }, 5 * 60 * 1000); // 5 min safety net
            }
        }, true);

        // --- Idle detection ---
        function resetIdleTimer() {
            warningShown = false;
            clearTimeout(idleTimer);
            clearTimeout(warningTimer);
            dismissWarning();

            // Set warning timer (fires 30s before logout)
            warningTimer = setTimeout(function() {
                if (activeUploads > 0) {
                    // Upload in progress — re-check every 10s
                    resetIdleTimer();
                    return;
                }
                showWarning();
            }, IDLE_TIMEOUT - WARNING_BEFORE);

            // Set logout timer
            idleTimer = setTimeout(function() {
                if (activeUploads > 0) {
                    // Upload in progress — defer logout, check again in 30s
                    resetIdleTimer();
                    return;
                }
                doLogout();
            }, IDLE_TIMEOUT);
        }

        function showWarning() {
            if (warningShown) return;
            warningShown = true;
            // Create a persistent warning banner
            var banner = document.getElementById('idle-warning-banner');
            if (!banner) {
                banner = document.createElement('div');
                banner.id = 'idle-warning-banner';
                banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:100000;background:linear-gradient(135deg,#f5576c,#ff6b6b);color:#fff;text-align:center;padding:12px 20px;font-size:0.95rem;font-weight:600;box-shadow:0 2px 12px rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;gap:12px;';
                banner.innerHTML = '<i class="fas fa-clock" style="font-size:1.2rem;"></i>'
                    + '<span>You will be logged out in <strong>30 seconds</strong> due to inactivity.</span>'
                    + '<button onclick="document.getElementById(\'idle-warning-banner\').remove();" '
                    + 'style="background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.4);color:#fff;padding:6px 16px;border-radius:6px;cursor:pointer;font-weight:600;font-size:0.85rem;">'
                    + '<i class="fas fa-mouse-pointer" style="margin-right:4px;"></i> I\'m still here</button>';
                document.body.appendChild(banner);
            }
            // Countdown
            var remaining = 30;
            var countdownInterval = setInterval(function() {
                remaining--;
                var span = banner ? banner.querySelector('strong') : null;
                if (span) span.textContent = remaining + ' seconds';
                if (remaining <= 0) clearInterval(countdownInterval);
            }, 1000);
            banner._countdownInterval = countdownInterval;
        }

        function dismissWarning() {
            var banner = document.getElementById('idle-warning-banner');
            if (banner) {
                if (banner._countdownInterval) clearInterval(banner._countdownInterval);
                banner.remove();
            }
        }

        function doLogout() {
            dismissWarning();
            var logoutUrl = (window.BASE_PATH || '') + '/logout.php';
            window.location.href = logoutUrl;
        }

        // Listen for user activity
        var activityEvents = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'];
        activityEvents.forEach(function(evt) {
            document.addEventListener(evt, resetIdleTimer, { passive: true });
        });

        // Start the timer
        resetIdleTimer();
    })();

});

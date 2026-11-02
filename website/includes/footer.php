    </div><!-- .content-area -->
</main><!-- .main-content -->

<!-- Modal Overlay -->
<div class="modal-overlay" id="modalOverlay"></div>

<!-- Global File Viewer Modal -->
<div class="modal view-modal" id="globalFileViewer" style="max-width: 900px; display: none;">
    <div class="modal-header">
        <h3><i class="fas fa-eye" style="margin-right: 8px; color: var(--primary);"></i> View Document</h3>
        <div style="display: flex; align-items: center; gap: 8px;">
            <button type="button" class="btn btn-ghost btn-sm" id="gfvInfoToggle" title="Toggle details" style="display:none;">
                <i class="fas fa-info-circle"></i>
            </button>
            <button type="button" class="modal-close" onclick="closeGlobalFileViewer()"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <div class="modal-body" style="padding: 0; display: flex; flex-direction: column; max-height: calc(90vh - 140px); overflow: hidden;">
        <!-- Preview pane (PDF iframe / image / non-previewable icon) -->
        <div id="gfvPreviewPane" style="flex: 1; min-height: 0; background: #1a1a2e; position: relative; display: flex; align-items: center; justify-content: center;">
            <div id="gfvLoading" style="color: var(--text-muted); padding: 40px; text-align: center;">
                <i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i>
                <p style="margin-top: 12px;">Loading preview...</p>
            </div>
        </div>
    </div>
    <div class="modal-footer" id="gfvFooter">
        <button type="button" id="gfvDownloadBtn" class="btn btn-primary btn-sm" onclick="gfvDownload()">
            <i class="fas fa-download"></i> Download
        </button>
        <button type="button" id="gfvOpenFullBtn" class="btn btn-ghost btn-sm" title="Open in new tab" style="display:none;" onclick="gfvOpenFull()">
            <i class="fas fa-external-link-alt"></i> Open Full
        </button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="closeGlobalFileViewer()">Close</button>
    </div>
</div>

<script>
/**
 * Global File Viewer - opens files (PDF, images, etc.) in an in-page modal
 * 
 * Usage: openFileViewer(previewUrl, downloadUrl, fileName)
 *   previewUrl  - URL that serves the file inline (Content-Disposition: inline)
 *   downloadUrl - URL that forces download (Content-Disposition: attachment)
 *   fileName    - Display name for the file
 */
// Store current file URLs for button handlers
var _gfvDownloadUrl = '';
var _gfvPreviewUrl = '';
var _gfvFileName = '';

function gfvDownload() {
    if (_gfvDownloadUrl) {
        // Use hidden link trick for reliable downloads
        var a = document.createElement('a');
        a.href = _gfvDownloadUrl;
        a.download = _gfvFileName || 'download';
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        setTimeout(function() { document.body.removeChild(a); }, 100);
    }
}

function gfvOpenFull() {
    if (_gfvPreviewUrl) {
        window.open(_gfvPreviewUrl, '_blank');
    }
}

function openFileViewer(previewUrl, downloadUrl, fileName) {
    var modal = document.getElementById('globalFileViewer');
    var overlay = document.getElementById('modalOverlay');
    var pane = document.getElementById('gfvPreviewPane');
    var loading = document.getElementById('gfvLoading');
    var openFullBtn = document.getElementById('gfvOpenFullBtn');
    var infoToggle = document.getElementById('gfvInfoToggle');
    // Store URLs for button handlers
    _gfvDownloadUrl = downloadUrl;
    _gfvPreviewUrl = previewUrl;
    _gfvFileName = fileName;

    // Reset
    pane.innerHTML = '';
    pane.appendChild(loading);
    loading.style.display = '';
    openFullBtn.style.display = 'none';
    infoToggle.style.display = 'none';

    // Determine file type from name
    var ext = (fileName || '').split('.').pop().toLowerCase();
    var isPdf = (ext === 'pdf');
    var isImage = ['jpg','jpeg','png','gif','bmp','webp','svg'].indexOf(ext) >= 0;

    modal.style.display = '';
    modal.classList.add('active');
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';

    if (isPdf) {
        // PDF: use iframe
        loading.style.display = 'none';
        var iframe = document.createElement('iframe');
        iframe.src = previewUrl;
        iframe.style.cssText = 'width:100%; height:100%; min-height:500px; border:none; display:block;';
        iframe.title = 'PDF Preview';
        pane.appendChild(iframe);
        openFullBtn.style.display = '';
        modal.style.maxWidth = '900px';
    } else if (isImage) {
        // Image: show img with zoom toggle
        var img = document.createElement('img');
        img.src = previewUrl;
        img.alt = fileName;
        img.style.cssText = 'max-width:100%; max-height:500px; border-radius:8px; object-fit:contain; cursor:zoom-in;';
        img.onclick = function() {
            if (this.style.maxHeight === 'none') {
                this.style.maxHeight = '500px';
                this.style.cursor = 'zoom-in';
            } else {
                this.style.maxHeight = 'none';
                this.style.cursor = 'zoom-out';
            }
        };
        img.onload = function() { loading.style.display = 'none'; };
        img.onerror = function() {
            loading.style.display = 'none';
            showNonPreviewable(pane, fileName, ext);
        };
        pane.style.padding = '20px';
        pane.appendChild(img);
        openFullBtn.style.display = '';
        modal.style.maxWidth = '900px';
    } else {
        // Non-previewable: show icon + filename
        loading.style.display = 'none';
        showNonPreviewable(pane, fileName, ext);
        modal.style.maxWidth = '600px';
    }
}

function showNonPreviewable(pane, fileName, ext) {
    var iconMap = {
        'doc': 'fa-file-word', 'docx': 'fa-file-word',
        'xls': 'fa-file-excel', 'xlsx': 'fa-file-excel', 'csv': 'fa-file-csv',
        'ppt': 'fa-file-powerpoint', 'pptx': 'fa-file-powerpoint',
        'zip': 'fa-file-archive', 'rar': 'fa-file-archive', '7z': 'fa-file-archive',
        'mp3': 'fa-file-audio', 'wav': 'fa-file-audio',
        'mp4': 'fa-file-video', 'mov': 'fa-file-video',
        'txt': 'fa-file-alt', 'json': 'fa-file-code', 'xml': 'fa-file-code',
    };
    var colorMap = {
        'doc': '#2b579a', 'docx': '#2b579a',
        'xls': '#217346', 'xlsx': '#217346', 'csv': '#217346',
        'ppt': '#d24726', 'pptx': '#d24726',
    };
    var icon = iconMap[ext] || 'fa-file';
    var color = colorMap[ext] || 'var(--primary)';

    var div = document.createElement('div');
    div.style.cssText = 'text-align:center; padding:40px;';
    div.innerHTML = '<div style="width:80px; height:80px; border-radius:16px; display:flex; align-items:center; justify-content:center; background:rgba(108,99,255,0.1); margin:0 auto 16px;">' +
        '<i class="fas ' + icon + '" style="font-size:2.5rem; color:' + color + ';"></i></div>' +
        '<h3 style="margin:0 0 4px;">' + escapeHtmlGfv(fileName) + '</h3>' +
        '<p style="color:var(--text-muted); font-size:0.85rem;">This file type cannot be previewed. Use Download to save it.</p>';
    pane.appendChild(div);
}

function escapeHtmlGfv(text) {
    var d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}

function closeGlobalFileViewer() {
    var modal = document.getElementById('globalFileViewer');
    var overlay = document.getElementById('modalOverlay');
    modal.classList.remove('active');
    modal.style.display = 'none';
    overlay.classList.remove('active');
    document.body.style.overflow = '';
    // Clean up iframe/img
    var pane = document.getElementById('gfvPreviewPane');
    var loading = document.getElementById('gfvLoading');
    pane.innerHTML = '';
    pane.appendChild(loading);
    pane.style.padding = '';
}

// Close on overlay click (if not already handled)
document.getElementById('modalOverlay').addEventListener('click', function() {
    var gfv = document.getElementById('globalFileViewer');
    if (gfv && gfv.classList.contains('active')) {
        closeGlobalFileViewer();
    }
});

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var gfv = document.getElementById('globalFileViewer');
        if (gfv && gfv.classList.contains('active')) {
            closeGlobalFileViewer();
        }
    }
});

function openNativeDateTimePicker(input) {
    if (!input || input.disabled || input.readOnly) return;
    var inputType = (input.getAttribute('type') || '').toLowerCase();
    if (['date', 'datetime-local', 'time', 'month'].indexOf(inputType) === -1) return;
    input.focus();
    if (typeof input.showPicker === 'function') {
        try {
            input.showPicker();
        } catch (err) {
            // Some browsers block showPicker in non-trusted contexts.
        }
    }
}

document.addEventListener('click', function(e) {
    if (!e.target) return;

    var directInput = e.target.closest('input[type="date"], input[type="datetime-local"], input[type="time"], input[type="month"]');
    if (directInput) {
        openNativeDateTimePicker(directInput);
        return;
    }

    var icon = e.target.closest('.input-wrapper i');
    if (!icon) return;

    var input = icon.previousElementSibling;
    openNativeDateTimePicker(input);
});

// ===== File Upload Speed Enhancements =====

// Show selected filename + size on file inputs immediately
document.addEventListener('change', function(e) {
    if (e.target && e.target.type === 'file' && e.target.classList.contains('cf-file-input')) {
        var input = e.target;
        var label = input.parentNode.querySelector('.file-label');
        if (input.files && input.files.length > 0) {
            var file = input.files[0];
            var sizeMB = (file.size / (1024 * 1024)).toFixed(2);
            var maxSize = 100 * 1024 * 1024;
            if (file.size > maxSize) {
                if (label) {
                    label.textContent = file.name + ' (' + sizeMB + ' MB) - TOO LARGE!';
                    label.style.color = '#f5576c';
                }
                input.value = '';
                return;
            }
            if (label) {
                label.textContent = file.name + ' (' + sizeMB + ' MB) ✓';
                label.style.color = '#2ecc71';
            }
            input.parentNode.style.borderColor = '#2ecc71';
        } else {
            if (label) {
                label.textContent = 'Click to upload (max 100MB)';
                label.style.color = 'var(--text-muted)';
            }
            input.parentNode.style.borderColor = '';
        }
    }
});

// Show upload progress overlay on form submit with files
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
        showUploadOverlay();
    }
}, true);

function showUploadOverlay() {
    if (document.getElementById('uploadProgressOverlay')) return;
    var overlay = document.createElement('div');
    overlay.id = 'uploadProgressOverlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(10,10,30,0.85);z-index:100001;display:flex;align-items:center;justify-content:center;';
    overlay.innerHTML = '<div style="text-align:center;color:#fff;max-width:400px;padding:40px;">' +
        '<div style="width:60px;height:60px;border:4px solid rgba(108,99,255,0.3);border-top-color:#6C63FF;border-radius:50%;animation:uploadSpin 0.8s linear infinite;margin:0 auto 20px;"></div>' +
        '<h3 style="margin:0 0 8px;font-size:1.2rem;">Uploading files...</h3>' +
        '<p style="margin:0;color:rgba(255,255,255,0.7);font-size:0.9rem;">Please wait, this may take a moment for large files.</p>' +
        '</div>';
    var style = document.createElement('style');
    style.textContent = '@keyframes uploadSpin{to{transform:rotate(360deg)}}';
    overlay.appendChild(style);
    document.body.appendChild(overlay);
}
</script>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- PWA Install Banner -->
<div class="install-banner" id="installBanner">
    <div class="install-banner-icon"><img src="<?php echo url('assets/icons/icon-96.png'); ?>" alt=""></div>
    <div class="install-banner-text">
        <strong>Install PDS</strong>
        <span id="installBannerHint">Add to your home screen for quick, app-like access.</span>
    </div>
    <div class="install-banner-actions">
        <button type="button" id="installBannerInstallBtn" class="btn btn-primary btn-sm">Install</button>
        <button type="button" id="installBannerDismissBtn" class="btn btn-ghost btn-sm" aria-label="Dismiss">&times;</button>
    </div>
</div>

<!-- App JavaScript -->
<?php
// Per-file mtime, not a shared/timed value -- see includes/header.php for why
// (the service worker caches /assets/*.js cache-first keyed by this exact URL).
$appJsVersion = @filemtime(__DIR__ . '/../assets/js/app.js') ?: time();
$actionButtonsJsVersion = @filemtime(__DIR__ . '/../assets/js/action-buttons.js') ?: time();
$safetyPinJsVersion = @filemtime(__DIR__ . '/../assets/js/safety-pin.js') ?: time();
?>
<!-- Safety PIN before any Edit / Delete (see includes/safety_pin.php) -->
<script src="<?php echo url('assets/js/safety-pin.js'); ?>?v=<?php echo $safetyPinJsVersion; ?>"></script>
<script src="<?php echo url('assets/js/app.js'); ?>?v=<?php echo $appJsVersion; ?>"></script>
<!-- Recursive Action Buttons (see includes/action_buttons_helper.php) -->
<script src="<?php echo url('assets/js/action-buttons.js'); ?>?v=<?php echo $actionButtonsJsVersion; ?>"></script>
<!-- UI v2: toasts, confirm, command palette (Ctrl+K), theme & accent menu -->
<script src="<?php echo url('assets/js/ui-plus.js'); ?>?v=<?php echo @filemtime(__DIR__ . '/../assets/js/ui-plus.js') ?: time(); ?>"></script>

<!-- PWA: Service Worker Registration -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        var base = window.BASE_PATH || '';
        navigator.serviceWorker.register(base + '/service-worker.js', { scope: base + '/' })
            .catch(function (err) { console.warn('SW registration failed:', err); });
    });
}
</script>


<!-- Error Display (Debug Mode) -->
<?php 
if (isset($_GET['_debug']) && class_exists('ApplicationErrorHandler') && ApplicationErrorHandler::hasErrors()) {
    $errors = ApplicationErrorHandler::getErrors();
    if (!empty($errors)) {
        echo '<div style="position: fixed; bottom: 20px; right: 20px; max-width: 400px; background: #ffebee; border: 2px solid #f44336; padding: 15px; border-radius: 4px; color: #c62828; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto; z-index: 10000;">';
        echo '<strong>⚠️ Errors Found (' . count($errors) . ')</strong><br><br>';
        foreach ($errors as $error) {
            echo '<div style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #f44336;">';
            echo '<strong>' . htmlspecialchars($error['type']) . '</strong><br>';
            echo htmlspecialchars($error['message']) . '<br>';
            echo '<small>' . htmlspecialchars($error['file']) . ':' . $error['line'] . '</small>';
            echo '</div>';
        }
        echo '</div>';
    }
}
?>

</body>
</html>
<?php
// Flush output buffer started in header.php
if (ob_get_level()) {
    ob_end_flush();
}
?>
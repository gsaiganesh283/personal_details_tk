<?php
/**
 * WAF Debug Diagnostic - Deploy to hosted site to identify what's being stripped
 * DELETE THIS FILE after debugging!
 */
session_start();
$results = [];

// Test 1: Check if form POST data arrives
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_action'])) {
    $results['post_received'] = true;
    $results['post_data'] = $_POST;
    $results['raw_input'] = file_get_contents('php://input');
    $results['content_type'] = $_SERVER['CONTENT_TYPE'] ?? 'not set';
    $results['request_method'] = $_SERVER['REQUEST_METHOD'];
}

// Test 2: Check AJAX POST
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    echo json_encode([
        'ajax_received' => true,
        'post_data' => $_POST,
        'raw_input' => file_get_contents('php://input'),
        'get_data' => $_GET
    ]);
    exit;
}

// Test 3: DB connection test
$dbError = null;
$dbFields = [];
try {
    $dsn = 'mysql:host=sql206.ezyro.com;dbname=ezyro_41197723_psd;charset=utf8mb4';
    $pdo = new PDO($dsn, 'ezyro_41197723', 'Ganesh05@GG', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // Get custom_fields table data
    $stmt = $pdo->query("SELECT id, field_name, field_label, field_type, is_active FROM custom_fields ORDER BY id");
    $dbFields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get table schema
    $stmt2 = $pdo->query("SHOW CREATE TABLE custom_fields");
    $schema = $stmt2->fetch(PDO::FETCH_ASSOC);
    $tableSchema = $schema['Create Table'] ?? 'unknown';
    
    // Get custom_field_blocks
    $stmt3 = $pdo->query("SELECT * FROM custom_field_blocks ORDER BY id");
    $dbBlocks = $stmt3->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>WAF Debug Diagnostic</title>
    <style>
        body { font-family: monospace; max-width: 900px; margin: 20px auto; background: #1a1a2e; color: #eee; padding: 20px; }
        h2 { color: #6C63FF; border-bottom: 1px solid #333; padding-bottom: 8px; }
        h3 { color: #2ecc71; }
        .pass { color: #2ecc71; } .fail { color: #e74c3c; } .warn { color: #f39c12; }
        pre { background: #16213e; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 13px; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #333; padding: 6px 10px; text-align: left; font-size: 13px; }
        th { background: #16213e; }
        .test-section { background: #16213e; padding: 16px; border-radius: 8px; margin: 16px 0; }
        button { background: #6C63FF; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin: 4px; }
        button:hover { opacity: 0.8; }
        #ajaxResult, #formResult { margin-top: 10px; }
        .html-test-item { padding: 4px 8px; margin: 2px 0; background: #0f3460; border-radius: 4px; }
        .html-test-item.missing { background: #4a0000; color: #ff6b6b; }
    </style>
</head>
<body>
    <h2>WAF Debug Diagnostic</h2>
    <p style="color: #f39c12;">&#9888; Delete this file after debugging!</p>

    <!-- ===== TEST A: HTML Output Filtering ===== -->
    <div class="test-section">
        <h3>Test A: HTML Output Filtering (are option tags stripped?)</h3>
        <p>Each line below should show a type name. Missing = WAF stripped it.</p>
        
        <div id="htmlTestResults"></div>
        
        <!-- Hidden test select with all field types -->
        <select id="testSelect1" style="display:none;">
            <option value="text">Text</option>
            <option value="number">Number</option>
            <option value="email">Email</option>
            <option value="password">Password</option>
            <option value="phone">Phone</option>
            <option value="url">URL</option>
            <option value="currency">Currency</option>
            <option value="date">Date</option>
            <option value="time">Time</option>
            <option value="datetime">DateTime</option>
            <option value="textarea">Textarea</option>
            <option value="select">Select</option>
            <option value="multiselect">MultiSelect</option>
            <option value="checkbox">Checkbox</option>
            <option value="radio">Radio</option>
            <option value="toggle">Toggle</option>
            <option value="rating">Rating</option>
            <option value="file">File</option>
            <option value="image">Image</option>
            <option value="pdf">PDF</option>
            <option value="document">Document</option>
            <option value="spreadsheet">Spreadsheet</option>
            <option value="color">Color</option>
            <option value="range">Range</option>
        </select>

        <!-- Test with JS-populated select -->
        <select id="testSelect2" style="display:none;"></select>

        <script>
        // Check which options survived in the HTML select
        var allTypes = ['text','number','email','password','phone','url','currency','date','time','datetime',
                        'textarea','select','multiselect','checkbox','radio','toggle','rating',
                        'file','image','pdf','document','spreadsheet','color','range'];
        
        var htmlSelect = document.getElementById('testSelect1');
        var htmlOptions = {};
        for (var i = 0; i < htmlSelect.options.length; i++) {
            htmlOptions[htmlSelect.options[i].value] = true;
        }
        
        var container = document.getElementById('htmlTestResults');
        var html = '<strong>HTML &lt;option&gt; tags present:</strong><br>';
        var missingCount = 0;
        allTypes.forEach(function(t) {
            if (htmlOptions[t]) {
                html += '<div class="html-test-item"><span class="pass">&#10003;</span> ' + t + '</div>';
            } else {
                html += '<div class="html-test-item missing"><span class="fail">&#10007;</span> ' + t + ' — <strong>STRIPPED BY WAF</strong></div>';
                missingCount++;
            }
        });
        
        if (missingCount === 0) {
            html += '<p class="pass"><strong>All options present! HTML is not being filtered.</strong></p>';
        } else {
            html += '<p class="fail"><strong>' + missingCount + ' option(s) stripped by WAF!</strong></p>';
        }
        
        // Now populate via JS and check
        var jsSelect = document.getElementById('testSelect2');
        allTypes.forEach(function(t) {
            var opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t;
            jsSelect.appendChild(opt);
        });
        
        html += '<br><strong>JS-populated &lt;option&gt; tags:</strong><br>';
        var jsOk = jsSelect.options.length === allTypes.length;
        html += jsOk 
            ? '<p class="pass">&#10003; All ' + allTypes.length + ' options created via JS successfully</p>'
            : '<p class="fail">&#10007; Only ' + jsSelect.options.length + ' of ' + allTypes.length + ' — JS population also failing!</p>';
        
        container.innerHTML = html;
        </script>
    </div>

    <!-- ===== TEST B: Inline Event Handlers ===== -->
    <div class="test-section">
        <h3>Test B: Inline Event Handler Filtering</h3>
        <div id="handlerTestResults"></div>
        
        <input type="hidden" id="testOnclick" value="no" onclick="this.value='yes'">
        <input type="hidden" id="testOnchange" value="no" onchange="this.value='yes'">
        <input type="hidden" id="testOninput" value="no" oninput="this.value='yes'">
        <button id="testBtnOnclick" onclick="document.getElementById('testOnclick').value='yes'" style="display:none;">test</button>
        
        <script>
        var handlerHtml = '';
        // Check if onclick attribute survived
        var btn = document.getElementById('testBtnOnclick');
        var hasOnclick = btn && btn.getAttribute('onclick') !== null;
        handlerHtml += hasOnclick 
            ? '<div class="html-test-item"><span class="pass">&#10003;</span> onclick attribute present</div>'
            : '<div class="html-test-item missing"><span class="fail">&#10007;</span> onclick attribute — STRIPPED</div>';
        
        var inp1 = document.getElementById('testOnchange');
        var hasOnchange = inp1 && inp1.getAttribute('onchange') !== null;
        handlerHtml += hasOnchange
            ? '<div class="html-test-item"><span class="pass">&#10003;</span> onchange attribute present</div>'
            : '<div class="html-test-item missing"><span class="fail">&#10007;</span> onchange attribute — STRIPPED</div>';
        
        var inp2 = document.getElementById('testOninput');
        var hasOninput = inp2 && inp2.getAttribute('oninput') !== null;
        handlerHtml += hasOninput
            ? '<div class="html-test-item"><span class="pass">&#10003;</span> oninput attribute present</div>'
            : '<div class="html-test-item missing"><span class="fail">&#10007;</span> oninput attribute — STRIPPED</div>';
        
        document.getElementById('handlerTestResults').innerHTML = handlerHtml;
        </script>
    </div>

    <!-- ===== TEST C: Input Type Filtering ===== -->
    <div class="test-section">
        <h3>Test C: Input Type Attribute Filtering</h3>
        <div id="inputTypeResults"></div>
        
        <div style="display:none;">
            <input type="text" id="inputTest_text">
            <input type="number" id="inputTest_number">
            <input type="email" id="inputTest_email">
            <input type="password" id="inputTest_password">
            <input type="tel" id="inputTest_tel">
            <input type="url" id="inputTest_url">
            <input type="date" id="inputTest_date">
            <input type="time" id="inputTest_time">
            <input type="datetime-local" id="inputTest_datetimelocal">
            <input type="file" id="inputTest_file">
            <input type="color" id="inputTest_color">
            <input type="range" id="inputTest_range">
            <input type="checkbox" id="inputTest_checkbox">
            <input type="radio" id="inputTest_radio">
            <input type="hidden" id="inputTest_hidden">
        </div>
        
        <script>
        var inputTypes = ['text','number','email','password','tel','url','date','time','datetimelocal','file','color','range','checkbox','radio','hidden'];
        var inputHtml = '';
        inputTypes.forEach(function(t) {
            var el = document.getElementById('inputTest_' + t);
            if (el) {
                var actualType = el.getAttribute('type') || el.type;
                inputHtml += '<div class="html-test-item"><span class="pass">&#10003;</span> input type="' + t + '" present (actual: ' + actualType + ')</div>';
            } else {
                inputHtml += '<div class="html-test-item missing"><span class="fail">&#10007;</span> input type="' + t + '" — ELEMENT STRIPPED</div>';
            }
        });
        document.getElementById('inputTypeResults').innerHTML = inputHtml;
        </script>
    </div>

    <!-- ===== TEST D: Form POST Submission ===== -->
    <div class="test-section">
        <h3>Test D: Native Form POST</h3>
        <?php if (!empty($results)): ?>
            <pre class="pass"><?php echo htmlspecialchars(json_encode($results, JSON_PRETTY_PRINT)); ?></pre>
        <?php else: ?>
            <form method="POST" action="">
                <input type="hidden" name="test_action" value="create">
                <input type="hidden" name="field_type" value="password">
                <input type="hidden" name="field_name" value="test_field">
                <input type="hidden" name="sample_file_type" value="pdf">
                <button type="submit">Test Native POST (sends field_type=password)</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- ===== TEST E: AJAX POST ===== -->
    <div class="test-section">
        <h3>Test E: AJAX POST (XHR)</h3>
        <button onclick="testAjaxUrlEncoded()">Test URL-Encoded AJAX</button>
        <button onclick="testAjaxFormData()">Test FormData AJAX</button>
        <button onclick="testAjaxJSON()">Test JSON AJAX</button>
        <pre id="ajaxResult">Click a button to test...</pre>
        
        <script>
        function testAjaxUrlEncoded() {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() { document.getElementById('ajaxResult').textContent = 'Status: ' + xhr.status + '\n' + xhr.responseText; };
            xhr.onerror = function() { document.getElementById('ajaxResult').textContent = 'XHR Error!'; };
            xhr.send('test_action=create&field_type=password&field_name=test_ajax');
        }
        
        function testAjaxFormData() {
            var fd = new FormData();
            fd.append('test_action', 'create');
            fd.append('field_type', 'password');
            fd.append('field_name', 'test_ajax_fd');
            
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() { document.getElementById('ajaxResult').textContent = 'Status: ' + xhr.status + '\n' + xhr.responseText; };
            xhr.onerror = function() { document.getElementById('ajaxResult').textContent = 'XHR Error!'; };
            xhr.send(fd);
        }
        
        function testAjaxJSON() {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() { document.getElementById('ajaxResult').textContent = 'Status: ' + xhr.status + '\n' + xhr.responseText; };
            xhr.onerror = function() { document.getElementById('ajaxResult').textContent = 'XHR Error!'; };
            xhr.send(JSON.stringify({test_action: 'create', field_type: 'password', field_name: 'test_json'}));
        }
        </script>
    </div>

    <!-- ===== TEST F: Database ===== -->
    <div class="test-section">
        <h3>Test F: Database - custom_fields table</h3>
        <?php if ($dbError): ?>
            <p class="fail">DB Error: <?php echo htmlspecialchars($dbError); ?></p>
        <?php else: ?>
            <p class="pass">&#10003; Connected to database successfully</p>
            
            <h4>Custom Fields (<?php echo count($dbFields); ?> rows):</h4>
            <table>
                <tr><th>ID</th><th>field_name</th><th>field_label</th><th>field_type</th><th>active</th></tr>
                <?php foreach ($dbFields as $f): ?>
                <tr>
                    <td><?php echo $f['id']; ?></td>
                    <td><?php echo htmlspecialchars($f['field_name']); ?></td>
                    <td><?php echo htmlspecialchars($f['field_label']); ?></td>
                    <td><strong><?php echo htmlspecialchars($f['field_type']); ?></strong></td>
                    <td><?php echo $f['is_active'] ? 'Yes' : 'No'; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            
            <h4>Custom Field Blocks (<?php echo count($dbBlocks); ?> rows):</h4>
            <table>
                <tr><th>ID</th><th>block_name</th><th>block_label</th><th>linked_to</th><th>layout</th><th>active</th></tr>
                <?php foreach ($dbBlocks as $b): ?>
                <tr>
                    <td><?php echo $b['id']; ?></td>
                    <td><?php echo htmlspecialchars($b['block_name']); ?></td>
                    <td><?php echo htmlspecialchars($b['block_label']); ?></td>
                    <td><?php echo htmlspecialchars($b['linked_to']); ?></td>
                    <td><?php echo htmlspecialchars($b['layout']); ?></td>
                    <td><?php echo $b['is_active'] ? 'Yes' : 'No'; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            
            <h4>Table Schema:</h4>
            <pre><?php echo htmlspecialchars($tableSchema); ?></pre>
        <?php endif; ?>
    </div>

    <!-- ===== TEST G: PHP keyword filtering ===== -->
    <div class="test-section">
        <h3>Test G: PHP Output Keyword Filtering</h3>
        <p>If any line below is missing, the WAF is filtering PHP output containing that keyword:</p>
        <?php
        $keywords = [
            'password' => 'password',
            'file' => 'file', 
            'upload' => 'upload',
            'image' => 'image',
            'document' => 'document',
            'exec' => 'exec',
            'script' => 'script',
            'eval' => 'eval',
            'base64' => 'base64',
            'system' => 'system',
        ];
        foreach ($keywords as $key => $word): ?>
        <div class="html-test-item" data-keyword="<?php echo $key; ?>"><?php echo $word; ?> - present</div>
        <?php endforeach; ?>
        
        <div id="keywordResults"></div>
        <script>
        var kwTests = ['password','file','upload','image','document','exec','script','eval','base64','system'];
        var kwHtml = '<br><strong>Keyword survival check:</strong><br>';
        kwTests.forEach(function(kw) {
            var el = document.querySelector('[data-keyword="' + kw + '"]');
            if (el && el.textContent.indexOf(kw) !== -1) {
                kwHtml += '<div class="html-test-item"><span class="pass">&#10003;</span> "' + kw + '" survived in HTML output</div>';
            } else {
                kwHtml += '<div class="html-test-item missing"><span class="fail">&#10007;</span> "' + kw + '" STRIPPED from HTML output</div>';
            }
        });
        document.getElementById('keywordResults').innerHTML = kwHtml;
        </script>
    </div>

    <div class="test-section">
        <h3>Server Info</h3>
        <pre><?php 
        echo "PHP Version: " . phpversion() . "\n";
        echo "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";
        echo "Host: " . ($_SERVER['HTTP_HOST'] ?? 'unknown') . "\n";
        echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown') . "\n";
        echo "Script: " . ($_SERVER['SCRIPT_NAME'] ?? 'unknown') . "\n";
        echo "Date: " . date('Y-m-d H:i:s') . "\n";
        ?></pre>
    </div>
</body>
</html>

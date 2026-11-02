<?php
/**
 * Test Form Submission
 * Tests if the personal details form can be submitted correctly
 */

$pageTitle = 'Test Form Submission';
require_once __DIR__ . '/includes/header.php';

$user = requireAuth();
$db = Database::getInstance();

?>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-flask"></i> Form Submission Test</h2>
    </div>
    
    <div style="padding: 20px;">
        <h3>Test 1: Verify url() function works</h3>
        <p><strong>url('personal-details.php'):</strong> 
            <code style="background: var(--bg-secondary); padding: 4px 8px; border-radius: 4px;">
                <?php echo htmlspecialchars(url('personal-details.php')); ?>
            </code>
        </p>
        
        <hr style="margin: 20px 0; border: none; border-top: 1px solid var(--border);">
        
        <h3>Test 2: Check database connection</h3>
        <?php
        try {
            $result = $db->fetch("SELECT COUNT(*) as count FROM personal_details WHERE user_id = ?", [$user['id']]);
            echo "<p style='color: var(--success);'><i class='fas fa-check'></i> Database connected successfully. Your existing records: <strong>" . $result['count'] . "</strong></p>";
        } catch (Exception $e) {
            echo "<p style='color: var(--danger);'><i class='fas fa-times'></i> Database error: " . $e->getMessage() . "</p>";
        }
        ?>
        
        <hr style="margin: 20px 0; border: none; border-top: 1px solid var(--border);">
        
        <h3>Test 3: Test form submission (Add a test record)</h3>
        <form method="POST" action="<?php echo url('personal-details.php'); ?>" style="border: 1px solid var(--border); padding: 16px; border-radius: 8px;">
            <div class="form-group" style="margin-bottom: 16px;">
                <label>First Name <span class="required">*</span></label>
                <input type="text" name="first_name" placeholder="Test First Name" value="Test" required style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg-secondary); color: var(--text);">
            </div>
            
            <div class="form-group" style="margin-bottom: 16px;">
                <label>Last Name <span class="required">*</span></label>
                <input type="text" name="last_name" placeholder="Test Last Name" value="Record" required style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg-secondary); color: var(--text);">
            </div>
            
            <div class="form-group" style="margin-bottom: 16px;">
                <label>Email</label>
                <input type="email" name="detail_email" placeholder="test@example.com" value="test@example.com" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg-secondary); color: var(--text);">
            </div>
            
            <div class="form-group" style="margin-bottom: 16px;">
                <label>Phone</label>
                <input type="tel" name="phone" placeholder="1234567890" value="1234567890" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg-secondary); color: var(--text);">
            </div>
            
            <div style="display: flex; gap: 12px;">
                <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">
                    <i class="fas fa-save"></i> Submit Test Form
                </button>
                <a href="<?php echo url('personal-details.php'); ?>" class="btn btn-secondary" style="padding: 10px 20px; text-decoration: none; display: inline-block;">
                    Cancel
                </a>
            </div>
        </form>
        
        <hr style="margin: 20px 0; border: none; border-top: 1px solid var(--border);">
        
        <h3>Test 4: Check if form action attribute is correct</h3>
        <p>The form above should submit to: <code style="background: var(--bg-secondary); padding: 4px 8px; border-radius: 4px;">
            <?php echo htmlspecialchars(url('personal-details.php')); ?>
        </code></p>
        <p style="color: var(--text-secondary); font-size: 0.9rem;">When you click "Submit Test Form", it should add a test record to the database and redirect back to the personal-details.php page showing the newly added record.</p>
        
        <hr style="margin: 20px 0; border: none; border-top: 1px solid var(--border);">
        
        <h3>Test 5: JavaScript console check</h3>
        <p>Open your browser's Developer Tools (F12) and check the Console tab for any errors.</p>
        <p style="color: var(--text-secondary); font-size: 0.9rem;">Common issues:</p>
        <ul style="color: var(--text-secondary); margin-left: 20px;">
            <li>CORS errors - check if assets load correctly</li>
            <li>JavaScript errors in app.js - check function definitions</li>
            <li>Network errors - check if form submission reaches the server</li>
        </ul>
        
        <hr style="margin: 20px 0; border: none; border-top: 1px solid var(--border);">
        
        <h3>Test 6: Check BASE_PATH constant</h3>
        <p><strong>BASE_PATH value:</strong> 
            <code style="background: var(--bg-secondary); padding: 4px 8px; border-radius: 4px;">
                <?php echo htmlspecialchars(defined('BASE_PATH') ? BASE_PATH : 'NOT DEFINED'); ?>
            </code>
        </p>
        <p style="color: var(--text-secondary); font-size: 0.9rem;">BASE_PATH should be empty for root domain or '/subdirectory' for subdomain installations.</p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

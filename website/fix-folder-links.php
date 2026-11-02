<?php
/**
 * One-time admin repair tool for the "Other Details" folder/custom-field
 * mismatch: custom_fields.linked_to / custom_field_blocks.linked_to /
 * custom_field_entries.linked_to store a bare 'other_folder_<id>' string
 * with no foreign key back to other_detail_folders.id. If a folder's id
 * ever got reused (deleted outside the app, or an id was skipped when it
 * was created), its old fields silently reattach to whichever folder now
 * holds that id. This page lists every such group next to its fields and
 * lets you manually re-point it to the correct current folder, or delete
 * it if it's genuinely stale. Delete this file once you're done with it.
 */
$pageTitle = 'Fix Folder Links';
require_once __DIR__ . '/includes/header.php';
$user = requireAuth();
$db = Database::getInstance();

$folders = $db->fetchAll("SELECT id, folder_name FROM other_detail_folders WHERE user_id = ? ORDER BY id", [$user['id']]);
$folderById = [];
foreach ($folders as $f) {
    $folderById[(int)$f['id']] = $f['folder_name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['fix_action'])) {
    $oldLinked = sanitize($_POST['old_linked'] ?? '');
    if (!preg_match('/^other_folder_\d+$/', $oldLinked)) {
        setFlash('error', 'Invalid group.');
        redirect('/fix-folder-links.php');
    }

    if ($_POST['fix_action'] === 'reassign') {
        $targetId = (int)($_POST['target_folder'] ?? 0);
        if (!isset($folderById[$targetId])) {
            setFlash('error', 'Pick a valid target folder.');
            redirect('/fix-folder-links.php');
        }
        $newLinked = 'other_folder_' . $targetId;
        $db->update("UPDATE custom_fields SET linked_to = ? WHERE linked_to = ? AND user_id = ?", [$newLinked, $oldLinked, $user['id']]);
        $db->update("UPDATE custom_field_blocks SET linked_to = ? WHERE linked_to = ? AND user_id = ?", [$newLinked, $oldLinked, $user['id']]);
        $db->update("UPDATE custom_field_entries SET linked_to = ? WHERE linked_to = ? AND user_id = ?", [$newLinked, $oldLinked, $user['id']]);
        setFlash('success', "Re-pointed $oldLinked to {$folderById[$targetId]} (id $targetId).");
    } elseif ($_POST['fix_action'] === 'delete_group') {
        $db->delete("DELETE FROM custom_field_entries WHERE linked_to = ? AND user_id = ?", [$oldLinked, $user['id']]);
        $db->delete("DELETE FROM custom_fields WHERE linked_to = ? AND user_id = ?", [$oldLinked, $user['id']]);
        $db->delete("DELETE FROM custom_field_blocks WHERE linked_to = ? AND user_id = ?", [$oldLinked, $user['id']]);
        setFlash('success', "Deleted all fields/blocks/entries for $oldLinked.");
    }
    redirect('/fix-folder-links.php');
}

// Group every custom_fields row (and block-only rows) by its raw linked_to value.
$fieldRows = $db->fetchAll(
    "SELECT id, field_label, field_name, linked_to, created_at FROM custom_fields
     WHERE user_id = ? AND linked_to LIKE 'other_folder_%' ORDER BY linked_to, field_order",
    [$user['id']]
);
$blockRows = $db->fetchAll(
    "SELECT id, block_label, linked_to, created_at FROM custom_field_blocks
     WHERE user_id = ? AND linked_to LIKE 'other_folder_%' ORDER BY linked_to, block_order",
    [$user['id']]
);

$groups = [];
foreach ($fieldRows as $r) {
    $groups[$r['linked_to']]['fields'][] = $r;
}
foreach ($blockRows as $r) {
    $groups[$r['linked_to']]['blocks'][] = $r;
}
ksort($groups);
?>

<div class="card">
    <h2>Fix Folder Links</h2>
    <p>Each group below is everything currently saved under one <code>other_folder_&lt;id&gt;</code> tag.
       Use the dropdown to point a group at the folder its fields actually belong to, based on the field names and dates.
       Delete this file (<code>fix-folder-links.php</code>) from the server once you're done.</p>

    <?php if (empty($groups)): ?>
        <p>No <code>other_folder_*</code> custom fields found.</p>
    <?php endif; ?>

    <?php foreach ($groups as $linkedTo => $data):
        $folderId = (int)substr($linkedTo, strlen('other_folder_'));
        $currentFolderName = $folderById[$folderId] ?? null;
    ?>
        <div style="border:1px solid var(--border); border-radius:8px; padding:16px; margin-bottom:16px;">
            <h3 style="margin-top:0;">
                <?php echo htmlspecialchars($linkedTo); ?>
                &mdash;
                <?php if ($currentFolderName): ?>
                    currently resolves to <strong><?php echo htmlspecialchars($currentFolderName); ?></strong> (id <?php echo $folderId; ?>)
                <?php else: ?>
                    <span style="color: var(--danger, #e74c3c);">ORPHAN &mdash; folder id <?php echo $folderId; ?> no longer exists</span>
                <?php endif; ?>
            </h3>

            <table style="width:100%; border-collapse: collapse; margin-bottom: 12px;">
                <thead>
                    <tr><th style="text-align:left;">Type</th><th style="text-align:left;">Label</th><th style="text-align:left;">Created</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($data['fields'] ?? [] as $f): ?>
                        <tr>
                            <td>Field</td>
                            <td><?php echo htmlspecialchars($f['field_label']); ?> (<?php echo htmlspecialchars($f['field_name']); ?>)</td>
                            <td><?php echo htmlspecialchars($f['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($data['blocks'] ?? [] as $b): ?>
                        <tr>
                            <td>Block</td>
                            <td><?php echo htmlspecialchars($b['block_label']); ?></td>
                            <td><?php echo htmlspecialchars($b['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="POST" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="fix_action" value="reassign">
                <input type="hidden" name="old_linked" value="<?php echo htmlspecialchars($linkedTo); ?>">
                <label>Move this group to:
                    <select name="target_folder" required>
                        <option value="">-- choose folder --</option>
                        <?php foreach ($folders as $f): ?>
                            <option value="<?php echo (int)$f['id']; ?>" <?php echo ((int)$f['id'] === $folderId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($f['folder_name']); ?> (id <?php echo (int)$f['id']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary btn-sm">Reassign</button>
            </form>
            <form method="POST" onsubmit="return confirm('Permanently delete all fields/blocks/entries in this group? This cannot be undone.');" style="margin-top:4px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="fix_action" value="delete_group">
                <input type="hidden" name="old_linked" value="<?php echo htmlspecialchars($linkedTo); ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete this group</button>
            </form>
        </div>
    <?php endforeach; ?>

    <h3>All current folders (id 2&ndash;<?php echo count($folders) ? (int)end($folders)['id'] : 0; ?>), including empty ones</h3>
    <p>This is the same data grouped the other way round: one row per <strong>folder that actually exists today</strong>,
       so you can see at a glance which folders have no fields at all (candidates to receive a reassigned group above).</p>
    <table style="width:100%; border-collapse: collapse; margin-bottom: 24px;">
        <thead>
            <tr><th style="text-align:left;">Folder</th><th style="text-align:left;">Fields currently attached (exact match)</th></tr>
        </thead>
        <tbody>
            <?php foreach ($folders as $f):
                $fid = (int)$f['id'];
                $linked = $groups['other_folder_' . $fid] ?? null;
                $labels = [];
                foreach ($linked['fields'] ?? [] as $fr) { $labels[] = $fr['field_label']; }
                foreach ($linked['blocks'] ?? [] as $br) { $labels[] = $br['block_label'] . ' (block)'; }
            ?>
                <tr style="border-top:1px solid var(--border);">
                    <td style="padding:4px 8px 4px 0; white-space:nowrap;">id <?php echo $fid; ?> &mdash; <strong><?php echo htmlspecialchars($f['folder_name']); ?></strong></td>
                    <td style="padding:4px 0;">
                        <?php if ($labels): ?>
                            <?php echo htmlspecialchars(implode(', ', $labels)); ?>
                        <?php else: ?>
                            <em style="color: var(--text-muted, #888);">— empty —</em>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Current folders (for reference)</h3>
    <ul>
        <?php foreach ($folders as $f): ?>
            <li>id <?php echo (int)$f['id']; ?> &mdash; <?php echo htmlspecialchars($f['folder_name']); ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

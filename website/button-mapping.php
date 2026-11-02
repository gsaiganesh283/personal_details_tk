<?php
/**
 * Mapping: one place to see how buttons, fields, blocks and saved values
 * hang together -- and to repair anything whose link points at something
 * that no longer exists (these links are plain strings/ids with no database
 * foreign key, so a missed cascade leaves them dangling and invisible):
 *
 *   - a button attached to a missing folder/button  -> attach it elsewhere
 *   - fields/blocks of a missing folder/button      -> move them, or delete
 *   - fields grouped in a block that's gone or
 *     belongs to another module                     -> ungroup them
 *   - saved values whose field or record is gone    -> delete the leftovers
 *
 * Every change bumps updated_at, so the desktop app picks it up on its next
 * sync. Nothing here deletes anything without an explicit click + confirm.
 */
$pageTitle = 'Mapping';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/custom_fields_helper.php';
require_once __DIR__ . '/includes/action_buttons_helper.php';
require_once __DIR__ . '/includes/fields_engine.php';
$user = requireAuth();
$db = Database::getInstance();
ensureActionButtonTables();
feEnsureSchema();

$moduleLabels = getModuleLabels();
// getModuleLabels() lists active folders only -- a button or field on a
// hidden folder is still correctly linked, so don't flag it.
foreach ($db->fetchAll(
    "SELECT id, folder_name FROM other_detail_folders WHERE user_id = ? AND is_active = 0", [$user['id']]
) as $folder) {
    $moduleLabels['other_folder_' . $folder['id']] = '📁 ' . $folder['folder_name'] . ' (inactive)';
}

$buttons = $db->fetchAll(
    "SELECT * FROM action_buttons WHERE user_id = ? ORDER BY parent_module ASC, button_order ASC, id ASC",
    [$user['id']]
);

function bmSubtreeModules($buttonId, array $buttons) {
    $modules = [actionButtonModule($buttonId)];
    for ($i = 0; $i < count($modules); $i++) {
        foreach ($buttons as $b) {
            if ($b['parent_module'] === $modules[$i]) {
                $modules[] = actionButtonModule($b['id']);
            }
        }
    }
    return $modules;
}

function bmLabel($module, array $moduleLabels) {
    return $moduleLabels[$module] ?? $module;
}

// ------------------------------------------------------------------ actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['map_action'])) {
    $action = $_POST['map_action'];
    try {
        if ($action === 'attach_button') {
            $buttonId = (int)($_POST['button_id'] ?? 0);
            $target = sanitize($_POST['target_module'] ?? '');
            if (!isset($moduleLabels[$target])) {
                setFlash('error', 'Pick a valid module to attach the button to.');
            } elseif (attachActionButton($buttonId, $target, $user['id'])) {
                setFlash('success', 'Button attached to ' . bmLabel($target, $moduleLabels) . '.');
            } else {
                setFlash('error', "A button can't be attached to itself or to a button nested inside it.");
            }
        } elseif ($action === 'reassign_module' || $action === 'delete_module') {
            $oldModule = sanitize($_POST['old_module'] ?? '');
            if (!preg_match('/^(orphan_)*(action_btn|other_folder)_\d+$/', $oldModule) || feModuleExists($oldModule, $user['id'])) {
                setFlash('error', 'Invalid group.');
            } elseif ($action === 'reassign_module') {
                $target = sanitize($_POST['target_module'] ?? '');
                if (!isset($moduleLabels[$target]) || !feModuleExists($target, $user['id'])) {
                    setFlash('error', 'Pick a valid module.');
                } else {
                    feReassignModule($user['id'], $oldModule, $target);
                    setFlash('success', 'Moved the fields and blocks to ' . bmLabel($target, $moduleLabels) . '.');
                }
            } else {
                feTransaction(function () use ($oldModule, $user) {
                    feDeleteModuleContents($oldModule, $user['id']);
                });
                setFlash('success', 'Deleted the fields, blocks and values left behind.');
            }
        } elseif ($action === 'ungroup_fields') {
            $n = feUngroupMisgroupedFields($user['id']);
            setFlash('success', "Ungrouped $n field(s); their saved values were kept.");
        } elseif ($action === 'delete_leftovers') {
            $module = sanitize($_POST['module'] ?? '');
            $n = feDeleteLeftoverValues($user['id'], $module);
            setFlash('success', "Deleted $n leftover value(s).");
        } else {
            setFlash('error', 'Invalid action.');
        }
    } catch (Throwable $e) {
        setFlash('error', 'Could not apply that change: ' . $e->getMessage());
    }
    redirect('/button-mapping.php');
}

// --------------------------------------------------------------------- scan
$broken = feFindBrokenLinks($user['id']);
$leftovers = feFindLeftoverValues($user['id']);
$brokenButtonIds = array_flip(array_map('intval', array_column($broken['buttons'], 'id')));

$countBy = function ($sql) use ($db, $user) {
    $out = [];
    foreach ($db->fetchAll($sql, [$user['id']]) as $r) {
        $out[$r['k']] = (int)$r['n'];
    }
    return $out;
};
$fieldCounts = $countBy("SELECT linked_to AS k, COUNT(*) AS n FROM custom_fields WHERE user_id = ? GROUP BY linked_to");
$recordCounts = $countBy("SELECT button_id AS k, COUNT(*) AS n FROM action_button_records WHERE user_id = ? GROUP BY button_id");

// Fields + blocks of missing folders/buttons, grouped by the missing module.
$orphanGroups = [];
foreach ($broken['fields'] as $f) {
    $orphanGroups[$f['linked_to']]['fields'][] = $f;
}
foreach ($broken['blocks'] as $b) {
    $orphanGroups[$b['linked_to']]['blocks'][] = $b;
}
ksort($orphanGroups);

$leftoverTotal = 0;
foreach ($leftovers as $counts) {
    $leftoverTotal += ($counts['field_gone'] ?? 0) + ($counts['record_gone'] ?? 0);
}
$issueCount = count($broken['buttons']) + count($orphanGroups) + count($broken['field_blocks']) + ($leftoverTotal ? 1 : 0);
?>

<style>
.bm-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; margin-bottom: 24px; }
.bm-stat { padding: 18px 20px; }
.bm-stat .num { font-size: 1.8rem; font-weight: 700; line-height: 1.1; }
.bm-stat .lbl { color: var(--text-secondary); font-size: 0.85rem; margin-top: 4px; }
.bm-stat.bad .num { color: var(--danger); }
.bm-stat.ok .num { color: var(--success); }
.bm-attach, .bm-actions form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0; }
.bm-attach select, .bm-actions select { min-width: 200px; max-width: 280px; }
.bm-missing { color: var(--danger); font-weight: 600; }
.bm-muted { color: var(--text-muted); font-size: 0.85rem; }
.bm-group { border: 1px solid var(--border); border-radius: 10px; padding: 16px; margin-top: 16px; }
.bm-group h3 { margin: 0 0 10px; font-size: 1rem; }
.bm-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
.bm-chip { background: var(--bg-tertiary, rgba(127,127,127,.12)); border-radius: 999px; padding: 3px 10px; font-size: 0.82rem; }
.bm-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.bm-ok { text-align: center; color: var(--text-muted); padding: 24px; }
@media (max-width: 700px) { .bm-attach select, .bm-actions select { min-width: 0; width: 100%; } }
</style>

<div class="toolbar">
    <div class="toolbar-left">
        <p style="margin: 0; color: var(--text-secondary);">
            How buttons, fields, blocks and saved values are linked -- and anything that has lost its link.
        </p>
    </div>
    <div class="toolbar-right">
        <a href="<?php echo url('button-mapping.php'); ?>" class="btn btn-secondary"><i class="fas fa-sync"></i> Refresh</a>
        <a href="<?php echo url('custom-fields.php'); ?>" class="btn btn-secondary"><i class="fas fa-puzzle-piece"></i> Custom Fields</a>
    </div>
</div>

<div class="bm-stats">
    <div class="card bm-stat"><div class="num"><?php echo count($buttons); ?></div><div class="lbl">Action buttons</div></div>
    <div class="card bm-stat <?php echo $broken['buttons'] ? 'bad' : 'ok'; ?>"><div class="num"><?php echo count($broken['buttons']); ?></div><div class="lbl">Buttons on a missing module</div></div>
    <div class="card bm-stat <?php echo $orphanGroups ? 'bad' : 'ok'; ?>"><div class="num"><?php echo count($broken['fields']) + count($broken['blocks']); ?></div><div class="lbl">Fields/blocks of a missing folder or button</div></div>
    <div class="card bm-stat <?php echo $broken['field_blocks'] ? 'bad' : 'ok'; ?>"><div class="num"><?php echo count($broken['field_blocks']); ?></div><div class="lbl">Fields in a wrong or missing block</div></div>
    <div class="card bm-stat <?php echo $leftoverTotal ? 'bad' : 'ok'; ?>"><div class="num"><?php echo $leftoverTotal; ?></div><div class="lbl">Leftover values</div></div>
</div>

<!-- Buttons -->
<div class="card animate-fade-in">
    <div class="card-header">
        <h2><i class="fas fa-sitemap" style="margin-right: 8px; color: var(--primary);"></i> Action Buttons</h2>
    </div>
    <div class="table-container" style="border: none;">
        <table class="data-table">
            <thead>
                <tr><th style="width: 50px;">ID</th><th>Button</th><th>Attached to</th><th>Fields</th><th>Records</th><th>Change attachment</th></tr>
            </thead>
            <tbody>
                <?php if (empty($buttons)): ?>
                <tr><td colspan="6" class="bm-ok">No action buttons yet. Add one from Custom Fields.</td></tr>
                <?php else: foreach ($buttons as $b):
                    $bid = (int)$b['id'];
                    $childModule = actionButtonModule($bid);
                    $parentOk = !isset($brokenButtonIds[$bid]);
                    $excluded = bmSubtreeModules($bid, $buttons);
                    $nRecords = $recordCounts[$bid] ?? 0;
                ?>
                <tr>
                    <td><?php echo $bid; ?></td>
                    <td>
                        <i class="fas <?php echo htmlspecialchars(($b['icon'] ?? '') ?: 'fa-layer-group', ENT_QUOTES, 'UTF-8'); ?>" style="color: var(--warning); margin-right: 6px;"></i>
                        <strong><?php echo htmlspecialchars($b['label']); ?></strong>
                    </td>
                    <td>
                        <?php if ($parentOk): ?>
                            <?php echo htmlspecialchars(bmLabel($b['parent_module'], $moduleLabels)); ?>
                        <?php else: ?>
                            <span class="bm-missing"><i class="fas fa-triangle-exclamation"></i> Missing: <code><?php echo htmlspecialchars($b['parent_module']); ?></code></span>
                        <?php endif; ?>
                    </td>
                    <td><a href="<?php echo url('custom-fields.php?module=' . urlencode($childModule)); ?>"><?php echo $fieldCounts[$childModule] ?? 0; ?> field(s)</a></td>
                    <td><?php echo $nRecords; ?></td>
                    <td>
                        <form method="POST" class="bm-attach"
                              onsubmit="return <?php echo $nRecords ? "confirm('This button has " . $nRecords . " record(s) attached to records of its current module. Moving it leaves them pointing at those old records. Continue?')" : 'true'; ?>;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="map_action" value="attach_button">
                            <input type="hidden" name="button_id" value="<?php echo $bid; ?>">
                            <select name="target_module" class="filter-select" required>
                                <?php if (!$parentOk): ?><option value="" selected>-- choose module --</option><?php endif; ?>
                                <?php foreach ($moduleLabels as $key => $label):
                                    if (in_array($key, $excluded, true)) continue; ?>
                                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $key === $b['parent_module'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-link"></i> Save</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Fields & blocks of missing folders/buttons -->
<div class="card animate-fade-in" style="margin-top: 24px;">
    <div class="card-header">
        <h2><i class="fas fa-unlink" style="margin-right: 8px; color: var(--danger);"></i> Fields of Missing Folders &amp; Buttons</h2>
        <p style="margin: 8px 0 0; color: var(--text-secondary); font-size: 0.9rem;">
            Their folder or button no longer exists, so they show up nowhere. Move them onto an existing module, or delete them.
        </p>
    </div>
    <?php if (empty($orphanGroups)): ?>
        <div class="bm-ok"><i class="fas fa-check-circle" style="color: var(--success);"></i> Every field and block belongs to something that exists.</div>
    <?php else: foreach ($orphanGroups as $oldModule => $group): ?>
        <div class="bm-group">
            <h3><?php echo strpos($oldModule, 'action_btn_') !== false ? 'Missing button' : 'Missing folder'; ?>
                <code><?php echo htmlspecialchars($oldModule); ?></code>
                <span class="bm-muted">&middot; <?php echo count($group['fields'] ?? []); ?> field(s)<?php echo !empty($group['blocks']) ? ', ' . count($group['blocks']) . ' block(s)' : ''; ?></span>
            </h3>
            <div class="bm-chips">
                <?php foreach ($group['fields'] ?? [] as $f): ?>
                    <span class="bm-chip"><?php echo htmlspecialchars($f['field_label']); ?> <span class="bm-muted">(<?php echo htmlspecialchars($f['field_type'] ?: 'text'); ?>)</span></span>
                <?php endforeach; ?>
                <?php foreach ($group['blocks'] ?? [] as $bl): ?>
                    <span class="bm-chip"><i class="fas fa-th-large"></i> <?php echo htmlspecialchars($bl['block_label']); ?></span>
                <?php endforeach; ?>
            </div>
            <div class="bm-actions">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="map_action" value="reassign_module">
                    <input type="hidden" name="old_module" value="<?php echo htmlspecialchars($oldModule); ?>">
                    <select name="target_module" class="filter-select" required>
                        <option value="">-- move to module --</option>
                        <?php foreach ($moduleLabels as $key => $label): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-arrow-right"></i> Move</button>
                </form>
                <form method="POST" onsubmit="return confirm('Permanently delete these fields, blocks and every value saved in them? This cannot be undone.');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="map_action" value="delete_module">
                    <input type="hidden" name="old_module" value="<?php echo htmlspecialchars($oldModule); ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                </form>
            </div>
            <p class="bm-muted" style="margin: 10px 0 0;">Moving keeps the field definitions; values saved under the missing folder/button stay with it (they belong to its records, which no longer exist).</p>
        </div>
    <?php endforeach; endif; ?>
</div>

<!-- Fields in a wrong/missing block -->
<div class="card animate-fade-in" style="margin-top: 24px;">
    <div class="card-header">
        <h2><i class="fas fa-th-large" style="margin-right: 8px; color: var(--warning);"></i> Fields in a Wrong or Missing Block</h2>
        <p style="margin: 8px 0 0; color: var(--text-secondary); font-size: 0.9rem;">
            Grouped into a block that was deleted or belongs to a different module. Ungrouping keeps the fields and all their values.
        </p>
    </div>
    <?php if (empty($broken['field_blocks'])): ?>
        <div class="bm-ok"><i class="fas fa-check-circle" style="color: var(--success);"></i> Every grouped field is in a block of its own module.</div>
    <?php else: ?>
        <div class="bm-group">
            <div class="bm-chips">
                <?php foreach ($broken['field_blocks'] as $f): ?>
                    <span class="bm-chip"><?php echo htmlspecialchars($f['field_label']); ?> <span class="bm-muted">— <?php echo htmlspecialchars(bmLabel($f['linked_to'], $moduleLabels)); ?>: <?php echo htmlspecialchars($f['problem']); ?></span></span>
                <?php endforeach; ?>
            </div>
            <form method="POST" class="bm-actions">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="map_action" value="ungroup_fields">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-object-ungroup"></i> Ungroup these fields</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- Leftover values -->
<div class="card animate-fade-in" style="margin-top: 24px;">
    <div class="card-header">
        <h2><i class="fas fa-broom" style="margin-right: 8px; color: var(--info);"></i> Leftover Values</h2>
        <p style="margin: 8px 0 0; color: var(--text-secondary); font-size: 0.9rem;">
            Values saved for a field or a record that has since been deleted. Nothing can show them; delete them when you're sure.
        </p>
    </div>
    <?php if (!$leftoverTotal): ?>
        <div class="bm-ok"><i class="fas fa-check-circle" style="color: var(--success);"></i> No leftover values.</div>
    <?php else: ?>
        <div class="table-container" style="border: none;">
            <table class="data-table">
                <thead><tr><th>Module</th><th>Field deleted</th><th>Record deleted</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($leftovers as $module => $counts): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(bmLabel($module, $moduleLabels)); ?></td>
                        <td><?php echo (int)($counts['field_gone'] ?? 0); ?></td>
                        <td><?php echo (int)($counts['record_gone'] ?? 0); ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Permanently delete these leftover values?');" style="margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="map_action" value="delete_leftovers">
                                <input type="hidden" name="module" value="<?php echo htmlspecialchars($module); ?>">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

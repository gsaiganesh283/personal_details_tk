<?php
/**
 * Sync protocol (v2) for the fields engine's 7 tables, called from
 * desktop-sync-api.php's fs_* actions.
 *
 * Rows are identified by their permanent `uid`, never by numeric id: each
 * side keeps its own numeric ids, and every link between rows travels as a
 * uid-based reference that each side resolves against its own database:
 *
 *   module ref   "m:bank_details"          a built-in module
 *                "f:<folder uid>"          other_folder_<id>
 *                "b:<button uid>"          action_btn_<id>
 *   record ref   "id:<server id>"          a record in a built-in module table
 *                "u:<record uid>"          an Other Details / Action Button record
 *
 * A row whose reference points at something that no longer exists is sent
 * with 'broken' => true (refs it can't encode are null) so the desktop can
 * leave it alone instead of guessing.
 *
 * Conflicts: every upsert/delete carries `base`, the updated_at the client
 * last saw for that row. If the server copy changed since, nothing is
 * overwritten -- the client gets the current server row back and keeps its
 * own version as a conflict copy. Deletes also refuse while the row still
 * has children the client didn't delete. Nothing is ever lost silently.
 */

require_once __DIR__ . '/fields_engine.php';

const FS_COLUMNS = [
    'other_detail_folders'  => ['folder_name', 'folder_icon', 'folder_color', 'folder_order', 'description', 'is_active'],
    'other_detail_records'  => ['record_name', 'notes'],
    'action_buttons'        => ['label', 'icon', 'button_order'],
    'action_button_records' => ['label'],
    'custom_field_blocks'   => ['block_name', 'block_label', 'layout', 'block_order', 'is_repeatable', 'max_instances', 'is_active'],
    'custom_fields'         => ['field_name', 'field_label', 'field_type', 'field_options', 'field_placeholder', 'is_required',
                                'field_order', 'field_order_in_block', 'is_active', 'show_in_list'],
    'custom_field_entries'  => ['field_value', 'block_instance'],
];

// Built-in modules whose records live in a same-named table -- the only
// targets an "id:<n>" record ref may point into.
const FS_CORE_RECORD_TABLES = [
    'personal_details', 'bank_details', 'pan_details', 'aadhaar_details', 'documents', 'contacts',
    'savings_daily', 'savings_bank_accounts', 'savings_fixed_deposits', 'savings_recurring_deposits',
    'savings_mutual_funds', 'savings_sip_investments', 'savings_stocks', 'savings_ppf_epf',
    'savings_gold_metals', 'savings_insurance_policies', 'savings_cash_on_hand', 'savings_loans',
    'savings_credit_cards', 'savings_lent_money', 'savings_documents_registry',
    'savings_bank_transactions', 'savings_transactions',
];

class FieldsSync {
    private $db;
    private $userId;
    private $uidOf = [];   // [table][id] => uid
    private $idOf = [];    // [table][uid] => id
    private $buttonParent = []; // [button id] => parent_module

    public function __construct($userId) {
        $this->db = Database::getInstance();
        $this->userId = (int)$userId;
        feAssignUids($this->userId);
        $this->loadMaps();
    }

    private function loadMaps() {
        $this->uidOf = $this->idOf = [];
        foreach (array_keys(FS_COLUMNS) as $table) {
            $this->uidOf[$table] = $this->idOf[$table] = [];
            $cols = $table === 'action_buttons' ? 'id, uid, parent_module' : 'id, uid';
            foreach ($this->db->fetchAll("SELECT $cols FROM `$table` WHERE user_id = ?", [$this->userId]) as $r) {
                $this->uidOf[$table][(int)$r['id']] = $r['uid'];
                $this->idOf[$table][$r['uid']] = (int)$r['id'];
                if ($table === 'action_buttons') {
                    $this->buttonParent[(int)$r['id']] = $r['parent_module'];
                }
            }
        }
    }

    private function remember($table, $id, $uid) {
        $this->uidOf[$table][(int)$id] = $uid;
        $this->idOf[$table][$uid] = (int)$id;
    }

    // --------------------------------------------------------- references

    private function encodeModule($module) {
        [$kind, $id] = feParseModule($module);
        if ($kind === 'folder') {
            return isset($this->uidOf['other_detail_folders'][$id]) ? 'f:' . $this->uidOf['other_detail_folders'][$id] : null;
        }
        if ($kind === 'button') {
            return isset($this->uidOf['action_buttons'][$id]) ? 'b:' . $this->uidOf['action_buttons'][$id] : null;
        }
        return $id === '' ? null : 'm:' . $id;
    }

    private function decodeModule($ref) {
        if (!is_string($ref) || strlen($ref) < 3) return null;
        $value = substr($ref, 2);
        switch (substr($ref, 0, 2)) {
            case 'm:':
                return preg_match('/^[a-z0-9_]+$/', $value) && !preg_match('/^(other_folder|action_btn)_/', $value) ? $value : null;
            case 'f:':
                return isset($this->idOf['other_detail_folders'][$value]) ? feFolderModule($this->idOf['other_detail_folders'][$value]) : null;
            case 'b:':
                return isset($this->idOf['action_buttons'][$value]) ? feButtonModule($this->idOf['action_buttons'][$value]) : null;
        }
        return null;
    }

    private function encodeRecord($module, $recordId) {
        [$kind] = feParseModule($module);
        if ($kind === 'folder') {
            $uid = $this->uidOf['other_detail_records'][(int)$recordId] ?? null;
            return $uid ? 'u:' . $uid : null;
        }
        if ($kind === 'button') {
            $uid = $this->uidOf['action_button_records'][(int)$recordId] ?? null;
            return $uid ? 'u:' . $uid : null;
        }
        return 'id:' . (int)$recordId;
    }

    private function decodeRecord($module, $ref) {
        if (!is_string($ref)) return null;
        [$kind, $key] = feParseModule($module);
        if (strpos($ref, 'u:') === 0) {
            $table = $kind === 'folder' ? 'other_detail_records' : ($kind === 'button' ? 'action_button_records' : null);
            return $table ? ($this->idOf[$table][substr($ref, 2)] ?? null) : null;
        }
        if (strpos($ref, 'id:') === 0 && $kind === 'core') {
            $id = (int)substr($ref, 3);
            if ($id <= 0) return null;
            // A category's own ledger module ("savings_transactions__savings_sip_investments")
            // holds values of savings_transactions rows.
            if (strpos($key, 'savings_transactions__') === 0) {
                $key = 'savings_transactions';
            }
            if (in_array($key, FS_CORE_RECORD_TABLES, true)) {
                try {
                    $exists = $this->db->fetch("SELECT id FROM `$key` WHERE id = ? AND user_id = ?", [$id, $this->userId]);
                } catch (Exception $e) {
                    $exists = true; // table without user_id -- trust the id
                }
                return $exists ? $id : null;
            }
            return $id;
        }
        return null;
    }

    // ------------------------------------------------------------- encode

    /** Server row -> wire format. */
    public function encode($table, array $row) {
        $data = [];
        foreach (FS_COLUMNS[$table] as $col) {
            $data[$col] = array_key_exists($col, $row) ? $row[$col] : null;
        }
        $refs = [];
        switch ($table) {
            case 'other_detail_records':
                $refs['folder'] = $this->uidOf['other_detail_folders'][(int)$row['folder_id']] ?? null;
                break;
            case 'action_buttons':
                $refs['parent'] = $this->encodeModule($row['parent_module']);
                break;
            case 'action_button_records':
                $refs['button'] = $this->uidOf['action_buttons'][(int)$row['button_id']] ?? null;
                $parentModule = $this->buttonParent[(int)$row['button_id']] ?? null;
                $refs['parent_record'] = $parentModule === null ? null : $this->encodeRecord($parentModule, $row['parent_record_id']);
                break;
            case 'custom_field_blocks':
                $refs['module'] = $this->encodeModule($row['linked_to']);
                break;
            case 'custom_fields':
                $refs['module'] = $this->encodeModule($row['linked_to']);
                // A dangling block_id isn't fatal -- the field just shows ungrouped.
                $refs['block'] = $row['block_id'] ? ($this->uidOf['custom_field_blocks'][(int)$row['block_id']] ?? null) : null;
                break;
            case 'custom_field_entries':
                $refs['field'] = $this->uidOf['custom_fields'][(int)$row['custom_field_id']] ?? null;
                $refs['module'] = $this->encodeModule($row['linked_to']);
                $refs['record'] = $this->encodeRecord($row['linked_to'], $row['record_id']);
                break;
        }
        $broken = false;
        foreach ($refs as $name => $value) {
            if ($value === null && $name !== 'block') $broken = true;
        }
        return [
            'uid' => $row['uid'],
            'updated_at' => $row['updated_at'],
            'hash' => self::contentHash($data, $refs),
            'data' => $data,
            'refs' => $refs,
            'broken' => $broken,
        ];
    }

    /**
     * Fingerprint of a row's synced content. Clients echo back the hash of
     * the version they last saw; any difference means the row changed here
     * since -- exact, unlike updated_at, which has 1-second resolution (two
     * edits in the same second looked like no change at all).
     */
    private static function contentHash(array $data, array $refs) {
        $norm = function ($v) { return $v === null ? '' : (string)$v; };
        ksort($data);
        ksort($refs);
        return md5(json_encode([array_map($norm, $data), array_map($norm, $refs)]));
    }

    /** True if the client's view ($op base_hash, or base timestamp) is out of date. */
    private static function isStale(array $op, array $current, $updatedAt) {
        if (!empty($op['base_hash'])) return $op['base_hash'] !== $current['hash'];
        return ($op['base'] ?? null) !== $updatedAt;
    }

    // --------------------------------------------------------------- pull

    public function pull($since) {
        $now = $this->db->fetch("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') AS t")['t'];
        $out = ['server_time' => $now, 'tables' => []];
        foreach (array_keys(FS_COLUMNS) as $table) {
            if ($since) {
                // 2 s of overlap: MySQL timestamps have 1 s resolution, and
                // re-sending a row the client already has is a harmless no-op.
                $rows = $this->db->fetchAll(
                    "SELECT * FROM `$table` WHERE user_id = ? AND updated_at >= DATE_SUB(?, INTERVAL 2 SECOND) ORDER BY id",
                    [$this->userId, $since]
                );
            } else {
                $rows = $this->db->fetchAll("SELECT * FROM `$table` WHERE user_id = ? ORDER BY id", [$this->userId]);
            }
            $out['tables'][$table] = [
                'rows' => array_map(function ($r) use ($table) { return $this->encode($table, $r); }, $rows),
                'uids' => array_keys($this->idOf[$table]),
            ];
        }
        return $out;
    }

    public function get($table, array $uids) {
        if (!isset(FS_COLUMNS[$table])) throw new InvalidArgumentException('Unknown table');
        $uids = array_slice(array_values(array_filter($uids, 'is_string')), 0, 500);
        if (!$uids) return [];
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $rows = $this->db->fetchAll(
            "SELECT * FROM `$table` WHERE user_id = ? AND uid IN ($placeholders)",
            array_merge([$this->userId], $uids)
        );
        return array_map(function ($r) use ($table) { return $this->encode($table, $r); }, $rows);
    }

    // --------------------------------------------------------------- push

    private function fetchByUid($table, $uid) {
        return $this->db->fetch("SELECT * FROM `$table` WHERE user_id = ? AND uid = ?", [$this->userId, $uid]);
    }

    private function current($table, $uid) {
        $row = $this->fetchByUid($table, $uid);
        return $row ? $this->encode($table, $row) : null;
    }

    /** Wire refs -> [column => value] for this table, or a string error. */
    private function decodeRefs($table, array $refs) {
        $cols = [];
        switch ($table) {
            case 'other_detail_records':
                $id = $this->idOf['other_detail_folders'][$refs['folder'] ?? ''] ?? null;
                if (!$id) return 'folder not on server yet';
                $cols['folder_id'] = $id;
                break;
            case 'action_buttons':
                $module = $this->decodeModule($refs['parent'] ?? null);
                if ($module === null) return 'parent module not on server yet';
                $cols['parent_module'] = $module;
                break;
            case 'action_button_records':
                $buttonId = $this->idOf['action_buttons'][$refs['button'] ?? ''] ?? null;
                if (!$buttonId) return 'button not on server yet';
                $parentModule = $this->buttonParent[$buttonId] ?? null;
                $recordId = $parentModule === null ? null : $this->decodeRecord($parentModule, $refs['parent_record'] ?? null);
                if (!$recordId) return 'parent record not on server yet';
                $cols['button_id'] = $buttonId;
                $cols['parent_record_id'] = $recordId;
                break;
            case 'custom_field_blocks':
                $module = $this->decodeModule($refs['module'] ?? null);
                if ($module === null) return 'module not on server yet';
                $cols['linked_to'] = $module;
                break;
            case 'custom_fields':
                $module = $this->decodeModule($refs['module'] ?? null);
                if ($module === null) return 'module not on server yet';
                $cols['linked_to'] = $module;
                $blockUid = $refs['block'] ?? null;
                if ($blockUid) {
                    $blockId = $this->idOf['custom_field_blocks'][$blockUid] ?? null;
                    if (!$blockId) return 'block not on server yet';
                    $cols['block_id'] = $blockId;
                } else {
                    $cols['block_id'] = null;
                }
                break;
            case 'custom_field_entries':
                $fieldId = $this->idOf['custom_fields'][$refs['field'] ?? ''] ?? null;
                if (!$fieldId) return 'field not on server yet';
                $module = $this->decodeModule($refs['module'] ?? null);
                if ($module === null) return 'module not on server yet';
                $recordId = $this->decodeRecord($module, $refs['record'] ?? null);
                if (!$recordId) return 'record not on server yet';
                $cols['custom_field_id'] = $fieldId;
                $cols['linked_to'] = $module;
                $cols['record_id'] = $recordId;
                break;
        }
        return $cols;
    }

    private static function sameData($a, $b) {
        foreach ($a as $k => $v) {
            if ((string)($v ?? '') !== (string)($b[$k] ?? '')) return false;
        }
        return true;
    }

    /** Children on the server that the client hasn't deleted -- a delete must not take them along. */
    private function hasChildren($table, array $row) {
        $u = $this->userId;
        $has = function ($sql, $params) { return (bool)$this->db->fetch($sql . ' LIMIT 1', $params); };
        switch ($table) {
            case 'other_detail_folders':
                $module = feFolderModule($row['id']);
                return $has("SELECT id FROM other_detail_records WHERE user_id = ? AND folder_id = ?", [$u, $row['id']])
                    || $this->moduleHasContents($module);
            case 'action_buttons':
                $module = feButtonModule($row['id']);
                return $has("SELECT id FROM action_button_records WHERE user_id = ? AND button_id = ?", [$u, $row['id']])
                    || $this->moduleHasContents($module);
            case 'other_detail_records':
                return $this->recordHasContents(feFolderModule($row['folder_id']), $row['id']);
            case 'action_button_records':
                return $this->recordHasContents(feButtonModule($row['button_id']), $row['id']);
            case 'custom_fields':
                return $has("SELECT id FROM custom_field_entries WHERE user_id = ? AND custom_field_id = ?", [$u, $row['id']]);
        }
        return false;
    }

    private function moduleHasContents($module) {
        foreach (['custom_fields', 'custom_field_blocks'] as $t) {
            if ($this->db->fetch("SELECT id FROM `$t` WHERE user_id = ? AND linked_to = ? LIMIT 1", [$this->userId, $module])) return true;
        }
        return (bool)$this->db->fetch("SELECT id FROM action_buttons WHERE user_id = ? AND parent_module = ? LIMIT 1", [$this->userId, $module]);
    }

    private function recordHasContents($module, $recordId) {
        if ($this->db->fetch(
            "SELECT id FROM custom_field_entries WHERE user_id = ? AND linked_to = ? AND record_id = ? LIMIT 1",
            [$this->userId, $module, $recordId]
        )) return true;
        return (bool)$this->db->fetch(
            "SELECT r.id FROM action_button_records r JOIN action_buttons b ON b.id = r.button_id
             WHERE r.user_id = ? AND b.parent_module = ? AND r.parent_record_id = ? LIMIT 1",
            [$this->userId, $module, $recordId]
        );
    }

    /**
     * Applies a batch of ops in order. Each op is its own transaction, so one
     * failure never undoes the others; the result list lines up with $ops.
     */
    public function push(array $ops) {
        $results = [];
        foreach (array_slice($ops, 0, 500) as $op) {
            try {
                $results[] = feTransaction(function () use ($op) { return $this->applyOp($op); });
            } catch (Throwable $e) {
                $results[] = ['status' => 'error', 'message' => substr($e->getMessage(), 0, 200)];
                $this->loadMaps(); // rolled back -- the in-memory maps may be ahead of the database
            }
        }
        return $results;
    }

    private function applyOp($op) {
        $table = $op['table'] ?? '';
        $uid = $op['uid'] ?? '';
        if (!isset(FS_COLUMNS[$table]) || !preg_match('/^[0-9a-f]{32}$/', (string)$uid)) {
            return ['status' => 'error', 'message' => 'bad table or uid'];
        }
        $base = $op['base'] ?? null;
        $existing = $this->fetchByUid($table, $uid);

        if (($op['op'] ?? '') === 'delete') {
            if (!$existing) return ['status' => 'gone'];
            $current = $this->encode($table, $existing);
            if (self::isStale($op, $current, $existing['updated_at']) || $this->hasChildren($table, $existing)) {
                return ['status' => 'conflict', 'current' => $current];
            }
            $this->db->delete("DELETE FROM `$table` WHERE id = ? AND user_id = ?", [$existing['id'], $this->userId]);
            unset($this->idOf[$table][$uid], $this->uidOf[$table][(int)$existing['id']]);
            return ['status' => 'ok'];
        }

        $refCols = $this->decodeRefs($table, is_array($op['refs'] ?? null) ? $op['refs'] : []);
        if (is_string($refCols)) {
            return ['status' => 'pending', 'message' => $refCols];
        }
        $data = [];
        $incoming = is_array($op['data'] ?? null) ? $op['data'] : [];
        foreach (FS_COLUMNS[$table] as $col) {
            if (array_key_exists($col, $incoming)) $data[$col] = $incoming[$col];
        }
        $values = $data + $refCols;

        if ($existing) {
            $mine = $this->encode($table, $existing);
            if (($base === null && empty($op['base_hash'])) || self::isStale($op, $mine, $existing['updated_at'])) {
                // Client never saw this version. Identical content = just a
                // retried push whose reply got lost; anything else is a conflict.
                if (!(self::sameData($data, $mine['data']) && self::sameData($op['refs'] ?? [], $mine['refs']))) {
                    return ['status' => 'conflict', 'current' => $mine];
                }
                return ['status' => 'ok', 'updated_at' => $existing['updated_at'], 'hash' => $mine['hash']];
            }
            if ($values) {
                $set = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($values)));
                $this->db->update(
                    "UPDATE `$table` SET $set WHERE id = ? AND user_id = ?",
                    array_merge(array_values($values), [$existing['id'], $this->userId])
                );
            }
            if ($table === 'action_buttons') $this->buttonParent[(int)$existing['id']] = $values['parent_module'];
            $now = $this->fetchByUid($table, $uid);
            return ['status' => 'ok', 'updated_at' => $now['updated_at'], 'hash' => $this->encode($table, $now)['hash']];
        }

        if ($table === 'custom_field_entries') {
            // Natural UNIQUE key (field, module, record, instance): the same
            // value slot may already exist here under another uid (written on
            // the website). Hand it back so the client adopts it instead.
            $slot = $this->db->fetch(
                "SELECT * FROM custom_field_entries WHERE user_id = ? AND custom_field_id = ? AND linked_to = ? AND record_id = ? AND block_instance = ?",
                [$this->userId, $values['custom_field_id'], $values['linked_to'], $values['record_id'], (int)($values['block_instance'] ?? 0)]
            );
            if ($slot) {
                return ['status' => 'conflict', 'current' => $this->encode($table, $slot)];
            }
        }

        $values['uid'] = $uid;
        $values['user_id'] = $this->userId;
        $cols = implode(', ', array_map(function ($c) { return "`$c`"; }, array_keys($values)));
        $marks = implode(', ', array_fill(0, count($values), '?'));
        $newId = $this->db->insert("INSERT INTO `$table` ($cols) VALUES ($marks)", array_values($values));
        $this->remember($table, $newId, $uid);
        if ($table === 'action_buttons') feQuarantineModule(feButtonModule($newId), $this->userId);
        if ($table === 'other_detail_folders') feQuarantineModule(feFolderModule($newId), $this->userId);
        if ($table === 'action_buttons') $this->buttonParent[(int)$newId] = $values['parent_module'];
        $now = $this->fetchByUid($table, $uid);
        return ['status' => 'ok', 'updated_at' => $now['updated_at'], 'hash' => $this->encode($table, $now)['hash']];
    }
}

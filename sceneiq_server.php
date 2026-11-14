<?php
    // sceneiq_server.php - Server Admin Interface for SceneIQ
    session_start();
    // SceneIQ uses a single-page folder workspace. Do not let the browser briefly
    // display a cached copy of an older stylesheet or older page markup.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (empty($_SESSION['password_csrf_token'])) {
        $_SESSION['password_csrf_token'] = bin2hex(random_bytes(32));
    }
    if (empty($_SESSION['data_delete_csrf_token'])) {
        $_SESSION['data_delete_csrf_token'] = bin2hex(random_bytes(32));
    }
    if (empty($_SESSION['entry_upload_csrf_token'])) {
        $_SESSION['entry_upload_csrf_token'] = bin2hex(random_bytes(32));
    }
    if (empty($_SESSION['sceneiq_storage_recovery_csrf_token'])) {
        $_SESSION['sceneiq_storage_recovery_csrf_token'] = bin2hex(random_bytes(32));
    }
    if (empty($_SESSION['sceneiq_copy_csrf_token'])) {
        $_SESSION['sceneiq_copy_csrf_token'] = bin2hex(random_bytes(32));
    }

    // Log PHP errors. If a fatal error still occurs during page rendering, show a
    // small diagnostic banner instead of leaving a partially-rendered, dead page.
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    register_shutdown_function(function () {
        $lastError = error_get_last();
        if (!$lastError || !in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        error_log('SceneIQ fatal error: ' . ($lastError['message'] ?? 'Unknown fatal') .
            ' in ' . ($lastError['file'] ?? 'unknown file') . ':' . ($lastError['line'] ?? 0));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            echo '<div style="position:fixed;z-index:2147483647;left:12px;right:12px;bottom:12px;padding:14px 16px;border:1px solid #fca5a5;border-radius:10px;background:#fff1f2;color:#991b1b;font:14px/1.5 Segoe UI,Arial,sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.2)">';
            echo '<strong>SceneIQ could not finish loading.</strong> A server-side PHP error stopped this page. Check the hosting PHP error log for the exact file and line. Your existing account data has not been deleted.';
            echo '</div>';
        }
    });

    // Database configuration
    $host = 'sql201.infinityfree.com';
    $dbname = 'if0_40367004_automation_tree';
    $username = 'if0_40367004';
    $password = 'NkwFAH15FRIlvCf';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch(PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }

    // ===== FUNCTIONS =====

    function getAllUsers($pdo) {
        $stmt = $pdo->query("SELECT id, email, username, created_at FROM users ORDER BY username");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    function getUserById($pdo, $userId) {
        $stmt = $pdo->prepare("SELECT id, email, username, created_at FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    function createUser($pdo, $email, $username, $password) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (email, username, password) VALUES (:email, :username, :password)");
        return $stmt->execute([
            ':email' => $email,
            ':username' => $username,
            ':password' => $hashedPassword
        ]);
    }

    function deleteUser($pdo, $userId) {
        $pdo->beginTransaction();
        try {
            sceneiqDeleteUserTableData($pdo, (int)$userId);
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $ok = $stmt->execute([':id' => $userId]);
            $pdo->commit();
            return $ok;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * SceneIQ relational storage layer.
     * JSON is used only for flexible field properties and individual project payloads;
     * each account field, tab and project is a separate database row.
     */
    function sceneiqEnsureTableStorageSchema(PDO $pdo) {
        $ddl = [];
        $ddl[] = "CREATE TABLE IF NOT EXISTS `sceneiq_dynamic_fields` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `field_key` VARCHAR(191) NOT NULL,
            `field_title` VARCHAR(255) NOT NULL DEFAULT '',
            `field_type` VARCHAR(32) NOT NULL DEFAULT 'individual',
            `element_type` VARCHAR(64) NOT NULL DEFAULT 'input',
            `data_type` VARCHAR(32) NOT NULL DEFAULT 'string',
            `default_value` LONGTEXT NULL,
            `default_values_json` LONGTEXT NULL,
            `button_url` TEXT NULL,
            `separate_paragraphs` VARCHAR(32) NOT NULL DEFAULT 'no',
            `copy_button_for_each_of_json` LONGTEXT NULL,
            `definition_json` LONGTEXT NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_sceneiq_field_user_key` (`user_id`, `field_key`),
            KEY `idx_sceneiq_fields_user_active` (`user_id`, `deleted_at`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $ddl[] = "CREATE TABLE IF NOT EXISTS `sceneiq_dynamic_field_subfields` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `field_id` BIGINT UNSIGNED NOT NULL,
            `subfield_key` VARCHAR(191) NOT NULL,
            `field_title` VARCHAR(255) NOT NULL DEFAULT '',
            `element_type` VARCHAR(64) NOT NULL DEFAULT 'input',
            `data_type` VARCHAR(32) NOT NULL DEFAULT 'string',
            `default_value` LONGTEXT NULL,
            `default_values_json` LONGTEXT NULL,
            `button_url` TEXT NULL,
            `separate_paragraphs` VARCHAR(32) NOT NULL DEFAULT 'no',
            `copy_button_for_each_of_json` LONGTEXT NULL,
            `definition_json` LONGTEXT NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_sceneiq_subfield_key` (`field_id`, `subfield_key`),
            KEY `idx_sceneiq_subfields_user_field` (`user_id`, `field_id`, `deleted_at`),
            CONSTRAINT `fk_sceneiq_subfield_field` FOREIGN KEY (`field_id`) REFERENCES `sceneiq_dynamic_fields` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $ddl[] = "CREATE TABLE IF NOT EXISTS `sceneiq_dynamic_tabs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `parent_tab_id` BIGINT UNSIGNED NULL,
            `tab_key` VARCHAR(191) NOT NULL,
            `tab_name` VARCHAR(255) NOT NULL DEFAULT '',
            `submit_tab` TINYINT(1) NOT NULL DEFAULT 1,
            `sub_tabs_mode` VARCHAR(32) NOT NULL DEFAULT 'none',
            `additional_features_json` LONGTEXT NULL,
            `tab_config_json` LONGTEXT NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_sceneiq_tab_user_key` (`user_id`, `tab_key`),
            KEY `idx_sceneiq_tabs_parent` (`user_id`, `parent_tab_id`, `deleted_at`, `sort_order`),
            CONSTRAINT `fk_sceneiq_tab_parent` FOREIGN KEY (`parent_tab_id`) REFERENCES `sceneiq_dynamic_tabs` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $ddl[] = "CREATE TABLE IF NOT EXISTS `sceneiq_dynamic_tab_fields` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `tab_id` BIGINT UNSIGNED NOT NULL,
            `field_id` BIGINT UNSIGNED NULL,
            `field_key` VARCHAR(191) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_sceneiq_tab_field` (`tab_id`, `field_key`),
            KEY `idx_sceneiq_tab_fields_user` (`user_id`, `tab_id`, `sort_order`),
            KEY `idx_sceneiq_tab_fields_field` (`field_id`),
            CONSTRAINT `fk_sceneiq_tab_field_tab` FOREIGN KEY (`tab_id`) REFERENCES `sceneiq_dynamic_tabs` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_sceneiq_tab_field_definition` FOREIGN KEY (`field_id`) REFERENCES `sceneiq_dynamic_fields` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $ddl[] = "CREATE TABLE IF NOT EXISTS `sceneiq_project_entries` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `status` ENUM('pending','completed','aborted') NOT NULL DEFAULT 'pending',
            `entry_data_json` LONGTEXT NOT NULL,
            `operation_status` LONGTEXT NULL,
            `display_order` INT NOT NULL DEFAULT 0,
            `version` INT UNSIGNED NOT NULL DEFAULT 1,
            `legacy_config_id` BIGINT NULL,
            `legacy_entry_index` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_sceneiq_legacy_entry` (`user_id`, `legacy_config_id`, `legacy_entry_index`),
            KEY `idx_sceneiq_projects_user_status` (`user_id`, `status`, `deleted_at`, `display_order`),
            KEY `idx_sceneiq_projects_updated` (`user_id`, `updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $ddl[] = "CREATE TABLE IF NOT EXISTS `sceneiq_project_status_history` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `project_entry_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `previous_status` VARCHAR(20) NOT NULL,
            `new_status` VARCHAR(20) NOT NULL,
            `changed_by` VARCHAR(191) NULL,
            `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_sceneiq_status_history_entry` (`project_entry_id`, `changed_at`),
            KEY `idx_sceneiq_status_history_user` (`user_id`, `changed_at`),
            CONSTRAINT `fk_sceneiq_status_history_entry` FOREIGN KEY (`project_entry_id`) REFERENCES `sceneiq_project_entries` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $ddl[] = "CREATE TABLE IF NOT EXISTS `sceneiq_data_revisions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `entity_type` VARCHAR(40) NOT NULL,
            `entity_id` BIGINT UNSIGNED NOT NULL,
            `revision_no` INT UNSIGNED NOT NULL,
            `record_json` LONGTEXT NOT NULL,
            `change_reason` VARCHAR(191) NOT NULL DEFAULT 'update',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_sceneiq_revision` (`entity_type`, `entity_id`, `revision_no`),
            KEY `idx_sceneiq_revisions_user` (`user_id`, `entity_type`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $ddl[] = "CREATE TABLE IF NOT EXISTS `sceneiq_table_migration_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `legacy_config_id` BIGINT NOT NULL,
            `migration_status` VARCHAR(24) NOT NULL DEFAULT 'started',
            `fields_migrated` INT UNSIGNED NOT NULL DEFAULT 0,
            `tabs_migrated` INT UNSIGNED NOT NULL DEFAULT 0,
            `projects_migrated` INT UNSIGNED NOT NULL DEFAULT 0,
            `message` LONGTEXT NULL,
            `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `completed_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_sceneiq_migration_user_config` (`user_id`,`legacy_config_id`),
            KEY `idx_sceneiq_migration_status` (`migration_status`,`started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        foreach ($ddl as $sql) $pdo->exec($sql);
    }

    function sceneiqEncodeTableJson($value) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    function sceneiqDecodeTableJson($value, $fallback = []) {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return $fallback;
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : $fallback;
        } catch (Throwable $e) {
            throw new RuntimeException('A table-based SceneIQ JSON record is invalid: ' . $e->getMessage(), 0, $e);
        }
    }

    function sceneiqCreateRevision(PDO $pdo, $userId, $entityType, $entityId, $recordJson, $reason = 'update') {
        if (!is_string($recordJson) || $recordJson === '') return;
        $next = $pdo->prepare('SELECT COALESCE(MAX(`revision_no`),0)+1 FROM `sceneiq_data_revisions` WHERE `entity_type`=:type AND `entity_id`=:id');
        $next->execute([':type' => $entityType, ':id' => (int)$entityId]);
        $revisionNo = (int)$next->fetchColumn();
        $insert = $pdo->prepare('INSERT INTO `sceneiq_data_revisions` (`user_id`,`entity_type`,`entity_id`,`revision_no`,`record_json`,`change_reason`) VALUES (:user_id,:type,:id,:revision,:record,:reason)');
        $insert->execute([':user_id'=>(int)$userId, ':type'=>$entityType, ':id'=>(int)$entityId, ':revision'=>$revisionNo, ':record'=>$recordJson, ':reason'=>(string)$reason]);
    }

    function sceneiqGetDynamicFields(PDO $pdo, $userId) {
        $stmt = $pdo->prepare('SELECT `id`,`field_key`,`definition_json`,`sort_order` FROM `sceneiq_dynamic_fields` WHERE `user_id`=:user_id AND `deleted_at` IS NULL ORDER BY `sort_order`,`id`');
        $stmt->execute([':user_id'=>(int)$userId]);
        $fields = [];
        $sub = $pdo->prepare('SELECT `subfield_key`,`definition_json` FROM `sceneiq_dynamic_field_subfields` WHERE `field_id`=:field_id AND `deleted_at` IS NULL ORDER BY `sort_order`,`id`');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try { $definition = sceneiqDecodeTableJson($row['definition_json'], []); }
            catch (Throwable $e) { error_log('SceneIQ invalid field definition ' . (int)$row['id'] . ': ' . $e->getMessage()); continue; }
            $sub->execute([':field_id'=>(int)$row['id']]);
            $subfields = [];
            foreach ($sub->fetchAll(PDO::FETCH_ASSOC) as $subRow) {
                $subfields[$subRow['subfield_key']] = sceneiqDecodeTableJson($subRow['definition_json'], []);
            }
            if (($definition['field_type'] ?? 'individual') === 'objects') $definition['fieldkeyvalue'] = $subfields;
            $fields[$row['field_key']] = $definition;
        }
        return $fields;
    }

    function sceneiqGetDynamicTabs(PDO $pdo, $userId) {
        $stmt = $pdo->prepare('SELECT * FROM `sceneiq_dynamic_tabs` WHERE `user_id`=:user_id AND `deleted_at` IS NULL ORDER BY `sort_order`,`id`');
        $stmt->execute([':user_id'=>(int)$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $mapping = $pdo->prepare('SELECT `field_key` FROM `sceneiq_dynamic_tab_fields` WHERE `tab_id`=:tab_id ORDER BY `sort_order`,`id`');
        $nodes = []; $children = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $data = sceneiqDecodeTableJson($row['tab_config_json'], []);
            unset($data['sub_tabs'], $data['dynamic_fields_display']);
            $mapping->execute([':tab_id'=>$id]);
            $displayFields = array_map(function($v) { return $v['field_key']; }, $mapping->fetchAll(PDO::FETCH_ASSOC));
            $data['tab_name'] = (string)$row['tab_name'];
            $data['submit_tab'] = (bool)$row['submit_tab'];
            $data['sub_tabs_mode'] = (string)$row['sub_tabs_mode'];
            $data['dynamic_fields_display'] = $displayFields;
            $data['sub_tabs'] = [];
            $data['_table_id'] = $id;
            $data['_tab_key'] = (string)$row['tab_key'];
            $nodes[$id] = $data;
            $parentId = $row['parent_tab_id'] === null ? 0 : (int)$row['parent_tab_id'];
            $children[$parentId][] = $id;
        }
        $build = function($id) use (&$build, &$nodes, &$children) {
            $node = $nodes[$id];
            $node['sub_tabs'] = [];
            foreach (($children[$id] ?? []) as $childId) $node['sub_tabs'][] = $build($childId);
            return $node;
        };
        $result = [];
        foreach (($children[0] ?? []) as $rootId) $result[] = $build($rootId);
        return $result;
    }

    function sceneiqGetProjectEntries(PDO $pdo, $userId) {
        $stmt = $pdo->prepare('SELECT `id`,`status`,`entry_data_json`,`operation_status`,`display_order` FROM `sceneiq_project_entries` WHERE `user_id`=:user_id AND `deleted_at` IS NULL ORDER BY `display_order`,`id`');
        $stmt->execute([':user_id'=>(int)$userId]);
        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try { $entry = sceneiqDecodeTableJson($row['entry_data_json'], []); }
            catch (Throwable $e) { error_log('SceneIQ invalid project entry ' . (int)$row['id'] . ': ' . $e->getMessage()); continue; }
            if (!is_array($entry)) continue;
            $entry['status'] = (string)$row['status'];
            $entry['operation_status'] = $row['operation_status'] ?? ($entry['operation_status'] ?? '');
            $entry['_db_id'] = (int)$row['id'];
            $entry['_index'] = (int)$row['id'];
            $entry['_position'] = (int)$row['display_order'];
            $entries[] = $entry;
        }
        return $entries;
    }

    function getUserConfig($pdo, $userId) {
        $userId = (int)$userId;
        return [
            'id' => $userId,
            'user_id' => $userId,
            'settings' => sceneiqEncodeTableJson(sceneiqGetProjectEntries($pdo, $userId)),
            'dynamic_fields' => sceneiqEncodeTableJson(sceneiqGetDynamicFields($pdo, $userId)),
            'dynamic_tabs' => sceneiqEncodeTableJson(sceneiqGetDynamicTabs($pdo, $userId))
        ];
    }

    /** Return the latest relational records after an AJAX save without reloading the page. */
    function sceneiqBuildLiveState(PDO $pdo, $userId) {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return ['userId' => 0, 'userSettings' => [], 'dynamicFields' => new stdClass(), 'dynamicTabs' => []];
        }
        $config = getUserConfig($pdo, $userId);
        if (!$config) {
            return ['userId' => $userId, 'userSettings' => [], 'dynamicFields' => new stdClass(), 'dynamicTabs' => []];
        }
        return [
            'userId' => $userId,
            'userSettings' => repairSettings(sceneiqDecodeConfigColumn($config['settings'] ?? '[]', 'settings', [])),
            'dynamicFields' => normalizeDynamicFieldDefinitions($config['dynamic_fields'] ?? '[]'),
            'dynamicTabs' => normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]')
        ];
    }

    function sceneiqSaveProjectEntriesTable(PDO $pdo, $userId, array $entries) {
        $userId = (int)$userId;
        $existingStmt = $pdo->prepare('SELECT `id`,`entry_data_json`,`status`,`display_order` FROM `sceneiq_project_entries` WHERE `user_id`=:user_id AND `deleted_at` IS NULL ORDER BY `display_order`,`id` FOR UPDATE');
        $existingStmt->execute([':user_id'=>$userId]);
        $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
        $existingById = []; foreach ($existingRows as $r) $existingById[(int)$r['id']] = $r;
        $existingByPosition = []; foreach ($existingRows as $r) $existingByPosition[(int)$r['display_order']] = $r;
        $used = []; $update = $pdo->prepare('UPDATE `sceneiq_project_entries` SET `status`=:status,`entry_data_json`=:data,`operation_status`=:operation_status,`display_order`=:position,`version`=`version`+1,`deleted_at`=NULL WHERE `id`=:id AND `user_id`=:user_id');
        $insert = $pdo->prepare('INSERT INTO `sceneiq_project_entries` (`user_id`,`status`,`entry_data_json`,`operation_status`,`display_order`) VALUES (:user_id,:status,:data,:operation_status,:position)');
        foreach (array_values($entries) as $position => $entry) {
            if (!is_array($entry)) continue;
            $entryId = (int)($entry['_db_id'] ?? 0);
            if ($entryId <= 0 && isset($existingByPosition[$position])) $entryId = (int)$existingByPosition[$position]['id'];
            $status = strtolower(trim((string)($entry['status'] ?? 'pending')));
            if (!in_array($status, ['pending','completed','aborted'], true)) $status = 'pending';
            $operationStatus = isset($entry['operation_status']) && is_scalar($entry['operation_status']) ? (string)$entry['operation_status'] : '';
            $storedEntry = $entry;
            unset($storedEntry['_db_id'], $storedEntry['_index'], $storedEntry['_position']);
            $storedEntry['status'] = $status;
            $storedEntry['operation_status'] = $operationStatus;
            $json = sceneiqEncodeTableJson($storedEntry);
            if ($entryId > 0 && isset($existingById[$entryId])) {
                $old = $existingById[$entryId];
                $oldJson = (string)$old['entry_data_json'];
                $contentChanged = !hash_equals(hash('sha256',$oldJson), hash('sha256',$json)) || $old['status'] !== $status;
                $positionChanged = (int)$old['display_order'] !== (int)$position;
                if ($contentChanged) {
                    sceneiqCreateRevision($pdo, $userId, 'project_entry', $entryId, $oldJson, 'project entry update');
                    if ($old['status'] !== $status) {
                        $history = $pdo->prepare('INSERT INTO `sceneiq_project_status_history` (`project_entry_id`,`user_id`,`previous_status`,`new_status`,`changed_by`) VALUES (:entry,:user,:old,:new,:by)');
                        $history->execute([':entry'=>$entryId,':user'=>$userId,':old'=>$old['status'],':new'=>$status,':by'=>'admin']);
                    }
                }
                if ($contentChanged || $positionChanged) {
                    $update->execute([':status'=>$status,':data'=>$json,':operation_status'=>$operationStatus,':position'=>$position,':id'=>$entryId,':user_id'=>$userId]);
                }
                $used[$entryId] = true;
            } else {
                $insert->execute([':user_id'=>$userId,':status'=>$status,':data'=>$json,':operation_status'=>$operationStatus,':position'=>$position]);
                $used[(int)$pdo->lastInsertId()] = true;
            }
        }
        $softDelete = $pdo->prepare('UPDATE `sceneiq_project_entries` SET `deleted_at`=NOW() WHERE `id`=:id AND `user_id`=:user_id AND `deleted_at` IS NULL');
        foreach ($existingRows as $row) {
            $id = (int)$row['id'];
            if (!isset($used[$id])) {
                sceneiqCreateRevision($pdo, $userId, 'project_entry', $id, (string)$row['entry_data_json'], 'project entry removed');
                $softDelete->execute([':id'=>$id,':user_id'=>$userId]);
            }
        }
    }

    function sceneiqSaveDynamicFieldsTable(PDO $pdo, $userId, array $fields) {
        $userId = (int)$userId;
        $existingStmt = $pdo->prepare('SELECT `id`,`field_key`,`definition_json` FROM `sceneiq_dynamic_fields` WHERE `user_id`=:user_id');
        $existingStmt->execute([':user_id'=>$userId]);
        $existing = []; foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $existing[$r['field_key']] = $r;
        $active = [];
        $position = 0;
        foreach ($fields as $fieldKey => $definition) {
            $position++;
            if (!is_array($definition)) $definition = ['field-title'=>(string)$fieldKey,'default_value'=>(string)$definition];
            $fieldKey = (string)$fieldKey;
            if ($fieldKey === '') continue;
            if (strlen($fieldKey) > 191) throw new InvalidArgumentException('Dynamic Content field keys must not exceed 191 bytes: ' . substr($fieldKey,0,80));
            $definition['field-title'] = (string)($definition['field-title'] ?? $fieldKey);
            $definition['field_type'] = in_array(($definition['field_type'] ?? 'individual'), ['object','objects'], true) ? 'objects' : 'individual';
            $definition['element_type'] = (string)($definition['element_type'] ?? 'input');
            $definition['data_type'] = (string)($definition['data_type'] ?? 'string');
            $subFields = is_array($definition['fieldkeyvalue'] ?? null) ? $definition['fieldkeyvalue'] : [];
            $json = sceneiqEncodeTableJson($definition);
            $defaults = sceneiqEncodeTableJson(is_array($definition['default_values'] ?? null) ? $definition['default_values'] : []);
            $copyOf = sceneiqEncodeTableJson(is_array($definition['copy_button_for_each_of'] ?? null) ? $definition['copy_button_for_each_of'] : []);
            if (isset($existing[$fieldKey])) {
                $id = (int)$existing[$fieldKey]['id'];
                if (!hash_equals(hash('sha256',(string)$existing[$fieldKey]['definition_json']), hash('sha256',$json))) sceneiqCreateRevision($pdo,$userId,'dynamic_field',$id,(string)$existing[$fieldKey]['definition_json'],'dynamic field update');
                $stmt = $pdo->prepare('UPDATE `sceneiq_dynamic_fields` SET `field_title`=:title,`field_type`=:type,`element_type`=:element,`data_type`=:dtype,`default_value`=:default_value,`default_values_json`=:defaults,`button_url`=:url,`separate_paragraphs`=:paragraphs,`copy_button_for_each_of_json`=:copyof,`definition_json`=:definition,`sort_order`=:position,`deleted_at`=NULL WHERE `id`=:id AND `user_id`=:user_id');
                $stmt->execute([':title'=>$definition['field-title'],':type'=>$definition['field_type'],':element'=>$definition['element_type'],':dtype'=>$definition['data_type'],':default_value'=>isset($definition['default_value'])?(string)$definition['default_value']:'',':defaults'=>$defaults,':url'=>(string)($definition['button_url']??''),':paragraphs'=>(string)($definition['separate_paragraphs']??'no'),':copyof'=>$copyOf,':definition'=>$json,':position'=>$position,':id'=>$id,':user_id'=>$userId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO `sceneiq_dynamic_fields` (`user_id`,`field_key`,`field_title`,`field_type`,`element_type`,`data_type`,`default_value`,`default_values_json`,`button_url`,`separate_paragraphs`,`copy_button_for_each_of_json`,`definition_json`,`sort_order`) VALUES (:user_id,:key,:title,:type,:element,:dtype,:default_value,:defaults,:url,:paragraphs,:copyof,:definition,:position)');
                $stmt->execute([':user_id'=>$userId,':key'=>$fieldKey,':title'=>$definition['field-title'],':type'=>$definition['field_type'],':element'=>$definition['element_type'],':dtype'=>$definition['data_type'],':default_value'=>isset($definition['default_value'])?(string)$definition['default_value']:'',':defaults'=>$defaults,':url'=>(string)($definition['button_url']??''),':paragraphs'=>(string)($definition['separate_paragraphs']??'no'),':copyof'=>$copyOf,':definition'=>$json,':position'=>$position]);
                $id = (int)$pdo->lastInsertId();
            }
            $active[$fieldKey] = $id;
            $existingSubStmt = $pdo->prepare('SELECT `id`,`subfield_key`,`definition_json` FROM `sceneiq_dynamic_field_subfields` WHERE `field_id`=:field_id');
            $existingSubStmt->execute([':field_id'=>$id]);
            $existingSubs = []; foreach ($existingSubStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $existingSubs[$r['subfield_key']] = $r;
            $subPosition = 0; $activeSubKeys = [];
            foreach ($subFields as $subKey => $subDefinition) {
                $subPosition++; $subKey = (string)$subKey;
                if (strlen($subKey) > 191) throw new InvalidArgumentException('Dynamic Content subfield keys must not exceed 191 bytes: ' . substr($subKey,0,80));
                if (!is_array($subDefinition)) $subDefinition = ['field-title'=>$subKey,'default_value'=>(string)$subDefinition];
                $subDefinition['field-title'] = (string)($subDefinition['field-title'] ?? $subKey);
                $subDefinition['element_type'] = (string)($subDefinition['element_type'] ?? 'input');
                $subDefinition['data_type'] = (string)($subDefinition['data_type'] ?? 'string');
                $subJson = sceneiqEncodeTableJson($subDefinition);
                $subDefaults = sceneiqEncodeTableJson(is_array($subDefinition['default_values'] ?? null) ? $subDefinition['default_values'] : []);
                if (isset($existingSubs[$subKey])) {
                    $subId = (int)$existingSubs[$subKey]['id'];
                    if (!hash_equals(hash('sha256',(string)$existingSubs[$subKey]['definition_json']),hash('sha256',$subJson))) sceneiqCreateRevision($pdo,$userId,'dynamic_subfield',$subId,(string)$existingSubs[$subKey]['definition_json'],'dynamic subfield update');
                    $subCopyOf = sceneiqEncodeTableJson(is_array($subDefinition['copy_button_for_each_of'] ?? null) ? $subDefinition['copy_button_for_each_of'] : []);
                    $subUpdate = $pdo->prepare('UPDATE `sceneiq_dynamic_field_subfields` SET `field_title`=:title,`element_type`=:element,`data_type`=:dtype,`default_value`=:default_value,`default_values_json`=:defaults,`button_url`=:url,`separate_paragraphs`=:paragraphs,`copy_button_for_each_of_json`=:copyof,`definition_json`=:definition,`sort_order`=:position,`deleted_at`=NULL WHERE `id`=:id');
                    $subUpdate->execute([':title'=>$subDefinition['field-title'],':element'=>$subDefinition['element_type'],':dtype'=>$subDefinition['data_type'],':default_value'=>(string)($subDefinition['default_value']??''),':defaults'=>$subDefaults,':url'=>(string)($subDefinition['button_url']??''),':paragraphs'=>(string)($subDefinition['separate_paragraphs']??'no'),':copyof'=>$subCopyOf,':definition'=>$subJson,':position'=>$subPosition,':id'=>$subId]);
                } else {
                    $subCopyOf = sceneiqEncodeTableJson(is_array($subDefinition['copy_button_for_each_of'] ?? null) ? $subDefinition['copy_button_for_each_of'] : []);
                    $subInsert = $pdo->prepare('INSERT INTO `sceneiq_dynamic_field_subfields` (`user_id`,`field_id`,`subfield_key`,`field_title`,`element_type`,`data_type`,`default_value`,`default_values_json`,`button_url`,`separate_paragraphs`,`copy_button_for_each_of_json`,`definition_json`,`sort_order`) VALUES (:user_id,:field_id,:key,:title,:element,:dtype,:default_value,:defaults,:url,:paragraphs,:copyof,:definition,:position)');
                    $subInsert->execute([':user_id'=>$userId,':field_id'=>$id,':key'=>$subKey,':title'=>$subDefinition['field-title'],':element'=>$subDefinition['element_type'],':dtype'=>$subDefinition['data_type'],':default_value'=>(string)($subDefinition['default_value']??''),':defaults'=>$subDefaults,':url'=>(string)($subDefinition['button_url']??''),':paragraphs'=>(string)($subDefinition['separate_paragraphs']??'no'),':copyof'=>$subCopyOf,':definition'=>$subJson,':position'=>$subPosition]);
                }
                $activeSubKeys[$subKey] = true;
            }
            foreach ($existingSubs as $subKey => $subRow) {
                if (!isset($activeSubKeys[$subKey])) {
                    sceneiqCreateRevision($pdo,$userId,'dynamic_subfield',(int)$subRow['id'],(string)$subRow['definition_json'],'dynamic subfield removed');
                    $pdo->prepare('UPDATE `sceneiq_dynamic_field_subfields` SET `deleted_at`=NOW() WHERE `id`=:id')->execute([':id'=>(int)$subRow['id']]);
                }
            }
        }
        foreach ($existing as $fieldKey => $row) {
            if (!isset($active[$fieldKey])) {
                sceneiqCreateRevision($pdo,$userId,'dynamic_field',(int)$row['id'],(string)$row['definition_json'],'dynamic field removed');
                $pdo->prepare('UPDATE `sceneiq_dynamic_fields` SET `deleted_at`=NOW() WHERE `id`=:id AND `user_id`=:user_id')->execute([':id'=>(int)$row['id'],':user_id'=>$userId]);
            }
        }
    }

    function sceneiqSaveDynamicTabsTable(PDO $pdo, $userId, array $tabs) {
        $userId = (int)$userId;
        $existingStmt = $pdo->prepare('SELECT `id`,`tab_key`,`tab_config_json` FROM `sceneiq_dynamic_tabs` WHERE `user_id`=:user_id');
        $existingStmt->execute([':user_id'=>$userId]);
        $existingById = []; $existingByKey = [];
        foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $existingById[(int)$row['id']]=$row; $existingByKey[$row['tab_key']]=$row; }
        $retained = [];
        $saveOne = function(array &$tab, $parentId, $parentKey, $position) use (&$saveOne,$pdo,$userId,&$existingById,&$existingByKey,&$retained) {
            $name = (string)($tab['tab_name'] ?? ('Tab '.$position));
            $tabKey = (string)($tab['_tab_key'] ?? 'tab_' . substr(hash('sha256',$userId.'|'.$parentKey.'|'.$name.'|'.$position),0,48));
            $id = (int)($tab['_table_id'] ?? 0);
            if ($id <= 0 || !isset($existingById[$id])) {
                $id = isset($existingByKey[$tabKey]) ? (int)$existingByKey[$tabKey]['id'] : 0;
            }
            $submit = array_key_exists('submit_tab',$tab) ? (!empty($tab['submit_tab'])?1:0) : 1;
            $mode = (string)($tab['sub_tabs_mode'] ?? (!empty($tab['sub_tabs'])?'add':'none'));
            $additional = is_array($tab['additional_features'] ?? null) ? sceneiqEncodeTableJson($tab['additional_features']) : null;
            $config = $tab;
            unset($config['sub_tabs'],$config['dynamic_fields_display'],$config['_table_id'],$config['_tab_key'],$config['_expanded']);
            $configJson = sceneiqEncodeTableJson($config);
            if ($id > 0 && isset($existingById[$id])) {
                if (!hash_equals(hash('sha256',(string)$existingById[$id]['tab_config_json']),hash('sha256',$configJson))) sceneiqCreateRevision($pdo,$userId,'dynamic_tab',$id,(string)$existingById[$id]['tab_config_json'],'dynamic tab update');
                $stmt = $pdo->prepare('UPDATE `sceneiq_dynamic_tabs` SET `parent_tab_id`=:parent,`tab_key`=:tab_key,`tab_name`=:name,`submit_tab`=:submit,`sub_tabs_mode`=:mode,`additional_features_json`=:additional,`tab_config_json`=:config,`sort_order`=:position,`deleted_at`=NULL WHERE `id`=:id AND `user_id`=:user_id');
                $stmt->execute([':parent'=>$parentId,':tab_key'=>$tabKey,':name'=>$name,':submit'=>$submit,':mode'=>$mode,':additional'=>$additional,':config'=>$configJson,':position'=>$position,':id'=>$id,':user_id'=>$userId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO `sceneiq_dynamic_tabs` (`user_id`,`parent_tab_id`,`tab_key`,`tab_name`,`submit_tab`,`sub_tabs_mode`,`additional_features_json`,`tab_config_json`,`sort_order`) VALUES (:user_id,:parent,:tab_key,:name,:submit,:mode,:additional,:config,:position)');
                $stmt->execute([':user_id'=>$userId,':parent'=>$parentId,':tab_key'=>$tabKey,':name'=>$name,':submit'=>$submit,':mode'=>$mode,':additional'=>$additional,':config'=>$configJson,':position'=>$position]);
                $id = (int)$pdo->lastInsertId();
            }
            $retained[$id] = true;
            $mapDelete = $pdo->prepare('DELETE FROM `sceneiq_dynamic_tab_fields` WHERE `tab_id`=:tab_id');
            $mapDelete->execute([':tab_id'=>$id]);
            $fieldLookup = $pdo->prepare('SELECT `id` FROM `sceneiq_dynamic_fields` WHERE `user_id`=:user_id AND `field_key`=:field_key AND `deleted_at` IS NULL LIMIT 1');
            $mapInsert = $pdo->prepare('INSERT INTO `sceneiq_dynamic_tab_fields` (`user_id`,`tab_id`,`field_id`,`field_key`,`sort_order`) VALUES (:user_id,:tab_id,:field_id,:field_key,:sort_order)');
            $seenKeys = [];
            foreach (array_values(is_array($tab['dynamic_fields_display'] ?? null) ? $tab['dynamic_fields_display'] : []) as $fieldPosition=>$fieldKey) {
                $fieldKey = (string)$fieldKey;
                if ($fieldKey === '' || isset($seenKeys[$fieldKey])) continue;
                $seenKeys[$fieldKey] = true;
                $fieldLookup->execute([':user_id'=>$userId,':field_key'=>$fieldKey]);
                $mappedFieldId = $fieldLookup->fetchColumn();
                $mapInsert->execute([':user_id'=>$userId,':tab_id'=>$id,':field_id'=>($mappedFieldId === false ? null : (int)$mappedFieldId),':field_key'=>$fieldKey,':sort_order'=>$fieldPosition]);
            }
            $tab['_table_id'] = $id; $tab['_tab_key'] = $tabKey;
            $children = is_array($tab['sub_tabs'] ?? null) ? $tab['sub_tabs'] : [];
            foreach (array_values($children) as $childPosition=>&$child) {
                if (is_array($child)) $saveOne($child,$id,$tabKey,$childPosition+1);
            }
            unset($child);
        };
        foreach (array_values($tabs) as $position=>$tab) {
            if (is_array($tabs[$position])) $saveOne($tabs[$position],null,'root',$position+1);
        }
        $softDelete = $pdo->prepare('UPDATE `sceneiq_dynamic_tabs` SET `deleted_at`=NOW() WHERE `id`=:id AND `user_id`=:user_id');
        foreach ($existingById as $id=>$row) if (!isset($retained[$id])) $softDelete->execute([':id'=>$id,':user_id'=>$userId]);
    }

    function saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs = null) {
        $userId = (int)$userId;
        if ($userId <= 0) throw new InvalidArgumentException('A valid account is required to save configuration.');
        $settings = repairSettings(sceneiqDecodeConfigColumn($settings, 'settings', []));
        $dynamicFields = normalizeDynamicFieldDefinitions($dynamicFields);
        $dynamicTabs = normalizeDynamicTabs($dynamicTabs ?? []);
        $current = getUserConfig($pdo, $userId);
        $currentSettings = repairSettings(sceneiqDecodeConfigColumn($current['settings'], 'settings', []));
        $currentFields = normalizeDynamicFieldDefinitions($current['dynamic_fields']);
        $currentTabs = normalizeDynamicTabs($current['dynamic_tabs']);
        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) $pdo->beginTransaction();
            if (sceneiqEncodeTableJson($settings) !== sceneiqEncodeTableJson($currentSettings)) sceneiqSaveProjectEntriesTable($pdo,$userId,$settings);
            if (sceneiqEncodeTableJson($dynamicFields) !== sceneiqEncodeTableJson($currentFields)) sceneiqSaveDynamicFieldsTable($pdo,$userId,$dynamicFields);
            if (sceneiqEncodeTableJson($dynamicTabs) !== sceneiqEncodeTableJson($currentTabs)) sceneiqSaveDynamicTabsTable($pdo,$userId,$dynamicTabs);
            if ($ownsTransaction) $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    function sceneiqFindProjectEntryIndex(array $entries, $entryId) {
        foreach ($entries as $i=>$entry) if (is_array($entry) && (int)($entry['_db_id'] ?? 0) === (int)$entryId) return $i;
        return -1;
    }

    function sceneiqCopyTableData(PDO $pdo, $sourceUserId, $targetUserId, array $columns) {
        $source = getUserConfig($pdo,$sourceUserId); $target = getUserConfig($pdo,$targetUserId);
        if (!$source || !$target) throw new RuntimeException('Source or destination account data could not be loaded.');
        $settings = sceneiqDecodeConfigColumn($target['settings'],'settings',[]);
        $fields = in_array('dynamic_fields',$columns,true) ? normalizeDynamicFieldDefinitions($source['dynamic_fields']) : normalizeDynamicFieldDefinitions($target['dynamic_fields']);
        $tabs = in_array('dynamic_tabs',$columns,true) ? normalizeDynamicTabs($source['dynamic_tabs']) : normalizeDynamicTabs($target['dynamic_tabs']);
        return saveUserConfig($pdo,$targetUserId,$settings,$fields,$tabs);
    }

    /** Stable identity for a tab, including support for older tabs without _tab_key. */
    function sceneiqCopyTabIdentity(array $tab, $path) {
        $key = trim((string)($tab['_tab_key'] ?? ''));
        return $key !== '' ? $key : '__legacy_' . hash('sha256', (string)$path . '|' . (string)($tab['tab_name'] ?? ''));
    }

    /** Flatten source tabs for the copy picker while preserving their hierarchy in labels. */
    function sceneiqFlattenCopyTabs(array $tabs, $parentPath = '', $path = 'root') {
        $result = [];
        $position = 0;
        foreach ($tabs as $tab) {
            if (!is_array($tab)) continue;
            $position++;
            $tabPath = $path . '.' . $position;
            $identity = sceneiqCopyTabIdentity($tab, $tabPath);
            $name = trim((string)($tab['tab_name'] ?? ('Tab ' . $position)));
            $label = $parentPath === '' ? $name : $parentPath . '  ›  ' . $name;
            $result[] = ['key' => $identity, 'name' => $name, 'label' => $label];
            $children = is_array($tab['sub_tabs'] ?? null) ? $tab['sub_tabs'] : [];
            if ($children) {
                $result = array_merge($result, sceneiqFlattenCopyTabs($children, $label, $tabPath));
            }
        }
        return $result;
    }

    /**
     * Merge only checked tabs. Unchecked destination tabs survive. A selected
     * source tab with the same stable key replaces that destination tab in full.
     * Selected descendants can be copied even where their source parent is not
     * checked; in that case a lightweight parent container is created if needed.
     */
    function sceneiqMergeSelectedTabs(array $targetTabs, array $sourceTabs, array $selectedKeys, $path = 'root') {
        $result = array_values(array_filter($targetTabs, 'is_array'));
        $targetIndexByKey = [];
        foreach ($result as $index => $targetTab) {
            $targetPath = $path . '.' . ($index + 1);
            $targetIndexByKey[sceneiqCopyTabIdentity($targetTab, $targetPath)] = $index;
        }

        $sourcePosition = 0;
        foreach ($sourceTabs as $sourceTab) {
            if (!is_array($sourceTab)) continue;
            $sourcePosition++;
            $sourcePath = $path . '.' . $sourcePosition;
            $identity = sceneiqCopyTabIdentity($sourceTab, $sourcePath);
            $targetIndex = array_key_exists($identity, $targetIndexByKey) ? $targetIndexByKey[$identity] : null;
            $targetNode = ($targetIndex !== null) ? $result[$targetIndex] : null;
            $targetChildren = (is_array($targetNode) && is_array($targetNode['sub_tabs'] ?? null)) ? $targetNode['sub_tabs'] : [];
            $sourceChildren = is_array($sourceTab['sub_tabs'] ?? null) ? $sourceTab['sub_tabs'] : [];
            $mergedChildren = sceneiqMergeSelectedTabs($targetChildren, $sourceChildren, $selectedKeys, $sourcePath);
            $isSelected = isset($selectedKeys[$identity]);

            if ($isSelected) {
                // Replace the complete tab configuration, but do not discard
                // destination-only children that the operator did not select.
                $replacement = $sourceTab;
                $replacement['sub_tabs'] = $mergedChildren;
                if ($targetIndex !== null) {
                    $result[$targetIndex] = $replacement;
                } else {
                    $targetIndexByKey[$identity] = count($result);
                    $result[] = $replacement;
                }
            } elseif ($mergedChildren) {
                if ($targetIndex !== null) {
                    $result[$targetIndex]['sub_tabs'] = $mergedChildren;
                } else {
                    // A child was selected without its parent. Preserve only
                    // enough parent structure to keep that copied child nested.
                    $container = [
                        'tab_name' => (string)($sourceTab['tab_name'] ?? 'Tab'),
                        'sub_tabs_mode' => 'add',
                        'dynamic_fields_display' => [],
                        'sub_tabs' => $mergedChildren
                    ];
                    if (!empty($sourceTab['_tab_key'])) $container['_tab_key'] = $sourceTab['_tab_key'];
                    $targetIndexByKey[$identity] = count($result);
                    $result[] = $container;
                }
            }
        }
        return array_values($result);
    }

    /** Copy a selected subset of Dynamic Content definitions, replacing matching keys only. */
    function sceneiqMergeSelectedFields(array $targetFields, array $sourceFields, array $selectedKeys) {
        foreach ($selectedKeys as $fieldKey => $enabled) {
            if (is_int($fieldKey)) {
                $fieldKey = (string)$enabled;
                $enabled = true;
            }
            $fieldKey = (string)$fieldKey;
            if (!$enabled || !array_key_exists($fieldKey, $sourceFields)) continue;
            // The entire definition is copied, including object subfields and all
            // element settings. Same key = complete replacement; new key = append.
            $targetFields[$fieldKey] = $sourceFields[$fieldKey];
        }
        return $targetFields;
    }

    /** Merge selected source data into one destination account without touching projects. */
    function sceneiqCopySelectedDataToAccount(PDO $pdo, $sourceUserId, $targetUserId, array $selectedTabKeys, array $selectedFieldKeys, $copyTabs, $copyFields, ?array $sourceConfig = null) {
        $sourceUserId = (int)$sourceUserId;
        $targetUserId = (int)$targetUserId;
        if ($sourceUserId <= 0 || $targetUserId <= 0 || $sourceUserId === $targetUserId) {
            throw new InvalidArgumentException('Choose different valid source and destination accounts.');
        }
        if ($sourceConfig === null) $sourceConfig = getUserConfig($pdo, $sourceUserId);
        $targetConfig = getUserConfig($pdo, $targetUserId);
        if (!$sourceConfig || !$targetConfig) throw new RuntimeException('Source or destination account data could not be loaded.');

        $sourceFields = normalizeDynamicFieldDefinitions($sourceConfig['dynamic_fields'] ?? '[]');
        $targetFields = normalizeDynamicFieldDefinitions($targetConfig['dynamic_fields'] ?? '[]');
        $sourceTabs = normalizeDynamicTabs($sourceConfig['dynamic_tabs'] ?? '[]');
        $targetTabs = normalizeDynamicTabs($targetConfig['dynamic_tabs'] ?? '[]');
        $settings = repairSettings(sceneiqDecodeConfigColumn($targetConfig['settings'] ?? '[]', 'settings', []));

        $mergedFields = $copyFields
            ? sceneiqMergeSelectedFields($targetFields, $sourceFields, $selectedFieldKeys)
            : $targetFields;

        if ($copyTabs) {
            $selectedTabSet = [];
            foreach ($selectedTabKeys as $tabKey) {
                $tabKey = (string)$tabKey;
                if ($tabKey !== '') $selectedTabSet[$tabKey] = true;
            }
            $mergedTabs = sceneiqMergeSelectedTabs($targetTabs, $sourceTabs, $selectedTabSet);
        } else {
            $mergedTabs = $targetTabs;
        }

        return saveUserConfig($pdo, $targetUserId, $settings, $mergedFields, $mergedTabs);
    }

    /**
     * Copy selected items to multiple destinations atomically. If any destination
     * fails, the transaction rolls back for every destination in this operation.
     */
    function sceneiqCopySelectedDataToAccounts(PDO $pdo, $sourceUserId, array $targetUserIds, array $selectedTabKeys, array $selectedFieldKeys, $copyTabs, $copyFields) {
        $sourceUserId = (int)$sourceUserId;
        $sourceConfig = getUserConfig($pdo, $sourceUserId);
        if (!$sourceConfig) throw new RuntimeException('The source account data could not be loaded.');
        $targets = [];
        foreach ($targetUserIds as $targetId) {
            $targetId = (int)$targetId;
            if ($targetId > 0 && $targetId !== $sourceUserId) $targets[$targetId] = $targetId;
        }
        if (!$targets) throw new InvalidArgumentException('Select at least one destination account.');

        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) $pdo->beginTransaction();
            foreach ($targets as $targetId) {
                if (!getUserById($pdo, $targetId)) throw new RuntimeException('A selected destination account no longer exists.');
                sceneiqCopySelectedDataToAccount(
                    $pdo, $sourceUserId, $targetId, $selectedTabKeys, $selectedFieldKeys,
                    (bool)$copyTabs, (bool)$copyFields, $sourceConfig
                );
            }
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return count($targets);
    }

    function sceneiqSoftDeleteDynamicData(PDO $pdo, $userId, $scope) {
        $userId = (int)$userId;
        $pdo->beginTransaction();
        try {
            if (in_array($scope,['dynamic_content','dynamic_both'],true)) {
                $pdo->prepare('UPDATE `sceneiq_dynamic_fields` SET `deleted_at`=NOW() WHERE `user_id`=:user_id AND `deleted_at` IS NULL')->execute([':user_id'=>$userId]);
            }
            if (in_array($scope,['dynamic_tabs','dynamic_both'],true)) {
                $pdo->prepare('UPDATE `sceneiq_dynamic_tabs` SET `deleted_at`=NOW() WHERE `user_id`=:user_id AND `deleted_at` IS NULL')->execute([':user_id'=>$userId]);
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    function sceneiqDeleteUserTableData(PDO $pdo, $userId) {
        $userId = (int)$userId;
        foreach (['sceneiq_project_entries','sceneiq_dynamic_fields','sceneiq_dynamic_tabs'] as $table) {
            $stmt = $pdo->prepare('UPDATE `' . $table . '` SET `deleted_at`=NOW() WHERE `user_id`=:user_id AND `deleted_at` IS NULL');
            $stmt->execute([':user_id'=>$userId]);
        }
    }


    // Convert submitted dynamic-field values according to their configured type.
    // JSON values remain arrays/objects/numbers/booleans/null; integers remain numeric.
    function castDynamicValueByType($value, $type) {
        $type = strtolower((string)$type);
        if ($value === null) return null;

        // JSON-looking content is data, not a string. Detect it even when an old
        // or incorrectly configured field says "string", so pasted objects and
        // arrays are retained as native JSON structures in project entry payloads.
        if (is_string($value)) {
            $jsonText = trim($value);
            $looksLikeJsonContainer = ($jsonText !== '' && ($jsonText[0] === '{' || $jsonText[0] === '['));
            $looksLikePropertyFragment = ($jsonText !== '' &&
                preg_match('/^"[^"]+"\s*:/s', $jsonText) === 1);
            $looksLikeEncodedJsonString = false;
            if ($jsonText !== '' && $jsonText[0] === '"') {
                $outerDecoded = json_decode($jsonText, true);
                if (json_last_error() === JSON_ERROR_NONE && is_string($outerDecoded)) {
                    $innerText = trim($outerDecoded);
                    $looksLikeEncodedJsonString = ($innerText !== '' &&
                        ($innerText[0] === '{' || $innerText[0] === '[' ||
                         preg_match('/^"[^"]+"\s*:/s', $innerText) === 1));
                }
            }
            if ($type === 'json' || $looksLikeJsonContainer || $looksLikePropertyFragment || $looksLikeEncodedJsonString) {
                if ($jsonText === '') return null;
                $decoded = json_decode($jsonText, true);
                if (json_last_error() !== JSON_ERROR_NONE && $looksLikePropertyFragment) {
                    $decoded = json_decode('{' . $jsonText . '}', true);
                }
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidArgumentException('This value looks like JSON but is invalid. Paste valid JSON with matching quotes, commas, braces and brackets.');
                }
                // Unwrap a JSON-encoded string only when the string itself contains
                // an object, array, or property fragment. Keep ordinary text strings intact.
                if (is_string($decoded)) {
                    $innerText = trim($decoded);
                    $innerLooksLikeJson = ($innerText !== '' &&
                        ($innerText[0] === '{' || $innerText[0] === '[' ||
                         preg_match('/^"[^"]+"\s*:/s', $innerText) === 1));
                    if ($innerLooksLikeJson) {
                        return castDynamicValueByType($innerText, 'json');
                    }
                }
                return $decoded;
            }
        } elseif ($type === 'json') {
            // Values that were already parsed by the browser stay native.
            if (is_array($value) || is_object($value) || is_int($value) || is_float($value) || is_bool($value)) return $value;
        }

        if ($type === 'json') {
            if (is_array($value) || is_object($value) || is_int($value) || is_float($value) || is_bool($value)) return $value;
            throw new InvalidArgumentException('A field configured as JSON must contain valid JSON.');
        }
        if ($type === 'int' || $type === 'integer') {
            if ($value === '') return '';
            if (is_int($value)) return $value;
            if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) return (int)trim($value);
            if (is_float($value) && floor($value) === $value) return (int)$value;
            throw new InvalidArgumentException('A field configured as Integer must contain a whole number.');
        }
        return is_string($value) ? $value : (is_scalar($value) ? (string)$value : $value);
    }

    function repairSettings($settings) {
        // The settings column must be represented as a list of entry records.
        // Older configurations may contain either a list of records, one record
        // directly, or a keyed collection of records. Never return a flat object
        // whose scalar field values would be mistaken for separate entries.
        if (!is_array($settings) || empty($settings)) {
            return [];
        }

        // Standard list of entries. Filter malformed scalar members so all
        // callers can safely access entry fields such as status and _index.
        if (isset($settings[0]) && is_array($settings[0])) {
            return array_values(array_filter($settings, function ($item) {
                return is_array($item);
            }));
        }

        // A single entry may contain built-in metadata or only dynamic fields.
        if (
            array_key_exists('status', $settings) ||
            array_key_exists('dynamic_values', $settings) ||
            array_key_exists('operation_status', $settings)
        ) {
            return [$settings];
        }

        // Legacy keyed collection: recognize nested records carrying entry
        // metadata and retain those records in their existing iteration order.
        $result = [];
        foreach ($settings as $value) {
            if (
                is_array($value) &&
                (array_key_exists('status', $value) ||
                 array_key_exists('dynamic_values', $value) ||
                 array_key_exists('operation_status', $value))
            ) {
                $result[] = $value;
            }
        }

        if (!empty($result)) {
            return array_values($result);
        }

        // A flat associative object with only user-defined fields is one entry,
        // not a collection of scalar entries. Wrapping it preserves every value.
        return [$settings];
    }

    /**
     * Normalize the stored dynamic_tabs JSON into a safe, sequential list of tab
     * records. Older/corrupt configurations can contain scalar strings among the
     * tab records, or a single tab object instead of a list. Rendering and the
     * browser-side editor expect every root and child tab to be an array/object.
     * Invalid scalar records are skipped; valid tab properties are preserved.
     */
    function sceneiqValueToText($value, $fallback = '') {
        if ($value === null) return (string)$fallback;
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        }
        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return ($encoded !== false) ? $encoded : (string)$fallback;
        }
        return (string)$fallback;
    }

    function sceneiqNormalizeValueList($values) {
        if (is_string($values)) {
            $decoded = json_decode($values, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $values = $decoded;
            } else {
                $values = ($values === '') ? [] : [$values];
            }
        }
        if (!is_array($values)) return [];

        $normalized = [];
        foreach ($values as $value) {
            if (is_scalar($value) || $value === null) {
                $normalized[] = $value === null ? '' : $value;
            }
        }
        return array_values($normalized);
    }

    /**
     * Inspect and, when necessary, decode SceneIQ's compressed Dynamic Content envelope.
     *
     * The format marker is gzip-json-v1. The historical payload in the supplied
     * database uses Base64 + zlib/DEFLATE (PHP gzcompress/gzuncompress), despite
     * the legacy marker name. We verify both the original byte count and SHA-256
     * before accepting the recovered JSON. This function is account-agnostic.
     */
    /**
     * Inspect a SceneIQ gzip-json-v1 storage envelope in any configuration column.
     * The known format is Base64 + zlib/DEFLATE (gzcompress/gzuncompress).
     * It is accepted only after byte-count, SHA-256 and JSON validation succeed.
     */
    function sceneiqInspectStorageEnvelope($storedValue) {
        $rawJson = is_string($storedValue) ? $storedValue : null;
        if (is_string($storedValue)) {
            $decodedEnvelope = json_decode($storedValue, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                if (strpos($storedValue, '__sceneiq_storage') !== false) {
                    throw new RuntimeException('A SceneIQ storage marker was found, but its JSON envelope is malformed. The original data was left untouched.');
                }
                return ['is_envelope' => false, 'value' => [], 'raw_json' => $rawJson];
            }
            $value = $decodedEnvelope;
        } else {
            $value = is_array($storedValue) ? $storedValue : [];
        }

        if (!is_array($value) || !array_key_exists('__sceneiq_storage', $value)) {
            return [
                'is_envelope' => false,
                'value' => is_array($value) ? $value : [],
                'raw_json' => $rawJson
            ];
        }

        if (!is_string($value['__sceneiq_storage'])) {
            throw new RuntimeException('The SceneIQ storage marker is malformed. No data was changed.');
        }
        $storageMarker = $value['__sceneiq_storage'];
        if ($storageMarker !== 'gzip-json-v1') {
            throw new RuntimeException('Unsupported SceneIQ storage format: ' . $storageMarker);
        }

        $expectedBytes = filter_var($value['original_bytes'] ?? null, FILTER_VALIDATE_INT);
        $expectedHash = strtolower((string)($value['sha256'] ?? ''));
        $payload = $value['payload'] ?? null;

        if ($expectedBytes === false || $expectedBytes <= 0 || $expectedBytes > (64 * 1024 * 1024)) {
            throw new RuntimeException('The compressed configuration has invalid or excessive original_bytes metadata. No data was changed.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            throw new RuntimeException('The compressed configuration has an invalid SHA-256 checksum. No data was changed.');
        }
        if (!is_string($payload) || $payload === '' || strlen($payload) > (96 * 1024 * 1024)) {
            throw new RuntimeException('The compressed configuration payload is missing or exceeds the safe size limit. No data was changed.');
        }

        $binary = base64_decode($payload, true);
        if ($binary === false) {
            throw new RuntimeException('The compressed configuration payload is not valid Base64. No data was changed.');
        }

        $plain = false;
        $method = '';
        $maxOutputBytes = (int)$expectedBytes + 1;

        // Primary format found in the supplied account data: zlib header 78 9C.
        if (function_exists('gzuncompress')) {
            $candidate = @gzuncompress($binary, $maxOutputBytes);
            if ($candidate !== false) {
                $plain = $candidate;
                $method = 'zlib/gzuncompress';
            }
        }
        // Compatibility with GZIP, raw DEFLATE, and zlib-body variants.
        if ($plain === false && function_exists('gzdecode') && substr($binary, 0, 2) === "\x1f\x8b") {
            $candidate = @gzdecode($binary, $maxOutputBytes);
            if ($candidate !== false) { $plain = $candidate; $method = 'gzip/gzdecode'; }
        }
        if ($plain === false && function_exists('gzinflate')) {
            $candidate = @gzinflate($binary, $maxOutputBytes);
            if ($candidate !== false) { $plain = $candidate; $method = 'raw-deflate/gzinflate'; }
        }
        if ($plain === false && function_exists('gzinflate') && strlen($binary) > 6) {
            $candidate = @gzinflate(substr($binary, 2, -4), $maxOutputBytes);
            if ($candidate !== false) { $plain = $candidate; $method = 'zlib-body/gzinflate'; }
        }
        if ($plain === false) {
            throw new RuntimeException('SceneIQ could not decompress this configuration payload. The original data has been left untouched.');
        }

        $actualBytes = strlen($plain);
        if ($actualBytes !== (int)$expectedBytes) {
            throw new RuntimeException('Recovered configuration size (' . $actualBytes . ' bytes) does not match recorded size (' . (int)$expectedBytes . ' bytes). No data was changed.');
        }
        $actualHash = hash('sha256', $plain);
        if (!hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('The compressed configuration SHA-256 checksum does not match. No data was changed.');
        }
        try {
            $decodedPlain = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('The verified compressed bytes do not contain valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($decodedPlain) || array_key_exists('__sceneiq_storage', $decodedPlain)) {
            throw new RuntimeException('The verified payload is not a valid SceneIQ configuration array. No data was changed.');
        }

        return [
            'is_envelope' => true,
            'value' => $decodedPlain,
            'raw_json' => $rawJson,
            'plain_json' => $plain,
            'method' => $method,
            'original_bytes' => $actualBytes,
            'sha256' => $actualHash,
            'compressed_bytes' => strlen($binary)
        ];
    }

    /** Decode a regular JSON config column or a verified compressed envelope. */
    function sceneiqDecodeConfigColumn($storedValue, $columnName, $fallback = []) {
        $inspection = sceneiqInspectStorageEnvelope($storedValue);
        $value = $inspection['value'] ?? $fallback;
        if (!is_array($value)) {
            throw new RuntimeException('The recovered ' . $columnName . ' configuration is not an array.');
        }
        return $value;
    }

    function normalizeDynamicFieldDefinitions($fields) {
        // This is deliberately account-agnostic: every user's compressed
        // gzip-json-v1 envelope is decoded and verified before field rendering.
        $storageResult = sceneiqInspectStorageEnvelope($fields);
        $fields = $storageResult['value'] ?? [];
        if (!is_array($fields) || empty($fields)) return [];

        // A single field definition may have been stored without its outer map key.
        if (array_key_exists('field_type', $fields) || array_key_exists('field-title', $fields) || array_key_exists('field-name', $fields)) {
            $singleKey = sceneiqValueToText($fields['field-name'] ?? 'field_1', 'field_1');
            $fields = [$singleKey => $fields];
        }

        $normalized = [];
        foreach ($fields as $fieldKey => $field) {
            $fieldKey = sceneiqValueToText($fieldKey, 'field');

            if (is_string($field)) {
                $decodedField = json_decode($field, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedField)) {
                    $field = $decodedField;
                }
            }

            if (!is_array($field)) {
                $field = [
                    'field-title' => $fieldKey,
                    'field_type' => 'individual',
                    'element_type' => 'input',
                    'data_type' => 'string',
                    'default_value' => sceneiqValueToText($field)
                ];
            }

            $field['field-title'] = sceneiqValueToText($field['field-title'] ?? $fieldKey, $fieldKey);
            $fieldType = strtolower(sceneiqValueToText($field['field_type'] ?? 'individual', 'individual'));
            $field['field_type'] = in_array($fieldType, ['objects', 'object'], true) ? 'objects' : 'individual';
            $field['element_type'] = sceneiqValueToText($field['element_type'] ?? 'input', 'input');
            $field['default_value'] = sceneiqValueToText($field['default_value'] ?? '');
            $field['default_values'] = sceneiqNormalizeValueList($field['default_values'] ?? []);
            $field['button_url'] = sceneiqValueToText($field['button_url'] ?? '');
            $field['separate_paragraphs'] = sceneiqValueToText($field['separate_paragraphs'] ?? 'no', 'no');
            $field['copy_button_for_each_of'] = sceneiqNormalizeValueList($field['copy_button_for_each_of'] ?? []);

            $dataType = strtolower(sceneiqValueToText($field['data_type'] ?? 'string', 'string'));
            $field['data_type'] = in_array($dataType, ['string', 'int', 'integer', 'json'], true) ? $dataType : 'string';

            if ($field['field_type'] === 'objects') {
                $subFields = $field['fieldkeyvalue'] ?? [];
                if (is_string($subFields)) {
                    $decodedSubFields = json_decode($subFields, true);
                    $subFields = (json_last_error() === JSON_ERROR_NONE && is_array($decodedSubFields)) ? $decodedSubFields : [];
                }
                if (!is_array($subFields)) $subFields = [];

                $normalizedSubFields = [];
                foreach ($subFields as $subKey => $subField) {
                    $subKey = sceneiqValueToText($subKey, 'field');
                    if (is_string($subField)) {
                        $decodedSubField = json_decode($subField, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedSubField)) {
                            $subField = $decodedSubField;
                        }
                    }
                    if (!is_array($subField)) {
                        $subField = [
                            'field-title' => $subKey,
                            'element_type' => 'input',
                            'data_type' => 'string',
                            'default_value' => sceneiqValueToText($subField)
                        ];
                    }
                    $subField['field-title'] = sceneiqValueToText($subField['field-title'] ?? $subKey, $subKey);
                    $subField['element_type'] = sceneiqValueToText($subField['element_type'] ?? 'input', 'input');
                    $subField['data_type'] = in_array(strtolower(sceneiqValueToText($subField['data_type'] ?? 'string', 'string')), ['string', 'int', 'integer', 'json'], true)
                        ? strtolower(sceneiqValueToText($subField['data_type'] ?? 'string', 'string'))
                        : 'string';
                    $subField['default_value'] = sceneiqValueToText($subField['default_value'] ?? '');
                    $subField['default_values'] = sceneiqNormalizeValueList($subField['default_values'] ?? []);
                    $subField['button_url'] = sceneiqValueToText($subField['button_url'] ?? '');
                    $subField['separate_paragraphs'] = sceneiqValueToText($subField['separate_paragraphs'] ?? 'no', 'no');
                    $subField['copy_button_for_each_of'] = sceneiqNormalizeValueList($subField['copy_button_for_each_of'] ?? []);
                    $normalizedSubFields[$subKey] = $subField;
                }
                $field['fieldkeyvalue'] = $normalizedSubFields;
            }

            $normalized[$fieldKey] = $field;
        }
        return $normalized;
    }

    /** Keep Dynamic Tabs linked when an edited field key is renamed. */
    function sceneiqReplaceDynamicFieldKeyInTabs(array &$tabs, $oldKey, $newKey) {
        foreach ($tabs as &$tab) {
            if (!is_array($tab)) continue;
            if (isset($tab['dynamic_fields_display']) && is_array($tab['dynamic_fields_display'])) {
                foreach ($tab['dynamic_fields_display'] as &$fieldKey) {
                    if ((string)$fieldKey === (string)$oldKey) $fieldKey = (string)$newKey;
                }
                unset($fieldKey);
            }
            if (isset($tab['sub_tabs']) && is_array($tab['sub_tabs'])) {
                sceneiqReplaceDynamicFieldKeyInTabs($tab['sub_tabs'], $oldKey, $newKey);
            }
        }
        unset($tab);
    }

    /**
     * Validate a submitted Dynamic Content definition without throwing away
     * configurable properties. Keep the complete definition so fields edited in
     * the browser (including URLs, JSON types, paragraph options and copy lists)
     * round-trip to the relational tables exactly as configured.
     */
    function sceneiqPrepareSubmittedDynamicField(array $fieldData, $requestedKey) {
        $fieldKey = strtolower(trim((string)$requestedKey));
        $fieldKey = preg_replace('/\s+/', '_', $fieldKey);
        $fieldKey = preg_replace('/[^a-z0-9_]/', '', $fieldKey);
        if ($fieldKey === '') {
            throw new InvalidArgumentException('Enter a valid Dynamic Content field key.');
        }
        if (strlen($fieldKey) > 191) {
            throw new InvalidArgumentException('Dynamic Content field keys must not exceed 191 bytes.');
        }

        $fieldData['field-name'] = $fieldKey;
        $fieldData['field-title'] = trim(sceneiqValueToText($fieldData['field-title'] ?? $fieldKey, $fieldKey));
        if ($fieldData['field-title'] === '') $fieldData['field-title'] = $fieldKey;

        $fieldType = strtolower(trim((string)($fieldData['field_type'] ?? 'individual')));
        $fieldData['field_type'] = in_array($fieldType, ['object', 'objects'], true) ? 'objects' : 'individual';

        if ($fieldData['field_type'] === 'objects') {
            $submittedSubFields = $fieldData['fieldkeyvalue'] ?? [];
            if (is_string($submittedSubFields)) {
                $decoded = json_decode($submittedSubFields, true);
                $submittedSubFields = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
            }
            if (!is_array($submittedSubFields)) {
                throw new InvalidArgumentException('Object field sub-fields must be a valid collection.');
            }

            $normalizedSubFields = [];
            foreach ($submittedSubFields as $rawSubKey => $rawSubField) {
                $subKey = strtolower(trim((string)$rawSubKey));
                $subKey = preg_replace('/\s+/', '_', $subKey);
                $subKey = preg_replace('/[^a-z0-9_]/', '', $subKey);
                if ($subKey === '') {
                    throw new InvalidArgumentException('Every sub-field must have a valid field key.');
                }
                if (strlen($subKey) > 191) {
                    throw new InvalidArgumentException('Dynamic Content subfield keys must not exceed 191 bytes: ' . substr($subKey, 0, 80));
                }
                if (array_key_exists($subKey, $normalizedSubFields)) {
                    throw new InvalidArgumentException('Duplicate sub-field key: ' . $subKey);
                }
                if (!is_array($rawSubField)) $rawSubField = [];

                $rawSubField['field-name'] = $subKey;
                $rawSubField['field-title'] = trim(sceneiqValueToText($rawSubField['field-title'] ?? $subKey, $subKey));
                if ($rawSubField['field-title'] === '') $rawSubField['field-title'] = $subKey;
                $rawSubField['field_type'] = 'individual';
                $rawSubField['element_type'] = sceneiqValueToText($rawSubField['element_type'] ?? 'input', 'input');
                $subDataType = strtolower(trim(sceneiqValueToText($rawSubField['data_type'] ?? 'string', 'string')));
                if (($rawSubField['element_type'] ?? '') === 'multiple-selections') $subDataType = 'json';
                $rawSubField['data_type'] = $subDataType === 'integer' ? 'int' : (in_array($subDataType, ['string', 'int', 'json'], true) ? $subDataType : 'string');
                $rawSubField['default_value'] = sceneiqValueToText($rawSubField['default_value'] ?? '');
                $rawSubField['default_values'] = is_array($rawSubField['default_values'] ?? null) ? $rawSubField['default_values'] : [];
                $rawSubField['button_url'] = sceneiqValueToText($rawSubField['button_url'] ?? '');
                $rawSubField['separate_paragraphs'] = (($rawSubField['separate_paragraphs'] ?? 'no') === 'yes') ? 'yes' : 'no';
                $rawSubField['copy_button_for_each_of'] = is_array($rawSubField['copy_button_for_each_of'] ?? null) ? $rawSubField['copy_button_for_each_of'] : [];
                $normalizedSubFields[$subKey] = $rawSubField;
            }

            $fieldData['fieldkeyvalue'] = $normalizedSubFields;
            return $fieldData;
        }

        $fieldData['element_type'] = sceneiqValueToText($fieldData['element_type'] ?? 'input', 'input');
        $dataType = strtolower(trim(sceneiqValueToText($fieldData['data_type'] ?? 'string', 'string')));
        if ($fieldData['element_type'] === 'multiple-selections') $dataType = 'json';
        $fieldData['data_type'] = $dataType === 'integer' ? 'int' : (in_array($dataType, ['string', 'int', 'json'], true) ? $dataType : 'string');
        $fieldData['default_value'] = sceneiqValueToText($fieldData['default_value'] ?? '');
        $fieldData['default_values'] = is_array($fieldData['default_values'] ?? null) ? $fieldData['default_values'] : [];
        $fieldData['button_url'] = sceneiqValueToText($fieldData['button_url'] ?? '');
        $fieldData['separate_paragraphs'] = (($fieldData['separate_paragraphs'] ?? 'no') === 'yes') ? 'yes' : 'no';
        $fieldData['copy_button_mode'] = (($fieldData['copy_button_mode'] ?? 'individually') === 'bulk') ? 'bulk' : 'individually';
        $fieldData['copy_button_for_each_of'] = is_array($fieldData['copy_button_for_each_of'] ?? null) ? $fieldData['copy_button_for_each_of'] : [];
        unset($fieldData['fieldkeyvalue']);
        return $fieldData;
    }

    /**
     * Normalize root and nested tab records. Scalar legacy entries are retained as
     * simple tab names; invalid nested values cannot break PHP rendering.
     */
    function normalizeDynamicTabs($tabs) {
        $storageResult = sceneiqInspectStorageEnvelope($tabs);
        $tabs = $storageResult['value'] ?? [];
        if (is_string($tabs)) {
            $decoded = json_decode($tabs, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $tabs = $decoded;
            } else {
                $tabs = ($tabs === '') ? [] : [['tab_name' => $tabs]];
            }
        }

        if (!is_array($tabs) || empty($tabs)) return [];

        // Accept a legacy single-tab object as well as the normal list of tabs.
        if (array_key_exists('tab_name', $tabs) || array_key_exists('dynamic_fields_display', $tabs)) {
            $tabs = [$tabs];
        }

        $normalizeOneTab = function ($tab, $fallbackName) use (&$normalizeOneTab) {
            if (is_string($tab)) {
                $decodedTab = json_decode($tab, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedTab)) {
                    $tab = $decodedTab;
                } else {
                    $tab = ['tab_name' => $tab];
                }
            } elseif (!is_array($tab)) {
                $tab = ['tab_name' => sceneiqValueToText($tab, $fallbackName)];
            }

            $tab['tab_name'] = sceneiqValueToText($tab['tab_name'] ?? $fallbackName, $fallbackName);
            $tab['dynamic_fields_display'] = sceneiqNormalizeValueList($tab['dynamic_fields_display'] ?? []);

            $children = [];
            $rawChildren = $tab['sub_tabs'] ?? [];
            if (is_string($rawChildren)) {
                $decodedChildren = json_decode($rawChildren, true);
                $rawChildren = (json_last_error() === JSON_ERROR_NONE && is_array($decodedChildren)) ? $decodedChildren : [];
            }
            if (is_array($rawChildren)) {
                // Array keys may be names such as "intro" or "details". Never
                // perform arithmetic on a foreach key; use a numeric position.
                $childPosition = 0;
                foreach ($rawChildren as $child) {
                    $childPosition++;
                    $fallbackChildName = 'Sub Tab ' . $childPosition;

                    if (is_string($child)) {
                        $decodedChild = json_decode($child, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedChild)) {
                            $child = $decodedChild;
                        } else {
                            $child = ['tab_name' => $child];
                        }
                    } elseif (!is_array($child)) {
                        $child = ['tab_name' => sceneiqValueToText($child, $fallbackChildName)];
                    }
                    $child['tab_name'] = sceneiqValueToText($child['tab_name'] ?? $fallbackChildName, $fallbackChildName);
                    $child['dynamic_fields_display'] = sceneiqNormalizeValueList($child['dynamic_fields_display'] ?? []);
                    $children[] = $child;
                }
            }
            $tab['sub_tabs'] = $children;
            $tab['sub_tabs_mode'] = sceneiqValueToText($tab['sub_tabs_mode'] ?? (!empty($children) ? 'add' : 'none'));
            return $tab;
        };

        $normalized = [];
        // Stored tabs can be associative (for example, keyed by a tab slug),
        // so their foreach keys are not guaranteed to be integers. Generate
        // display fallback names from a separate numeric counter instead.
        $tabPosition = 0;
        foreach ($tabs as $tab) {
            $tabPosition++;
            $normalized[] = $normalizeOneTab($tab, 'Tab ' . $tabPosition);
        }
        return $normalized;
    }

    // Build and save one New Project entry. Both the regular POST fallback and the
    // chunked uploader use this exact path, keeping submit-tab filtering and types intact.
    function addNewProjectEntry($pdo, $userId, $entryData) {
        $userId = (int)$userId;
        if ($userId <= 0 || !is_array($entryData) || empty($entryData)) {
            throw new InvalidArgumentException('No valid entry data was received.');
        }
        $config = getUserConfig($pdo, $userId);
        $dynamicFields = normalizeDynamicFieldDefinitions($config['dynamic_fields'] ?? '[]');
        $dynamicTabs = normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]');
        $submittedFieldKeys = [];
        $collectSubmitFields = function($tab) use (&$collectSubmitFields,&$submittedFieldKeys) {
            if (!is_array($tab)) return;
            foreach (($tab['dynamic_fields_display'] ?? []) as $fieldKey) $submittedFieldKeys[(string)$fieldKey] = true;
            $children = is_array($tab['sub_tabs'] ?? null) ? $tab['sub_tabs'] : [];
            foreach ($children as $child) $collectSubmitFields($child);
        };
        foreach ($dynamicTabs as $tab) {
            if (!is_array($tab)) continue;
            // The Submit Tabs toggle on a root tab controls the root and its nested tabs.
            if (isset($tab['submit_tab']) && !(bool)$tab['submit_tab']) continue;
            $collectSubmitFields($tab);
        }
        $flatEntry = [];
        foreach ($entryData as $key => $value) {
            $key = (string)$key;
            if (!isset($submittedFieldKeys[$key])) continue;
            if (is_array($value) && isset($value['_object'])) {
                $objectData = [];
                $subFieldDefinitions = $dynamicFields[$key]['fieldkeyvalue'] ?? [];
                if (!is_array($subFieldDefinitions)) $subFieldDefinitions = [];
                foreach ($value as $subKey => $subValue) {
                    if ($subKey === '_object') continue;
                    $subType = $subFieldDefinitions[$subKey]['data_type'] ?? 'string';
                    $objectData[$subKey] = castDynamicValueByType($subValue, $subType);
                }
                $flatEntry[$key] = $objectData;
            } else {
                $flatEntry[$key] = castDynamicValueByType($value, $dynamicFields[$key]['data_type'] ?? 'string');
            }
        }
        if (!$flatEntry) throw new InvalidArgumentException('No fields from enabled Submit Tabs were received.');
        $flatEntry['status'] = 'pending';
        $flatEntry['operation_status'] = 'entry_created: New Project added by admin';
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(`display_order`),-1)+1 FROM `sceneiq_project_entries` WHERE `user_id`=:user_id AND `deleted_at` IS NULL');
        $maxStmt->execute([':user_id'=>$userId]);
        $nextPosition = (int)$maxStmt->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO `sceneiq_project_entries` (`user_id`,`status`,`entry_data_json`,`operation_status`,`display_order`) VALUES (:user_id,:status,:data,:operation_status,:position)');
        $stmt->execute([':user_id'=>$userId,':status'=>'pending',':data'=>sceneiqEncodeTableJson($flatEntry),':operation_status'=>$flatEntry['operation_status'],':position'=>$nextPosition]);
        return count($flatEntry) - 2;
    }

    function sendEntryUploadJson($success, $message, $extra = [], $httpCode = 200) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode(array_merge([
            'success' => (bool)$success,
            'message' => (string)$message
        ], is_array($extra) ? $extra : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    function getEntryUploadPath($uploadId) {
        $safeSessionHash = hash('sha256', session_id());
        $safeUploadId = preg_replace('/[^a-f0-9]/', '', strtolower((string)$uploadId));
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
            'sceneiq_entry_' . $safeSessionHash . '_' . $safeUploadId . '.tmp';
    }

    function removeEntryUpload($uploadId) {
        if (!isset($_SESSION['entry_uploads'][$uploadId])) return;
        $metadata = $_SESSION['entry_uploads'][$uploadId];
        $path = $metadata['path'] ?? '';
        if ($path && is_file($path)) {
            @unlink($path);
        }
        unset($_SESSION['entry_uploads'][$uploadId]);
        if (empty($_SESSION['entry_uploads'])) unset($_SESSION['entry_uploads']);
    }

    // AJAX upload endpoint: each request carries only a small file chunk, avoiding
    // PHP post_max_size limits on large transcripts, scripts, and structured fields.
    function handleEntryUploadRequests($pdo) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

        $actionKeys = ['entry_upload_start', 'entry_upload_chunk', 'entry_upload_finish', 'entry_upload_cancel'];
        $action = null;
        foreach ($actionKeys as $actionKey) {
            if (isset($_POST[$actionKey])) {
                $action = $actionKey;
                break;
            }
        }
        if ($action === null) return;

        $providedToken = (string)($_POST['csrf_token'] ?? '');
        $expectedToken = (string)($_SESSION['entry_upload_csrf_token'] ?? '');
        if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            sendEntryUploadJson(false, 'Security check failed. Refresh the page and try again.', [], 403);
        }

        if (!isset($_SESSION['entry_uploads']) || !is_array($_SESSION['entry_uploads'])) {
            $_SESSION['entry_uploads'] = [];
        }

        // Expire abandoned uploads belonging to this session.
        foreach ($_SESSION['entry_uploads'] as $staleId => $metadata) {
            if (time() - (int)($metadata['created_at'] ?? 0) > 1800) {
                removeEntryUpload($staleId);
            }
        }

        if ($action === 'entry_upload_start') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $totalBytes = (int)($_POST['total_bytes'] ?? 0);
            $totalChunks = (int)($_POST['total_chunks'] ?? 0);
            $maxBytes = 64 * 1024 * 1024;

            if ($userId <= 0 || $totalBytes <= 0 || $totalBytes > $maxBytes || $totalChunks <= 0) {
                sendEntryUploadJson(false, 'Invalid upload size or account. The maximum entry size is 64 MB.', [], 400);
            }

            $selectedUserId = (int)($_SESSION['selected_user_id'] ?? 0);
            if ($selectedUserId > 0 && $selectedUserId !== $userId) {
                sendEntryUploadJson(false, 'The selected account changed. Refresh the page and try again.', [], 403);
            }

            if (count($_SESSION['entry_uploads']) >= 3) {
                sendEntryUploadJson(false, 'There are already several active uploads. Cancel or finish one before starting another.', [], 429);
            }

            $uploadId = bin2hex(random_bytes(16));
            $path = getEntryUploadPath($uploadId);
            $handle = @fopen($path, 'xb');
            if (!$handle) {
                sendEntryUploadJson(false, 'The server could not create a temporary upload file. Check temporary-folder permissions.', [], 500);
            }
            fclose($handle);
            @chmod($path, 0600);

            $_SESSION['entry_uploads'][$uploadId] = [
                'user_id' => $userId,
                'path' => $path,
                'total_bytes' => $totalBytes,
                'total_chunks' => $totalChunks,
                'received_bytes' => 0,
                'next_chunk' => 0,
                'created_at' => time()
            ];

            sendEntryUploadJson(true, 'Upload started.', [
                'upload_id' => $uploadId,
                'chunk_size' => 48 * 1024,
                'total_chunks' => $totalChunks
            ]);
        }

        $uploadId = strtolower((string)($_POST['upload_id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId) || !isset($_SESSION['entry_uploads'][$uploadId])) {
            sendEntryUploadJson(false, 'This upload session is no longer available. Start the save again.', [], 404);
        }

        $metadata = $_SESSION['entry_uploads'][$uploadId];
        if (time() - (int)($metadata['created_at'] ?? 0) > 1800) {
            removeEntryUpload($uploadId);
            sendEntryUploadJson(false, 'The upload expired. Please save the entry again.', [], 408);
        }

        if ($action === 'entry_upload_cancel') {
            removeEntryUpload($uploadId);
            sendEntryUploadJson(true, 'Upload cancelled.');
        }

        if ($action === 'entry_upload_chunk') {
            $chunkIndex = (int)($_POST['chunk_index'] ?? -1);
            if ($chunkIndex !== (int)$metadata['next_chunk']) {
                sendEntryUploadJson(false, 'A chunk arrived out of order. Please restart the save.', [], 409);
            }

            if (!isset($_FILES['chunk_data']) || (int)($_FILES['chunk_data']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                sendEntryUploadJson(false, 'The server did not receive an upload chunk. Please retry.', [], 400);
            }

            $temporaryFile = $_FILES['chunk_data']['tmp_name'] ?? '';
            if (!$temporaryFile || !is_uploaded_file($temporaryFile)) {
                sendEntryUploadJson(false, 'The uploaded chunk could not be verified.', [], 400);
            }

            $chunk = file_get_contents($temporaryFile);
            if ($chunk === false || $chunk === '' || strlen($chunk) > 64 * 1024) {
                sendEntryUploadJson(false, 'The uploaded chunk is empty or larger than the allowed chunk size.', [], 400);
            }

            $newReceivedBytes = (int)$metadata['received_bytes'] + strlen($chunk);
            if ($newReceivedBytes > (int)$metadata['total_bytes']) {
                removeEntryUpload($uploadId);
                sendEntryUploadJson(false, 'The uploaded data exceeded the expected size. Please restart the save.', [], 400);
            }

            $written = file_put_contents($metadata['path'], $chunk, FILE_APPEND | LOCK_EX);
            if ($written !== strlen($chunk)) {
                removeEntryUpload($uploadId);
                sendEntryUploadJson(false, 'The server could not store an upload chunk. Check temporary-folder space and permissions.', [], 500);
            }

            $_SESSION['entry_uploads'][$uploadId]['received_bytes'] = $newReceivedBytes;
            $_SESSION['entry_uploads'][$uploadId]['next_chunk'] = $chunkIndex + 1;
            $_SESSION['entry_uploads'][$uploadId]['created_at'] = time();

            sendEntryUploadJson(true, 'Chunk received.', [
                'received_bytes' => $newReceivedBytes,
                'total_bytes' => (int)$metadata['total_bytes'],
                'next_chunk' => $chunkIndex + 1
            ]);
        }

        if ($action === 'entry_upload_finish') {
            $expectedChunks = (int)($metadata['total_chunks'] ?? 0);
            $receivedChunks = (int)($metadata['next_chunk'] ?? 0);
            $expectedBytes = (int)($metadata['total_bytes'] ?? 0);
            $receivedBytes = (int)($metadata['received_bytes'] ?? 0);
            $path = (string)($metadata['path'] ?? '');

            if ($receivedChunks !== $expectedChunks || $receivedBytes !== $expectedBytes || !is_file($path) || filesize($path) !== $expectedBytes) {
                sendEntryUploadJson(false, 'The upload is incomplete. No entry was saved; please retry.', [], 400);
            }

            $rawEntryData = file_get_contents($path);
            if ($rawEntryData === false) {
                removeEntryUpload($uploadId);
                sendEntryUploadJson(false, 'The server could not read the completed upload.', [], 500);
            }

            try {
                @set_time_limit(0);
                $entryData = json_decode($rawEntryData, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($entryData) || empty($entryData)) {
                    throw new InvalidArgumentException('The submitted entry did not contain any fields.');
                }

                $savedFieldCount = addNewProjectEntry($pdo, (int)$metadata['user_id'], $entryData);
                removeEntryUpload($uploadId);
                $_SESSION['flash_success'] = 'Entry added successfully! ' . $savedFieldCount . ' field(s) saved.';
                sendEntryUploadJson(true, 'Entry saved successfully.', [
                    'saved_fields' => $savedFieldCount
                ]);
            } catch (Throwable $e) {
                error_log('SceneIQ chunked entry save failed for user ' . (int)$metadata['user_id'] . ': ' . $e->getMessage());
                removeEntryUpload($uploadId);
                sendEntryUploadJson(false, 'The entry could not be saved. ' . $e->getMessage(), [], 500);
            }
        }
    }

    // Create/verify the relational storage tables before any reads or writes.
    sceneiqEnsureTableStorageSchema($pdo);

    // Process chunked upload requests before normal page POST handlers.
    handleEntryUploadRequests($pdo);

    // ===== HANDLE POST REQUESTS WITH PRG PATTERN =====
    $redirect = false;
    $debugMessages = [];

    // Create User
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (!$email || !$username || !$password) {
            $_SESSION['flash_error'] = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Invalid email address.';
        } elseif (strlen($password) < 6) {
            $_SESSION['flash_error'] = 'Password must be at least 6 characters.';
        } else {
            try {
                if (createUser($pdo, $email, $username, $password)) {
                    $_SESSION['flash_success'] = 'User created successfully!';
                } else {
                    $_SESSION['flash_error'] = 'Failed to create user. Email or username may already exist.';
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
            }
        }
        $redirect = true;
    }

    // Delete User
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            if (deleteUser($pdo, $userId)) {
                $_SESSION['flash_success'] = 'User deleted successfully!';
                if (isset($_SESSION['selected_user_id']) && $_SESSION['selected_user_id'] == $userId) {
                    unset($_SESSION['selected_user_id']);
                }
            } else {
                $_SESSION['flash_error'] = 'Failed to delete user.';
            }
        }
        $redirect = true;
    }

    // Select User (set session)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_user'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $_SESSION['selected_user_id'] = $userId;
            unset($_SESSION['dashboard_view']);
        }
        $redirect = true;
    }

    // Clear selected user (go to dashboard). All Accounts is the default landing view.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_user'])) {
        unset($_SESSION['selected_user_id']);
        $_SESSION['dashboard_view'] = 'accounts';
        $redirect = true;
    }

    // Set dashboard view. Fall back to All Accounts if an unexpected value arrives.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_dashboard_view'])) {
        $_SESSION['dashboard_view'] = in_array(($_POST['view'] ?? 'accounts'), ['initialize', 'accounts', 'copy_data'], true) ? $_POST['view'] : 'accounts';
        $redirect = true;
    }

    // Load source-account choices for the custom multi-selection copy modal.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['load_account_copy_options'])) {
        header('Content-Type: application/json; charset=utf-8');
        $postedToken = (string)($_POST['sceneiq_copy_csrf_token'] ?? '');
        $sourceUserId = (int)($_POST['source_user_id'] ?? 0);
        if (empty($_SESSION['sceneiq_copy_csrf_token']) || !hash_equals($_SESSION['sceneiq_copy_csrf_token'], $postedToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Security check failed. Refresh the page and try again.']);
            exit;
        }
        try {
            if ($sourceUserId <= 0 || !getUserById($pdo, $sourceUserId)) throw new InvalidArgumentException('Choose a valid source account.');
            $sourceConfig = getUserConfig($pdo, $sourceUserId);
            if (!$sourceConfig) throw new RuntimeException('The source account data could not be loaded.');
            $fields = normalizeDynamicFieldDefinitions($sourceConfig['dynamic_fields'] ?? '[]');
            $fieldOptions = [];
            foreach ($fields as $fieldKey => $definition) {
                $fieldOptions[] = [
                    'key' => (string)$fieldKey,
                    'title' => (string)($definition['field-title'] ?? $fieldKey),
                    'type' => (($definition['field_type'] ?? 'individual') === 'objects') ? 'Object field' : 'Individual field'
                ];
            }
            $tabs = normalizeDynamicTabs($sourceConfig['dynamic_tabs'] ?? '[]');
            echo json_encode([
                'success' => true,
                'dynamicTabs' => sceneiqFlattenCopyTabs($tabs),
                'dynamicFields' => $fieldOptions
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    // Merge checked Dynamic Tabs and/or Dynamic Content into multiple accounts.
    // Destination-only data remains untouched; matching keys are replaced in full.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['copy_account_data'])) {
        header('Content-Type: application/json; charset=utf-8');
        $postedToken = (string)($_POST['sceneiq_copy_csrf_token'] ?? '');
        $sourceUserId = (int)($_POST['copy_from_account'] ?? 0);
        $targetUserIds = $_POST['destination_user_ids'] ?? [];
        $copyScope = (string)($_POST['copy_data_scope'] ?? '');
        $selectedTabKeys = $_POST['selected_dynamic_tab_keys'] ?? [];
        $selectedFieldKeys = $_POST['selected_dynamic_field_keys'] ?? [];
        $scopeMap = [
            'dynamic_tabs' => [true, false],
            'dynamic_fields' => [false, true],
            'dynamic_tabs_and_fields' => [true, true]
        ];

        if (empty($_SESSION['sceneiq_copy_csrf_token']) || !hash_equals($_SESSION['sceneiq_copy_csrf_token'], $postedToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Security check failed. Refresh the page and try again.']);
            exit;
        }
        try {
            if (!isset($scopeMap[$copyScope])) throw new InvalidArgumentException('Choose Dynamic tabs, Dynamic field, or Both before copying.');
            if (!is_array($targetUserIds)) $targetUserIds = [];
            if (!is_array($selectedTabKeys)) $selectedTabKeys = [];
            if (!is_array($selectedFieldKeys)) $selectedFieldKeys = [];
            $copyTabs = $scopeMap[$copyScope][0];
            $copyFields = $scopeMap[$copyScope][1];
            if ($copyTabs && !$selectedTabKeys && !$copyFields) throw new InvalidArgumentException('Select at least one Dynamic tab to copy.');
            if ($copyFields && !$selectedFieldKeys && !$copyTabs) throw new InvalidArgumentException('Select at least one Dynamic field to copy.');
            if ($copyTabs && $copyFields && !$selectedTabKeys && !$selectedFieldKeys) throw new InvalidArgumentException('Select at least one Dynamic tab or Dynamic field to copy.');

            $sourceUser = getUserById($pdo, $sourceUserId);
            if (!$sourceUser) throw new InvalidArgumentException('The source account could not be found.');
            $copiedCount = sceneiqCopySelectedDataToAccounts(
                $pdo, $sourceUserId, $targetUserIds, $selectedTabKeys, $selectedFieldKeys, $copyTabs, $copyFields
            );
            $message = 'Selected data copied to ' . $copiedCount . ' account' . ($copiedCount === 1 ? '' : 's') . '. Destination-only data was preserved.';
            $selectedAccountId = (int)($_SESSION['selected_user_id'] ?? 0);
            $state = $selectedAccountId > 0 ? sceneiqBuildLiveState($pdo, $selectedAccountId) : null;
            echo json_encode(['success' => true, 'message' => $message, 'state' => $state], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            error_log('Account selected-data copy failed: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unable to copy account data. ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    // Copy one complete Dynamic Content field (individual or object) to many accounts.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['copy_dynamic_field_to_accounts'])) {
        header('Content-Type: application/json; charset=utf-8');
        $postedToken = (string)($_POST['sceneiq_copy_csrf_token'] ?? '');
        $sourceUserId = (int)($_POST['source_user_id'] ?? 0);
        $fieldKey = trim((string)($_POST['field_key'] ?? ''));
        $targetJson = (string)($_POST['destination_user_ids_json'] ?? '[]');
        $targetUserIds = json_decode($targetJson, true);
        if (empty($_SESSION['sceneiq_copy_csrf_token']) || !hash_equals($_SESSION['sceneiq_copy_csrf_token'], $postedToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Security check failed. Refresh the page and try again.']);
            exit;
        }
        try {
            if ($sourceUserId <= 0 || $sourceUserId !== (int)($_SESSION['selected_user_id'] ?? 0)) {
                throw new InvalidArgumentException('The source account could not be verified. Reopen the selected account and try again.');
            }
            if ($fieldKey === '' || !is_array($targetUserIds)) throw new InvalidArgumentException('The field or destination selection is invalid.');
            $sourceConfig = getUserConfig($pdo, $sourceUserId);
            if (!$sourceConfig) throw new RuntimeException('The source account data could not be loaded.');
            $sourceFields = normalizeDynamicFieldDefinitions($sourceConfig['dynamic_fields'] ?? '[]');
            if (!array_key_exists($fieldKey, $sourceFields)) throw new InvalidArgumentException('The selected Dynamic Content field no longer exists.');
            $copiedCount = sceneiqCopySelectedDataToAccounts(
                $pdo, $sourceUserId, $targetUserIds, [], [$fieldKey], false, true, $sourceConfig
            );
            $message = 'Field "' . $fieldKey . '" copied to ' . $copiedCount . ' account' . ($copiedCount === 1 ? '' : 's') . '. Matching field keys were replaced in full.';
            $state = sceneiqBuildLiveState($pdo, $sourceUserId);
            echo json_encode(['success' => true, 'message' => $message, 'state' => $state], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            error_log('Single Dynamic Content field copy failed: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    // Permanently delete the selected account's Dynamic Content and/or Dynamic Tabs.
    // This endpoint is reached only after the user confirms in the custom warning modal.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account_dynamic_data'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $deleteScope = (string)($_POST['delete_scope'] ?? '');
        $postedToken = (string)($_POST['data_delete_csrf_token'] ?? '');

        $deleteScopes = [
            'dynamic_content' => [
                'columns' => ['dynamic_fields'],
                'label' => 'Dynamic content'
            ],
            'dynamic_tabs' => [
                'columns' => ['dynamic_tabs'],
                'label' => 'Dynamic tabs'
            ],
            'dynamic_both' => [
                'columns' => ['dynamic_fields', 'dynamic_tabs'],
                'label' => 'Dynamic contents and Dynamic tabs'
            ]
        ];

        if (empty($_SESSION['data_delete_csrf_token']) ||
            !hash_equals($_SESSION['data_delete_csrf_token'], $postedToken)) {
            $_SESSION['flash_error'] = 'Security check failed. Please reopen the menu and try again.';
        } elseif ($userId <= 0 || $userId !== intval($_SESSION['selected_user_id'] ?? 0)) {
            $_SESSION['flash_error'] = 'The selected account could not be verified. No data was deleted.';
        } elseif (!isset($deleteScopes[$deleteScope])) {
            $_SESSION['flash_error'] = 'Invalid delete option. No data was deleted.';
        } else {
            try {
                sceneiqSoftDeleteDynamicData($pdo, $userId, $deleteScope);
                $_SESSION['flash_success'] = $deleteScopes[$deleteScope]['label'] . ' moved to the recoverable deleted state for this account.';
            } catch (Throwable $e) {
                error_log('Dynamic data deletion failed for user ' . $userId . ': ' . $e->getMessage());
                $_SESSION['flash_error'] = 'An error occurred while deleting the selected data. Please try again.';
            }
        }

        // Rotate the token after every destructive-operation attempt.
        $_SESSION['data_delete_csrf_token'] = bin2hex(random_bytes(32));
        $redirect = true;
    }

    // Save Account Dynamic Fields
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_global_dynamic_fields'])) {
        $userId = intval($_POST['user_id'] ?? ($_SESSION['selected_user_id'] ?? 0));
        if ($userId <= 0) { http_response_code(400); exit('Select an account before editing dynamic fields.'); }
        $debugMessages[] = "=== SAVE GLOBAL DYNAMIC FIELDS DEBUG ===";
        $debugMessages[] = "POST keys: " . implode(', ', array_keys($_POST));
        
        $dynamicFields = [];
        $rawData = '';
        
        if (isset($_POST['dynamic_fields_data']) && !empty($_POST['dynamic_fields_data'])) {
            $rawData = $_POST['dynamic_fields_data'];
            $debugMessages[] = "Raw dynamic_fields_data length: " . strlen($rawData);
            
            $decoded = json_decode($rawData, true);
            if (is_array($decoded) && !empty($decoded)) {
                $dynamicFields = $decoded;
                $debugMessages[] = "SUCCESS: Decoded dynamic_fields_data, count: " . count($dynamicFields);
                $debugMessages[] = "Field keys: " . implode(', ', array_keys($dynamicFields));
            } else {
                $debugMessages[] = "ERROR: Failed to decode dynamic_fields_data";
            }
        }
        if ($rawData === '' || !is_array($decoded ?? null) || empty($decoded)) {
            $_SESSION['flash_error'] = 'Dynamic Content was not saved because the submitted field definitions were empty or invalid. Existing fields were preserved.';
            $_SESSION['debug_messages'] = $debugMessages;
            $redirect = true;
        } else {
        
        $config = getUserConfig($pdo, $userId);
                $dynamicTabs = $config ? normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]') : [];
        $settings = $config ? sceneiqDecodeConfigColumn($config['settings'] ?? '[]', 'settings', []) : [];
        if (!is_array($settings)) $settings = [];
        $settings = repairSettings($settings);
        
        $result = saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);
        $debugMessages[] = "Save result: " . ($result ? "TRUE" : "FALSE");
        
        if ($result) {
            $_SESSION['flash_success'] = 'Dynamic fields saved successfully! ' . count($dynamicFields) . ' field(s) saved.';
        } else {
            $_SESSION['flash_error'] = 'Failed to save dynamic fields.';
        }
        
        $_SESSION['debug_messages'] = $debugMessages;
        $redirect = true;
        }
    }

    // Update one account-scoped Dynamic Content field.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_global_field'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        $userId = (int)($_POST['user_id'] ?? ($_SESSION['selected_user_id'] ?? 0));
        $oldKey = trim((string)($_POST['old_key'] ?? ''));
        $newKey = trim((string)($_POST['new_key'] ?? ''));
        $fieldData = isset($_POST['field_data']) ? json_decode((string)$_POST['field_data'], true) : null;

        try {
            if ($userId <= 0) throw new InvalidArgumentException('Select an account first.');
            if (!is_array($fieldData) || !$fieldData) throw new InvalidArgumentException('The field editor did not provide valid field content.');
            if ($oldKey === '') throw new InvalidArgumentException('The field being edited could not be identified.');

            $config = getUserConfig($pdo, $userId);
            if (!$config) throw new RuntimeException('The selected account configuration could not be loaded.');
            $dynamicFields = normalizeDynamicFieldDefinitions($config['dynamic_fields'] ?? '[]');
            if (!array_key_exists($oldKey, $dynamicFields)) throw new RuntimeException('The field being edited no longer exists. Refresh the page and try again.');

            $preparedField = sceneiqPrepareSubmittedDynamicField($fieldData, $newKey);
            $preparedKey = (string)$preparedField['field-name'];
            if ($preparedKey !== $oldKey && array_key_exists($preparedKey, $dynamicFields)) {
                throw new InvalidArgumentException('Another Dynamic Content field already uses the key "' . $preparedKey . '". Choose a different key.');
            }

            $settings = repairSettings(sceneiqDecodeConfigColumn($config['settings'] ?? '[]', 'settings', []));
            $dynamicTabs = normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]');
            if ($preparedKey !== $oldKey) {
                sceneiqReplaceDynamicFieldKeyInTabs($dynamicTabs, $oldKey, $preparedKey);
            }
            unset($dynamicFields[$oldKey]);
            $dynamicFields[$preparedKey] = $preparedField;
            saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);

            $state = sceneiqBuildLiveState($pdo, $userId);
            echo json_encode([
                'success' => true,
                'message' => 'Dynamic Content field updated and saved successfully.',
                'state' => $state
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            error_log('SceneIQ Dynamic Content update failed for user ' . $userId . ': ' . $e->getMessage());
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        exit;
    }

    // Save a new account-scoped Dynamic Content field.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_global_single_field'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        $userId = (int)($_POST['user_id'] ?? ($_SESSION['selected_user_id'] ?? 0));
        $fieldData = isset($_POST['field_data']) ? json_decode((string)$_POST['field_data'], true) : null;

        try {
            if ($userId <= 0) throw new InvalidArgumentException('Select an account first.');
            if (!is_array($fieldData) || !$fieldData) throw new InvalidArgumentException('The field editor did not provide valid field content.');

            $fieldKey = (string)($fieldData['field-name'] ?? '');
            $preparedField = sceneiqPrepareSubmittedDynamicField($fieldData, $fieldKey);
            $preparedKey = (string)$preparedField['field-name'];

            $config = getUserConfig($pdo, $userId);
            if (!$config) throw new RuntimeException('The selected account configuration could not be loaded.');
            $dynamicFields = normalizeDynamicFieldDefinitions($config['dynamic_fields'] ?? '[]');
            if (array_key_exists($preparedKey, $dynamicFields)) {
                throw new InvalidArgumentException('A Dynamic Content field already uses the key "' . $preparedKey . '". Choose a different key.');
            }

            $settings = repairSettings(sceneiqDecodeConfigColumn($config['settings'] ?? '[]', 'settings', []));
            $dynamicTabs = normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]');
            $dynamicFields[$preparedKey] = $preparedField;
            saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);

            $state = sceneiqBuildLiveState($pdo, $userId);
            echo json_encode([
                'success' => true,
                'message' => 'New Dynamic Content field saved successfully.',
                'state' => $state
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            error_log('SceneIQ Dynamic Content create failed for user ' . $userId . ': ' . $e->getMessage());
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        exit;
    }

    // Delete Global Dynamic Field
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_global_dynamic_field'])) {
        $userId = intval($_POST['user_id'] ?? ($_SESSION['selected_user_id'] ?? 0));
        if ($userId <= 0) { http_response_code(400); exit('Select an account first.'); }
        $fieldKey = $_POST['field_key'] ?? '';
        
        if ($fieldKey) {
            $config = getUserConfig($pdo, $userId);
                $dynamicTabs = $config ? normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]') : [];
            if ($config) {
                $settings = sceneiqDecodeConfigColumn($config['settings'] ?? '[]', 'settings', []);
                if (!is_array($settings)) $settings = [];
                $settings = repairSettings($settings);
                
                $dynamicFields = normalizeDynamicFieldDefinitions($config['dynamic_fields'] ?? '[]');
                
                if (isset($dynamicFields[$fieldKey])) {
                    unset($dynamicFields[$fieldKey]);
                    saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);
                    $_SESSION['flash_success'] = 'Field "' . $fieldKey . '" deleted successfully!';
                } else {
                    $_SESSION['flash_error'] = 'Field not found.';
                }
            }
        }
        $redirect = true;
    }

    // Save Settings for selected user
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $settings = isset($_POST['settings']) ? json_decode($_POST['settings'], true) : [];
        
        if ($userId > 0 && is_array($settings)) {
            $settings = repairSettings($settings);
            $config = getUserConfig($pdo, $userId);
            $dynamicFields = $config ? normalizeDynamicFieldDefinitions($config['dynamic_fields'] ?? '[]') : [];
            $dynamicTabs = $config ? normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]') : [];
            if (!is_array($dynamicTabs)) $dynamicTabs = [];
            
            if (saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs)) {
                $_SESSION['flash_success'] = 'Settings saved successfully!';
            } else {
                $_SESSION['flash_error'] = 'Failed to save settings.';
            }
        }
        $redirect = true;
    }

    // Handle Delete Entry for a user
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_entry'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $index = intval($_POST['index'] ?? -1);
        
        if ($userId > 0 && $index >= 0) {
            $config = getUserConfig($pdo, $userId);
            if ($config) {
                $settings = sceneiqDecodeConfigColumn($config['settings'] ?? '[]', 'settings', []);
                if (!is_array($settings)) $settings = [];
                $settings = repairSettings($settings);
                
                $entryPosition = sceneiqFindProjectEntryIndex($settings, $index);
                if ($entryPosition >= 0) {
                    array_splice($settings, $entryPosition, 1);
                    $dynamicFields = normalizeDynamicFieldDefinitions($config['dynamic_fields'] ?? '[]');
                    $dynamicTabs = normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]');
                    $result = saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);
                    
                    if ($result) {
                        $_SESSION['flash_success'] = 'Entry deleted successfully!';
                    } else {
                        $_SESSION['flash_error'] = 'Failed to delete entry.';
                    }
                } else {
                    $_SESSION['flash_error'] = 'Entry not found.';
                }
            } else {
                $_SESSION['flash_error'] = 'User config not found.';
            }
        }
        $redirect = true;
    }

    // Handle New Project for a user. Large entries use the chunked AJAX endpoint above;
    // this regular POST handler remains available as a compatibility fallback.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_entry'])) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $rawEntryData = (string)($_POST['entry_data'] ?? '');

        try {
            if ($rawEntryData === '') {
                throw new InvalidArgumentException('No entry data was provided.');
            }
            $entryData = json_decode($rawEntryData, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($entryData) || empty($entryData)) {
                throw new InvalidArgumentException('No valid entry fields were provided.');
            }

            $savedFieldCount = addNewProjectEntry($pdo, $userId, $entryData);
            $_SESSION['flash_success'] = 'Entry added successfully! ' . $savedFieldCount . ' field(s) saved.';
        } catch (Throwable $e) {
            error_log('SceneIQ regular entry save failed for user ' . $userId . ': ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Entry could not be saved. ' . $e->getMessage();
        }

        $redirect = true;
    }

    // Update entry status
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_entry_status'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $index = intval($_POST['index'] ?? -1);
        $newStatus = $_POST['new_status'] ?? '';
        
        if ($userId > 0 && $index >= 0 && in_array($newStatus, ['pending', 'completed', 'aborted'])) {
            $config = getUserConfig($pdo, $userId);
            if ($config) {
                $settings = sceneiqDecodeConfigColumn($config['settings'] ?? '[]', 'settings', []);
                if (!is_array($settings)) $settings = [];
                $settings = repairSettings($settings);
                
                $entryPosition = sceneiqFindProjectEntryIndex($settings, $index);
                if ($entryPosition >= 0) {
                    $settings[$entryPosition]['status'] = $newStatus;
                    $dynamicFields = normalizeDynamicFieldDefinitions($config['dynamic_fields'] ?? '[]');
                    $dynamicTabs = normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]');
                    saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);
                    $_SESSION['flash_success'] = 'Status updated successfully!';
                }
            }
        }
        $redirect = true;
    }

    // Update entry field data
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_entry_field'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $index = intval($_POST['index'] ?? -1);
        $fieldKey = $_POST['field_key'] ?? '';
        $fieldValue = $_POST['field_value'] ?? '';
        
        if ($userId > 0 && $index >= 0 && $fieldKey) {
            $config = getUserConfig($pdo, $userId);
            if ($config) {
                $settings = sceneiqDecodeConfigColumn($config['settings'] ?? '[]', 'settings', []);
                if (!is_array($settings)) $settings = [];
                $settings = repairSettings($settings);
                
                $entryPosition = sceneiqFindProjectEntryIndex($settings, $index);
                if ($entryPosition >= 0) {
                    $dynamicFields = normalizeDynamicFieldDefinitions($config['dynamic_fields'] ?? '[]');
                    $settings[$entryPosition][$fieldKey] = $fieldValue;
                    $dynamicTabs = normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]');
                    saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);
                    $_SESSION['flash_success'] = 'Field updated successfully!';
                }
            }
        }
        $redirect = true;
    }

    // ===== DYNAMIC TABS HANDLING =====
    
    // Save Dynamic Tabs for a user (UPDATED with additional_features)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_dynamic_tabs'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $tabsData = normalizeDynamicTabs($_POST['tabs_data'] ?? '[]');
        
        if ($userId > 0 && is_array($tabsData)) {
            $config = getUserConfig($pdo, $userId);
            $settings = $config ? sceneiqDecodeConfigColumn($config['settings'] ?? '[]', 'settings', []) : [];
            if (!is_array($settings)) $settings = [];
            $settings = repairSettings($settings);
            $dynamicFields = $config ? normalizeDynamicFieldDefinitions($config['dynamic_fields'] ?? '[]') : [];
            
            // Preserve existing submit_tab values from stored tabs
            $existingTabs = $config ? normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]') : [];
            
            // Create a lookup of existing submit_tab values by tab_name
            $submitTabLookup = [];
            foreach ($existingTabs as $existingTab) {
                if (isset($existingTab['tab_name'])) {
                    $submitTabLookup[$existingTab['tab_name']] = $existingTab['submit_tab'] ?? true;
                }
            }
            
            // Merge submitted tabs with existing submit_tab values and ensure additional_features
            foreach ($tabsData as &$tab) {
                if (!is_array($tab)) continue;
                if (isset($tab['tab_name']) && isset($submitTabLookup[$tab['tab_name']])) {
                    $tab['submit_tab'] = $submitTabLookup[$tab['tab_name']];
                } elseif (!isset($tab['submit_tab'])) {
                    $tab['submit_tab'] = true;
                }
                
                // Keep nested-tab metadata optional so legacy tab records remain valid.
                if (!isset($tab['sub_tabs_mode'])) {
                    $tab['sub_tabs_mode'] = !empty($tab['sub_tabs']) ? 'add' : 'none';
                }
                if (!isset($tab['sub_tabs']) || !is_array($tab['sub_tabs'])) {
                    $tab['sub_tabs'] = [];
                }
                // Ensure additional_features exists with defaults
                if (!isset($tab['additional_features']) || !is_array($tab['additional_features'])) {
                    $tab['additional_features'] = [
                        'copy_button' => false,
                        'transcript_detection' => false,
                        'transcript_with_structured_data_detection' => false
                    ];
                }
                // Ensure all keys exist
                if (!isset($tab['additional_features']['copy_button'])) {
                    $tab['additional_features']['copy_button'] = false;
                }
                if (!isset($tab['additional_features']['transcript_detection'])) {
                    $tab['additional_features']['transcript_detection'] = false;
                }
                if (!isset($tab['additional_features']['transcript_with_structured_data_detection'])) {
                    $tab['additional_features']['transcript_with_structured_data_detection'] = false;
                }
            }
            
            if (saveUserConfig($pdo, $userId, $settings, $dynamicFields, $tabsData)) {
                $_SESSION['flash_success'] = 'Dynamic tabs saved successfully!';
            } else {
                $_SESSION['flash_error'] = 'Failed to save dynamic tabs.';
            }
        }
        $redirect = true;
    }

    // Save Submit Tabs Configuration
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_submit_tabs'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $submitTabsData = normalizeDynamicTabs($_POST['submit_tabs_data'] ?? '[]');
        
        if ($userId > 0 && is_array($submitTabsData)) {
            $config = getUserConfig($pdo, $userId);
            $settings = $config ? sceneiqDecodeConfigColumn($config['settings'] ?? '[]', 'settings', []) : [];
            if (!is_array($settings)) $settings = [];
            $settings = repairSettings($settings);
            $dynamicFields = $config ? normalizeDynamicFieldDefinitions($config['dynamic_fields'] ?? '[]') : [];
            
            // Get existing tabs
            $existingTabs = $config ? normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]') : [];
            
            // Update ONLY submit_tab values on existing tabs - NO NEW TABS CREATED
            foreach ($submitTabsData as $updatedTab) {
                if (!is_array($updatedTab)) continue;
                $tabName = $updatedTab['tab_name'] ?? '';
                if (!$tabName) continue;
                
                // Find and update the existing tab
                foreach ($existingTabs as &$existingTab) {
                    if (!is_array($existingTab)) continue;
                    if (($existingTab['tab_name'] ?? '') === $tabName) {
                        $existingTab['submit_tab'] = $updatedTab['submit_tab'] ?? true;
                        break;
                    }
                }
            }
            
            // Save only if we have existing tabs (preserve all other tab properties)
            if (!empty($existingTabs)) {
                if (saveUserConfig($pdo, $userId, $settings, $dynamicFields, $existingTabs)) {
                    $_SESSION['flash_success'] = 'Submit tabs configuration saved successfully!';
                } else {
                    $_SESSION['flash_error'] = 'Failed to save submit tabs configuration.';
                }
            } else {
                $_SESSION['flash_error'] = 'No tabs found to update. Please create tabs in "Dynamic Tabs" first.';
            }
        }
        $redirect = true;
    }
    
    // Delete a dynamic tab
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_dynamic_tab'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $tabIndex = intval($_POST['tab_index'] ?? -1);
        
        if ($userId > 0 && $tabIndex >= 0) {
            $config = getUserConfig($pdo, $userId);
            if ($config) {
                $dynamicTabs = normalizeDynamicTabs($config['dynamic_tabs'] ?? '[]');
                
                if (isset($dynamicTabs[$tabIndex])) {
                    array_splice($dynamicTabs, $tabIndex, 1);
                    $settings = sceneiqDecodeConfigColumn($config['settings'] ?? '[]', 'settings', []);
                    if (!is_array($settings)) $settings = [];
                    $settings = repairSettings($settings);
                    $dynamicFields = normalizeDynamicFieldDefinitions($config['dynamic_fields'] ?? '[]');
                    
                    saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);
                    $_SESSION['flash_success'] = 'Tab deleted successfully!';
                } else {
                    $_SESSION['flash_error'] = 'Tab not found.';
                }
            }
        }
        $redirect = true;
    }

    // Show debug modal
    if (isset($_GET['show_debug'])) {
        $debugMessages = isset($_SESSION['debug_messages']) ? $_SESSION['debug_messages'] : ['No debug messages available'];
        echo '<!DOCTYPE html><html><head><title>Debug Info</title>';
        echo '<style>body{font-family:monospace;padding:20px;background:#1a1a2e;color:#fff;}';
        echo '.debug-box{background:#2d2d44;padding:20px;border-radius:10px;margin:10px 0;overflow:auto;max-height:80vh;}';
        echo '.debug-line{padding:4px 0;border-bottom:1px solid #3d3d5a;}';
        echo '.error{color:#ff6b6b;}';
        echo '.success{color:#51cf66;}';
        echo '.warning{color:#ffd93d;}';
        echo 'pre{background:#1a1a2e;padding:10px;border-radius:5px;overflow:auto;}';
        echo '
        /* ===== CLEAN, CONSISTENT DYNAMIC TABS EDITOR ===== */
        #dynamicTabsEditor { width: 100%; min-width: 0; }
        .dynamic-tabs-editor-header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; flex-wrap: wrap; margin-bottom: 18px;
            padding: 18px; background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 14px;
        }
        .dynamic-tabs-editor-header h3 { color: #0f172a; font-size: 20px; margin: 0 0 5px; }
        .dynamic-tabs-editor-header p { color: #64748b; font-size: 13px; line-height: 1.5; margin: 0; max-width: 720px; }
        .dynamic-tabs-editor-list { display: flex; flex-direction: column; gap: 14px; }
        .tab-editor-item {
            width: 100%; min-width: 0; background: #fff; border: 1px solid #dbe3ea;
            border-radius: 13px; padding: 16px; margin: 0; box-shadow: 0 2px 7px rgba(15,23,42,.04);
        }
        .root-tab-editor-item { border-left: 4px solid #0f766e; }
        .sub-tab-editor-item { border-left: 3px solid #94a3b8; background: #fff; }
        .tab-editor-heading { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .tab-editor-heading-copy { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .tab-editor-kind { font-size: 10px; letter-spacing: .09em; font-weight: 800; color: #0f766e; }
        .sub-tab-editor-item .tab-editor-kind { color: #64748b; }
        .tab-editor-title { color: #0f172a; font-size: 15px; overflow-wrap: anywhere; }
        .tab-editor-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .nested-tab-editor-body { margin-top: 16px; display: block; }
        .tab-editor-item .form-group { margin-bottom: 14px; }
        .tab-editor-item .form-group label { font-size: 13px; font-weight: 650; color: #334155; }
        .tab-editor-item .form-group input[type="text"],
        .tab-editor-item .form-group select,
        .tab-editor-item .form-group textarea {
            width: 100%; max-width: 100%; min-width: 0; padding: 10px 12px;
            border: 1px solid #cbd5e1; border-radius: 8px; background: #fff;
            color: #0f172a; box-shadow: none; font-size: 14px !important;
        }
        .tab-editor-item .form-group input:focus,
        .tab-editor-item .form-group select:focus,
        .tab-editor-item .form-group textarea:focus {
            outline: none; border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15,118,110,.12);
        }
        .tab-fields-toggle-list {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));
            gap: 6px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 9px; background: #f8fafc;
        }
        .tab-field-option {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            min-width: 0; padding: 9px 10px; border: 1px solid #e2e8f0;
            border-radius: 8px; background: #fff; cursor: pointer;
        }
        .tab-field-option input { flex: 0 0 auto; width: 16px; height: 16px; accent-color: #0f766e; }
        .tab-field-label { min-width: 0; overflow-wrap: anywhere; color: #334155; font-size: 12px; }
        .tab-field-label small { color: #64748b; }
        .tab-editor-empty-fields { color: #64748b; font-size: 12px; padding: 8px; margin: 0; }
        .tab-feature-options { display: flex; align-items: center; gap: 8px 18px; flex-wrap: wrap; padding: 12px; border: 1px solid #e2e8f0; border-radius: 9px; background: #f8fafc; }
        .tab-feature-options label { display: inline-flex; align-items: center; gap: 7px; color: #475569; font-size: 12px; cursor: pointer; }
        .tab-feature-options input { accent-color: #0f766e; }
        .tab-editor-help { margin-top: 6px; font-size: 12px; line-height: 1.5; color: #64748b; }
        .nested-sub-tabs-container { margin-top: 16px; padding: 14px; border: 1px solid #99f6e4; background: #f0fdfa; border-radius: 12px; }
        .nested-sub-tabs-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
        .nested-sub-tabs-heading strong { display: block; color: #115e59; font-size: 14px; }
        .nested-sub-tabs-heading p { margin: 4px 0 0; color: #64748b; font-size: 12px; }
        .nested-sub-tabs-list { display: flex; flex-direction: column; gap: 10px; }
        .nested-sub-tabs-list:empty { display: none; }
        .nested-sub-tabs-empty { padding: 12px; margin: 0; border: 1px dashed #99f6e4; border-radius: 8px; color: #64748b; font-size: 12px; text-align: center; }
        .dynamic-tabs-save-bar { display: flex; justify-content: flex-end; margin-top: 18px; padding: 14px 0 2px; }
        .tab-editor-empty-state { display: flex; flex-direction: column; gap: 5px; align-items: center; padding: 28px 16px; border: 1px dashed #cbd5e1; border-radius: 12px; color: #64748b; text-align: center; margin-bottom: 14px; }
        .tab-editor-empty-state strong { color: #334155; font-size: 14px; }
        #newProjectSubTabs { gap: 8px; padding: 4px 2px 10px; border-bottom: 1px solid #e2e8f0; }
        #newProjectSubTabs .dynamic-sub-tab-btn,
        .nested-project-tabs .nested-child-btn {
            border: 1px solid #e2e8f0; border-radius: 9px; padding: 9px 14px;
            background: #f8fafc; color: #475569; margin: 0; min-height: 38px;
            transition: background .15s ease, border-color .15s ease, color .15s ease;
        }
        #newProjectSubTabs .dynamic-sub-tab-btn.active,
        .nested-project-tabs .nested-child-btn.active {
            background: #e6fffb; border-color: #0f766e; color: #115e59; font-weight: 700;
        }
        .nested-project-tabs { padding: 14px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
        .nested-project-tabs .dynamic-sub-tabs { gap: 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; }
        .nested-child-content { min-width: 0; padding-top: 8px; }
        @media (max-width: 640px) {
            .dynamic-tabs-editor-header { padding: 14px; align-items: stretch; }
            .dynamic-tabs-editor-header > .btn { width: 100%; }
            .tab-editor-item { padding: 12px; }
            .nested-sub-tabs-container { padding: 10px; }
            .tab-editor-actions { width: 100%; }
            .tab-editor-actions .btn { flex: 1 1 auto; }
            .tab-fields-toggle-list { grid-template-columns: minmax(0, 1fr); }
            .dynamic-tabs-save-bar .btn { width: 100%; }
        }

    
/* ===== Dynamic tab editor interaction updates ===== */
#dynamicTabsEditor .tab-editor-heading {
    cursor: pointer;
}
#dynamicTabsEditor .tab-editor-heading:focus-visible {
    outline: 2px solid #087f75;
    outline-offset: -2px;
}
#dynamicTabsEditor .tab-editor-item:not(.is-expanded) .tab-delete-button {
    display: none !important;
}
#dynamicTabsEditor .tab-editor-item.is-expanded .tab-delete-button {
    display: inline-flex !important;
}
#dynamicTabsEditor .display-tab-setting select.display-tab-input {
    width: 100%;
    max-width: 220px;
    min-height: 40px;
    padding: 9px 12px;
    border: 1px solid #cfd8e3;
    border-radius: 8px;
    background: #fff;
    color: #172033;
    cursor: pointer;
}
#dynamicTabsEditor .tab-editor-heading .tab-editor-actions {
    cursor: default;
}
</style></head><body>';
        echo '<h1> Debug Information</h1>';
        echo '<div class="debug-box">';
        foreach ($debugMessages as $msg) {
            $class = '';
            if (strpos($msg, 'ERROR') !== false) $class = 'error';
            else if (strpos($msg, 'SUCCESS') !== false) $class = 'success';
            else if (strpos($msg, 'WARNING') !== false) $class = 'warning';
            echo '<div class="debug-line ' . $class . '">' . htmlspecialchars($msg) . '</div>';
        }
        echo '</div>';
        echo '<button onclick="window.close()" style="padding:10px 20px;margin-top:20px;cursor:pointer;">Close</button>';
        echo '<button onclick="location.href=\'sceneiq_server.php\'" style="padding:10px 20px;margin-top:20px;margin-left:10px;cursor:pointer;">Back to Admin</button>';
        echo '</body></html>';
        exit;
    }


    // Change password for the currently selected account.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_account_password'])) {
        $userId = intval($_POST['password_user_id'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $postedToken = (string)($_POST['password_csrf_token'] ?? '');

        if (empty($_SESSION['password_csrf_token']) ||
            !hash_equals($_SESSION['password_csrf_token'], $postedToken)) {
            $_SESSION['flash_error'] = 'Security check failed. Please try again.';
        } elseif ($userId <= 0 || $userId !== intval($_SESSION['selected_user_id'] ?? 0)) {
            $_SESSION['flash_error'] = 'Please select the account whose password you want to change.';
        } elseif (strlen($newPassword) < 8) {
            $_SESSION['flash_error'] = 'Password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $_SESSION['flash_error'] = 'The new password and confirmation do not match.';
        } else {
            try {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
                $stmt->execute([':password' => $hashedPassword, ':id' => $userId]);
                if ($stmt->rowCount() > 0) {
                    $_SESSION['flash_success'] = 'Password changed successfully.';
                } else {
                    // rowCount can be zero when the value is unchanged; verify account exists.
                    $check = $pdo->prepare('SELECT id FROM users WHERE id = :id');
                    $check->execute([':id' => $userId]);
                    $exists = (bool)$check->fetchColumn();
                    $_SESSION[$exists ? 'flash_success' : 'flash_error'] =
                        $exists ? 'Password changed successfully.' : 'Account not found.';
                }
                $_SESSION['password_csrf_token'] = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                error_log('Password update failed: ' . $e->getMessage());
                $_SESSION['flash_error'] = 'Unable to change the password. Please try again.';
            }
        }
        $redirect = true;
    }

    // Legacy JSON recovery is intentionally no longer used by the table-based interface.
    // The original sceneiq_config table remains untouched as the migration source/archive.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['restore_all_config_storage']) || isset($_POST['restore_all_dynamic_field_storage']))) {
        $_SESSION['flash_error'] = 'This version uses relational tables. Run the supplied SceneIQ migration utility to import any remaining legacy sceneiq_config records; the originals are preserved.';
        $redirect = true;
    }

    // Lightweight state endpoint used by editor actions that already return JSON.
    // It returns the latest table-backed records without changing navigation.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sceneiq_live_state'])) {
        try {
            $liveUserId = (int)($_SESSION['selected_user_id'] ?? 0);
            $liveState = sceneiqBuildLiveState($pdo, $liveUserId);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            echo json_encode(['success' => true, 'state' => $liveState], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            error_log('SceneIQ live state refresh failed: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'The latest account data could not be refreshed. ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    // Save requests marked as XMLHttpRequest return JSON + the latest account state.
    // Navigation requests (select account / dashboard / back to account list) still
    // use the original normal redirect behavior.
    $sceneiqAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || (string)($_SERVER['HTTP_X_SCENEIQ_REQUEST'] ?? '') === 'save';

    // If any POST request should redirect, return JSON for background saves instead.
    if ($redirect) {
        if ($sceneiqAjaxRequest) {
            $flashSuccessAjax = (string)($_SESSION['flash_success'] ?? '');
            $flashErrorAjax = (string)($_SESSION['flash_error'] ?? '');
            $debugMessagesAjax = $_SESSION['debug_messages'] ?? [];
            unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['debug_messages']);
            $liveStateAjax = null;
            try {
                $liveUserIdAjax = (int)($_SESSION['selected_user_id'] ?? 0);
                $liveStateAjax = sceneiqBuildLiveState($pdo, $liveUserIdAjax);
            } catch (Throwable $stateError) {
                error_log('SceneIQ post-save state build failed: ' . $stateError->getMessage());
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            echo json_encode([
                'success' => $flashErrorAjax === '',
                'message' => $flashErrorAjax !== '' ? $flashErrorAjax : ($flashSuccessAjax !== '' ? $flashSuccessAjax : 'Saved successfully.'),
                'state' => $liveStateAjax,
                'debug' => $debugMessagesAjax
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // ===== GET FLASH MESSAGES =====
    $flashSuccess = isset($_SESSION['flash_success']) ? $_SESSION['flash_success'] : null;
    $flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
    $debugMessages = isset($_SESSION['debug_messages']) ? $_SESSION['debug_messages'] : [];
    unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['debug_messages']);

    // ===== GET DATA =====
    $users = getAllUsers($pdo);
    $selectedUserId = isset($_SESSION['selected_user_id']) ? intval($_SESSION['selected_user_id']) : 0;

    // One-time session migration: users who had the previous default saved as
    // "initialize" are moved to All Accounts once after this update. Later tab
    // selections are respected normally.
    if (empty($_SESSION['sceneiq_accounts_default_view_v1'])) {
        $_SESSION['dashboard_view'] = 'accounts';
        $_SESSION['sceneiq_accounts_default_view_v1'] = '1';
    }

    $dashboardView = isset($_SESSION['dashboard_view']) ? $_SESSION['dashboard_view'] : 'accounts';
    if (!in_array($dashboardView, ['initialize', 'accounts', 'copy_data'], true)) { $dashboardView = 'accounts'; }

    $selectedUser = null;
    $userConfig = null;
    $userSettings = [];
    $userDynamicFields = [];
    $userDynamicTabs = [];
    // Dynamic fields are account-scoped; there is no shared/global field definition.
    $globalDynamicFields = [];

    $groupedEntries = ['pending' => [], 'completed' => [], 'aborted' => []];
    $statusLabels = ['pending' => 'Pending', 'completed' => 'Completed', 'aborted' => 'Aborted'];
    $statusColors = ['pending' => '#f59e0b', 'completed' => '#10b981', 'aborted' => '#ef4444'];
    $statusIcons = ['pending' => '', 'completed' => '', 'aborted' => ''];

    if ($selectedUserId > 0) {
        $selectedUser = getUserById($pdo, $selectedUserId);
        if ($selectedUser) {
            $userConfig = getUserConfig($pdo, $selectedUserId);
            if ($userConfig) {
                try {
                    // Project entries and statuses are loaded from relational project rows.
                    $userSettings = sceneiqDecodeConfigColumn($userConfig['settings'] ?? '[]', 'settings', []);
                    $userSettings = repairSettings($userSettings);
                } catch (Throwable $storageError) {
                    error_log('SceneIQ project/settings decode failed for user ' . $selectedUserId . ': ' . $storageError->getMessage());
                    $userSettings = [];
                    $storageWarning = 'Project entries for this account could not be verified and were not modified. Use the all-account SceneIQ data recovery action from All Accounts; unverifiable data remains preserved in the database.';
                    $flashError = $flashError ? ($flashError . ' ' . $storageWarning) : $storageWarning;
                }

                try {
                    $userDynamicFields = normalizeDynamicFieldDefinitions($userConfig['dynamic_fields'] ?? '[]');
                } catch (Throwable $storageError) {
                    error_log('SceneIQ Dynamic Content decode failed for user ' . $selectedUserId . ': ' . $storageError->getMessage());
                    $userDynamicFields = [];
                    $storageWarning = 'Dynamic Content for this account could not be verified and was not modified. Use the all-account SceneIQ data recovery action from All Accounts; unverifiable data remains preserved in the database.';
                    $flashError = $flashError ? ($flashError . ' ' . $storageWarning) : $storageWarning;
                }

                try {
                    $userDynamicTabs = normalizeDynamicTabs($userConfig['dynamic_tabs'] ?? '[]');
                } catch (Throwable $storageError) {
                    error_log('SceneIQ Dynamic Tabs decode failed for user ' . $selectedUserId . ': ' . $storageError->getMessage());
                    $userDynamicTabs = [];
                    $storageWarning = 'Dynamic Tabs for this account could not be verified and were not modified. Use the all-account SceneIQ data recovery action from All Accounts; unverifiable data remains preserved in the database.';
                    $flashError = $flashError ? ($flashError . ' ' . $storageWarning) : $storageWarning;
                }
                
                foreach ($userSettings as $index => $entry) {
                    // Defensive guard: legacy or malformed JSON must never cause
                    // an attempt to write an array offset into a scalar value.
                    if (!is_array($entry)) {
                        continue;
                    }

                    $statusValue = $entry['status'] ?? 'pending';
                    $status = is_scalar($statusValue)
                        ? strtolower(trim((string)$statusValue))
                        : 'pending';
                    if ($status === '') {
                        $status = 'pending';
                    }

                    if (!isset($groupedEntries[$status])) {
                        $groupedEntries[$status] = [];
                    }
                    $entry['_index'] = (int)($entry['_db_id'] ?? $index);
                    $groupedEntries[$status][] = $entry;
                }
            } else {
                saveUserConfig($pdo, $selectedUserId, [], [], []);
                $userDynamicFields = [];
                $userDynamicTabs = [];
            }
        }
    }
    // Existing JavaScript uses this variable name; its data is now strictly per selected account.
    $globalDynamicFields = $userDynamicFields;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>SceneIQ Server - Admin</title>
<style>
        /* Prevent the legacy tab layout from flashing before the final folder UI initializes. */
        body.sceneiq-folder-pending .custom-body { visibility: hidden !important; }
        body.sceneiq-folder-ready .custom-body { visibility: visible !important; }

        /* Visible feedback while the account workspace or any configuration save is pending. */
        #sceneiq-loading-indicator {
            position: fixed; inset: 0; z-index: 2147483000; display: none;
            align-items: center; justify-content: center; flex-direction: column; gap: 14px;
            padding: 24px; background: transparent; color: #f8fafc;
            text-align: center; backdrop-filter: none;
        }
        body.sceneiq-folder-pending #sceneiq-loading-indicator,
        body.sceneiq-operation-loading #sceneiq-loading-indicator { display: flex; }
        #sceneiq-loading-indicator .sceneiq-loader-spinner {
            width: 42px; height: 42px; border: 4px solid rgba(255,255,255,.25);
            border-top-color: #5eead4; border-radius: 50%; animation: sceneiq-loader-spin .8s linear infinite;
        }
        @keyframes sceneiq-loader-spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) {
            #sceneiq-loading-indicator .sceneiq-loader-spinner { animation-duration: 2s; }
        }
        .copy-button-copied { background:#dcfce7 !important; border-color:#16a34a !important; color:#166534 !important; }

        /* ===== RESET & BODY ===== */
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        html, body { 
            height: 100%; 
            overflow: hidden;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #ffffff;
        }
        
        input, select, textarea {
            font-size: 16px !important;
        }
        
        .custom-body {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            overflow: hidden;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: 100%;
        }
        
        /* ===== HEADER ===== */
        .main-header {
            flex-shrink: 0;
            z-index: 1000;
            background: linear-gradient(135deg, #00695c 0%, #00897b 50%, #26a69a 100%);
            color: white;
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0,105,92,0.3);
            min-height: 60px;
            width: 100%;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .main-header h1 { 
            font-size: 18px; 
            font-weight: 600;
            white-space: nowrap;
        }
        .main-header h1 span { opacity: 0.6; font-weight: 300; }
        
        .debug-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 6px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
            white-space: nowrap;
        }
        
        .debug-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        /* ===== SCROLLABLE BODY ===== */
        .scroll-body {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0 15px 30px;
            padding-top: 0 !important;
            padding-bottom: 30px;
            background: #ffffff;
            width: 100%;
            max-width: 100%;
        }
        
        .scroll-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .scroll-body::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.02);
            border-radius: 10px;
        }
        
        .scroll-body::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #00695c, #26a69a);
            border-radius: 10px;
        }
        
        .scroll-body::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #004d40, #00897b);
        }
        
        .server-container {
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            padding: 0 5px;
        }
        
        .two-col {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 20px;
            margin-top: 15px;
            width: 100%;
        }
        
        .sidebar {
            background: #ffffff;
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid rgba(0,0,0,0.05);
            height: fit-content;
            width: 100%;
            min-width: 0;
            overflow: hidden;
        }
        
        .sidebar h2 {
            font-size: 16px;
            color: #1a1a2e;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .search-users-btn {
            width: 100%;
            padding: 10px 14px;
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            color: #6b7280;
            transition: all 0.3s;
            margin-bottom: 12px;
            text-align: center;
        }
        
        .search-users-btn:hover {
            background: #e5e7eb;
            border-color: #00695c;
            color: #00695c;
        }
        
        .content-area {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid rgba(0,0,0,0.05);
            min-height: 400px;
            width: 100%;
            min-width: 0;
            overflow: hidden;
        }
        
        /* ===== DASHBOARD TABS - HORIZONTAL SCROLL ===== */
        .dashboard-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
            overflow-x: auto;
            overflow-y: hidden;
            width: 100%;
            white-space: nowrap;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
            align-items: stretch;
            min-height: 48px;
            flex-wrap: nowrap;
        }
        
        .dashboard-tabs::-webkit-scrollbar {
            height: 3px;
        }
        
        .dashboard-tabs::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .dashboard-tabs::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 10px;
        }
        
        .dashboard-tabs::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        .dashboard-tab-btn {
            padding: 10px 16px;
            background: none;
            border: none;
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            white-space: nowrap;
            flex-shrink: 0;
            min-height: 46px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .dashboard-tab-btn:hover {
            color: #1a1a2e;
        }
        
        .dashboard-tab-btn.active {
            color: #00695c;
            border-bottom-color: #00695c;
        }
        
        .dashboard-content {
            display: none;
            padding-top: 10px;
            width: 100%;
        }
        
        .dashboard-content.active {
            display: block;
        }
        
        /* ===== USER CONFIG TABS - HORIZONTAL SCROLL ===== */
        .user-config-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
            overflow-x: auto;
            overflow-y: hidden;
            width: 100%;
            white-space: nowrap;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
            align-items: stretch;
            min-height: 48px;
            flex-wrap: nowrap;
        }
        
        .user-config-tabs::-webkit-scrollbar {
            height: 3px;
        }
        
        .user-config-tabs::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .user-config-tabs::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 10px;
        }
        
        .user-config-tabs::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        .user-config-tab-btn {
            padding: 10px 16px;
            background: none;
            border: none;
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            white-space: nowrap;
            flex-shrink: 0;
            min-height: 46px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .user-config-tab-btn:hover {
            color: #1a1a2e;
        }
        
        .user-config-tab-btn.active {
            color: #00695c;
            border-bottom-color: #00695c;
        }
        
        .user-config-content {
            display: none;
            padding-top: 10px;
            width: 100%;
        }
        
        .user-config-content.active {
            display: block;
        }
        
        /* ===== DYNAMIC SUB TABS ===== */
        .dynamic-sub-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
            overflow-x: auto;
            overflow-y: hidden;
            width: 100%;
            white-space: nowrap;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
            align-items: stretch;
            min-height: 40px;
            flex-wrap: nowrap;
        }
        
        .dynamic-sub-tabs::-webkit-scrollbar {
            height: 3px;
        }
        
        .dynamic-sub-tabs::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .dynamic-sub-tabs::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 10px;
        }
        
        .dynamic-sub-tabs::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        .dynamic-sub-tab-btn {
            padding: 8px 14px;
            background: none;
            border: none;
            font-size: 13px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            white-space: nowrap;
            flex-shrink: 0;
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .dynamic-sub-tab-btn:hover {
            color: #1a1a2e;
        }
        
        .dynamic-sub-tab-btn.active {
            color: #00695c;
            border-bottom-color: #00695c;
        }
        
        .dynamic-sub-tab-content {
            display: none;
            padding-top: 10px;
            width: 100%;
        }
        
        .dynamic-sub-tab-content.active {
            display: block;
        }
        
        .create-user-form {
            max-width: 500px;
            width: 100%;
            margin: 0 auto;
            padding: 20px;
            background: #fafafa;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
        }
        
        .create-user-form h3 {
            font-size: 18px;
            color: #1a1a2e;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .create-user-form .form-row {
            display: grid;
            gap: 10px;
            width: 100%;
        }
        
        .create-user-form input {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
            width: 100%;
            box-sizing: border-box;
        }
        
        .create-user-form input:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
        }
        
        .create-user-form .btn-create {
            padding: 12px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 16px;
            width: 100%;
        }
        
        .create-user-form .btn-create:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(16,185,129,0.3);
        }
        
        .form-group {
            margin-bottom: 18px;
            width: 100%;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #1a1a2e;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: #fafafa;
            box-sizing: border-box;
            max-width: 100%;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
            background: white;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #00695c, #26a69a);
            color: white;
            box-shadow: 0 4px 20px rgba(0,105,92,0.25);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(0,105,92,0.35);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 20px rgba(16,185,129,0.25);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(16,185,129,0.35);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 4px 20px rgba(239,68,68,0.2);
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(239,68,68,0.3);
        }
        
        .btn-secondary {
            background: #f3f4f6;
            color: #1a1a2e;
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 4px 20px rgba(245,158,11,0.25);
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(245,158,11,0.35);
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
            width: 100%;
        }
        
        .user-settings-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
            width: 100%;
        }
        
        .user-settings-header .user-info h2 {
            font-size: 20px;
            color: #1a1a2e;
            word-break: break-word;
        }
        
        .user-settings-header .user-info .email {
            font-size: 14px;
            color: #6b7280;
            word-break: break-all;
        }
        
        .config-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 15px;
            width: 100%;
        }
        
        .config-item {
            background: #ffffff;
            border-radius: 12px;
            padding: 16px;
            border-left: 4px solid #00695c;
            transition: all 0.3s;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid rgba(0,0,0,0.05);
            border-left-width: 4px;
            width: 100%;
            overflow: visible;
        }
        
        .config-item:hover {
            box-shadow: 0 8px 32px rgba(0,0,0,0.10);
            transform: translateX(5px);
        }
        
        .config-item .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
            width: 100%;
        }
        
        .config-item .item-id {
            font-size: 13px;
            font-weight: 600;
            color: #00695c;
        }
        
        .config-item .item-status {
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            color: white;
            white-space: nowrap;
        }
        
        /* ===== ENTRY DISPLAY STYLES - UPDATED FOR OBJECT FIELDS ===== */
        .entry-details {
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: 100%;
            margin: 10px 0;
        }
        
        .entry-field-group {
            border-radius: 8px;
            overflow: visible;
            width: 100%;
        }
        
        .entry-field-group.individual-field {
            background: #f8fafc;
            padding: 10px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
        
        .entry-field-group.object-field {
            background: #f0fdf4;
            padding: 12px 14px;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
        }
        
        .field-label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 4px;
        }
        
        .field-value {
            font-size: 14px;
            color: #1a1a2e;
            word-break: break-word;
            max-height: 120px;
            overflow-y: auto;
            padding-right: 5px;
        }
        
        .field-value::-webkit-scrollbar {
            width: 4px;
        }
        
        .field-value::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .field-value::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 4px;
        }
        
        .field-value::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        .sub-fields-container {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 8px;
            padding-left: 8px;
            border-left: 2px solid #a7f3d0;
        }
        
        .sub-field-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 6px 10px;
            background: #ffffff;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }
        
        .sub-field-item .sub-label {
            font-size: 11px;
            font-weight: 500;
            color: #6b7280;
        }
        
        .sub-field-item .sub-value {
            font-size: 13px;
            color: #1a1a2e;
            word-break: break-word;
            max-height: 80px;
            overflow-y: auto;
        }
        
        .sub-field-item .sub-value::-webkit-scrollbar {
            width: 3px;
        }
        
        .sub-field-item .sub-value::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .sub-field-item .sub-value::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 3px;
        }
        
        .object-label {
            font-size: 13px;
            font-weight: 600;
            color: #00695c;
            margin-bottom: 6px;
        }
        
        .entry-field-group .field-value {
            max-height: 120px;
            overflow-y: auto;
            padding-right: 5px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar {
            width: 4px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 4px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        /* Fallback for older browsers */
        .config-item .item-details .detail-label {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            flex-shrink: 0;
            padding-bottom: 2px;
        }
        
        .config-item .item-details .detail-value {
            font-size: 13px;
            color: #1a1a2e;
            word-break: break-word;
            max-height: 80px;
            overflow-y: auto;
        }
        
        .config-item .item-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
            width: 100%;
        }
        
        .config-item .item-actions .btn {
            padding: 6px 14px;
            font-size: 12px;
        }
        
        .config-item .item-actions form {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .config-item .status-select {
            padding: 4px 8px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 12px;
            background: #fafafa;
            cursor: pointer;
        }
        
        .config-item .status-select:focus {
            border-color: #00695c;
            outline: none;
        }
        
        .entry-user-tag {
            font-size: 12px;
            color: #00695c;
            background: #e0f2f1;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 500;
        }
        
        .notification {
            padding: 12px 18px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 14px;
            width: 100%;
            box-sizing: border-box;
            word-break: break-word;
        }
        
        .notification.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .notification.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .datetime-group {
            width: 100%;
            box-sizing: border-box;
        }
        
        .datetime-group .date-input {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: #fafafa;
            box-sizing: border-box;
        }
        
        .datetime-group .date-input:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
            background: white;
        }
        
        .datetime-group .time-group {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 8px;
            width: 100%;
        }
        
        .datetime-group .time-group input[type="number"],
        .datetime-group .time-group select {
            padding: 8px 10px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: #fafafa;
            min-width: 50px;
        }
        
        .datetime-group .time-group input[type="number"] {
            width: 60px;
        }
        
        .datetime-group .time-group select {
            width: 70px;
        }
        
        .datetime-group .time-group input[type="number"]:focus,
        .datetime-group .time-group select:focus {
            border-color: #00695c;
            outline: none;
        }
        
        .value-tag {
            display: inline-block;
            background: #e5e7eb;
            padding: 4px 12px;
            border-radius: 12px;
            margin: 3px;
            font-size: 13px;
        }
        
        .value-tag .remove-value {
            cursor: pointer;
            color: #ef4444;
            margin-left: 6px;
        }
        
        .sub-field-group {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            background: #fafafa;
            position: relative;
        }
        
        .remove-sub-field {
            color: #ef4444;
            cursor: pointer;
            float: right;
            font-size: 18px;
            background: none;
            border: none;
            padding: 0 5px;
        }
        
        /* ================================================================
           CUSTOM DIALOG - FIXED FOR ALL SCREEN SIZES
           ================================================================ */
        .custom-dialog-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100vw;
            height: 100vh;
            min-height: 100vh;
            background: rgba(0, 0, 0, 0.6);
            z-index: 999999 !important;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            padding: 20px;
            box-sizing: border-box;
            overflow: hidden;
        }
        
        .custom-dialog-overlay.active {
            display: flex !important;
            opacity: 1;
            visibility: visible;
        }
        
        .custom-dialog-box {
            background: #ffffff;
            border-radius: 16px;
            padding: 30px;
            max-width: 650px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4);
            animation: dialogSlideIn 0.3s ease;
            position: relative;
            margin: 20px;
            box-sizing: border-box;
            display: block !important;
            visibility: visible !important;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        @keyframes dialogSlideIn {
            from {
                transform: translateY(-40px) scale(0.95);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }
        
        .custom-dialog-box .dialog-title {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #1a1a2e;
            display: block;
        }
        
        .custom-dialog-box .dialog-message {
            font-size: 16px;
            color: #6b7280;
            margin-bottom: 20px;
            line-height: 1.6;
            display: block;
        }
        
        .custom-dialog-box .dialog-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
            width: 100%;
            margin-top: 10px;
        }
        
        .custom-dialog-box .dialog-buttons .btn {
            min-width: 80px;
        }
        
        .custom-dialog-box .dialog-buttons .btn-confirm {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        .custom-dialog-box .dialog-buttons .btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(239, 68, 68, 0.3);
        }
        
        .custom-dialog-box .dialog-content-area {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #e5e7eb;
            max-height: 300px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-all;
            width: 100%;
            box-sizing: border-box;
        }
        
        .custom-dialog-box .dialog-content-area:focus {
            outline: 2px solid #00695c;
            outline-offset: 2px;
        }
        
        /* Scrollbar for dialog content */
        .custom-dialog-box .dialog-content-area::-webkit-scrollbar {
            width: 6px;
        }
        
        .custom-dialog-box .dialog-content-area::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .custom-dialog-box .dialog-content-area::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 4px;
        }
        
        /* Dialog content wrapper */
        #dialogContent {
            margin-bottom: 15px;
            display: block;
            width: 100%;
        }
        
        #dialogContent > * {
            display: block;
            width: 100%;
        }
        
        /* ===== USER SEARCH MODAL ===== */
        .user-search-modal .custom-dialog-box {
            max-width: 600px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            padding: 0;
            overflow: hidden;
        }
        
        .user-search-modal .dialog-header {
            padding: 20px 25px 15px;
            border-bottom: 1px solid #e5e7eb;
            flex-shrink: 0;
            background: white;
            border-radius: 16px 16px 0 0;
        }
        
        .user-search-modal .dialog-header h2 {
            font-size: 20px;
            color: #1a1a2e;
            margin-bottom: 12px;
        }
        
        .user-search-modal .dialog-header .search-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            background: #fafafa;
            transition: all 0.3s;
        }
        
        .user-search-modal .dialog-header .search-input:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
            background: white;
        }
        
        .user-search-modal .dialog-body {
            flex: 1;
            overflow-y: auto;
            padding: 15px 25px 25px;
        }
        
        .user-search-modal .dialog-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .user-search-modal .dialog-body::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.02);
            border-radius: 10px;
        }
        
        .user-search-modal .dialog-body::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #00695c, #26a69a);
            border-radius: 10px;
        }
        
        .user-search-modal .user-search-item {
            padding: 12px 16px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 4px;
            border: 1px solid transparent;
        }
        
        .user-search-modal .user-search-item:hover {
            background: #f3f4f6;
            border-color: #e5e7eb;
        }
        
        .user-search-modal .user-search-item .user-name {
            font-weight: 500;
            color: #1a1a2e;
            font-size: 15px;
        }
        
        .user-search-modal .user-search-item .user-email {
            font-size: 13px;
            color: #6b7280;
        }
        
        .user-search-modal .dialog-footer {
            padding: 15px 25px 20px;
            border-top: 1px solid #e5e7eb;
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
            background: white;
            border-radius: 0 0 16px 16px;
        }
        
        
        .copy-btn {
            background: none;
            border: 1px solid #d1d5db;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            color: #6b7280;
        }
        
        .copy-btn:hover {
            background: #f3f4f6;
            border-color: #00695c;
            color: #00695c;
        }
        
        /* ===== TOGGLE SWITCH ===== */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
            flex-shrink: 0;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 22px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        .toggle-switch input:checked + .toggle-slider {
            background-color: #00695c;
        }
        
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(18px);
        }
        
        .tab-editor-item {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            background: #fafafa;
        }
        
        .tab-editor-item .tab-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .tab-editor-item .tab-header .tab-title {
            font-weight: 600;
            color: #1a1a2e;
        }
        
        .field-toggle-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 10px;
            margin: 2px 0;
            border-radius: 6px;
            background: #f3f4f6;
        }
        
        .field-toggle-item:hover {
            background: #e5e7eb;
        }
        
        .field-toggle-item .field-name {
            font-size: 13px;
            color: #1a1a2e;
        }
        
        /* ===== SCROLLABLE VALUE ===== */
        .scrollable-value {
            max-height: 80px;
            overflow-y: auto;
            padding-right: 5px;
            word-break: break-word;
            display: block;
            width: 100%;
        }
        
        .scrollable-value::-webkit-scrollbar {
            width: 4px;
        }
        
        .scrollable-value::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .scrollable-value::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 4px;
        }
        
        .scrollable-value::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        /* ================================================================
           RESPONSIVE MEDIA QUERIES
           ================================================================ */
        @media (max-width: 1024px) {
            .two-col {
                grid-template-columns: 250px 1fr;
                gap: 15px;
            }
        }
        
        @media (max-width: 768px) {
            .two-col {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .sidebar {
                max-height: 300px;
                overflow-y: auto;
            }
            
            .content-area {
                padding: 15px;
            }
            
            .main-header {
                padding: 10px 15px;
                min-height: 55px;
            }
            
            .main-header h1 {
                font-size: 16px;
            }
            
            .scroll-body {
                padding: 0 10px 30px !important;
                padding-top: 0 !important;
            }
            
            .dashboard-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
            }
            
            .dashboard-tab-btn {
                padding: 8px 14px;
                font-size: 13px;
                white-space: nowrap;
                min-height: 42px;
            }
            
            .user-config-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
            }
            
            .user-config-tab-btn {
                padding: 8px 14px;
                font-size: 13px;
                white-space: nowrap;
                min-height: 42px;
            }
            
            .dynamic-sub-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
            }
            
            .dynamic-sub-tab-btn {
                padding: 6px 12px;
                font-size: 12px;
                white-space: nowrap;
                min-height: 36px;
            }
            
            .config-item {
                padding: 14px;
            }
            
            .config-item .item-details {
                grid-template-columns: 1fr;
            }
            
            .custom-dialog-box {
                padding: 20px;
                margin: 10px;
                max-width: 95%;
                max-height: 90vh;
            }
            
            .user-search-modal .custom-dialog-box {
                max-width: 95%;
                max-height: 90%;
            }
            
            .user-search-modal .dialog-header {
                padding: 15px 18px 12px;
            }
            
            .user-search-modal .dialog-body {
                padding: 12px 18px 18px;
            }
            
            .create-user-form {
                padding: 15px;
            }
            
            .btn {
                padding: 8px 16px;
                font-size: 13px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
            }
            
            .config-item .item-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .config-item .item-actions form {
                width: 100%;
            }
            
            .config-item .item-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .config-item .status-select {
                width: 100%;
            }
            
            .datetime-group .time-group {
                flex-wrap: wrap;
            }
            
            .datetime-group .time-group input[type="number"] {
                width: 50px;
            }
            
            .datetime-group .time-group select {
                width: 60px;
            }
        }
        
        @media (max-width: 480px) {
            .main-header {
                padding: 8px 12px;
                min-height: 50px;
            }
            
            .main-header h1 {
                font-size: 14px;
            }
            
            .debug-btn {
                padding: 4px 10px;
                font-size: 12px;
            }
            
            .content-area {
                padding: 12px;
            }
            
            .sidebar {
                padding: 12px;
            }
            
            .dashboard-tab-btn {
                padding: 6px 10px;
                font-size: 12px;
                min-height: 38px;
            }
            
            .user-config-tab-btn {
                padding: 6px 10px;
                font-size: 12px;
                min-height: 38px;
            }
            
            .dynamic-sub-tab-btn {
                padding: 5px 10px;
                font-size: 11px;
                min-height: 34px;
            }
            
            .config-item {
                padding: 12px;
            }
            
            .config-item .item-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .custom-dialog-box {
                padding: 15px;
                max-width: 98%;
                max-height: 95vh;
                margin: 10px;
            }
            
            .custom-dialog-box .dialog-title {
                font-size: 18px;
            }
            
            .create-user-form {
                padding: 12px;
            }
            
            .create-user-form input {
                padding: 10px 14px;
                font-size: 14px;
            }
            
            .form-group input,
            .form-group select,
            .form-group textarea {
                padding: 8px 12px;
                font-size: 14px;
            }
            
            .btn {
                padding: 8px 14px;
                font-size: 12px;
            }
            
            .btn-sm {
                padding: 4px 10px;
                font-size: 11px;
            }
            
            .user-settings-header .user-info h2 {
                font-size: 18px;
            }
            
            .user-search-modal .custom-dialog-box {
                max-width: 98%;
                max-height: 95%;
            }
        }
        
        .default-value-wrapper {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .default-value-wrapper input {
            width: 100%;
        }
        
        .field-with-default {
            width: 100%;
        }
        
        /* ===== COPY BUTTON STYLES ===== */
        .entry-field-group .field-value {
            max-height: 120px;
            overflow-y: auto;
            padding-right: 5px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar {
            width: 4px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 4px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        /* Individual field copy button */
        .entry-field-group.individual-field {
            background: #f8fafc;
            padding: 10px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
        
        /* Object field copy button */
        .entry-field-group.object-field {
            background: #f0fdf4;
            padding: 12px 14px;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
        }
        
        /* Copy button styling */
        .btn-secondary.btn-sm {
            padding: 4px 10px;
            font-size: 11px;
            background: #e5e7eb;
            color: #1a1a2e;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-secondary.btn-sm:hover {
            background: #d1d5db;
            transform: translateY(-1px);
        }
        
        /* ================================================================
           DIALOG FIXES FOR LARGE SCREENS - ENSURES VISIBILITY
           ================================================================ */
        .custom-dialog-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100vw;
            height: 100vh;
            min-height: 100vh;
            background: rgba(0, 0, 0, 0.6);
            z-index: 999999 !important;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            padding: 20px;
            box-sizing: border-box;
            overflow: hidden;
        }
        
        .custom-dialog-overlay.active {
            display: flex !important;
            opacity: 1;
            visibility: visible;
        }
        
        /* Force dialog to be on top of everything */
        .custom-dialog-overlay,
        .custom-dialog-overlay * {
            z-index: 999999 !important;
        }
        
        /* Ensure dialog box is visible on all screen sizes */
        .custom-dialog-box {
            background: #ffffff;
            border-radius: 16px;
            padding: 30px;
            max-width: 650px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4);
            animation: dialogSlideIn 0.3s ease;
            position: relative;
            margin: 20px;
            box-sizing: border-box;
            display: block !important;
            visibility: visible !important;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        /* Fix for very large screens */
        @media (min-width: 1600px) {
            .custom-dialog-box {
                max-width: 700px;
                padding: 40px;
            }
        }
        
        /* Fix for very small screens */
        @media (max-width: 400px) {
            .custom-dialog-box {
                padding: 12px;
                margin: 5px;
            }
            
            .custom-dialog-box .dialog-title {
                font-size: 16px;
            }
            
            .custom-dialog-box .dialog-message {
                font-size: 14px;
            }
            
            .custom-dialog-box .dialog-buttons .btn {
                font-size: 12px;
                padding: 6px 12px;
                min-width: 60px;
            }
        }
    /* ===== TRANSCRIPT DETECTION STYLES ===== */
    .transcript-indicator {
        margin-top: 8px;
        padding: 8px 12px;
        background: #d1fae5;
        border-radius: 8px;
        border-left: 4px solid #00695c;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
        animation: slideIn 0.3s ease;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .transcript-indicator .indicator-text {
        color: #065f46;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .transcript-indicator .btn-view {
        padding: 4px 12px;
        font-size: 12px;
    }

    /* Transcript viewer modal adjustments */
    #transcriptConversionResult {
        margin-top: 15px;
    }

    #transcriptConversionResult .result-box {
        background: #f0fdf4;
        border-radius: 8px;
        padding: 15px;
        border: 1px solid #a7f3d0;
    }

    #transcriptConversionResult .result-box .result-title {
        color: #065f46;
        font-weight: 600;
        margin-bottom: 8px;
    }

    #transcriptConversionResult .result-box .result-stats {
        font-size: 13px;
        color: #065f46;
    }
    /* Toggle switch styles - ensure these exist */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 40px;
        height: 22px;
        flex-shrink: 0;
    }

    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 22px;
    }

    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 16px;
        width: 16px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }

    .toggle-switch input:checked + .toggle-slider {
        background-color: #00695c;
    }

    .toggle-switch input:checked + .toggle-slider:before {
        transform: translateX(18px);
    }
/* ===== REFINED DYNAMIC TABS UI (scoped, responsive, predictable) ===== */
#dynamicTabsEditor{--dt-ink:#172033;--dt-muted:#687386;--dt-line:#dce3ec;--dt-soft:#f6f8fb;--dt-teal:#087f75;--dt-teal-dark:#075e57;--dt-red:#b42318;width:100%;min-width:0;color:var(--dt-ink)}
#dynamicTabsEditor *{box-sizing:border-box}
#dynamicTabsEditor .dynamic-tabs-editor-header{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:22px 24px;margin:0 0 22px;border:1px solid #d8e7e5;border-radius:16px;background:linear-gradient(120deg,#f0fdfa 0%,#f8fbff 72%);box-shadow:0 5px 18px rgba(15,45,55,.045)}
#dynamicTabsEditor .dynamic-tabs-editor-header h3{font-size:22px;line-height:1.25;font-weight:750;letter-spacing:-.025em;color:#102a32;margin:0 0 7px}
#dynamicTabsEditor .dynamic-tabs-editor-header p{max-width:720px;color:#5c6d7c;font-size:13px;line-height:1.65;margin:0}
#dynamicTabsEditor .dynamic-tabs-editor-header .btn{flex:0 0 auto;min-height:42px;padding:10px 16px;border-radius:10px;font-weight:700;box-shadow:0 3px 8px rgba(8,127,117,.12)}
#dynamicTabsEditor .dynamic-tabs-editor-list{display:flex;flex-direction:column;gap:18px;counter-reset:main-tab;min-width:0}
#dynamicTabsEditor .tab-editor-item{position:relative;min-width:0;width:100%;margin:0;padding:0;overflow:hidden;border:1px solid var(--dt-line);border-radius:15px;background:#fff;box-shadow:0 4px 14px rgba(20,35,55,.045)}
#dynamicTabsEditor .root-tab-editor-item{counter-increment:main-tab;border-top:3px solid #087f75;border-left:1px solid var(--dt-line)}
#dynamicTabsEditor .root-tab-editor-item>.tab-editor-heading{padding:16px 18px;background:linear-gradient(180deg,#f7fcfb,#fff);border-bottom:1px solid #e5ecef}
#dynamicTabsEditor .root-tab-editor-item>.tab-editor-heading .tab-editor-kind:before{content:'MAIN TAB ' counter(main-tab);display:inline-flex;align-items:center;justify-content:center;margin-right:8px;padding:4px 8px;border-radius:6px;background:#d9f4ef;color:#075e57;font-size:10px;letter-spacing:.07em}
#dynamicTabsEditor .root-tab-editor-item>.tab-editor-heading .tab-editor-kind{font-size:0;color:transparent}
#dynamicTabsEditor .tab-editor-heading{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;min-width:0}
#dynamicTabsEditor .tab-editor-heading-copy{display:flex;flex:1 1 220px;min-width:0;flex-direction:column;gap:7px}
#dynamicTabsEditor .tab-editor-title{display:block;min-width:0;overflow-wrap:anywhere;font-size:16px;line-height:1.4;font-weight:750;color:#182638}
#dynamicTabsEditor .tab-editor-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}
#dynamicTabsEditor .tab-editor-actions .btn{min-height:34px;padding:7px 11px;border-radius:8px;font-size:12px;font-weight:650}
#dynamicTabsEditor .tab-editor-actions .btn-danger{background:#fff5f4;color:#a32920;border:1px solid #f2c7c3}
#dynamicTabsEditor .tab-editor-actions .btn-danger:hover{background:#fee9e7}
#dynamicTabsEditor .tab-editor-actions .btn-secondary{background:#fff;color:#425166;border:1px solid #d8e0e9}
#dynamicTabsEditor .nested-tab-editor-body{display:block;padding:20px;min-width:0}
#dynamicTabsEditor .tab-editor-item .form-group{display:flex;flex-direction:column;gap:7px;margin:0 0 18px;min-width:0}
#dynamicTabsEditor .tab-editor-item .form-group>label{font-size:12px;font-weight:750;letter-spacing:.015em;color:#344256}
#dynamicTabsEditor .tab-editor-item .form-group input[type=text],#dynamicTabsEditor .tab-editor-item .form-group select,#dynamicTabsEditor .tab-editor-item .form-group textarea{display:block;width:100%;max-width:100%;min-width:0;min-height:42px;padding:10px 12px;border:1px solid #cfd8e3;border-radius:9px;background:#fff;color:#172033;font-size:14px!important;line-height:1.45;box-shadow:none;transition:border-color .15s,box-shadow .15s}
#dynamicTabsEditor .tab-editor-item .form-group textarea{min-height:82px;resize:vertical}
#dynamicTabsEditor .tab-editor-item .form-group input:focus,#dynamicTabsEditor .tab-editor-item .form-group select:focus,#dynamicTabsEditor .tab-editor-item .form-group textarea:focus{outline:none;border-color:#087f75;box-shadow:0 0 0 3px rgba(8,127,117,.12)}
#dynamicTabsEditor .tab-editor-help{margin:2px 0 0;padding:10px 12px;border-radius:8px;background:#f2f7fb;color:#5e6e81;font-size:12px;line-height:1.6}
#dynamicTabsEditor .tab-fields-toggle-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:12px;border:1px solid #e0e7ef;border-radius:11px;background:#f8fafc}
#dynamicTabsEditor .tab-field-option{display:flex;align-items:center;justify-content:space-between;gap:12px;min-width:0;min-height:44px;padding:10px 12px;border:1px solid #e0e6ed;border-radius:9px;background:#fff;cursor:pointer;transition:border-color .15s,background .15s}
#dynamicTabsEditor .tab-field-option:hover{border-color:#8dcfc5;background:#f7fffd}
#dynamicTabsEditor .tab-field-option input{flex:0 0 auto;width:17px;height:17px;margin:0;accent-color:#087f75}
#dynamicTabsEditor .tab-field-label{min-width:0;overflow-wrap:anywhere;font-size:12px;line-height:1.5;color:#344256}
#dynamicTabsEditor .tab-field-label small{display:block;margin-top:2px;color:#8490a0;font-size:10px}
#dynamicTabsEditor .tab-feature-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;padding:12px;border:1px solid #e0e7ef;border-radius:11px;background:#fbfcfe}
#dynamicTabsEditor .tab-feature-options label{display:flex;align-items:center;gap:8px;min-width:0;margin:0;padding:10px;border:1px solid #e6ebf1;border-radius:8px;background:#fff;color:#3f4d60;font-size:12px;line-height:1.45;cursor:pointer}
#dynamicTabsEditor .tab-feature-options input{flex:0 0 auto;accent-color:#087f75;width:16px;height:16px;margin:0}
#dynamicTabsEditor .nested-sub-tabs-container{margin:20px 0 0;padding:17px;border:1px solid #b8e4dc;border-radius:13px;background:#f2fbf9}
#dynamicTabsEditor .nested-sub-tabs-heading{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:0 0 14px;padding-bottom:13px;border-bottom:1px solid #d7eeea}
#dynamicTabsEditor .nested-sub-tabs-heading strong{display:block;color:#145b55;font-size:15px;font-weight:800}
#dynamicTabsEditor .nested-sub-tabs-heading p{margin:5px 0 0;color:#647b7a;font-size:12px;line-height:1.5}
#dynamicTabsEditor .nested-sub-tabs-heading .btn{min-height:36px;padding:8px 12px;border-radius:9px;font-weight:700}
#dynamicTabsEditor .nested-sub-tabs-list{display:flex;flex-direction:column;gap:12px;min-width:0}
#dynamicTabsEditor .sub-tab-editor-item{border:1px solid #d8e2eb;border-left:4px solid #7b8da4;border-radius:11px;background:#fff;box-shadow:none}
#dynamicTabsEditor .sub-tab-editor-item>.tab-editor-heading{padding:13px 15px;background:#f7f9fc;border-bottom:1px solid #e7edf3}
#dynamicTabsEditor .sub-tab-editor-item .tab-editor-kind{display:inline-flex;align-self:flex-start;padding:4px 7px;border-radius:5px;background:#e8eef5;color:#53677e;font-size:10px;font-weight:800;letter-spacing:.08em}
#dynamicTabsEditor .sub-tab-editor-item .tab-editor-title{font-size:14px}
#dynamicTabsEditor .sub-tab-editor-item .nested-tab-editor-body{padding:16px}
#dynamicTabsEditor .nested-sub-tabs-empty{margin:0;padding:16px;border:1px dashed #b8d9d4;border-radius:9px;background:rgba(255,255,255,.7);color:#637c79;text-align:center;font-size:12px;line-height:1.5}
#dynamicTabsEditor .tab-editor-empty-fields{margin:0;padding:13px;border:1px dashed #d1dbe5;border-radius:9px;background:#fff;color:#718096;font-size:12px;line-height:1.5}
#dynamicTabsEditor .tab-editor-empty-state{margin:0 0 16px;padding:34px 20px;border:1px dashed #cbd7e2;border-radius:14px;background:#fafcff;color:#6b7788;display:flex;flex-direction:column;align-items:center;gap:8px;text-align:center}
#dynamicTabsEditor .tab-editor-empty-state strong{font-size:15px;color:#27364a}
#dynamicTabsEditor .dynamic-tabs-save-bar{position:sticky;bottom:0;z-index:20;display:flex;justify-content:flex-end;gap:12px;margin:22px -2px 0;padding:14px 2px 3px;background:linear-gradient(180deg,rgba(255,255,255,0),#fff 28%)}
#dynamicTabsEditor .dynamic-tabs-save-bar .btn{min-height:44px;padding:11px 20px;border-radius:10px;font-size:14px;font-weight:800;box-shadow:0 4px 12px rgba(8,127,117,.16)}
#dynamicTabsEditor .btn:focus-visible{outline:3px solid rgba(8,127,117,.25);outline-offset:2px}
/* Nested project tabs: clearly separated parent and child navigation/content */
#newProjectSubTabs{display:flex;flex-wrap:wrap;gap:8px;padding:10px;margin:0 0 18px;border:1px solid #dce5ed;border-radius:12px;background:#f7f9fc;white-space:normal;overflow:visible}
#newProjectSubTabs .dynamic-sub-tab-btn{min-height:40px;margin:0;padding:9px 14px;border:1px solid #d9e2eb;border-radius:9px;background:#fff;color:#46566b;font-size:13px;font-weight:650;white-space:normal}
#newProjectSubTabs .dynamic-sub-tab-btn.active{border-color:#087f75;background:#e5f7f3;color:#075e57;box-shadow:inset 0 0 0 1px rgba(8,127,117,.08)}
#user_tab_add_entry .dynamic-sub-tab-content{padding:18px;border:1px solid #dce5ed;border-radius:13px;background:#fff}
#user_tab_add_entry .nested-project-tabs{padding:14px;border:1px solid #d5e9e5;border-radius:12px;background:#f8fcfb}
#user_tab_add_entry .nested-project-tabs .dynamic-sub-tabs{display:flex;flex-wrap:wrap;gap:7px;padding:0 0 12px;border-bottom:1px solid #dfe8ec;overflow:visible;white-space:normal}
#user_tab_add_entry .nested-project-tabs .nested-child-btn{margin:0;padding:8px 12px;border:1px solid #d8e2eb;border-radius:8px;background:#fff;color:#526176;font-size:12px;font-weight:650;white-space:normal}
#user_tab_add_entry .nested-project-tabs .nested-child-btn.active{border-color:#8acfc4;background:#e7f8f4;color:#075e57}
#user_tab_add_entry .nested-child-content{padding:16px 4px 4px}
@media(max-width:760px){#dynamicTabsEditor .dynamic-tabs-editor-header{align-items:stretch;flex-direction:column;padding:17px}#dynamicTabsEditor .dynamic-tabs-editor-header .btn{width:100%}#dynamicTabsEditor .nested-tab-editor-body{padding:14px}#dynamicTabsEditor .root-tab-editor-item>.tab-editor-heading{padding:13px}#dynamicTabsEditor .tab-editor-actions{width:100%;justify-content:flex-start}#dynamicTabsEditor .tab-editor-actions .btn{flex:1 1 auto}#dynamicTabsEditor .tab-fields-toggle-list{grid-template-columns:minmax(0,1fr)}#dynamicTabsEditor .tab-feature-options{grid-template-columns:minmax(0,1fr)}#dynamicTabsEditor .nested-sub-tabs-container{padding:12px}#dynamicTabsEditor .nested-sub-tabs-heading{align-items:stretch;flex-direction:column}#dynamicTabsEditor .nested-sub-tabs-heading .btn{width:100%}#dynamicTabsEditor .dynamic-tabs-save-bar .btn{width:100%}#user_tab_add_entry .dynamic-sub-tab-content{padding:12px}}
@media(prefers-reduced-motion:reduce){#dynamicTabsEditor *,#newProjectSubTabs *{transition:none!important;scroll-behavior:auto!important}}

/* ===== SINGLE-WINNER STICKY NAVIGATION =====
   Navigation hand-off:
   1) The hardcoded account tabs (New Project, Pending, Completed, Aborted, etc.)
      stay at the top while they are the only navigation row in use.
   2) When the dynamic main-tab row reaches the top, it takes over and the
      hardcoded row is released to scroll away.
   3) When a nested row reaches the top, it takes over and the dynamic main row
      is released. Scrolling upward restores the previous row naturally.
   ========================================================== */
.content-area {
    overflow: visible !important;
    min-height: 0;
}

/* Sticky navigation stacking order: default 800, dynamic main 900, nested child 1000. */
.user-config-tabs {
    position: sticky;
    top: 0;
    z-index: 800;
    isolation: isolate;
    background: rgba(255, 255, 255, .98);
    -webkit-backdrop-filter: blur(12px);
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 14px rgba(15, 35, 55, .09);
}

/* Keep the default row sticky, but always beneath both dynamic navigation rows. */
.user-config-tabs.default-nav-released {
    position: sticky !important;
    top: 0 !important;
    z-index: 800 !important;
}

/* The main dynamic row is sticky until a nested child row arrives. */
#user_tab_add_entry #newProjectSubTabs {
    position: sticky;
    top: 0;
    z-index: 900;
    isolation: isolate;
    background: rgba(247, 249, 252, .99);
    -webkit-backdrop-filter: blur(12px);
    backdrop-filter: blur(12px);
    box-shadow: 0 5px 16px rgba(15, 35, 55, .10);
    border-color: #cbd9e5;
    scroll-margin-top: 0;
}

/* The main row remains sticky underneath the nested child row. */
#user_tab_add_entry.child-nav-priority #newProjectSubTabs {
    position: sticky !important;
    top: 0 !important;
    z-index: 900 !important;
}

/* Child row takes over the entire dynamic-content width while stuck. */
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    position: sticky;
    top: 0;
    z-index: 1000;
    isolation: isolate;
    width: calc(100% + 64px);
    max-width: none;
    margin-left: -32px;
    margin-right: -32px;
    padding: 10px 32px 12px !important;
    background: rgba(248, 252, 251, .99);
    -webkit-backdrop-filter: blur(12px);
    backdrop-filter: blur(12px);
    box-shadow: 0 5px 16px rgba(15, 35, 55, .12);
    border-bottom: 1px solid #cbdedb;
    box-sizing: border-box;
}

#user_tab_add_entry.child-nav-priority #newProjectSubTabs,
#user_tab_add_entry.child-nav-priority .nested-project-tabs > .dynamic-sub-tabs {
    transition: box-shadow .18s ease, background-color .18s ease;
}

.custom-dialog-overlay { z-index: 999999 !important; }

@media (max-width: 760px) {
    #user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
        width: calc(100% + 52px);
        margin-left: -26px;
        margin-right: -26px;
        padding: 9px 26px 11px !important;
        gap: 6px;
    }
    #user_tab_add_entry #newProjectSubTabs {
        gap: 6px;
        padding: 8px;
        border-radius: 0 0 11px 11px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .user-config-tabs,
    #user_tab_add_entry #newProjectSubTabs,
    #user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
        transition: none !important;
    }
}


        /* Dynamic-tab editor controls */
        .dynamic-tabs-save-bar { position: sticky; bottom: 0; z-index: 50; background: rgba(255,255,255,.97); border-top: 1px solid #e2e8f0; padding: 12px; margin-top: 16px; }
        .display-tab-toggle { display:inline-flex; align-items:center; gap:10px; cursor:pointer; padding:8px 10px; border:1px solid #cbd5e1; border-radius:9px; background:#f8fafc; }
        .display-tab-toggle input { width:18px; height:18px; accent-color:#0f766e; }
        .display-tab-state { font-size:12px; color:#334155; }
        #user_tab_dynamic_fields > div > div[style*="display:flex"] { position:sticky; bottom:0; z-index:40; background:rgba(255,255,255,.97); padding:12px 0; border-top:1px solid #e2e8f0; }
        .dynamic-tabs-editor-list { padding-bottom:70px; }

        .dynamic-tabs-save-bar { position: sticky; bottom: 0; z-index: 50; background: rgba(255,255,255,.97); border-top: 1px solid #e2e8f0; padding: 12px; margin-top: 16px; }
        .display-tab-toggle { display:inline-flex; align-items:center; gap:10px; cursor:pointer; padding:8px 10px; border:1px solid #cbd5e1; border-radius:9px; background:#f8fafc; }
        .display-tab-toggle input { width:18px; height:18px; accent-color:#0f766e; }
        .display-tab-state { font-size:12px; color:#334155; }
        #user_tab_dynamic_fields > div > div[style*="display:flex"] { position:sticky; bottom:0; z-index:40; background:rgba(255,255,255,.97); padding:12px 0; border-top:1px solid #e2e8f0; }
        .dynamic-tabs-editor-list { padding-bottom:70px; }

/* ===== FINAL DESIGN RULES: full outlines only, no directional accent borders ===== */
#dynamicTabsEditor .root-tab-editor-item,
#dynamicTabsEditor .sub-tab-editor-item,
#dynamicTabsEditor .root-tab-editor-item > .tab-editor-heading,
#dynamicTabsEditor .sub-tab-editor-item > .tab-editor-heading,
#dynamicTabsEditor .nested-sub-tabs-heading,
#dynamicTabsEditor .nested-project-tabs .dynamic-sub-tabs,
#dynamicTabsEditor .dynamic-tabs-save-bar,
#user_tab_add_entry #newProjectSubTabs,
#user_tab_add_entry .nested-project-tabs .dynamic-sub-tabs,
#user_tab_submit_tabs .submit-tabs-save-bar,
#user_tab_dynamic_fields .account-dynamic-fields-add-bar {
    border-top: 0 !important;
    border-left: 0 !important;
    border-right: 0 !important;
    border-bottom: 0 !important;
}
#dynamicTabsEditor .root-tab-editor-item,
#dynamicTabsEditor .sub-tab-editor-item,
#dynamicTabsEditor .root-tab-editor-item > .tab-editor-heading,
#dynamicTabsEditor .sub-tab-editor-item > .tab-editor-heading,
#dynamicTabsEditor .nested-sub-tabs-heading,
#dynamicTabsEditor .dynamic-tabs-save-bar,
#user_tab_add_entry #newProjectSubTabs,
#user_tab_add_entry .nested-project-tabs .dynamic-sub-tabs,
#user_tab_submit_tabs .submit-tabs-save-bar,
#user_tab_dynamic_fields .account-dynamic-fields-add-bar {
    border: 1px solid #dce5ed !important;
}
#dynamicTabsEditor .root-tab-editor-item > .tab-editor-heading,
#dynamicTabsEditor .sub-tab-editor-item > .tab-editor-heading,
#dynamicTabsEditor .nested-sub-tabs-heading {
    border-radius: 12px;
}
#dynamicTabsEditor .root-tab-editor-item { border: 1px solid var(--dt-line) !important; }
#dynamicTabsEditor .sub-tab-editor-item { border: 1px solid #d8e2eb !important; }
#dynamicTabsEditor .dynamic-tabs-save-bar {
    position: sticky !important; bottom: 0; z-index: 100;
    margin-top: 18px; padding: 12px;
    background: rgba(255,255,255,.98);
    box-shadow: 0 -8px 18px rgba(15,23,42,.08);
    border-radius: 12px;
}
.content-area { overflow: visible !important; }
#user_tab_submit_tabs { overflow: visible !important; }
#user_tab_submit_tabs #submitTabsEditor { padding-bottom: 92px; }
#user_tab_submit_tabs .submit-tabs-save-bar {
    position: sticky; bottom: 0; z-index: 100;
    display: flex; justify-content: flex-end;
    margin: 14px 0 0; padding: 12px;
    background: rgba(255,255,255,.98);
    box-shadow: 0 -8px 18px rgba(15,23,42,.08);
    border-radius: 12px;
}
#user_tab_submit_tabs .submit-tabs-save-bar .btn { min-height: 44px; }
#user_tab_dynamic_fields { overflow: visible !important; }
#user_tab_dynamic_fields #userGlobalDynamicFieldsList { padding-bottom: 92px; }
#user_tab_dynamic_fields .account-dynamic-fields-add-bar {
    position: sticky; bottom: 0; z-index: 100;
    display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap;
    margin-top: 12px; padding: 12px;
    background: rgba(255,255,255,.98);
    box-shadow: 0 -8px 18px rgba(15,23,42,.08);
    border-radius: 12px;
}
.account-dynamic-field-card {
    display: block; width: 100%; margin: 0 0 12px; padding: 0;
    border: 1px solid #dce3ec; border-radius: 12px;
    background: #fff; box-shadow: 0 3px 10px rgba(15,23,42,.04);
    cursor: pointer; overflow: hidden; transition: background .15s, box-shadow .15s;
}
.account-dynamic-field-card:hover { background: #fbfdfd; box-shadow: 0 5px 14px rgba(15,23,42,.07); }
.account-dynamic-field-card:focus-visible { outline: 2px solid #087f75; outline-offset: 2px; }
.account-dynamic-field-heading {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 15px; min-width: 0;
}
.account-dynamic-field-title-wrap { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.account-dynamic-field-title { color: #1a1a2e; overflow-wrap: anywhere; }
.account-dynamic-field-key { color: #6b7280; font-size: 12px; overflow-wrap: anywhere; }
.account-dynamic-field-heading-side { display: flex; align-items: center; gap: 10px; flex: 0 0 auto; }
.account-dynamic-field-type { font-size: 11px; color: #475569; background: #eef2f7; padding: 5px 9px; border-radius: 999px; }
.account-dynamic-field-chevron { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border: 1px solid #dce3ec; border-radius: 8px; color: #334155; font-weight: 700; }
.account-dynamic-field-details { padding: 14px 15px 15px; border: 1px solid #e5eaf0; border-radius: 10px; margin: 0 12px 12px; background: #f8fafc; cursor: default; }
.account-dynamic-field-details[hidden] { display: none !important; }
.account-dynamic-field-info { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 8px 14px; color: #64748b; font-size: 13px; overflow-wrap: anywhere; }
.account-dynamic-subfield { grid-column: 1 / -1; margin-left: 8px; font-size: 12px; color: #64748b; }
.account-dynamic-field-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; padding: 12px 0 0; border: 1px solid #e5eaf0; border-radius: 9px; }
.account-object-reorder { margin-top: 14px; padding: 12px; border: 1px solid #dbe5ec; border-radius: 10px; background: #fff; }
.account-object-reorder-heading { display:flex; flex-direction:column; gap:4px; margin-bottom:10px; color:#1e293b; }
.account-object-reorder-heading small { color:#64748b; font-size:12px; line-height:1.45; }
.account-object-reorder-list { list-style:none; display:flex; flex-direction:column; gap:7px; padding:0; margin:0; }
.account-object-reorder-item { display:flex; align-items:center; gap:10px; min-width:0; padding:9px; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; }
.account-object-position { display:flex; align-items:center; justify-content:center; flex:0 0 25px; height:25px; border-radius:7px; background:#e2e8f0; color:#334155; font-size:12px; font-weight:700; }
.account-object-element-name { display:flex; flex:1; flex-direction:column; min-width:0; gap:3px; overflow-wrap:anywhere; color:#334155; font-size:13px; }
.account-object-element-name small { color:#64748b; font-size:11px; }
.account-object-move-controls { display:flex; gap:5px; }
.account-object-move-controls .btn { min-width:34px; padding:5px 9px; }
.account-object-move-controls .btn:disabled { opacity:.35; cursor:not-allowed; }
.account-dynamic-field-card.is-saving-order { opacity:.65; pointer-events:none; }

/* SceneIQ admin now uses a regular, familiar page font. */
html, body, .custom-body, .custom-body * {
    font-family: Arial, Helvetica, sans-serif !important;
}
/* Keep very large field values inside their own scrollable preview area. */
.account-dynamic-field-details {
    max-height: 440px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.account-dynamic-field-info {
    display: grid;
    align-content: start;
    max-height: 280px;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 2px 6px 2px 0;
}
.account-dynamic-field-value {
    grid-column: 1 / -1;
    min-width: 0;
    overflow-wrap: anywhere;
}
.account-dynamic-field-value pre {
    display: block;
    max-height: 150px;
    margin-top: 5px;
    padding: 9px 10px;
    overflow: auto;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    border: 1px solid #e2e8f0;
    border-radius: 7px;
    background: #fff;
    color: #334155;
    font: 13px/1.45 Arial, Helvetica, sans-serif;
}
.account-dynamic-field-heading-side { flex-wrap: wrap; justify-content: flex-end; }
.account-field-copy { flex: 0 0 auto; white-space: nowrap; }

@media (max-width: 640px) {
    #user_tab_submit_tabs .submit-tabs-save-bar .btn,
    #user_tab_dynamic_fields .account-dynamic-fields-add-bar .btn { width: 100%; }
    #user_tab_submit_tabs .submit-tabs-save-bar,
    #user_tab_dynamic_fields .account-dynamic-fields-add-bar { justify-content: stretch; }
    .account-dynamic-field-info { grid-template-columns: minmax(0,1fr); }
    .account-dynamic-field-heading { align-items: flex-start; }
    .account-dynamic-field-heading-side { gap: 6px; }
}


/* ===== STICKY ACTIONS: ONLY THE BUTTONS FLOAT; THE WRAPPER IS CLICK-THROUGH ===== */
#dynamicTabsEditor .dynamic-tabs-save-bar,
.dynamic-tabs-save-bar,
#user_tab_submit_tabs .submit-tabs-save-bar,
#user_tab_dynamic_fields .account-dynamic-fields-add-bar {
    position: sticky !important;
    bottom: 0 !important;
    z-index: 100 !important;
    display: flex !important;
    justify-content: flex-end !important;
    align-items: center !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
    width: 100% !important;
    min-height: 0 !important;
    margin: 12px 0 0 !important;
    padding: 0 !important;
    background: transparent !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    pointer-events: none !important;
}

/* The invisible sticky area must never block controls beneath it. */
#dynamicTabsEditor .dynamic-tabs-save-bar > *,
.dynamic-tabs-save-bar > *,
#user_tab_submit_tabs .submit-tabs-save-bar > *,
#user_tab_dynamic_fields .account-dynamic-fields-add-bar > * {
    pointer-events: auto !important;
    flex: 0 0 auto;
}

/* Keep only the actual controls visible and interactive—not a white card. */
#dynamicTabsEditor .dynamic-tabs-save-bar .btn,
.dynamic-tabs-save-bar .btn,
#user_tab_submit_tabs .submit-tabs-save-bar .btn,
#user_tab_dynamic_fields .account-dynamic-fields-add-bar .btn {
    pointer-events: auto !important;
    position: relative;
    z-index: 101;
    box-shadow: 0 3px 10px rgba(15, 23, 42, .16);
}

/* Preserve scroll clearance without creating a visible backing panel. */
#user_tab_submit_tabs #submitTabsEditor,
#user_tab_dynamic_fields #userGlobalDynamicFieldsList,
#dynamicTabsEditor .dynamic-tabs-editor-list {
    padding-bottom: 76px !important;
}

@media (max-width: 640px) {
    #user_tab_submit_tabs .submit-tabs-save-bar,
    #user_tab_dynamic_fields .account-dynamic-fields-add-bar,
    #dynamicTabsEditor .dynamic-tabs-save-bar {
        justify-content: flex-end !important;
        padding: 0 !important;
        background: transparent !important;
        border: 0 !important;
        box-shadow: none !important;
    }
    #user_tab_submit_tabs .submit-tabs-save-bar .btn,
    #user_tab_dynamic_fields .account-dynamic-fields-add-bar .btn,
    #dynamicTabsEditor .dynamic-tabs-save-bar .btn {
        width: auto !important;
        max-width: 100%;
    }
}


/* ===== Dynamic tab editor interaction updates ===== */
#dynamicTabsEditor .tab-editor-heading {
    cursor: pointer;
}
#dynamicTabsEditor .tab-editor-heading:focus-visible {
    outline: 2px solid #087f75;
    outline-offset: -2px;
}
#dynamicTabsEditor .tab-editor-item:not(.is-expanded) .tab-delete-button {
    display: none !important;
}
#dynamicTabsEditor .tab-editor-item.is-expanded .tab-delete-button {
    display: inline-flex !important;
}
#dynamicTabsEditor .display-tab-setting select.display-tab-input {
    width: 100%;
    max-width: 220px;
    min-height: 40px;
    padding: 9px 12px;
    border: 1px solid #cfd8e3;
    border-radius: 8px;
    background: #fff;
    color: #172033;
    cursor: pointer;
}
#dynamicTabsEditor .tab-editor-heading .tab-editor-actions {
    cursor: default;
}


/* ===== STICKY NAV VISUAL SPACING FIX (preserve existing scroll hand-off) =====
   Keep the default account tabs, dynamic main tabs, and nested tabs flush
   vertically. This changes only default layout spacing; sticky positioning,
   z-index priority, sentinels, and scroll listeners remain untouched.
*/
.user-config-tabs {
    margin-bottom: 0 !important;
}
.user-config-content {
    padding-top: 0 !important;
}
#user_tab_add_entry #newProjectSubTabs {
    margin-top: 0 !important;
    margin-bottom: 0 !important;
}
/* Remove the parent container's top inset above the nested sticky row. */
#user_tab_add_entry .nested-project-tabs {
    padding-top: 0 !important;
}
/* Do not add a gap above the nested sticky row or between its own tabs. */
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    margin-top: 0 !important;
    margin-bottom: 0 !important;
}
/* Keep tab rows edge-to-edge without changing their horizontal button spacing. */
#user_tab_add_entry #newProjectSubTabs,
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    align-self: stretch;
}
/* Keep the nested sub-tab row flush with the very top of its parent element.
   The sticky row previously had 10px (9px on mobile) of top padding, which
   made its buttons look separated from the main dynamic tab above it.
   This is a visual-only change: sticky positioning and scroll hand-off stay intact. */
#user_tab_add_entry .nested-project-tabs {
    padding-top: 0 !important;
}
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

/* ===== END STICKY NAV VISUAL SPACING FIX ===== */

/* ===== FINAL FIX: SUB-TAB STICKY ROW TOUCHES MAIN DYNAMIC TAB =====
   The active dynamic-tab content had top padding before its nested tabs.
   Remove only that inset when the content contains nested-project-tabs, so
   the child sticky row begins flush against the bottom of #newProjectSubTabs.
   Sticky top offsets, z-index priority, sentinels, and scroll hand-off stay intact.
*/
#user_tab_add_entry .dynamic-sub-tab-content:has(.nested-project-tabs) {
    padding-top: 0 !important;
    margin-top: 0 !important;
}

#user_tab_add_entry .dynamic-sub-tab-content .nested-project-tabs {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

#user_tab_add_entry .dynamic-sub-tab-content .nested-project-tabs > .dynamic-sub-tabs {
    margin-top: 0 !important;
    padding-top: 0 !important;
    top: 0 !important;
}

@supports not selector(.dynamic-sub-tab-content:has(.nested-project-tabs)) {
    /* Fallback for older browsers: remove the top inset from the content row. */
    #user_tab_add_entry .dynamic-sub-tab-content {
        padding-top: 0 !important;
    }
}
/* ===== END FINAL SUB-TAB FLUSH FIX ===== */

/* ===== SUB-TAB STICKY HEIGHT, CENTERING, AND LEFT-AXIS ALIGNMENT =====
   Match the nested sticky row to the main dynamic-tab row's measured height,
   plus 2px. The buttons stay vertically centered, and their left edge aligns
   with the first main dynamic-tab button. Sticky hand-off logic is unchanged.
*/
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    display: flex !important;
    align-items: center !important;
    box-sizing: border-box !important;
    min-height: 0 !important;
    height: var(--nested-sticky-nav-height, auto) !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    padding-left: 42px !important;
    padding-right: 32px !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    background: #f8fcfb !important;
    opacity: 1 !important;
}

#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs > .nested-child-btn {
    align-self: center !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    flex-shrink: 0;
}

@media (max-width: 760px) {
    #user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
        padding-left: 34px !important;
        padding-right: 26px !important;
    }
}
/* ===== END SUB-TAB STICKY HEIGHT, CENTERING, AND LEFT-AXIS ALIGNMENT ===== */




/* ===== REQUESTED STICKY TAB CLEAN WHITE DESIGN (CSS ONLY) =====
   Keep all existing sticky positioning and scroll hand-off behaviour.
   Remove outlines, border colors, rounded corners, shadows, and tinted
   backgrounds from the default, main dynamic, and nested sub-tab areas.
*/
.user-config-tabs,
.user-config-tabs.default-nav-released {
    border-bottom: 0 !important;
    background: #fff !important;
    box-shadow: none !important;
    border-radius: 0 !important;
}

/* Main dynamic sticky tab row and its buttons */
#user_tab_add_entry #newProjectSubTabs,
#user_tab_add_entry #newProjectSubTabs .dynamic-sub-tab-btn,
#user_tab_add_entry #newProjectSubTabs .dynamic-sub-tab-btn:hover,
#user_tab_add_entry #newProjectSubTabs .dynamic-sub-tab-btn.active {
    border: 0 !important;
    border-color: transparent !important;
    border-radius: 0 !important;
    outline-color: transparent !important;
    box-shadow: none !important;
    background: #fff !important;
}

/* Nested sticky sub-tab row and all of its buttons */
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs,
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs .nested-child-btn,
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs .nested-child-btn:hover,
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs .nested-child-btn.active {
    border: 0 !important;
    border-color: transparent !important;
    border-radius: 0 !important;
    outline-color: transparent !important;
    box-shadow: none !important;
    background: #fff !important;
}

/* Make all tab content and nested containers plain white, without outlines */
#user_tab_add_entry .dynamic-sub-tab-content,
#user_tab_add_entry .nested-project-tabs,
#user_tab_add_entry .nested-child-content {
    border: 0 !important;
    border-color: transparent !important;
    border-radius: 0 !important;
    outline: 0 !important;
    box-shadow: none !important;
    background: #fff !important;
}

/* Remove any remaining bottom divider from the default and dynamic tab rows */
.user-config-tabs,
#user_tab_add_entry #newProjectSubTabs,
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    border-top: 0 !important;
    border-right: 0 !important;
    border-bottom: 0 !important;
    border-left: 0 !important;
    background-color: #fff !important;
    -webkit-backdrop-filter: none !important;
    backdrop-filter: none !important;
}


/* ===== FINAL TAB ALIGNMENT + ACTIVE UNDERLINE =====
   Keep the existing sticky positioning and scroll hand-off behaviour.
   Remove row-level horizontal inset so the first dynamic/sub-tab button
   starts on the same left axis as the default New Project button.
*/
#user_tab_add_entry #newProjectSubTabs {
    padding-left: 0 !important;
    padding-right: 0 !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
    gap: 0 !important;
    border: 0 !important;
    background: #fff !important;
}

#user_tab_add_entry #newProjectSubTabs > .dynamic-sub-tab-btn {
    flex: 0 0 auto;
    margin-left: 0 !important;
    margin-right: 0 !important;
    padding-left: 16px !important;
    padding-right: 16px !important;
    border: 0 !important;
    border-bottom: 2px solid transparent !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    background: #fff !important;
    box-sizing: border-box;
}

#user_tab_add_entry #newProjectSubTabs > .dynamic-sub-tab-btn.active {
    border-bottom: 2px solid #00695c !important;
    color: #00695c !important;
    font-weight: 700 !important;
    background: #fff !important;
}

/* Nested sub-tab row: remove row and first-button left/right insets. */
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    padding-left: 0 !important;
    padding-right: 0 !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
    gap: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    border: 0 !important;
    border-radius: 0 !important;
    background: #fff !important;
    box-shadow: none !important;
}

#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs > .nested-child-btn {
    flex: 0 0 auto;
    margin-left: 0 !important;
    margin-right: 0 !important;
    padding-left: 16px !important;
    padding-right: 16px !important;
    border: 0 !important;
    border-bottom: 2px solid transparent !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    background: #fff !important;
    box-sizing: border-box;
}

#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs > .nested-child-btn.active {
    border-bottom: 2px solid #00695c !important;
    color: #00695c !important;
    font-weight: 700 !important;
    background: #fff !important;
}

/* Prevent inherited first-child offsets from shifting either tab row. */
#user_tab_add_entry #newProjectSubTabs > :first-child,
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs > :first-child {
    margin-left: 0 !important;
}


/* ===== FINAL FIX: NESTED SUB-TAB ROW MUST MATCH MAIN DYNAMIC ROW WIDTH =====
   The nested row and its first button must share the exact same left axis and
   full available width as #newProjectSubTabs. Remove horizontal padding,
   margins, negative offsets, and parent insets that could make the row appear
   narrower or start 10% inward. Sticky positioning and scroll hand-off remain
   unchanged.
*/
#user_tab_add_entry #newProjectSubTabs,
#user_tab_add_entry .dynamic-sub-tab-content,
#user_tab_add_entry .dynamic-sub-tab-content:has(.nested-project-tabs),
#user_tab_add_entry .dynamic-sub-tab-content .nested-project-tabs,
#user_tab_add_entry .nested-project-tabs,
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    box-sizing: border-box !important;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
}

#user_tab_add_entry .dynamic-sub-tab-content:has(.nested-project-tabs),
#user_tab_add_entry .dynamic-sub-tab-content .nested-project-tabs,
#user_tab_add_entry .nested-project-tabs {
    padding-left: 0 !important;
    padding-right: 0 !important;
}

#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    padding-left: 0 !important;
    padding-right: 0 !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
    align-self: stretch !important;
    justify-content: flex-start !important;
    box-sizing: border-box !important;
}

#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs > .nested-child-btn,
#user_tab_add_entry #newProjectSubTabs > .dynamic-sub-tab-btn {
    margin-left: 0 !important;
    flex-shrink: 0 !important;
}

@media (max-width: 760px) {
    #user_tab_add_entry .dynamic-sub-tab-content:has(.nested-project-tabs),
    #user_tab_add_entry .dynamic-sub-tab-content .nested-project-tabs,
    #user_tab_add_entry .nested-project-tabs,
    #user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
        width: 100% !important;
        max-width: 100% !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        padding-left: 0 !important;
        padding-right: 0 !important;
    }
}
/* ===== END FINAL FULL-WIDTH SUB-TAB ALIGNMENT FIX ===== */


/* ===== EXACT GLOBAL-HEADER-TO-STICKY-NAV CONTACT FIX =====
   .scroll-body begins immediately below .main-header. A sticky top of 0
   therefore pins each navigation row directly to the header's bottom edge.
   No header-height offset, bottom offset, or extra sticky gap is used.
*/
.user-config-tabs,
.user-config-tabs.default-nav-released,
#user_tab_add_entry #newProjectSubTabs,
#user_tab_add_entry.child-nav-priority #newProjectSubTabs,
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    top: 0 !important;
    scroll-margin-top: 0 !important;
}

/* ELIMINATE THE HEADER-TO-STICKY GAP AT ITS SOURCE.
   The scroll viewport starts directly under .main-header. Remove all top
   padding from that viewport on desktop and mobile so sticky rows can meet
   the header edge with no 15px/10px inset. */
.scroll-body {
    padding-top: 0 !important;
    scroll-padding-top: 0 !important;
}

/* Keep the header-gap fix, restore the sticky rows' full available width,
   and remove bottom padding from the sticky row containers themselves. */
.user-config-tabs,
.user-config-tabs.default-nav-released,
#user_tab_add_entry #newProjectSubTabs,
#user_tab_add_entry.child-nav-priority #newProjectSubTabs,
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    top: 0 !important;
    margin-top: 0 !important;
    scroll-margin-top: 0 !important;
    translate: 0 0 !important;
    padding-bottom: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
}

/* Preserve the original horizontal layout of the page; only the top inset
   is removed from the scroll viewport. */
.scroll-body {
    padding-top: 0 !important;
    scroll-padding-top: 0 !important;
}

/* The sentinel checks switch priority only when the sentinel reaches the
   exact scroll viewport top, with no early 1px allowance. */

/* ===== FINAL CORRECTION: NO STICKY-ROW PADDING + MATCH CONTENT-AREA WIDTH =====
   Keep the top-contact fix exactly as it is.
   The content-area has 20px of internal horizontal padding, so each navigation
   row expands by that exact amount on both sides to match the content-area's
   outer width. Row containers themselves have zero left/right/bottom padding.
   Button padding is deliberately preserved so button labels remain readable.
*/
.content-area > .user-config-tabs,
.content-area .user-config-tabs,
#user_tab_add_entry #newProjectSubTabs,
#user_tab_add_entry.child-nav-priority #newProjectSubTabs,
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    box-sizing: border-box !important;
    width: calc(100% + 40px) !important;
    max-width: none !important;
    min-width: 0 !important;
    margin-left: -20px !important;
    margin-right: -20px !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    padding-bottom: 0 !important;
    padding-block-end: 0 !important;
    scroll-margin-top: 0 !important;
    top: 0 !important;
    translate: 0 0 !important;
}

/* Explicitly remove any bottom padding from the nested row, including mobile
   overrides and earlier height/alignment rules. */
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    padding-block: 0 !important;
}

/* The main row has no bottom padding either; only the buttons retain their
   own horizontal padding. */
#user_tab_add_entry #newProjectSubTabs {
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    padding-block: 0 !important;
}

/* Preserve the exact header contact: no top inset in the scroll viewport. */
.scroll-body {
    padding-top: 0 !important;
    scroll-padding-top: 0 !important;
}
/* ===== END EXACT GLOBAL-HEADER-TO-STICKY-NAV CONTACT FIX ===== */


/* ===== EXACT STICKY NAV HEIGHT HIERARCHY =====
   default account tabs are 1px shorter than the main dynamic row;
   the nested row is 1px taller than the main dynamic row.
   JS measures the main row and applies the exact pixel heights.
*/
.user-config-tabs,
.user-config-tabs.default-nav-released,
#user_tab_add_entry #newProjectSubTabs,
#user_tab_add_entry.child-nav-priority #newProjectSubTabs,
#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    box-sizing: border-box !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    align-items: center !important;
}

.user-config-tabs,
.user-config-tabs.default-nav-released {
    min-height: 0 !important;
}

.user-config-tabs > .user-config-tab-btn {
    min-height: 0 !important;
    align-self: center !important;
}

#user_tab_add_entry #newProjectSubTabs {
    min-height: 0 !important;
}

#user_tab_add_entry #newProjectSubTabs > .dynamic-sub-tab-btn {
    min-height: 0 !important;
    align-self: center !important;
}

#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs {
    min-height: 0 !important;
}

#user_tab_add_entry .nested-project-tabs > .dynamic-sub-tabs > .nested-child-btn {
    min-height: 0 !important;
    align-self: center !important;
}
/* ===== END EXACT STICKY NAV HEIGHT HIERARCHY ===== */


/* ===== Requested navigation and account-list updates ===== */
.two-col.dashboard-layout { display:block !important; grid-template-columns:minmax(0,1fr) !important; gap:0 !important; margin-top:0 !important; }
.two-col.dashboard-layout > .content-area { width:100% !important; max-width:100% !important; min-width:0 !important; }
/* Keep tab rows horizontally scrollable when their buttons exceed the available width, but hide every scrollbar. */
.dashboard-tabs, .user-config-tabs, .dynamic-sub-tabs, #newProjectSubTabs,
#dynamicTabsEditor .dynamic-sub-tabs, .nested-project-tabs > .dynamic-sub-tabs,
.nested-project-tabs .dynamic-sub-tabs {
    display:flex !important; flex-wrap:nowrap !important; overflow-x:auto !important; overflow-y:hidden !important;
    max-width:100% !important; min-width:0 !important; white-space:nowrap !important;
    scrollbar-width:none !important; -ms-overflow-style:none !important; -webkit-overflow-scrolling:touch;
}
.dashboard-tabs::-webkit-scrollbar, .user-config-tabs::-webkit-scrollbar, .dynamic-sub-tabs::-webkit-scrollbar,
#newProjectSubTabs::-webkit-scrollbar, #dynamicTabsEditor .dynamic-sub-tabs::-webkit-scrollbar,
.nested-project-tabs > .dynamic-sub-tabs::-webkit-scrollbar, .nested-project-tabs .dynamic-sub-tabs::-webkit-scrollbar,
*::-webkit-scrollbar { display:none !important; width:0 !important; height:0 !important; }
* { scrollbar-width:none !important; }
.dashboard-tab-btn, .user-config-tab-btn, .dynamic-sub-tab-btn, .nested-child-btn { flex:0 0 auto !important; }
.accounts-list { display:flex; flex-direction:column; gap:8px; width:100%; }
.account-list-row { margin:0; width:100%; }
.account-list-select { display:flex; align-items:center; justify-content:space-between; gap:14px; width:100%; min-width:0; padding:14px 16px; text-align:left; background:#fff; border:1px solid #e5e7eb; border-radius:10px; cursor:pointer; }
.account-list-select:hover { background:#f8fafc; border-color:#00897b; }
.account-list-name { font-weight:650; color:#1f2937; overflow-wrap:anywhere; }
.account-list-email { color:#6b7280; font-size:13px; overflow-wrap:anywhere; text-align:right; }
.accounts-empty { padding:28px 12px; color:#6b7280; text-align:center; }
.copy-data-panel { width:100%; max-width:760px; margin:10px auto; padding:22px; border:1px solid #e2e8f0; border-radius:14px; background:#fff; box-shadow:0 4px 18px rgba(15,23,42,.05); }
.copy-data-panel h3 { margin:0 0 8px; color:#172033; font-size:20px; }
.copy-data-description { margin:0 0 22px; color:#64748b; font-size:13px; line-height:1.6; }
.copy-data-form { display:flex; flex-direction:column; gap:16px; }
.copy-data-field { display:flex; flex-direction:column; gap:7px; min-width:0; }
.copy-data-field label { color:#334155; font-size:13px; font-weight:700; }
.copy-data-field select { width:100%; min-height:44px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#172033; }
.copy-data-actions { display:flex; justify-content:flex-end; padding-top:4px; }
.copy-data-empty { padding:22px 12px; border:1px dashed #cbd5e1; border-radius:10px; color:#64748b; text-align:center; }
@media(max-width:560px) { .copy-data-panel { padding:15px; } .copy-data-actions .btn { width:100%; } }
.menu-drawer-overlay { position:fixed; inset:0; z-index:5000; background:rgba(15,23,42,.42); opacity:0; visibility:hidden; pointer-events:none; transition:opacity .22s ease, visibility .22s ease; }
.menu-drawer-overlay.active { opacity:1; visibility:visible; pointer-events:auto; }
.menu-drawer { position:absolute; top:0; left:0; bottom:0; width:min(340px,86vw); background:#fff; box-shadow:8px 0 30px rgba(0,0,0,.18); transform:translateX(-105%); transition:transform .25s ease; padding:20px 16px; display:flex; flex-direction:column; gap:10px; }
.menu-drawer-overlay.active .menu-drawer { transform:translateX(0); }
.menu-drawer-heading { display:flex; align-items:center; justify-content:space-between; font-size:20px; color:#0f172a; padding-bottom:12px; border-bottom:1px solid #e5e7eb; }
.menu-drawer-close { border:0; background:transparent; font-size:30px; line-height:1; cursor:pointer; color:#64748b; padding:0 5px; }
.menu-account-identity { padding:14px 12px; background:#f8fafc; border-radius:10px; overflow-wrap:anywhere; }
.menu-account-username { font-weight:700; color:#0f172a; margin-bottom:5px; }
.menu-account-email { color:#64748b; font-size:13px; }
.menu-drawer-item { width:100%; text-align:left; padding:13px 12px; border:0; border-radius:8px; background:#fff; color:#1f2937; font-size:15px; cursor:pointer; }
.menu-drawer-item:hover { background:#f1f5f9; color:#00695c; }
.delete-data-toggle { display:flex; align-items:center; justify-content:space-between; gap:12px; color:#b91c1c; font-weight:700; }
.delete-data-toggle:hover { background:#fef2f2; color:#991b1b; }
.delete-data-chevron { display:inline-block; font-size:12px; transition:transform .18s ease; }
.delete-data-toggle[aria-expanded="true"] .delete-data-chevron { transform:rotate(180deg); }
.delete-data-options { display:none; flex-direction:column; gap:5px; margin: -3px 0 5px 10px; padding:5px 0 5px 10px; border-left:2px solid #fecaca; }
.delete-data-options.open { display:flex; }
.menu-drawer-subitem { width:100%; padding:10px 11px; border:0; border-radius:7px; background:#fff; color:#475569; text-align:left; font-size:13px; line-height:1.4; cursor:pointer; }
.menu-drawer-subitem:hover { background:#fff1f2; color:#991b1b; }
.menu-drawer { overflow-y:auto; overscroll-behavior:contain; }
.change-password-form { padding:12px; margin:0 0 6px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; }
.change-password-form label { display:block; margin:8px 0 5px; color:#334155; font-size:13px; font-weight:600; }
.change-password-form input { display:block; width:100%; min-width:0; padding:10px; border:1px solid #cbd5e1; border-radius:7px; background:#fff; color:#0f172a; }
.menu-password-save { width:100%; margin-top:12px; padding:11px 12px; border:0; border-radius:8px; background:#00695c; color:#fff; font-weight:700; cursor:pointer; }
.menu-password-save:hover { background:#004d40; }
.password-form-message { margin-top:8px; color:#b91c1c; font-size:12px; }

@media (max-width:640px) { .account-list-select { align-items:flex-start; flex-direction:column; gap:5px; } .account-list-email { text-align:left; } }

/* Multiple selections field: searchable modal and selected-value storer */
.multiple-selections-control { width:100%; min-width:0; }
.multiple-selections-trigger { display:block; width:100%; min-height:44px; padding:11px 13px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#334155; text-align:left; cursor:pointer; }
.multiple-selections-trigger:focus { outline:none; border-color:#0f766e; box-shadow:0 0 0 3px rgba(15,118,110,.12); }
.multiple-selections-storer { display:flex; flex-wrap:wrap; align-items:center; gap:7px; min-height:42px; margin-top:8px; padding:9px; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; }
.multiple-selections-empty { color:#94a3b8; font-size:12px; }
.multiple-selections-chip { display:inline-flex; align-items:center; gap:7px; max-width:100%; padding:6px 9px; border:1px solid #99f6e4; border-radius:7px; background:#ecfdf5; color:#115e59; font-size:12px; overflow-wrap:anywhere; }
.multiple-selections-chip-text { min-width:0; white-space:pre-wrap; overflow-wrap:anywhere; }
.multiple-selections-chip-remove { border:0; background:transparent; color:#b91c1c; font-size:17px; line-height:1; cursor:pointer; padding:0 1px; }
.multiple-selections-modal { position:fixed; inset:0; z-index:2000000; display:flex; align-items:center; justify-content:center; width:100vw; height:100vh; height:100dvh; padding:16px; overflow:hidden; overscroll-behavior:contain; background:rgba(15,23,42,.58); }
.multiple-selections-modal[hidden] { display:none !important; }
.multiple-selections-dialog { display:flex; flex-direction:column; gap:12px; width:min(560px, calc(100vw - 28px)); max-height:calc(100vh - 32px); max-height:calc(100dvh - 32px); min-height:0; padding:18px; overflow:hidden; border:1px solid #dbe3ea; border-radius:14px; background:#fff; box-shadow:0 24px 70px rgba(15,23,42,.28); }
.multiple-selections-dialog-header { display:flex; align-items:center; justify-content:space-between; gap:12px; flex:0 0 auto; color:#0f172a; }
.multiple-selections-dialog-header h4 { margin:0; font-size:17px; }
.multiple-selections-dialog-search { width:100%; min-height:42px; flex:0 0 auto; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; color:#0f172a; background:#fff; }
.multiple-selections-options { display:flex; flex:1 1 auto; flex-direction:column; gap:7px; min-height:0; overflow-x:hidden; overflow-y:auto; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; padding:2px; }
.multiple-selections-option { display:flex; align-items:center; gap:11px; width:100%; min-height:42px; flex:0 0 auto; padding:10px 12px; border:1px solid #e2e8f0; border-radius:8px; background:#fff; color:#334155; text-align:left; cursor:pointer; overflow-wrap:anywhere; user-select:none; -webkit-user-select:none; }
.multiple-selections-option:hover { border-color:#0f766e; background:#f0fdfa; }
.multiple-selections-option.is-selected { border-color:#0f766e; background:#ecfdf5; color:#115e59; }
.multiple-selections-option-checkbox { width:18px; height:18px; flex:0 0 18px; margin:0; accent-color:#0f766e; cursor:pointer; }
.multiple-selections-option-label { min-width:0; flex:1 1 auto; overflow-wrap:anywhere; pointer-events:none; }
.multiple-selections-no-results { flex:0 0 auto; padding:12px; color:#64748b; font-size:13px; text-align:center; }
.multiple-selections-dialog-footer { display:flex; flex:0 0 auto; justify-content:flex-end; gap:8px; padding-top:8px; border-top:1px solid #e2e8f0; }

/* A visible custom modal sits above the page and locks the page's scroll viewport.
   Scrolling remains available only inside the modal's own content area. */
html.sceneiq-modal-open, body.sceneiq-modal-open { overflow:hidden !important; }
body.sceneiq-modal-open .custom-body { overflow:hidden !important; }
body.sceneiq-modal-open .scroll-body { overflow:hidden !important; }
.custom-dialog-overlay { position:fixed !important; inset:0 !important; width:100vw !important; height:100vh !important; height:100dvh !important; overflow:hidden !important; overscroll-behavior:contain; }
.custom-dialog-box { min-height:0; max-height:calc(100vh - 40px) !important; max-height:calc(100dvh - 40px) !important; overflow-x:hidden !important; overflow-y:auto !important; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; }
.custom-dialog-box .dialog-content-area,
.user-search-modal .dialog-body { min-height:0; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; }
.user-search-modal .custom-dialog-box { overflow:hidden !important; }
.user-search-modal .dialog-body { overflow-y:auto !important; }
@media (max-width:560px) { .multiple-selections-dialog { padding:14px; } .multiple-selections-dialog-header h4 { font-size:15px; } }
</style>

<style id="sceneiq-folder-navigation-style">
/* SceneIQ folder/file navigation */
.main-header.folder-mode{justify-content:center;position:relative;min-height:64px;padding:12px 72px}
.main-header.folder-mode .folder-header-left{position:absolute;left:16px;top:50%;transform:translateY(-50%)}
.main-header.folder-mode .folder-header-title{font-size:17px;font-weight:750;text-align:center;max-width:100%;overflow-wrap:anywhere}
.main-header.folder-mode .folder-header-right{position:absolute;right:16px;top:50%;transform:translateY(-50%)}
.folder-back-btn{width:40px;height:40px;border:1px solid rgba(255,255,255,.35);border-radius:12px;background:rgba(255,255,255,.15);color:#fff;font-size:24px;line-height:1;cursor:pointer;display:grid;place-items:center}
.folder-back-btn:hover{background:rgba(255,255,255,.27)}
.folder-nav-root{width:100%;max-width:1100px;margin:0 auto;padding:clamp(14px,3vw,30px) 0 35px}
.folder-nav-heading{margin:0 0 7px;color:#102a43;font-size:clamp(21px,3vw,28px);font-weight:800;letter-spacing:-.035em}
.folder-nav-description{color:#718096;font-size:13px;margin:0 0 22px;line-height:1.6}
.folder-card-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.folder-card{appearance:none;position:relative;min-width:0;min-height:142px;text-align:left;padding:20px 18px;border:1px solid #e2e8f0;border-radius:18px;background:linear-gradient(145deg,#fff 0%,#f8fbfc 100%);box-shadow:0 5px 18px rgba(15,35,55,.055);cursor:pointer;display:flex;flex-direction:column;align-items:flex-start;justify-content:space-between;gap:17px;color:#142b3c;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;overflow:hidden}
.folder-card:before{content:"";position:absolute;inset:0 auto 0 0;width:4px;background:var(--folder-accent,#0f8b80);opacity:.85}
.folder-card:hover{transform:translateY(-3px);border-color:#9bd7ce;box-shadow:0 12px 28px rgba(15,75,80,.11)}
.folder-card:focus-visible{outline:3px solid #79d3c6;outline-offset:3px}
.folder-card-top{display:flex;width:100%;align-items:flex-start;justify-content:space-between;gap:12px}
.folder-card-icon{width:43px;height:43px;flex:0 0 43px;border-radius:13px;background:var(--folder-tint,#e6f7f4);display:grid;place-items:center;font-size:22px}
.folder-card-arrow{color:#91a2b2;font-size:20px}
.folder-card-title{font-size:15px;font-weight:780;line-height:1.35;overflow-wrap:anywhere}
.folder-card-subtitle{margin-top:5px;font-size:12px;line-height:1.45;color:#81909e;overflow-wrap:anywhere}
.folder-card.wide{grid-column:1/-1;min-height:112px}
.folder-card.wide .folder-card-top{align-items:center}
.folder-card.wide .folder-card-copy{flex:1;min-width:0}
.folder-card-count{font-size:11px;font-weight:750;color:#087f75;background:#e6f7f4;border-radius:99px;padding:5px 9px;white-space:nowrap}
.folder-page-content{min-width:0;width:100%;animation:folderPageIn .18s ease both}
.folder-page-content .user-config-content{display:block!important;padding-top:0}
.folder-page-content .user-config-content.folder-active-content{display:block!important}
.folder-page-content .dynamic-sub-tabs,.folder-page-content .nested-project-tabs>.dynamic-sub-tabs{display:none!important}
.folder-page-content .dynamic-sub-tab-content{display:none!important}
.folder-page-content .dynamic-sub-tab-content.folder-active-content{display:block!important}
.folder-page-content .nested-child-content{display:none!important}
.folder-page-content .nested-child-content.folder-active-content{display:block!important}
.folder-breadcrumb{font-size:12px;color:#8a9aa9;margin-bottom:15px;overflow-wrap:anywhere}
.folder-breadcrumb span{color:#087f75;font-weight:700}
@keyframes folderPageIn{from{opacity:.4;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:560px){.folder-card-grid{gap:10px}.folder-card{min-height:128px;padding:15px 13px;border-radius:15px}.folder-card-icon{width:37px;height:37px;flex-basis:37px;font-size:19px}.folder-card-title{font-size:13px}.folder-card-subtitle{font-size:11px}.main-header.folder-mode{padding-left:62px;padding-right:62px}.main-header.folder-mode .folder-header-left{left:10px}.main-header.folder-mode .folder-header-right{right:10px}.folder-back-btn{width:38px;height:38px}}
@media(prefers-reduced-motion:reduce){.folder-card,.folder-page-content{animation:none!important;transition:none!important}}

/* Selected-account header: menu icon at the far left and identity centered. */
.main-header.account-selected-header{
    position:relative;
    justify-content:center!important;
    min-height:64px;
    padding:10px 72px;
}
.main-header.account-selected-header .account-header-menu{
    position:absolute;
    left:16px;
    top:50%;
    transform:translateY(-50%);
    display:flex;
    align-items:center;
}
.account-menu-button{
    width:42px;
    height:42px;
    display:grid;
    place-items:center;
    border:1px solid rgba(255,255,255,.35);
    border-radius:12px;
    background:rgba(255,255,255,.15);
    color:#fff;
    font-size:23px;
    line-height:1;
    cursor:pointer;
}
.account-menu-button:hover{background:rgba(255,255,255,.27)}
.account-header-identity,
.account-folder-identity{
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:2px;
    width:100%;
    min-width:0;
    max-width:min(60vw,720px);
    margin:0 auto;
    text-align:center;
}
.account-header-username{color:#fff;font-size:17px;font-weight:750;line-height:1.25;overflow-wrap:anywhere}
.account-header-email{color:rgba(255,255,255,.88);font-size:12px;font-weight:500;line-height:1.3;overflow-wrap:anywhere}
.main-header.folder-mode.account-selected-header .folder-header-title.account-folder-identity{
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:2px;
    max-width:min(60vw,720px);
    width:100%;
    text-align:center;
    font-size:inherit;
    font-weight:inherit;
}
.main-header.folder-mode.account-selected-header .folder-header-left{left:16px}
.main-header.folder-mode.account-selected-header .folder-header-right{right:16px}
.main-header.folder-mode.account-selected-header .account-header-username{font-size:17px;font-weight:750}
.main-header.folder-mode.account-selected-header .account-header-email{font-size:12px;font-weight:500}
.main-header.folder-mode.account-selected-header .folder-menu-button{display:grid}
@media(max-width:560px){
    .main-header.account-selected-header{min-height:62px;padding:9px 58px}
    .account-header-identity,.main-header.folder-mode.account-selected-header .folder-header-title.account-folder-identity{max-width:calc(100% - 150px);width:calc(100% - 150px)}
    .main-header.account-selected-header .account-header-menu{left:10px}
    .account-menu-button{width:38px;height:38px;font-size:21px}
    .account-header-username{font-size:15px}
    .account-header-email{font-size:11px}
    .main-header.folder-mode.account-selected-header .folder-header-left{left:10px}
    .main-header.folder-mode.account-selected-header .folder-header-right{right:10px}
    .main-header.folder-mode.account-selected-header .account-header-username{font-size:15px}
    .main-header.folder-mode.account-selected-header .account-header-email{font-size:11px}
}

/* Right-edge account switcher dropdown */
.main-header.account-selected-header { overflow:visible; }
.account-switcher-host { position:absolute; right:16px; top:50%; transform:translateY(-50%); z-index:12000; }
.account-switcher { position:relative; }
.account-switcher-toggle {
    display:flex; align-items:center; gap:8px; max-width:230px; min-height:40px;
    padding:8px 12px; border:1px solid rgba(255,255,255,.38); border-radius:11px;
    background:rgba(255,255,255,.16); color:#fff; cursor:pointer; font:600 13px/1.2 'Segoe UI',Arial,sans-serif;
}
.account-switcher-toggle:hover,.account-switcher-toggle[aria-expanded="true"] { background:rgba(255,255,255,.28); }
.account-switcher-toggle-label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.account-switcher-chevron { opacity:.9; font-size:11px; flex:0 0 auto; }
.account-switcher-dropdown {
    position:absolute; top:calc(100% + 9px); right:0; width:min(330px,calc(100vw - 20px));
    max-height:min(68vh,520px); overflow:hidden; display:flex; flex-direction:column;
    border:1px solid #dce5ec; border-radius:14px; background:#fff; color:#243244;
    box-shadow:0 18px 50px rgba(15,23,42,.25); z-index:12001;
}
.account-switcher-dropdown[hidden] { display:none !important; }
.account-switcher-search { width:calc(100% - 20px); margin:10px; padding:10px 12px; border:1px solid #dce5ec; border-radius:9px; outline:none; }
.account-switcher-list { overflow-y:auto; padding:0 8px 8px; }
.account-switcher-user-form { margin:0; }
.account-switcher-user { display:flex; width:100%; flex-direction:column; gap:3px; padding:10px 11px; border:0; border-radius:9px; background:#fff; text-align:left; cursor:pointer; color:#253449; }
.account-switcher-user:hover { background:#f1f7f8; }
.account-switcher-user.is-current { background:#e8f5f2; color:#075e57; }
.account-switcher-user-name { font-weight:700; font-size:13px; overflow-wrap:anywhere; }
.account-switcher-user-email { color:#718096; font-size:11px; overflow-wrap:anywhere; }
.account-switcher-empty { padding:16px 12px; color:#64748b; font-size:12px; text-align:center; }
.main-header.folder-mode.account-selected-header .account-switcher-host { right:16px; }
@media(max-width:560px) {
  .account-switcher-host { right:9px; }
  .account-switcher-toggle { max-width:112px; min-height:37px; padding:7px 8px; font-size:11px; gap:5px; }
  .account-switcher-dropdown { right:0; width:min(300px,calc(100vw - 16px)); }
  .main-header.folder-mode.account-selected-header .account-switcher-host { right:9px; }
}

/* FINAL FOLDER-ONLY NAVIGATION OVERRIDES: no tab strips or tab-shaped navigation */
body:has(#sceneiqFolderRoot) .dashboard-tabs,
body:has(#sceneiqFolderRoot) .user-config-tabs,
body:has(#sceneiqFolderRoot) #newProjectSubTabs,
body:has(#sceneiqFolderRoot) .folder-page-content .dynamic-sub-tabs,
body:has(#sceneiqFolderRoot) .folder-page-content .nested-project-tabs > .dynamic-sub-tabs {
    display:none !important;
    visibility:hidden !important;
    height:0 !important;
    min-height:0 !important;
    max-height:0 !important;
    margin:0 !important;
    padding:0 !important;
    border:0 !important;
    overflow:hidden !important;
}
body:has(#sceneiqFolderRoot) .folder-page-content .user-config-content {
    display:block !important;
    width:100%;
    padding:0 !important;
}
body:has(#sceneiqFolderRoot) .folder-page-content .dynamic-sub-tab-content {
    display:none !important;
    width:100%;
    padding:0 !important;
    border:0 !important;
    background:transparent !important;
}
body:has(#sceneiqFolderRoot) .folder-page-content .dynamic-sub-tab-content.folder-active-content {
    display:block !important;
}
body:has(#sceneiqFolderRoot) .folder-page-content .nested-child-content {
    display:none !important;
}
body:has(#sceneiqFolderRoot) .folder-page-content .nested-child-content.folder-active-content {
    display:block !important;
}
body:has(#sceneiqFolderRoot) .folder-page-content .folder-page-content {
    width:100%;
    max-width:100%;
    padding:0;
    margin:0;
}
body:has(#sceneiqFolderRoot) .folder-nav-root {
    padding-top:20px;
}
@media(max-width:560px) {
    body:has(#sceneiqFolderRoot) .folder-nav-root { padding-top:14px; }
}
</style>
</head>
<script>
window.sceneIQAccountSwitcherUsers = <?php
    $switcherUsers = array_map(function ($account) {
        return [
            'id' => (int)($account['id'] ?? 0),
            'username' => (string)($account['username'] ?? ''),
            'email' => (string)($account['email'] ?? '')
        ];
    }, $users);
    echo json_encode($switcherUsers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>;
window.sceneIQSelectedAccountId = <?php echo (int)$selectedUserId; ?>;
</script>
<body class="<?php echo $selectedUserId > 0 ? 'sceneiq-folder-pending' : ''; ?>">
    <div id="sceneiq-loading-indicator" role="status" aria-live="polite" aria-atomic="true">
        <div class="sceneiq-loader-spinner" aria-hidden="true"></div>
    </div>
    <div class="custom-body">
        <?php if ($selectedUserId > 0 && $selectedUser): ?>
            <div class="main-header account-selected-header"
                 data-account-username="<?php echo htmlspecialchars($selectedUser['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                 data-account-email="<?php echo htmlspecialchars($selectedUser['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                 data-account-id="<?php echo (int)($selectedUser['id'] ?? 0); ?>">
                <div class="account-header-menu">
                    <button type="button" class="account-menu-button" id="menuButton" onclick="openMenuDrawer()"
                            aria-label="Open menu" title="Menu" aria-haspopup="dialog" aria-controls="menuDrawer">
                        <span aria-hidden="true">☰</span>
                    </button>
                </div>
                <div class="account-header-identity" aria-label="Selected account">
                    <div class="account-header-username"><?php echo htmlspecialchars($selectedUser['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="account-header-email"><?php echo htmlspecialchars($selectedUser['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="account-switcher-host" aria-live="polite"></div>
            </div>
        <?php else: ?>
            <div class="main-header" style="display:flex;">
                <div><h1>SceneIQ <span>Server</span></h1></div>
                <div>
                    <button type="button" class="debug-btn" id="taskButton" onclick="window.location.href='index.php'">Task</button>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="scroll-body">
            <div class="server-container">
                
                <?php if ($flashSuccess): ?>
                    <div class="notification success"> <?php echo htmlspecialchars($flashSuccess); ?></div>
                <?php endif; ?>
                <?php if ($flashError): ?>
                    <div class="notification error"> <?php echo htmlspecialchars($flashError); ?></div>
                <?php endif; ?>
                
                <div class="two-col dashboard-layout">
                    <div class="content-area">
                        <?php if ($selectedUserId > 0 && $selectedUser): ?>
                            <div class="user-config-tabs">
                                <button class="user-config-tab-btn  active" onclick="switchUserConfigTab('add_entry', this)"> New Project</button>
                                <button class="user-config-tab-btn" onclick="switchUserConfigTab('pending', this)"> Pending</button>
                                <button class="user-config-tab-btn" onclick="switchUserConfigTab('completed', this)"> Completed</button>
                                <button class="user-config-tab-btn" onclick="switchUserConfigTab('aborted', this)"> Aborted</button>
                                <button class="user-config-tab-btn" onclick="switchUserConfigTab('dynamic_tabs', this)"> Dynamic Tabs</button>
                                <button class="user-config-tab-btn" onclick="switchUserConfigTab('submit_tabs', this)"> Submit Tabs</button>
                                <button class="user-config-tab-btn" onclick="switchUserConfigTab('dynamic_fields', this)"> Dynamic Content</button>
                            </div>
                            
                            <div id="user_tab_pending" class="user-config-content active">
                                <div class="status-copy-toolbar" style="display:flex;justify-content:flex-end;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyAllStatusEntries('pending', this)">📋 Copy All Pending Data</button>
                                </div>
                                <div id="pendingEntriesList"></div>
                            </div>

                            <div id="user_tab_completed" class="user-config-content">
                                <div class="status-copy-toolbar" style="display:flex;justify-content:flex-end;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyAllStatusEntries('completed', this)">📋 Copy All Completed Data</button>
                                </div>
                                <div id="completedEntriesList"></div>
                            </div>

                            <div id="user_tab_aborted" class="user-config-content">
                                <div class="status-copy-toolbar" style="display:flex;justify-content:flex-end;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyAllStatusEntries('aborted', this)">📋 Copy All Aborted Data</button>
                                </div>
                                <div id="abortedEntriesList"></div>
                            </div>

                            <!-- Dynamic Fields Tab -->
                            <div id="user_tab_dynamic_fields" class="user-config-content">
                                <h3 style="margin-bottom:15px; color:#1a1a2e;">Dynamic Content</h3>
                                <p style="color:#6b7280; margin-bottom:15px;">These fields belong only to the selected account.</p>
                                
                                <div id="userGlobalDynamicFieldsList"></div>
                                
                                <div class="account-dynamic-fields-add-bar">
                                    <button type="button" class="btn btn-primary" onclick="addGlobalDynamicField()">+ Add Field</button>
                                </div>
                            </div>
                            
                            
                            <div id="user_tab_dynamic_tabs" class="user-config-content">
                                <div id="dynamicTabsEditor"></div>
                            </div>

                            <div id="user_tab_submit_tabs" class="user-config-content">
                                <h3 style="margin-bottom:15px; color:#1a1a2e;">Submit Tabs Configuration</h3>
                                <p style="color:#6b7280; margin-bottom:15px;">Toggle which tab's fields should be submitted to entries. Tabs turned OFF will have their fields excluded when saving to Pending, Completed, or Aborted.</p>
                                <div id="submitTabsEditor"></div>
                            </div>
                            

                            <div id="user_tab_add_entry" class="user-config-content active">
                                <?php $globalDynamicFields = $userDynamicFields; ?>
                                <?php if (empty($globalDynamicFields)): ?>
                                    <p style="color:#6b7280;">No dynamic fields configured for this account. Add fields in the Account Dynamic Fields tab first.</p>
                                <?php else: ?>
                                    <!-- Dynamic Sub Tabs for New Project -->
                                    <div class="dynamic-sub-tabs" id="newProjectSubTabs">
                                        <?php 
                                        $firstTabIndex = -1;
                                    foreach ($userDynamicTabs as $candidateIndex => $candidateTab) {
                                        if (!is_array($candidateTab)) continue;
                                        if (!array_key_exists('display_tab', $candidateTab) || !empty($candidateTab['display_tab'])) {
                                            $firstTabIndex = $candidateIndex;
                                            break;
                                        }
                                    }
                                        foreach ($userDynamicTabs as $tabIndex => $tab): 
                                            if (!is_array($tab)) continue;
                                            if (array_key_exists('display_tab', $tab) && empty($tab['display_tab'])) continue;
                                            $isActive = ($tabIndex === $firstTabIndex) ? 'active' : '';
                                        ?>
                                            <button type="button" class="dynamic-sub-tab-btn <?php echo $isActive; ?>" data-tab="tab_<?php echo $tabIndex; ?>" data-submit-enabled="<?php echo (!array_key_exists('submit_tab', $tab) || !empty($tab['submit_tab'])) ? '1' : '0'; ?>" onclick="switchNewProjectSubTab('tab_<?php echo $tabIndex; ?>', this)"> <?php echo htmlspecialchars($tab['tab_name'] ?? 'Tab ' . ($tabIndex + 1)); ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <!-- All Fields Content -->
                                    <div id="newProjectSubTab_all_fields" class="dynamic-sub-tab-content">
                                        <?php renderNewProjectForm($globalDynamicFields, $selectedUserId); ?>
                                    </div>
                                    
                                    <!-- Dynamic Tab Contents -->
                                    <?php 
                                    $firstTabIndex = -1;
                                    foreach ($userDynamicTabs as $candidateIndex => $candidateTab) {
                                        if (!is_array($candidateTab)) continue;
                                        if (!array_key_exists('display_tab', $candidateTab) || !empty($candidateTab['display_tab'])) {
                                            $firstTabIndex = $candidateIndex;
                                            break;
                                        }
                                    }
                                    foreach ($userDynamicTabs as $tabIndex => $tab): 
                                        if (!is_array($tab)) continue;
                                        if (array_key_exists('display_tab', $tab) && empty($tab['display_tab'])) continue;
                                        $isActive = ($tabIndex === $firstTabIndex) ? 'active' : '';
                                    ?>
                                        <div id="newProjectSubTab_tab_<?php echo $tabIndex; ?>" class="dynamic-sub-tab-content <?php echo $isActive; ?>">
                                            <?php
                                                $children = (isset($tab['sub_tabs_mode']) && $tab['sub_tabs_mode'] === 'add' && !empty($tab['sub_tabs']) && is_array($tab['sub_tabs'])) ? array_values(array_filter($tab['sub_tabs'], function($child) { return is_array($child) && (!array_key_exists('display_tab', $child) || !empty($child['display_tab'])); })) : [];
                                                if (!empty($children)):
                                            ?>
                                                <?php if (!empty($tab['header']) || !empty($tab['description'])): ?>
                                                    <div style="margin-bottom:12px;padding:10px;background:#f0fdf4;border-radius:8px;border-left:4px solid #00695c;">
                                                        <?php if (!empty($tab['header'])): ?><h4 style="color:#00695c;"><?php echo htmlspecialchars($tab['header']); ?></h4><?php endif; ?>
                                                        <?php if (!empty($tab['description'])): ?><p style="color:#6b7280;font-size:14px;"><?php echo htmlspecialchars($tab['description']); ?></p><?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="nested-project-tabs" data-parent-tab="<?php echo $tabIndex; ?>">
                                                    <div class="dynamic-sub-tabs" style="margin-bottom:12px;">
                                                        <?php foreach ($children as $childIndex => $child): ?>
                                                            <button type="button" class="dynamic-sub-tab-btn nested-child-btn <?php echo $childIndex === 0 ? 'active' : ''; ?>" onclick="switchNewProjectNestedSubTab(this)"><?php echo htmlspecialchars($child['tab_name'] ?? ('Sub Tab ' . ($childIndex + 1))); ?></button>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <?php foreach ($children as $childIndex => $child):
                                                        $childFields = [];
                                                        foreach (($child['dynamic_fields_display'] ?? []) as $childFieldKey) {
                                                            if (isset($globalDynamicFields[$childFieldKey])) $childFields[$childFieldKey] = $globalDynamicFields[$childFieldKey];
                                                        }
                                                    ?>
                                                        <div class="nested-child-content <?php echo $childIndex === 0 ? 'active' : ''; ?>" style="display:<?php echo $childIndex === 0 ? 'block' : 'none'; ?>;">
                                                            <?php if (!empty($child['header']) || !empty($child['description'])): ?>
                                                                <div style="margin-bottom:12px;padding:10px;background:#f0fdf4;border-radius:8px;border-left:4px solid #00695c;">
                                                                    <?php if (!empty($child['header'])): ?><h4 style="color:#00695c;"><?php echo htmlspecialchars($child['header']); ?></h4><?php endif; ?>
                                                                    <?php if (!empty($child['description'])): ?><p style="color:#6b7280;font-size:14px;"><?php echo htmlspecialchars($child['description']); ?></p><?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($childFields)) renderNewProjectForm($childFields, $selectedUserId); else echo '<p style="color:#6b7280;">No fields selected for this sub-tab.</p>'; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else:
                                                $displayFields = $tab['dynamic_fields_display'] ?? [];
                                                $filteredFields = [];
                                                foreach ($displayFields as $fieldKey) if (isset($globalDynamicFields[$fieldKey])) $filteredFields[$fieldKey] = $globalDynamicFields[$fieldKey];
                                            ?>
                                                <?php if (!empty($tab['header']) || !empty($tab['description'])): ?>
                                                    <div style="margin-bottom:15px;padding:10px;background:#f0fdf4;border-radius:8px;border-left:4px solid #00695c;">
                                                        <?php if (!empty($tab['header'])): ?><h4 style="color:#00695c;"><?php echo htmlspecialchars($tab['header']); ?></h4><?php endif; ?>
                                                        <?php if (!empty($tab['description'])): ?><p style="color:#6b7280;font-size:14px;"><?php echo htmlspecialchars($tab['description']); ?></p><?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($filteredFields)) renderNewProjectForm($filteredFields, $selectedUserId); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div id="user_tab_pending" class="user-config-content">
                                <div id="pendingEntriesList"></div>
                            </div>

                            <div id="user_tab_completed" class="user-config-content">
                                <div id="completedEntriesList"></div>
                            </div>

                            <div id="user_tab_aborted" class="user-config-content">
                                <div id="abortedEntriesList"></div>
                            </div>
                            
                        <?php else: ?>
                            <div class="dashboard-tabs" role="tablist" aria-label="Account dashboard">
                                <button type="button" class="dashboard-tab-btn <?php echo ($dashboardView === 'accounts') ? 'active' : ''; ?>" onclick="switchDashboardTab('accounts', this)">All Accounts</button>
                                <button type="button" class="dashboard-tab-btn <?php echo ($dashboardView === 'initialize') ? 'active' : ''; ?>" onclick="switchDashboardTab('initialize', this)">Initialize Account</button>
                                <button type="button" class="dashboard-tab-btn <?php echo ($dashboardView === 'copy_data') ? 'active' : ''; ?>" onclick="switchDashboardTab('copy_data', this)">Copy Data</button>
                            </div>
                            
                            <div id="dash_tab_accounts" class="dashboard-content <?php echo ($dashboardView === 'accounts') ? 'active' : ''; ?>">
                                <div class="accounts-list" id="allAccountsList">
                                    <?php if (empty($users)): ?>
                                        <p class="accounts-empty">No accounts found. Create an account first.</p>
                                    <?php else: ?>
                                        <?php foreach ($users as $user): ?>
                                            <form method="POST" class="account-list-row">
                                                <input type="hidden" name="select_user" value="1">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                                <button type="submit" class="account-list-select">
                                                    <span class="account-list-name"><?php echo htmlspecialchars($user['username']); ?></span>
                                                    <span class="account-list-email"><?php echo htmlspecialchars($user['email']); ?></span>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div id="dash_tab_initialize" class="dashboard-content <?php echo ($dashboardView === 'initialize') ? 'active' : ''; ?>">
                                <div class="create-user-form">
                                    <h3> Create New Account</h3>
                                    <form method="POST" id="createUserForm">
                                        <div class="form-row">
                                            <input type="email" name="email" placeholder="Email" required>
                                            <input type="text" name="username" placeholder="Username" required>
                                            <input type="password" name="password" placeholder="Password (min 6 chars)" required minlength="6">
                                            <button type="submit" name="create_user" class="btn-create">Create Account</button>
                                            <button class="btn-create" onclick="window.location.href='users.php'">Login as a Account</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            
                            <div id="dash_tab_copy_data" class="dashboard-content <?php echo ($dashboardView === 'copy_data') ? 'active' : ''; ?>">
                                <section class="copy-data-panel">
                                    <h3>Copy Data Between Accounts</h3>
                                    <p class="copy-data-description">
                                        Choose a source, select Dynamic tabs, Dynamic field, or Both, then choose one or more destination accounts.
                                        Only the checked items are copied. Destination data with unrelated keys is preserved; matching field or tab keys are replaced.
                                        Project entries (Pending, Completed, and Aborted) are never changed.
                                    </p>

                                    <?php if (count($users) < 2): ?>
                                        <p class="copy-data-empty">At least two accounts are required to copy data between accounts.</p>
                                    <?php else: ?>
                                        <form method="POST" id="copyAccountDataForm" class="copy-data-form">
                                            <input type="hidden" name="copy_account_data" value="1">
                                            <input type="hidden" name="copy_data_scope" id="copyDataScope" value="">
                                            <input type="hidden" name="sceneiq_copy_csrf_token" value="<?php echo htmlspecialchars($_SESSION['sceneiq_copy_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                                            <div class="copy-data-field">
                                                <label for="copyDataFromAccount">Copy data from Account</label>
                                                <select name="copy_from_account" id="copyDataFromAccount" required>
                                                    <option value="">Select source account</option>
                                                    <?php foreach ($users as $user): ?>
                                                        <option value="<?php echo (int)$user['id']; ?>"><?php echo htmlspecialchars($user['username'] . ' — ' . $user['email'], ENT_QUOTES, 'UTF-8'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="copy-data-field">
                                                <label>What should be copied?</label>
                                                <div class="copy-mode-grid" id="copyModeGrid">
                                                    <button type="button" class="copy-mode-button" data-copy-scope="dynamic_tabs">Dynamic tabs</button>
                                                    <button type="button" class="copy-mode-button" data-copy-scope="dynamic_fields">Dynamic field</button>
                                                    <button type="button" class="copy-mode-button" data-copy-scope="dynamic_tabs_and_fields">Both</button>
                                                </div>
                                                <small class="copy-data-help">Nothing is selected by default. Choose an option to configure exactly which items are copied.</small>
                                            </div>

                                            <div class="copy-data-field">
                                                <label>Copy to these accounts</label>
                                                <button type="button" class="copy-destination-trigger" id="copyDestinationTrigger" disabled>
                                                    <span id="copyDestinationLabel">Choose destination accounts</span>
                                                    <span class="copy-destination-count" id="copyDestinationCount">0 selected</span>
                                                </button>
                                                <small class="copy-data-help">You can select multiple destination accounts. The source account is excluded automatically.</small>
                                            </div>

                                            <div class="copy-selection-summary" id="copySelectionSummary">No data category selected.</div>
                                            <div class="copy-data-actions">
                                                <button type="submit" class="btn btn-primary" id="copyDataSubmit" disabled>Copy Data</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </section>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ===== LEFT MENU DRAWER ===== -->
    <div class="menu-drawer-overlay" id="menuDrawerOverlay" onclick="closeMenuDrawer(event)" aria-hidden="true">
        <aside class="menu-drawer" id="menuDrawer" role="dialog" aria-modal="true" aria-label="Main menu">
            <div class="menu-drawer-heading">
                <strong>Menu</strong>
                <button type="button" class="menu-drawer-close" onclick="closeMenuDrawer()" aria-label="Close menu">×</button>
            </div>
            <div class="menu-account-identity">
                <div class="menu-account-username">Username: <?php echo htmlspecialchars($selectedUser['username'] ?? '—'); ?></div>
                <div class="menu-account-email">Email: <?php echo htmlspecialchars($selectedUser['email'] ?? '—'); ?></div>
            </div>
            <?php if ($selectedUser): ?>
            <button type="button" class="menu-drawer-item" id="changePasswordToggle" onclick="toggleChangePasswordForm()" aria-expanded="false">Change Password</button>
            <form method="POST" id="changePasswordForm" class="change-password-form" style="display:none;">
                <input type="hidden" name="change_account_password" value="1">
                <input type="hidden" name="password_user_id" value="<?php echo (int)$selectedUser['id']; ?>">
                <input type="hidden" name="password_csrf_token" value="<?php echo htmlspecialchars($_SESSION['password_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <label for="newPassword">New password</label>
                <input type="password" id="newPassword" name="new_password" minlength="8" autocomplete="new-password" required>
                <label for="confirmPassword">Confirm password</label>
                <input type="password" id="confirmPassword" name="confirm_password" minlength="8" autocomplete="new-password" required>
                <button type="submit" class="menu-password-save">Save Password</button>
                <p id="passwordFormMessage" class="password-form-message" aria-live="polite"></p>
            </form>
            <button type="button" class="menu-drawer-item delete-data-toggle" id="deleteDataToggle" onclick="toggleDeleteDataOptions()" aria-expanded="false" aria-controls="deleteDataOptions">
                <span>Delete Data</span><span class="delete-data-chevron" aria-hidden="true">▾</span>
            </button>
            <div class="delete-data-options" id="deleteDataOptions">
                <button type="button" class="menu-drawer-subitem" onclick="requestDeleteData('dynamic_content')">Delete Dynamic content</button>
                <button type="button" class="menu-drawer-subitem" onclick="requestDeleteData('dynamic_tabs')">Delete Dynamic tabs</button>
                <button type="button" class="menu-drawer-subitem" onclick="requestDeleteData('dynamic_both')">Delete Dynamic contents and Dynamic tabs</button>
            </div>
            <form method="POST" id="deleteDataForm" style="display:none;">
                <input type="hidden" name="delete_account_dynamic_data" value="1">
                <input type="hidden" name="user_id" value="<?php echo (int)$selectedUser['id']; ?>">
                <input type="hidden" name="delete_scope" id="deleteDataScope" value="">
                <input type="hidden" name="data_delete_csrf_token" value="<?php echo htmlspecialchars($_SESSION['data_delete_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            </form>
            <?php endif; ?>
            <button type="button" class="menu-drawer-item" onclick="goToDashboard()">Dashboard</button>
            <button type="button" class="menu-drawer-item" onclick="window.location.href='index.php'">Task</button>
        </aside>
    </div>

    <!-- ===== CUSTOM DIALOG ===== -->
    <div class="custom-dialog-overlay" id="customDialog">
        <div class="custom-dialog-box">
            <div class="dialog-title" id="dialogTitle">Notification</div>
            <div class="dialog-message" id="dialogMessage">Message here</div>
            <div id="dialogContent" style="margin-bottom:15px;"></div>
            <div class="dialog-buttons" id="dialogButtons">
                <button class="btn btn-primary" onclick="closeDialog()">OK</button>
            </div>
        </div>
    </div>
    
    <!-- ===== USER SEARCH DIALOG ===== -->
    <div class="custom-dialog-overlay user-search-modal" id="userSearchDialog">
        <div class="custom-dialog-box">
            <div class="dialog-header">
                <h2> Select Account</h2>
                <input type="text" class="search-input" id="userSearchInput" placeholder="Type to Search Account..." onkeyup="filterUserSearch()">
            </div>
            <div class="dialog-body" id="userSearchResults">
                <?php foreach ($users as $user): ?>
                    <div class="user-search-item" data-user-id="<?php echo $user['id']; ?>" data-user-name="<?php echo strtolower(htmlspecialchars($user['username'])); ?>" data-user-email="<?php echo strtolower(htmlspecialchars($user['email'])); ?>" onclick="selectUserFromSearch(<?php echo $user['id']; ?>)">
                        <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                        <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="dialog-footer">
                <button class="btn btn-secondary" onclick="closeUserSearchDialog()">Close</button>
            </div>
        </div>
    </div>

<?php
    function renderNewProjectForm($dynamicFields, $selectedUserId) {
        $dynamicFields = normalizeDynamicFieldDefinitions($dynamicFields);
        if (empty($dynamicFields)) {
            echo '<p style="color:#6b7280;">No fields available for this view.</p>';
            return;
        }
        ?>
        <form method="POST" class="addEntryForm">
            <input type="hidden" name="add_entry" value="true">
            <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
            <input type="hidden" name="entry_data" class="entryDataInput">
            <input type="hidden" class="entryUploadCsrf" value="<?php echo htmlspecialchars($_SESSION['entry_upload_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            
            <?php foreach ($dynamicFields as $key => $field): ?>
                <?php if (!is_array($field)) continue; ?>
                <?php if (($field['field_type'] ?? 'individual') === 'objects'): ?>
                    <div class="form-group" style="border:2px solid #00695c; border-radius:10px; padding:15px; margin-bottom:15px;">
                        <h4 style="color:#00695c; margin-bottom:10px;"> <?php echo htmlspecialchars($field['field-title'] ?? $key); ?></h4>
                        <div id="object_<?php echo $key; ?>_container">
                            <?php 
                                $subFields = $field['fieldkeyvalue'] ?? [];
                                if (!is_array($subFields)) $subFields = [];
                                foreach ($subFields as $subKey => $subField):
                                    if (!is_array($subField)) continue;
                                    $elementType = $subField['element_type'] ?? 'input';
                                    $title = $subField['field-title'] ?? $subKey;
                                    $defaultValue = sceneiqValueToText($subField['default_value'] ?? '');
                                    $buttonUrl = sceneiqValueToText($subField['button_url'] ?? '');
                            ?>
                                <div class="sub-field-group">
                                    <label><?php echo htmlspecialchars($title); ?></label>
                                    <?php if ($elementType === 'date-time-input'): ?>
                                        <div class="datetime-group">
                                            <input type="date" id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>_date" class="date-input" value="<?php echo htmlspecialchars($defaultValue); ?>">
                                            <div class="time-group">
                                                <input type="number" id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>_hour_12" placeholder="HH" min="1" max="12" style="width:60px;">
                                                <span>:</span>
                                                <input type="number" id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>_minute_12" placeholder="MM" min="0" max="59" style="width:60px;">
                                                <select id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>_ampm" style="width:70px;">
                                                    <option value="AM">AM</option>
                                                    <option value="PM">PM</option>
                                                </select>
                                                <span style="color:#6b7280; font-size:12px; margin-left:5px;">(12h format)</span>
                                            </div>
                                        </div>
                                    <?php elseif ($elementType === 'textarea'): ?>
                                        <?php if (($subField['separate_paragraphs'] ?? 'no') === 'yes' && trim($defaultValue) !== ''): ?>
                                            <div class="separated-default-paragraphs" style="display:flex;flex-direction:column;gap:8px;margin-bottom:8px;">
                                                <?php foreach (preg_split('/\R+/', trim($defaultValue)) as $paragraph): $paragraph = trim($paragraph); if ($paragraph === '') continue; ?>
                                                    <div style="padding:10px;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb;">
                                                        <div style="white-space:pre-wrap;margin-bottom:7px;"><?php echo htmlspecialchars($paragraph); ?></div>
                                                        <button type="button" class="btn btn-secondary btn-sm" data-copy-text="<?php echo htmlspecialchars($paragraph, ENT_QUOTES); ?>" onclick="copyToClipboard(this.getAttribute('data-copy-text'), this)">📋 Copy</button>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <textarea id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>" rows="3" style="display:none;" class="field-with-default new-project-field" data-field-key="<?php echo $key; ?>" data-sub-key="<?php echo $subKey; ?>"><?php echo htmlspecialchars($defaultValue); ?></textarea>
                                        <?php else: ?>
                                            <textarea id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>" rows="3" placeholder="Enter <?php echo htmlspecialchars($title); ?>..." class="field-with-default new-project-field" data-field-key="<?php echo $key; ?>" data-sub-key="<?php echo $subKey; ?>"><?php echo htmlspecialchars($defaultValue); ?></textarea>
                                        <?php endif; ?>
                                    <?php elseif ($elementType === 'select'): ?>
                                        <select id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>" class="new-project-field" data-field-key="<?php echo $key; ?>" data-sub-key="<?php echo $subKey; ?>">
                                            <option value="">Select...</option>
                                            <?php foreach (($subField['default_values'] ?? []) as $val): ?>
                                                <option value="<?php echo htmlspecialchars($val); ?>" <?php echo ($val === $defaultValue) ? 'selected' : ''; ?>><?php echo htmlspecialchars($val); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($elementType === 'multiple-selections'): ?>
                                        <div id="field_<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>_<?php echo htmlspecialchars((string)$subKey, ENT_QUOTES, 'UTF-8'); ?>_container" class="multiple-selections-control">
                                            <input type="text" class="multiple-selections-trigger" readonly role="button" aria-haspopup="dialog" placeholder="Click to search and select values..." onclick="openMultipleSelectionsModal(this)">
                                            <div class="multiple-selections-storer" aria-live="polite">
                                                <span class="multiple-selections-empty">No values selected yet.</span>
                                            </div>
                                            <input type="hidden" id="entry_<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>_<?php echo htmlspecialchars((string)$subKey, ENT_QUOTES, 'UTF-8'); ?>" value="[]" class="new-project-field" data-field-key="<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>" data-sub-key="<?php echo htmlspecialchars((string)$subKey, ENT_QUOTES, 'UTF-8'); ?>">
                                            <div class="multiple-selections-modal" hidden onclick="if(event.target === this) closeMultipleSelectionsModal(this)">
                                                <div class="multiple-selections-dialog" role="dialog" aria-modal="true" aria-label="Select values for <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <div class="multiple-selections-dialog-header">
                                                        <h4><?php echo htmlspecialchars($title); ?></h4>
                                                        <button type="button" class="btn btn-secondary btn-sm" onclick="closeMultipleSelectionsModal(this)">Done</button>
                                                    </div>
                                                    <input type="search" class="multiple-selections-dialog-search" placeholder="Search available values..." oninput="filterMultipleSelectionOptions(this)">
                                                    <div class="multiple-selections-options">
                                                        <?php foreach (($subField['default_values'] ?? []) as $optionValue): ?>
                                                            <label class="multiple-selections-option" data-value="<?php echo htmlspecialchars((string)$optionValue, ENT_QUOTES, 'UTF-8'); ?>">
                                                                <input type="checkbox" class="multiple-selections-option-checkbox" value="<?php echo htmlspecialchars((string)$optionValue, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Select <?php echo htmlspecialchars((string)$optionValue, ENT_QUOTES, 'UTF-8'); ?>" onchange="selectMultipleSelectionOption(this)">
                                                                <span class="multiple-selections-option-label"><?php echo htmlspecialchars((string)$optionValue); ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                        <div class="multiple-selections-no-results" hidden>No matching values found.</div>
                                                    </div>
                                                    <?php if (empty($subField['default_values'])): ?>
                                                        <div class="multiple-selections-no-results">No values have been configured for this field.</div>
                                                    <?php endif; ?>
                                                    <div class="multiple-selections-dialog-footer">
                                                        <button type="button" class="btn btn-primary btn-sm" onclick="closeMultipleSelectionsModal(this)">Done</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php elseif ($elementType === 'button'): ?>
                                        <div>
                                            <button type="button" class="btn btn-primary" onclick="openUrl('<?php echo htmlspecialchars(trim($buttonUrl), ENT_QUOTES); ?>')" style="padding:8px 20px;">
                                                <?php echo htmlspecialchars($title); ?>
                                            </button>
                                            <input type="hidden" id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>" value="<?php echo htmlspecialchars($buttonUrl); ?>" class="new-project-field" data-field-key="<?php echo $key; ?>" data-sub-key="<?php echo $subKey; ?>">
                                        </div>
                                    <?php else: ?>
                                        <input type="text" id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>" placeholder="Enter <?php echo htmlspecialchars($title); ?>..." value="<?php echo htmlspecialchars($defaultValue); ?>" class="new-project-field" data-field-key="<?php echo $key; ?>" data-sub-key="<?php echo $subKey; ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="copyObjectFromDOM(this)">📋 Copy data</button>
                        </div>
                        <input type="hidden" id="object_<?php echo $key; ?>_data" value="">
                    </div>
                <?php else: ?>
                    <div class="form-group" id="field_group_<?php echo $key; ?>">
                        <label><?php echo htmlspecialchars($field['field-title'] ?? $key); ?></label>
                        <?php 
                            $elementType = $field['element_type'] ?? 'input';
                            $defaultValue = sceneiqValueToText($field['default_value'] ?? '');
                            $defaultValues = $field['default_values'] ?? [];
                            $buttonUrl = sceneiqValueToText($field['button_url'] ?? '');
                        ?>
                        <?php if ($elementType === 'copy_button_for_each_of'): ?>
                            <div id="field_<?php echo htmlspecialchars($key); ?>_container" style="display:flex;flex-direction:column;gap:12px;margin:10px 0;">
                                <?php $copySentences = $field['copy_button_for_each_of'] ?? []; if (is_array($copySentences)): foreach ($copySentences as $sentence): $sentence = trim((string)$sentence); if ($sentence === '') continue; ?>
                                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:12px;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb;margin-bottom:4px;">
                                        <div style="white-space:pre-wrap;overflow-wrap:anywhere;flex:1;min-width:0;"><?php echo htmlspecialchars($sentence); ?></div>
                                        <button type="button" class="btn btn-secondary btn-sm individual-sentence-copy-button" data-copy-text="<?php echo htmlspecialchars($sentence, ENT_QUOTES); ?>" onclick="copyToClipboard(this.getAttribute('data-copy-text'), this)">📋 Copy</button>
                                    </div>
                                <?php endforeach; endif; ?>
                                <input type="hidden" id="entry_<?php echo htmlspecialchars($key); ?>" value="" class="new-project-field" data-field-key="<?php echo htmlspecialchars($key); ?>">
                            </div>
                        <?php elseif ($elementType === 'date-time-input'): ?>
                            <div class="datetime-group" id="field_<?php echo $key; ?>_container">
                                <input type="date" id="entry_<?php echo $key; ?>_date" class="date-input new-project-field" data-field-key="<?php echo $key; ?>" value="<?php echo htmlspecialchars($defaultValue); ?>">
                                <div class="time-group">
                                    <input type="number" id="entry_<?php echo $key; ?>_hour_12" placeholder="HH" min="1" max="12" style="width:60px;">
                                    <span>:</span>
                                    <input type="number" id="entry_<?php echo $key; ?>_minute_12" placeholder="MM" min="0" max="59" style="width:60px;">
                                    <select id="entry_<?php echo $key; ?>_ampm" style="width:70px;">
                                        <option value="AM">AM</option>
                                        <option value="PM">PM</option>
                                    </select>
                                    <span style="color:#6b7280; font-size:12px; margin-left:5px;">(12h format)</span>
                                </div>
                                <div style="margin-top:5px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualFromDOM(this)">📋 Copy Data</button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualDefaultValue(this)" style="display: none">📋 Copy Default Value</button>
                                </div>
                            </div>
                        <?php elseif ($elementType === 'textarea'): ?>
                            <div id="field_<?php echo $key; ?>_container">
                                <?php if (($field['separate_paragraphs'] ?? 'no') === 'yes' && trim($defaultValue) !== ''): ?>
                                    <div class="separated-default-paragraphs" style="display:flex;flex-direction:column;gap:8px;margin-bottom:8px;">
                                        <?php foreach (preg_split('/\R+/', trim($defaultValue)) as $paragraph): $paragraph = trim($paragraph); if ($paragraph === '') continue; ?>
                                            <div style="padding:10px;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb;">
                                                <div style="white-space:pre-wrap;margin-bottom:7px;"><?php echo htmlspecialchars($paragraph); ?></div>
                                                <button type="button" class="btn btn-secondary btn-sm" data-copy-text="<?php echo htmlspecialchars($paragraph, ENT_QUOTES); ?>" onclick="copyToClipboard(this.getAttribute('data-copy-text'), this)">📋 Copy</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <textarea id="entry_<?php echo $key; ?>" rows="3" style="display:none;" class="field-with-default new-project-field" data-field-key="<?php echo $key; ?>"><?php echo htmlspecialchars($defaultValue); ?></textarea>
                                <?php else: ?>
                                    <textarea id="entry_<?php echo $key; ?>" rows="3" placeholder="Enter <?php echo htmlspecialchars($field['field-title'] ?? $key); ?>..." class="field-with-default new-project-field" data-field-key="<?php echo $key; ?>"><?php echo htmlspecialchars($defaultValue); ?></textarea>
                                    <div style="margin-top:5px;display:flex;gap:8px;flex-wrap:wrap;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualFromDOM(this)">📋 Copy Data</button>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualDefaultValue(this)" style="display:none">📋 Copy Default Value</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($elementType === 'multiple-selections'): ?>
                            <div id="field_<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>_container" class="multiple-selections-control">
                                <input type="text" class="multiple-selections-trigger" readonly role="button" aria-haspopup="dialog" placeholder="Click to search and select values..." onclick="openMultipleSelectionsModal(this)">
                                <div class="multiple-selections-storer" aria-live="polite">
                                    <span class="multiple-selections-empty">No values selected yet.</span>
                                </div>
                                <input type="hidden" id="entry_<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>" value="[]" class="new-project-field" data-field-key="<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="multiple-selections-modal" hidden onclick="if(event.target === this) closeMultipleSelectionsModal(this)">
                                    <div class="multiple-selections-dialog" role="dialog" aria-modal="true" aria-label="Select values">
                                        <div class="multiple-selections-dialog-header">
                                            <h4><?php echo htmlspecialchars($field['field-title'] ?? $key); ?></h4>
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="closeMultipleSelectionsModal(this)">Done</button>
                                        </div>
                                        <input type="search" class="multiple-selections-dialog-search" placeholder="Search available values..." oninput="filterMultipleSelectionOptions(this)">
                                        <div class="multiple-selections-options">
                                            <?php foreach ($defaultValues as $optionValue): ?>
                                                <label class="multiple-selections-option" data-value="<?php echo htmlspecialchars((string)$optionValue, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="checkbox" class="multiple-selections-option-checkbox" value="<?php echo htmlspecialchars((string)$optionValue, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Select <?php echo htmlspecialchars((string)$optionValue, ENT_QUOTES, 'UTF-8'); ?>" onchange="selectMultipleSelectionOption(this)">
                                                    <span class="multiple-selections-option-label"><?php echo htmlspecialchars((string)$optionValue); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                            <div class="multiple-selections-no-results" hidden>No matching values found.</div>
                                        </div>
                                        <?php if (empty($defaultValues)): ?>
                                            <div class="multiple-selections-no-results">No values have been configured for this field.</div>
                                        <?php endif; ?>
                                        <div class="multiple-selections-dialog-footer">
                                            <button type="button" class="btn btn-primary btn-sm" onclick="closeMultipleSelectionsModal(this)">Done</button>
                                        </div>
                                    </div>
                                </div>
                                <div style="margin-top:5px;display:flex;gap:8px;flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualFromDOM(this)">📋 Copy Data</button>
                                </div>
                            </div>
                        <?php elseif ($elementType === 'select'): ?>
                            <div id="field_<?php echo $key; ?>_container">
                                <select id="entry_<?php echo $key; ?>" class="new-project-field" data-field-key="<?php echo $key; ?>">
                                    <option value="">Select...</option>
                                    <?php foreach ($defaultValues as $val): ?>
                                        <option value="<?php echo htmlspecialchars($val); ?>" <?php echo ($val === $defaultValue) ? 'selected' : ''; ?>><?php echo htmlspecialchars($val); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="margin-top:5px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualFromDOM(this)">📋 Copy Data</button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualDefaultValue(this)" style="display: none">📋 Copy Default Value</button>
                                </div>
                            </div>
                        <?php elseif ($elementType === 'button'): ?>
                            <div id="field_<?php echo $key; ?>_container">
                                <button type="button" class="btn btn-primary" onclick="openUrl('<?php echo htmlspecialchars(trim($buttonUrl), ENT_QUOTES); ?>')" style="padding:8px 20px;">
                                    <?php echo htmlspecialchars($field['field-title'] ?? $key); ?>
                                </button>
                                <input type="hidden" id="entry_<?php echo $key; ?>" value="<?php echo htmlspecialchars($buttonUrl); ?>" class="new-project-field" data-field-key="<?php echo $key; ?>">
                                <div style="margin-top:5px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualFromDOM(this)">📋 Copy Data</button>
                                </div>
                            </div>
                        <?php else: ?>
                            <div id="field_<?php echo $key; ?>_container">
                                <input type="text" id="entry_<?php echo $key; ?>" placeholder="Enter <?php echo htmlspecialchars($field['field-title'] ?? $key); ?>..." value="<?php echo htmlspecialchars($defaultValue); ?>" class="new-project-field" data-field-key="<?php echo $key; ?>">
                                <div style="margin-top:5px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualFromDOM(this)">📋 Copy Data</button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualDefaultValue(this)" style="display: none">📋 Copy Default Value</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            
            <div class="form-actions">
                <button type="button" class="btn btn-success saveEntryButton" onclick="submitUserEntry()" style="display:none;" hidden aria-hidden="true"> Save Entry</button>
            </div>
        </form>
        <?php
    }
?>

<div class="sceneiq-copy-modal" id="copySelectionModal" aria-hidden="true">
    <div class="sceneiq-copy-modal-panel" role="dialog" aria-modal="true" aria-labelledby="copySelectionModalTitle">
        <div class="sceneiq-copy-modal-header">
            <div><h3 id="copySelectionModalTitle">Choose data to copy</h3><p id="copySelectionModalDescription">All source items are selected initially. Uncheck anything you do not want copied.</p></div>
            <button type="button" class="sceneiq-copy-modal-close" data-copy-modal-close="selection" aria-label="Close">×</button>
        </div>
        <div class="copy-config-tabs" id="copyConfigTabs" hidden>
            <button type="button" class="active" data-copy-config-tab="dynamic_tabs">Dynamic tabs</button>
            <button type="button" data-copy-config-tab="dynamic_fields">Dynamic field</button>
        </div>
        <div class="copy-config-toolbar">
            <span id="copyConfigCount">0 selected</span>
            <div><button type="button" class="btn btn-secondary btn-sm" id="copyConfigSelectAll">Select all</button><button type="button" class="btn btn-secondary btn-sm" id="copyConfigClearAll">Clear all</button></div>
        </div>
        <div class="copy-config-list" id="copyConfigList"></div>
        <div class="sceneiq-copy-modal-actions">
            <button type="button" class="btn btn-secondary" data-copy-modal-close="selection">Cancel</button>
            <button type="button" class="btn btn-primary" id="copyConfigApply">Use selected data</button>
        </div>
    </div>
</div>

<div class="sceneiq-copy-modal" id="copyDestinationModal" aria-hidden="true">
    <div class="sceneiq-copy-modal-panel" role="dialog" aria-modal="true" aria-labelledby="copyDestinationModalTitle">
        <div class="sceneiq-copy-modal-header">
            <div><h3 id="copyDestinationModalTitle">Choose destination accounts</h3><p id="copyDestinationModalDescription">Select one or more accounts. The source account is not listed.</p></div>
            <button type="button" class="sceneiq-copy-modal-close" data-copy-modal-close="destinations" aria-label="Close">×</button>
        </div>
        <div class="copy-config-toolbar">
            <span id="copyDestinationModalCount">0 selected</span>
            <div><button type="button" class="btn btn-secondary btn-sm" id="copyDestinationSelectAll">Select all</button><button type="button" class="btn btn-secondary btn-sm" id="copyDestinationClearAll">Clear all</button></div>
        </div>
        <div class="copy-config-list" id="copyDestinationList"></div>
        <div class="sceneiq-copy-modal-actions">
            <button type="button" class="btn btn-secondary" data-copy-modal-close="destinations">Cancel</button>
            <button type="button" class="btn btn-primary" id="copyDestinationApply">Use selected accounts</button>
        </div>
    </div>
</div>

<style>
.copy-mode-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:9px; }
.copy-mode-button { min-height:46px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:9px; background:#fff; color:#334155; font-weight:700; cursor:pointer; }
.copy-mode-button:hover:not(:disabled), .copy-mode-button.active { border-color:#0f766e; background:#f0fdfa; color:#115e59; }
.copy-mode-button:disabled { opacity:.45; cursor:not-allowed; }
.copy-data-help { color:#64748b; font-size:12px; line-height:1.5; }
.copy-destination-trigger { width:100%; min-height:48px; padding:10px 12px; display:flex; align-items:center; justify-content:space-between; gap:12px; border:1px solid #cbd5e1; border-radius:9px; background:#fff; color:#172033; font-weight:600; text-align:left; cursor:pointer; }
.copy-destination-trigger:disabled { opacity:.5; cursor:not-allowed; }
.copy-destination-count { color:#0f766e; font-size:12px; white-space:nowrap; }
.copy-selection-summary { padding:11px 12px; border:1px solid #e2e8f0; border-radius:9px; background:#f8fafc; color:#475569; font-size:13px; }
.sceneiq-copy-modal { position:fixed; inset:0; z-index:2147482000; display:none; align-items:center; justify-content:center; padding:18px; background:rgba(15,23,42,.58); }
.sceneiq-copy-modal.active { display:flex; }
.sceneiq-copy-modal-panel { width:min(720px,100%); max-height:min(86vh,850px); display:flex; flex-direction:column; overflow:hidden; border:1px solid #dbe3eb; border-radius:16px; background:#fff; box-shadow:0 24px 80px rgba(15,23,42,.28); }
.sceneiq-copy-modal-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:18px 20px; border-bottom:1px solid #e5e7eb; }
.sceneiq-copy-modal-header h3 { margin:0 0 5px; color:#172033; font-size:19px; }
.sceneiq-copy-modal-header p { margin:0; color:#64748b; font-size:13px; line-height:1.45; }
.sceneiq-copy-modal-close { width:34px; height:34px; flex:0 0 34px; border:0; border-radius:8px; background:#f1f5f9; color:#334155; font-size:24px; cursor:pointer; }
.copy-config-tabs { display:flex; gap:8px; padding:12px 18px 0; }
.copy-config-tabs[hidden] { display:none !important; }
.copy-config-tabs button { min-height:38px; padding:8px 14px; border:1px solid #dbe3eb; border-radius:8px; background:#f8fafc; color:#475569; font-weight:700; cursor:pointer; }
.copy-config-tabs button.active { border-color:#0f766e; background:#f0fdfa; color:#115e59; }
.copy-config-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 18px; color:#64748b; font-size:12px; }
.copy-config-toolbar > div { display:flex; gap:7px; }
.copy-config-list { min-height:120px; max-height:52vh; overflow:auto; padding:0 18px 14px; overscroll-behavior:contain; }
.copy-config-option { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:11px 12px; margin-bottom:7px; border:1px solid #e2e8f0; border-radius:9px; background:#fff; cursor:pointer; }
.copy-config-option:hover { background:#f8fafc; }
.copy-config-option-copy { display:flex; flex-direction:column; gap:4px; min-width:0; }
.copy-config-option-title { color:#1f2937; font-size:13px; font-weight:700; overflow-wrap:anywhere; }
.copy-config-option-meta { color:#64748b; font-size:11px; overflow-wrap:anywhere; }
.copy-config-option input { width:18px; height:18px; flex:0 0 18px; accent-color:#0f766e; }
.copy-config-empty { padding:24px 12px; color:#64748b; text-align:center; }
.sceneiq-copy-modal-actions { display:flex; justify-content:flex-end; gap:9px; padding:14px 18px; border-top:1px solid #e5e7eb; background:#fff; }
@media(max-width:560px) { .copy-mode-grid { grid-template-columns:1fr; } .sceneiq-copy-modal { padding:8px; } .sceneiq-copy-modal-panel { max-height:92vh; } .copy-config-list { max-height:58vh; } .sceneiq-copy-modal-actions { flex-direction:column-reverse; } .sceneiq-copy-modal-actions .btn { width:100%; } }
</style>

<script>
    function openUrl(url) {
        if (!url) return;
        // If URL doesn't have a protocol, add https://
        if (!url.match(/^https?:\/\//i)) {
            url = 'https://' + url;
        }
        window.open(url, '_blank');
    }
    // ===== DATA =====
    var selectedUserId = <?php echo json_encode($selectedUserId, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var globalDynamicFields = <?php echo json_encode($globalDynamicFields, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var userSettings = <?php echo json_encode($userSettings, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var userDynamicTabs = <?php echo json_encode($userDynamicTabs, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var _pendingSaveData = null;

    var sceneIQLoadingDepth = 0;
    function sceneIQBeginLoading(message, detail) {
        sceneIQLoadingDepth++;
        var titleNode = document.getElementById('sceneiq-loading-title');
        var detailNode = document.getElementById('sceneiq-loading-detail');
        if (titleNode) titleNode.textContent = message || 'Loading configuration';
        if (detailNode) detailNode.textContent = detail || 'Please wait while SceneIQ applies your changes...';
        document.body.classList.add('sceneiq-operation-loading');
    }
    function sceneIQEndLoading() {
        sceneIQLoadingDepth = Math.max(0, sceneIQLoadingDepth - 1);
        if (sceneIQLoadingDepth === 0) document.body.classList.remove('sceneiq-operation-loading');
    }

    // ============================================================
    // ===== BACKGROUND SYNC FUNCTIONS - KEEPS DATA FRESH =====
    // ============================================================

    window._fieldValueCache = {};

    // Keep active modal overlays attached directly to <body> so fixed positioning
    // is relative to the viewport rather than clipped by the page's scroll container.
    // Restore each modal to its original DOM location when it closes.
    var _sceneiqOpenModals = new Set();
    var _sceneiqLockedScrollTop = 0;

    function moveSceneIQModalToBody(modal) {
        if (!modal || !modal.parentNode || modal.parentNode === document.body) return;
        if (!modal._sceneiqModalPlaceholder) {
            modal._sceneiqModalPlaceholder = document.createComment('SceneIQ modal placeholder');
            modal.parentNode.insertBefore(modal._sceneiqModalPlaceholder, modal);
        }
        document.body.appendChild(modal);
    }

    function restoreSceneIQModal(modal) {
        if (!modal) return;
        var placeholder = modal._sceneiqModalPlaceholder;
        if (placeholder && placeholder.parentNode) {
            placeholder.parentNode.insertBefore(modal, placeholder);
            placeholder.parentNode.removeChild(placeholder);
        }
        modal._sceneiqModalPlaceholder = null;
    }

    function lockSceneIQBackground(modal) {
        if (!modal || _sceneiqOpenModals.has(modal)) return;
        if (_sceneiqOpenModals.size === 0) {
            var scrollHost = document.querySelector('.scroll-body');
            _sceneiqLockedScrollTop = scrollHost ? scrollHost.scrollTop : 0;
            document.documentElement.classList.add('sceneiq-modal-open');
            document.body.classList.add('sceneiq-modal-open');
        }
        _sceneiqOpenModals.add(modal);
    }

    function unlockSceneIQBackground(modal) {
        if (!modal) return;
        _sceneiqOpenModals.delete(modal);
        if (_sceneiqOpenModals.size === 0) {
            document.documentElement.classList.remove('sceneiq-modal-open');
            document.body.classList.remove('sceneiq-modal-open');
            var scrollHost = document.querySelector('.scroll-body');
            if (scrollHost) scrollHost.scrollTop = _sceneiqLockedScrollTop;
        }
    }

    // Multiple selections values are kept as a native JSON array in the hidden
    // form control, then submitted as an array (not as a comma-joined string).
    function readMultipleSelectionValues(control) {
        if (!control) return [];
        var hidden = control.querySelector('.new-project-field[data-field-key]');
        if (!hidden) return [];
        try {
            var parsed = JSON.parse(hidden.value || '[]');
            return Array.isArray(parsed) ? parsed.map(function(value) { return String(value); }) : [];
        } catch (error) {
            console.warn('[SceneIQ Multiple Selections] Could not parse selected values.', error);
            return [];
        }
    }

    function writeMultipleSelectionValues(control, values) {
        if (!control) return;
        var hidden = control.querySelector('.new-project-field[data-field-key]');
        var storer = control.querySelector('.multiple-selections-storer');
        var modal = control._multipleSelectionsModal || control.querySelector('.multiple-selections-modal');
        var cleanValues = [];
        (Array.isArray(values) ? values : []).forEach(function(value) {
            value = String(value == null ? '' : value).trim();
            if (value && cleanValues.indexOf(value) === -1) cleanValues.push(value);
        });

        if (hidden) hidden.value = JSON.stringify(cleanValues);
        if (storer) {
            storer.innerHTML = '';
            if (!cleanValues.length) {
                var empty = document.createElement('span');
                empty.className = 'multiple-selections-empty';
                empty.textContent = 'No values selected yet.';
                storer.appendChild(empty);
            } else {
                cleanValues.forEach(function(value) {
                    var chip = document.createElement('span');
                    chip.className = 'multiple-selections-chip';
                    var label = document.createElement('span');
                    label.className = 'multiple-selections-chip-text';
                    label.textContent = value;
                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'multiple-selections-chip-remove';
                    remove.setAttribute('aria-label', 'Remove ' + value);
                    remove.textContent = '×';
                    remove.addEventListener('click', function() {
                        writeMultipleSelectionValues(control, readMultipleSelectionValues(control).filter(function(item) { return item !== value; }));
                    });
                    chip.appendChild(label);
                    chip.appendChild(remove);
                    storer.appendChild(chip);
                });
            }
        }

        if (modal) {
            modal.querySelectorAll('.multiple-selections-option').forEach(function(option) {
                var isSelected = cleanValues.indexOf(option.getAttribute('data-value') || '') !== -1;
                var checkbox = option.querySelector('.multiple-selections-option-checkbox');
                option.classList.toggle('is-selected', isSelected);
                option.setAttribute('aria-checked', isSelected ? 'true' : 'false');
                if (checkbox) checkbox.checked = isSelected;
            });
        }
        if (typeof syncFieldValues === 'function') syncFieldValues();
    }

    function openMultipleSelectionsModal(trigger) {
        var control = trigger && trigger.closest ? trigger.closest('.multiple-selections-control') : null;
        if (!control) return;
        var modal = control._multipleSelectionsModal || control.querySelector('.multiple-selections-modal');
        if (!modal) return;
        control._multipleSelectionsModal = modal;
        modal._sceneiqModalControl = control;
        moveSceneIQModalToBody(modal);
        lockSceneIQBackground(modal);
        modal.hidden = false;
        modal.style.display = 'flex';
        var search = modal.querySelector('.multiple-selections-dialog-search');
        if (search) {
            search.value = '';
            filterMultipleSelectionOptions(search);
            window.setTimeout(function() { search.focus(); }, 50);
        }
        writeMultipleSelectionValues(control, readMultipleSelectionValues(control));
    }

    function closeMultipleSelectionsModal(source) {
        var modal = source && source.classList && source.classList.contains('multiple-selections-modal')
            ? source
            : (source && source.closest ? source.closest('.multiple-selections-modal') : null);
        if (!modal) return;
        var control = modal._sceneiqModalControl || null;
        modal.hidden = true;
        modal.style.display = 'none';
        unlockSceneIQBackground(modal);
        restoreSceneIQModal(modal);
        if (control) {
            control._multipleSelectionsModal = null;
            modal._sceneiqModalControl = null;
        }
    }

    function filterMultipleSelectionOptions(searchInput) {
        var modal = searchInput && searchInput.closest ? searchInput.closest('.multiple-selections-modal') : null;
        if (!modal) return;
        var query = String(searchInput.value || '').trim().toLowerCase();
        var visibleCount = 0;
        modal.querySelectorAll('.multiple-selections-option').forEach(function(option) {
            var matches = (option.textContent || '').toLowerCase().indexOf(query) !== -1;
            option.style.display = matches ? '' : 'none';
            if (matches) visibleCount++;
        });
        var optionButtons = modal.querySelectorAll('.multiple-selections-option');
        var noResults = modal.querySelector('.multiple-selections-options .multiple-selections-no-results');
        if (noResults) noResults.hidden = optionButtons.length === 0 || visibleCount !== 0;
    }

    function selectMultipleSelectionOption(optionCheckbox) {
        if (!optionCheckbox) return;
        var option = optionCheckbox.closest ? optionCheckbox.closest('.multiple-selections-option') : null;
        if (!option) return;
        var modal = option.closest ? option.closest('.multiple-selections-modal') : null;
        var control = option.closest ? option.closest('.multiple-selections-control') : null;
        if (!control && modal) control = modal._sceneiqModalControl || null;
        if (!control) return;

        var value = option.getAttribute('data-value');
        if (value === null) value = optionCheckbox.value || option.textContent || '';
        var selected = readMultipleSelectionValues(control);
        if (optionCheckbox.checked) {
            if (selected.indexOf(value) === -1) selected.push(value);
        } else {
            selected = selected.filter(function(item) { return item !== value; });
        }
        writeMultipleSelectionValues(control, selected);
    }

    function syncFieldValues() {
        var activeContent = document.querySelector('#user_tab_add_entry .dynamic-sub-tab-content.active');
        if (!activeContent) return;
        
        var fields = activeContent.querySelectorAll('.new-project-field');
        fields.forEach(function(field) {
            var id = field.id;
            if (!id) return;
            var value = field.value || '';
            var cacheKey = id;
            if (id.startsWith('entry_')) {
                cacheKey = id.replace('entry_', '');
            }
            window._fieldValueCache[cacheKey] = value;
        });
        
        var objectContainers = activeContent.querySelectorAll('[id^="object_"]');
        objectContainers.forEach(function(container) {
            var containerId = container.id;
            if (!containerId) return;
            var objectKey = containerId.replace('object_', '').replace('_container', '');
            var subFields = container.querySelectorAll('.sub-field-group');
            var objectData = {};
            subFields.forEach(function(subField) {
                var inputs = subField.querySelectorAll('input, textarea, select');
                inputs.forEach(function(input) {
                    if (input.id && input.id.startsWith('entry_' + objectKey + '_')) {
                        var subKey = input.id.replace('entry_' + objectKey + '_', '');
                        if (subKey.includes('_date') || subKey.includes('_hour_12') || 
                            subKey.includes('_minute_12') || subKey.includes('_ampm')) {
                            return;
                        }
                        if (input.classList && input.classList.contains('new-project-field') && input.closest('.multiple-selections-control')) {
                            try {
                                var selectedArray = JSON.parse(input.value || '[]');
                                objectData[subKey] = Array.isArray(selectedArray) ? selectedArray : [];
                            } catch (parseError) {
                                objectData[subKey] = [];
                            }
                        } else {
                            objectData[subKey] = input.value || '';
                        }
                    }
                });
            });
            window._fieldValueCache[objectKey] = objectData;
        });
    }

    var syncInterval = null;

    function startBackgroundSync() {
        if (syncInterval) clearInterval(syncInterval);
        syncInterval = setInterval(function() {
            syncFieldValues();
        }, 1000);
    }

    function stopBackgroundSync() {
        if (syncInterval) {
            clearInterval(syncInterval);
            syncInterval = null;
        }
    }

    // ============================================================
    // ===== COPY FUNCTIONS =====
    // ============================================================

    // Resolve the field from the button that was actually clicked.
    // This avoids selecting a different copy of a field when the same field
    // key is rendered in more than one tab or view.
    function resolveCopyContainer(buttonOrContainer, kind) {
        if (buttonOrContainer && buttonOrContainer.nodeType === 1) {
            if (kind === 'object') {
                // The object Copy Data button is rendered AFTER (as a sibling of)
                // the object container, not inside it. Search the surrounding
                // field group first, then fall back to the closest object container.
                var objectGroup = buttonOrContainer.closest('.form-group');
                if (objectGroup) {
                    var objectContainer = objectGroup.querySelector('[id^="object_"][id$="_container"]');
                    if (objectContainer) {
                        return objectContainer;
                    }
                }
                return buttonOrContainer.closest('[id^="object_"][id$="_container"]');
            }

            // Individual-field buttons are normally inside their field container.
            var fieldContainer = buttonOrContainer.closest('[id^="field_"][id$="_container"]');
            if (fieldContainer) {
                return fieldContainer;
            }

            // Fallback for fields whose Copy Data button is adjacent to the
            // value control rather than nested in the container.
            var fieldGroup = buttonOrContainer.closest('.form-group');
            if (fieldGroup) {
                return fieldGroup.querySelector('[id^="field_"][id$="_container"]') || fieldGroup;
            }
            return null;
        }

        // Backward compatibility for callers still passing an element ID.
        if (typeof buttonOrContainer === 'string') {
            return document.getElementById(buttonOrContainer);
        }
        return null;
    }

    function copyIndividualFromDOM(buttonOrContainer) {
        var container = resolveCopyContainer(buttonOrContainer, 'individual');
        if (!container) {
            showDialog('Error', 'Could not find the field connected to this Copy Data button.');
            return;
        }

        // Read the live form control inside the exact field whose button was clicked.
        // Never use defaults, cached values, or text rendered in another tab.
        var valueElement = container.querySelector('.new-project-field, .field-with-default, textarea, select, input');
        if (!valueElement) {
            showDialog('Error', 'Value element not found in the selected field.');
            return;
        }

        var textToCopy = valueElement.value;
        if (textToCopy === undefined || textToCopy === null) {
            textToCopy = '';
        }
        copyToClipboard(String(textToCopy), buttonOrContainer);
    }

    function copyObjectFromDOM(buttonOrContainer) {
        var container = resolveCopyContainer(buttonOrContainer, 'object');
        if (!container) {
            showDialog('Error', 'Could not find the object connected to this Copy Data button.');
            return;
        }

        var subFields = container.querySelectorAll('.sub-field-group');
        var rows = [];

        subFields.forEach(function(subField) {
            var values = [];
            var inputs = subField.querySelectorAll('input, textarea, select');
            var multipleControl = subField.querySelector('.multiple-selections-control');
            if (multipleControl) {
                // Keep selected multiple-selection values as a JSON array when copying an object.
                values.push(JSON.stringify(readMultipleSelectionValues(multipleControl)));
            }

            inputs.forEach(function(input) {
                if (multipleControl && input.closest && input.closest('.multiple-selections-control')) return;
                if (input.id && (
                    input.id.includes('_date') ||
                    input.id.includes('_hour_12') ||
                    input.id.includes('_minute_12') ||
                    input.id.includes('_ampm')
                )) {
                    return;
                }
                if (input.type === 'button' || input.type === 'submit' ||
                    input.type === 'reset' || input.type === 'hidden') {
                    return;
                }
                values.push(input.value === undefined || input.value === null
                    ? ''
                    : String(input.value));
            });

            rows.push(values.join(' '));
        });

        copyToClipboard(rows.join('\n'), buttonOrContainer);
    }

    function getElementDefaultValue(valueElement) {
        if (!valueElement) {
            return '';
        }

        var tagName = valueElement.tagName ? valueElement.tagName.toLowerCase() : '';

        if (tagName === 'select') {
            // defaultSelected reflects the option marked as selected in the
            // original markup, not the option currently selected by the user.
            var defaultOption = null;
            for (var i = 0; i < valueElement.options.length; i++) {
                if (valueElement.options[i].defaultSelected) {
                    defaultOption = valueElement.options[i];
                    break;
                }
            }
            return defaultOption ? defaultOption.value : '';
        }

        // For input and textarea, defaultValue is the original value from the
        // markup. It remains independent of edits to the live .value property.
        if (typeof valueElement.defaultValue === 'string') {
            return valueElement.defaultValue;
        }

        return '';
    }

    function copyIndividualDefaultValue(buttonOrContainer) {
        var container = resolveCopyContainer(buttonOrContainer, 'individual');
        if (!container) {
            showDialog('Error', 'Field not found');
            return;
        }

        var valueElement = container.querySelector('input, textarea, select');
        if (!valueElement) {
            showDialog('Error', 'Value element not found');
            return;
        }

        // Copy Default Values reads the element's original default separately
        // from Copy Data and never uses the current edited value or cache.
        copyToClipboard(getElementDefaultValue(valueElement));
    }

    function copyObjectDefaultValues(buttonOrContainer) {
        var container = resolveCopyContainer(buttonOrContainer, 'object');
        if (!container) {
            showDialog('Error', 'Object container not found');
            return;
        }

        var subFields = container.querySelectorAll('.sub-field-group');
        var rows = [];

        subFields.forEach(function(subField) {
            var values = [];
            var inputs = subField.querySelectorAll('input, textarea, select');

            inputs.forEach(function(input) {
                if (input.id && (
                    input.id.includes('_date') ||
                    input.id.includes('_hour_12') ||
                    input.id.includes('_minute_12') ||
                    input.id.includes('_ampm')
                )) {
                    return;
                }

                if (input.type === 'button' || input.type === 'submit' || input.type === 'reset') {
                    return;
                }

                values.push(getElementDefaultValue(input));
            });

            rows.push(values.join(' '));
        });

        // Empty default values are valid and are copied as empty text/rows.
        copyToClipboard(rows.join('\n'));
    }

    function copyToClipboard(text, triggerElement) {
        // Do not reject an empty string. Copy Data must be able to copy a
        // field after its current value has been erased.
        var textToCopy = typeof text === 'string'
            ? text
            : (text === undefined || text === null ? '' : String(text));

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textToCopy).then(function() {
                showDialog('Success', textToCopy === '' ? 'Copied an empty value to clipboard.' : 'Copied to clipboard!');
            }).catch(function() {
                fallbackCopy(textToCopy);
            });
        } else {
            fallbackCopy(textToCopy);
        }
    }

    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text === undefined || text === null ? '' : String(text);
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '-9999px';
        textarea.style.width = '1px';
        textarea.style.height = '1px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        var copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (e) {
            copied = false;
        }

        document.body.removeChild(textarea);

        if (copied) {
            showDialog('Success', textarea.value === '' ? 'Copied an empty value to clipboard.' : 'Copied to clipboard!');
        } else {
            showDialog('Error', 'Failed to copy. Please select and copy manually.');
        }
    }

    // ============================================================
    // ===== CUSTOM DIALOG =====
    // ============================================================

    function showDialog(title, message, buttons, content) {
        console.log('showDialog called with title:', title);
        
        document.getElementById('dialogTitle').textContent = title;
        document.getElementById('dialogMessage').textContent = message;
        
        var contentDiv = document.getElementById('dialogContent');
        if (content) {
            contentDiv.innerHTML = content;
            contentDiv.style.display = 'block';
            console.log('Content set, length:', content.length);
        } else {
            contentDiv.innerHTML = '';
            contentDiv.style.display = 'none';
        }
        
        var buttonsDiv = document.getElementById('dialogButtons');
        if (buttons && buttons.length > 0) {
            buttonsDiv.innerHTML = buttons.map(function(b, i) {
                return '<button class="btn ' + (b.class || 'btn-primary') + '" onclick="dialogButtonClick(' + i + ')">' + b.label + '</button>';
            }).join('');
            window._dialogCallbacks = buttons.map(function(b) { return b.callback || null; });
        } else {
            buttonsDiv.innerHTML = '<button class="btn btn-primary" onclick="closeDialog()">OK</button>';
            window._dialogCallbacks = [null];
        }
        
        var dialog = document.getElementById('customDialog');
        if (dialog) {
            moveSceneIQModalToBody(dialog);
            lockSceneIQBackground(dialog);
            dialog.style.display = 'flex';
            dialog.classList.add('active');
            console.log('Dialog overlay activated');
            
            void dialog.offsetHeight;
            
            var dialogBox = dialog.querySelector('.custom-dialog-box');
            if (dialogBox) {
                dialogBox.style.display = 'block';
                dialogBox.style.visibility = 'visible';
            }
        } else {
            console.error('Dialog overlay not found!');
        }
    }
    
    function dialogButtonClick(index) {
        var callback = window._dialogCallbacks ? window._dialogCallbacks[index] : null;
        if (callback && typeof callback === 'function') {
            callback();
        }
        closeDialog();
    }
    
    function closeDialog() {
        var dialog = document.getElementById('customDialog');
        if (dialog) {
            dialog.classList.remove('active');
            dialog.style.display = 'none';
            unlockSceneIQBackground(dialog);
            restoreSceneIQModal(dialog);
        }
        document.getElementById('dialogContent').innerHTML = '';
        window._dialogCallbacks = null;
    }
    
    document.getElementById('customDialog').addEventListener('click', function(e) {
        if (e.target === this) closeDialog();
    });
    
    function confirmAction(title, message, callback) {
        showDialog(title, message, [
            { label: 'Cancel', class: 'btn-secondary', callback: null },
            { label: 'Confirm', class: 'btn-confirm', callback: callback }
        ]);
    }

    // ===== LEFT MENU DRAWER =====
    function openMenuDrawer() {
        var overlay = document.getElementById('menuDrawerOverlay');
        if (!overlay) return;
        overlay.classList.add('active');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('menu-open');
    }
    function closeMenuDrawer(event) {
        if (event && event.target !== event.currentTarget) return;
        var overlay = document.getElementById('menuDrawerOverlay');
        if (!overlay) return;
        overlay.classList.remove('active');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('menu-open');
    }
    function toggleChangePasswordForm() {
        var form = document.getElementById('changePasswordForm');
        var toggle = document.getElementById('changePasswordToggle');
        if (!form || !toggle) return;
        var opening = form.style.display === 'none' || form.style.display === '';
        form.style.display = opening ? 'block' : 'none';
        toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
        if (opening) {
            var input = document.getElementById('newPassword');
            if (input) input.focus();
        }
    }

    function toggleDeleteDataOptions() {
        var options = document.getElementById('deleteDataOptions');
        var toggle = document.getElementById('deleteDataToggle');
        if (!options || !toggle) return;

        // Closing an already-open section does not need another confirmation.
        if (options.classList.contains('open')) {
            options.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
            return;
        }

        // Opening Delete Data is deliberately gated by the custom confirmation modal.
        showDialog('Open Delete Data?', 'Are you sure you want to open this?', [
            { label: 'No', class: 'btn-secondary', callback: null },
            {
                label: 'Yes',
                class: 'btn-confirm',
                callback: function() {
                    options.classList.add('open');
                    toggle.setAttribute('aria-expanded', 'true');
                }
            }
        ]);
    }

    function requestDeleteData(scope) {
        var deleteLabels = {
            dynamic_content: 'Dynamic content',
            dynamic_tabs: 'Dynamic tabs',
            dynamic_both: 'Dynamic contents and Dynamic tabs'
        };
        var selectedLabel = deleteLabels[scope];

        if (!selectedLabel) {
            showDialog('Invalid option', 'The selected delete option is not valid.');
            return;
        }

        // Close the drawer before displaying the final custom confirmation modal.
        closeMenuDrawer();

        confirmAction(
            'Final confirmation — Delete Data',
            'WARNING: You are about to permanently delete ' + selectedLabel +
                ' for this account. This action cannot be undone. Project entries, statuses, and other account settings will not be deleted. Do you want to continue?',
            function() {
                var form = document.getElementById('deleteDataForm');
                var scopeInput = document.getElementById('deleteDataScope');
                if (!form || !scopeInput) {
                    showDialog('Unable to delete data', 'The delete form could not be found. No data was deleted.');
                    return;
                }
                scopeInput.value = scope;
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }
        );
    }

    var changePasswordForm = document.getElementById('changePasswordForm');
    if (changePasswordForm) {
        changePasswordForm.addEventListener('submit', function(event) {
            var newPassword = document.getElementById('newPassword').value;
            var confirmPassword = document.getElementById('confirmPassword').value;
            var message = document.getElementById('passwordFormMessage');
            if (newPassword.length < 8) {
                event.preventDefault();
                message.textContent = 'Password must be at least 8 characters long.';
            } else if (newPassword !== confirmPassword) {
                event.preventDefault();
                message.textContent = 'The passwords do not match.';
            } else {
                message.textContent = '';
            }
        });
    }

    function goToDashboard() {
        closeMenuDrawer();
        var form = document.createElement('form');
        form.method = 'POST';
        var clear = document.createElement('input'); clear.type = 'hidden'; clear.name = 'clear_user'; clear.value = '1';
        form.appendChild(clear); document.body.appendChild(form); form.submit();
    }
    document.addEventListener('keydown', function(event) { if (event.key === 'Escape') closeMenuDrawer(); });

    // ============================================================
    // ===== DASHBOARD TAB SWITCHING =====
    // ============================================================

    function switchDashboardTab(tabName, btn) {
        var targetPanel = document.getElementById('dash_tab_' + tabName);
        if (!targetPanel) return;

        document.querySelectorAll('.dashboard-content').forEach(function(el) { el.classList.remove('active'); });
        document.querySelectorAll('.dashboard-tab-btn').forEach(function(el) { el.classList.remove('active'); });
        targetPanel.classList.add('active');
        if (btn) btn.classList.add('active');

        var formData = new FormData();
        formData.append('set_dashboard_view', 'true');
        formData.append('view', tabName);
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).catch(function(error) {
            console.error('Unable to save dashboard view:', error);
        });
    }

    // The account-copy controls are initialized by the dedicated SceneIQ multi-copy controller below.

    // ============================================================
    // ===== USER CONFIG TAB SWITCHING =====
    // ============================================================

    function switchUserConfigTab(tabName, btn) {
        document.querySelectorAll('.user-config-content').forEach(function(el) { el.classList.remove('active'); });
        document.querySelectorAll('.user-config-tab-btn').forEach(function(el) { el.classList.remove('active'); });
        document.getElementById('user_tab_' + tabName).classList.add('active');
        if (btn) btn.classList.add('active');
        if (tabName === 'pending') {
            renderStatusEntries('pending', 'pendingEntriesList');
        } else if (tabName === 'completed') {
            renderStatusEntries('completed', 'completedEntriesList');
        } else if (tabName === 'aborted') {
            renderStatusEntries('aborted', 'abortedEntriesList');
        } else if (tabName === 'dynamic_tabs') {
            renderDynamicTabsEditor();
        } else if (tabName === 'submit_tabs') {
            renderSubmitTabsEditor();
        } else if (tabName === 'dynamic_fields') {
            renderUserGlobalDynamicFields();
        }
    }

    function renderSubmitTabsEditor() {
        var container = document.getElementById('submitTabsEditor');
        if (!container) return;
        container.innerHTML = '';
        
        var tabs = userDynamicTabs || [];
        var globalFields = globalDynamicFields || {};
        var fieldKeys = Object.keys(globalFields);
        
        var html = '';
        
        if (tabs.length === 0) {
            html += '<p style="color:#6b7280; text-align:center; padding:20px;">No dynamic tabs configured. Please add tabs in the "Dynamic Tabs" tab first.</p>';
        } else {
            html += '<div style="margin-bottom:15px;">';
            html += '<p style="color:#6b7280; font-size:14px;">Toggle the switches below to control which tab\'s fields are submitted to entries.</p>';
            html += '<p style="color:#6b7280; font-size:14px; margin-top:5px;"><strong style="color:#10b981;">Green</strong> = Fields will be submitted &nbsp;|&nbsp; <strong style="color:#6b7280;">Gray</strong> = Fields will be excluded</p>';
            html += '</div>';
            
            html += '<form method="POST" id="submitTabsForm">';
            html += '<input type="hidden" name="save_submit_tabs" value="true">';
            html += '<input type="hidden" name="user_id" value="' + selectedUserId + '">';
            html += '<input type="hidden" name="submit_tabs_data" id="submitTabsDataInput">';
            
            tabs.forEach(function(tab, index) {
                var isSubmitted = tab.submit_tab !== undefined ? tab.submit_tab : true;
                var tabFields = tab.dynamic_fields_display || [];
                var fieldLabels = tabFields.map(function(fk) {
                    var field = globalFields[fk];
                    return field ? field['field-title'] || fk : fk;
                });
                
                html += '<div class="submit-tab-card tab-editor-item" style="border:1px solid ' + (isSubmitted ? '#10b981' : '#e5e7eb') + '; border-radius:10px; padding:15px; margin-bottom:12px; background:' + (isSubmitted ? '#f0fdf4' : '#fafafa') + ';">';
                html += '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">';
                html += '<div>';
                html += '<strong style="font-size:16px; color:#1a1a2e;">' + (tab.tab_name || 'Tab #' + (index + 1)) + '</strong>';
                html += '<div style="font-size:13px; color:#6b7280; margin-top:4px;">Fields: ' + (fieldLabels.length > 0 ? fieldLabels.join(', ') : 'No fields assigned') + '</div>';
                html += '</div>';
                html += '<div style="display:flex; align-items:center; gap:12px;">';
                html += '<span style="font-size:13px; color:' + (isSubmitted ? '#10b981' : '#6b7280') + '; font-weight:500;">' + (isSubmitted ? 'Submitted' : 'Excluded') + '</span>';
                html += '<label class="toggle-switch" style="position:relative; display:inline-block; width:50px; height:26px; flex-shrink:0;">';
                html += '<input type="checkbox" class="submit-tab-toggle" data-tab-index="' + index + '" ' + (isSubmitted ? 'checked' : '') + ' style="opacity:0; width:0; height:0;">';
                html += '<span class="toggle-slider" style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:' + (isSubmitted ? '#10b981' : '#ccc') + '; transition:.4s; border-radius:26px;"></span>';
                html += '</label>';
                html += '</div>';
                html += '</div>';
                html += '</div>';
            });
            
            html += '<div class="form-actions submit-tabs-save-bar">';
            html += '<button type="button" class="btn btn-success" onclick="saveSubmitTabs()">Save Submit Tabs Configuration</button>';
            html += '</div>';
            html += '</form>';
        }
        
        container.innerHTML = html;
    }

    function saveSubmitTabs() {
        var tabItems = document.querySelectorAll('.tab-editor-item');
        var updatedTabs = [];
        var processedIndices = new Set();
        
        tabItems.forEach(function(item) {
            var toggle = item.querySelector('.submit-tab-toggle');
            var indexAttr = item.querySelector('.submit-tab-toggle');
            if (!indexAttr) return;
            
            var index = parseInt(indexAttr.getAttribute('data-tab-index'));
            if (isNaN(index)) return;
            
            if (userDynamicTabs[index]) {
                var tab = userDynamicTabs[index];
                tab.submit_tab = toggle ? toggle.checked : true;
                updatedTabs.push(tab);
                processedIndices.add(index);
            }
        });
        
        userDynamicTabs.forEach(function(tab, idx) {
            if (!processedIndices.has(idx)) {
                if (tab.submit_tab === undefined) tab.submit_tab = true;
                updatedTabs.push(tab);
            }
        });
        
        updatedTabs = updatedTabs.map(function(tab) {
            if (tab.submit_tab === undefined) tab.submit_tab = true;
            return tab;
        });
        
        userDynamicTabs = updatedTabs;
        document.getElementById('submitTabsDataInput').value = JSON.stringify(updatedTabs);
        (document.getElementById('submitTabsForm').requestSubmit ? document.getElementById('submitTabsForm').requestSubmit() : document.getElementById('submitTabsForm').submit());
    }

    // ===== UPDATE renderUserGlobalDynamicFields FUNCTION =====
    // Replace the entire function with this version

    function toggleAccountDynamicFieldCard(card, event) {
        if (event && event.target && event.target.closest('button, a, input, select, textarea, label, .account-dynamic-field-details')) return;
        var body = card.querySelector('.account-dynamic-field-details');
        var icon = card.querySelector('.account-dynamic-field-chevron');
        if (!body) return;
        var opening = body.hidden;
        body.hidden = !opening;
        card.classList.toggle('is-expanded', opening);
        if (icon) icon.textContent = opening ? '−' : '+';
        card.setAttribute('aria-expanded', opening ? 'true' : 'false');
    }

    // Persist object sub-field order by rebuilding fieldkeyvalue in the requested order.
    // JavaScript object insertion order is retained by JSON.stringify and PHP json_decode.
    function moveAccountObjectSubField(fieldKey, subKey, direction, button) {
        var field = (globalDynamicFields || {})[fieldKey];
        if (!field || field.field_type !== 'objects') return;
        var current = field.fieldkeyvalue || {};
        var keys = Object.keys(current);
        var from = keys.indexOf(subKey);
        var to = from + (direction === 'up' ? -1 : 1);
        if (from < 0 || to < 0 || to >= keys.length) return;

        var reorderedKeys = keys.slice();
        var moved = reorderedKeys.splice(from, 1)[0];
        reorderedKeys.splice(to, 0, moved);
        var reordered = {};
        reorderedKeys.forEach(function(k) { reordered[k] = current[k]; });
        var previous = current;
        field.fieldkeyvalue = reordered;

        var card = button && button.closest('.account-dynamic-field-card');
        if (card) card.classList.add('is-saving-order');
        var formData = new FormData();
        formData.append('save_global_single_field', 'true');
        formData.append('user_id', selectedUserId);
        formData.append('field_data', JSON.stringify({
            'field-title': field['field-title'] || fieldKey,
            'field-name': fieldKey,
            'field_type': 'objects',
            'fieldkeyvalue': reordered
        }));

        fetch(window.location.href, { method: 'POST', body: formData })
            .then(function(response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function(result) {
                if (!result || !result.success) throw new Error((result && result.message) || 'The new order could not be saved.');
                renderUserGlobalDynamicFields();
                var cards = document.querySelectorAll('#userGlobalDynamicFieldsList .account-dynamic-field-card');
                Array.prototype.forEach.call(cards, function(c) {
                    var keyNode = c.querySelector('.account-dynamic-field-key');
                    if (keyNode && keyNode.textContent === 'Key: ' + fieldKey) {
                        var details = c.querySelector('.account-dynamic-field-details');
                        var icon = c.querySelector('.account-dynamic-field-chevron');
                        if (details) details.hidden = false;
                        c.classList.add('is-expanded');
                        c.setAttribute('aria-expanded', 'true');
                        if (icon) icon.textContent = '−';
                    }
                });
            })
            .catch(function(error) {
                field.fieldkeyvalue = previous;
                renderUserGlobalDynamicFields();
                if (typeof showDialog === 'function') showDialog('Order not saved', error.message || 'Could not save the new element order.');
            })
            .finally(function() {
                if (card) card.classList.remove('is-saving-order');
            });
    }

    function renderUserGlobalDynamicFields() {
        var container = document.getElementById('userGlobalDynamicFieldsList');
        if (!container) return;
        container.innerHTML = '';
        var fields = globalDynamicFields || {};
        var fieldKeys = Object.keys(fields);
        if (fieldKeys.length === 0) {
            container.innerHTML = '<p style="color:#6b7280;text-align:center;padding:20px;">No dynamic fields configured.</p>';
            return;
        }

        fieldKeys.forEach(function(key) {
            var field = fields[key] || {};
            var card = document.createElement('div');
            card.className = 'account-dynamic-field-card';
            card.setAttribute('role', 'button');
            card.setAttribute('tabindex', '0');
            card.setAttribute('aria-expanded', 'false');
            card.addEventListener('click', function(event) { toggleAccountDynamicFieldCard(card, event); });
            card.addEventListener('keydown', function(event) {
                if (event.target !== card) return;
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggleAccountDynamicFieldCard(card, event);
                }
            });

            var fieldType = field.field_type === 'objects' ? 'Objects field' : 'Individual field';
            var fieldInfo = '';
            var reorderHtml = '';
            if (field.field_type === 'objects') {
                var subFields = field.fieldkeyvalue || {};
                var subKeys = Object.keys(subFields);
                fieldInfo = '<div><strong>Type:</strong> Objects field</div>' +
                    '<div><strong>Elements:</strong> ' + subKeys.length + '</div>';
                if (subKeys.length) {
                    reorderHtml = '<div class="account-object-reorder"><div class="account-object-reorder-heading">' +
                        '<strong>Reposition elements</strong><small>Use the arrows to move an element above or below another element. Changes save automatically.</small></div>' +
                        '<ol class="account-object-reorder-list">' +
                        subKeys.map(function(sk, index) {
                            var sub = subFields[sk] || {};
                            var subElement = sub.element_type || 'input';
                            var extraInfo = subElement === 'button' ? ' (Button)' : '';
                            return '<li class="account-object-reorder-item" data-sub-key="' + escapeHtml(String(sk)) + '">' +
                                '<span class="account-object-position">' + (index + 1) + '</span>' +
                                '<span class="account-object-element-name"><strong>' + escapeHtml(String(sub['field-title'] || sk)) + '</strong><small>' + escapeHtml(String(sk)) + ' · ' + escapeHtml(String(subElement + extraInfo)) + '</small></span>' +
                                '<span class="account-object-move-controls">' +
                                '<button type="button" class="btn btn-secondary btn-sm account-object-move-up" aria-label="Move ' + escapeHtml(String(sk)) + ' up" ' + (index === 0 ? 'disabled' : '') + '>↑</button>' +
                                '<button type="button" class="btn btn-secondary btn-sm account-object-move-down" aria-label="Move ' + escapeHtml(String(sk)) + ' down" ' + (index === subKeys.length - 1 ? 'disabled' : '') + '>↓</button>' +
                                '</span></li>';
                        }).join('') + '</ol></div>';
                }
            } else {
                var defaultValue = field.default_value || '';
                var defaultValues = field.default_values || [];
                var elementType = field.element_type || 'input';
                var buttonUrl = field.button_url || '';
                var extraInfo = elementType === 'button' ? ' (URL: ' + buttonUrl + ')' : '';
                fieldInfo = '<div><strong>Type:</strong> Individual field</div>' +
                    '<div><strong>Element:</strong> ' + escapeHtml(String(elementType + extraInfo)) + '</div>' +
                    (defaultValue !== '' ? '<div class="account-dynamic-field-value"><strong>Default value:</strong><pre>' + escapeHtml(String(defaultValue)) + '</pre></div>' : '') +
                    (defaultValues.length ? '<div class="account-dynamic-field-value"><strong>' + (elementType === 'multiple-selections' ? 'Available values:' : 'Select options:') + '</strong><pre>' + defaultValues.map(function(v) { return escapeHtml(String(v)); }).join(', ') + '</pre></div>' : '');
            }

            card.innerHTML =
                '<div class="account-dynamic-field-heading">' +
                    '<div class="account-dynamic-field-title-wrap">' +
                        '<strong class="account-dynamic-field-title">' + escapeHtml(String(field['field-title'] || key)) + '</strong>' +
                        '<span class="account-dynamic-field-key">Key: ' + escapeHtml(String(key)) + '</span>' +
                    '</div>' +
                    '<div class="account-dynamic-field-heading-side">' +
                        '<span class="account-dynamic-field-type">' + escapeHtml(fieldType) + '</span>' +
                        '<span class="account-dynamic-field-chevron" aria-hidden="true">+</span>' +
                    '</div>' +
                '</div>' +
                '<div class="account-dynamic-field-details" hidden>' +
                    '<div class="account-dynamic-field-info">' + fieldInfo + '</div>' +
                    reorderHtml +
                    '<div class="account-dynamic-field-actions">' +
                        '<button type="button" class="btn btn-primary btn-sm account-field-edit">Edit Field</button>' +
                        '<button type="button" class="btn btn-danger btn-sm account-field-delete">Delete</button>' +
                    '</div>' +
                '</div>';

            var copyFieldButton = document.createElement('button');
            copyFieldButton.type = 'button';
            copyFieldButton.className = 'btn btn-secondary btn-sm account-field-copy';
            copyFieldButton.textContent = 'Copy field to';
            copyFieldButton.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                openCopyFieldToUsers(key);
            });
            var headingSide = card.querySelector('.account-dynamic-field-heading-side');
            var chevron = headingSide ? headingSide.querySelector('.account-dynamic-field-chevron') : null;
            if (headingSide) headingSide.insertBefore(copyFieldButton, chevron);

            var editButton = card.querySelector('.account-field-edit');
            var deleteButton = card.querySelector('.account-field-delete');
            editButton.addEventListener('click', function(event) {
                event.stopPropagation();
                editGlobalDynamicField(key);
            });
            deleteButton.addEventListener('click', function(event) {
                event.stopPropagation();
                deleteGlobalDynamicField(key);
            });
            card.querySelectorAll('.account-object-move-up').forEach(function(button) {
                button.addEventListener('click', function(event) {
                    event.preventDefault(); event.stopPropagation();
                    var item = button.closest('.account-object-reorder-item');
                    if (item) moveAccountObjectSubField(key, item.getAttribute('data-sub-key'), 'up', button);
                });
            });
            card.querySelectorAll('.account-object-move-down').forEach(function(button) {
                button.addEventListener('click', function(event) {
                    event.preventDefault(); event.stopPropagation();
                    var item = button.closest('.account-object-reorder-item');
                    if (item) moveAccountObjectSubField(key, item.getAttribute('data-sub-key'), 'down', button);
                });
            });
            container.appendChild(card);
        });
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function(ch) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];
        });
    }

    // ============================================================
    // ===== SWITCH STATUS SUB TAB =====
    // ============================================================

    function switchStatusSubTab(status, tabId, btn) {
        var container = document.getElementById(status + 'SubTabs');
        if (container) {
            container.querySelectorAll('.dynamic-sub-tab-btn').forEach(function(el) { el.classList.remove('active'); });
            if (btn) btn.classList.add('active');
        }
        var contentContainer = document.getElementById('user_tab_' + status);
        if (contentContainer) {
            contentContainer.querySelectorAll('.dynamic-sub-tab-content').forEach(function(el) { el.classList.remove('active'); });
            var target = document.getElementById(status + 'SubTab_' + tabId);
            if (target) {
                target.classList.add('active');
            }
        }
        if (tabId === 'all_fields') {
            renderStatusEntries(status, status + 'EntriesList');
        } else {
            var tabIndex = parseInt(tabId.replace('tab_', ''));
            if (!isNaN(tabIndex)) {
                renderStatusEntriesWithFilter(status, status + 'EntriesList_tab_' + tabIndex, tabIndex);
            }
        }
    }

    // ============================================================
    // ===== RENDER STATUS ENTRIES =====
    // ============================================================

    // Copy every record in the selected status as separate JSON objects.
    // Objects are comma-separated, with no surrounding JSON array, matching
    // the requested export format. Internal UI-only keys such as _index are excluded.
    function normalizeRecordTypesForCopy(record) {
        var normalized = {};
        Object.keys(record || {}).forEach(function(key) {
            if (key.charAt(0) === '_') return;
            var value = record[key];
            var definition = (typeof globalDynamicFields !== 'undefined' && globalDynamicFields)
                ? globalDynamicFields[key] : null;
            var dataType = definition && definition.data_type ? String(definition.data_type).toLowerCase() : 'string';

            // Repair older records that were stored as strings before JSON field types
            // were preserved. Export/copy them as actual objects/arrays/numbers/booleans.
            if (typeof value === 'string') {
                var trimmedValue = value.trim();
                var looksLikeJsonContainer = trimmedValue.charAt(0) === '{' || trimmedValue.charAt(0) === '[';
                var looksLikePropertyFragment = /^"[^"]+"\s*:/s.test(trimmedValue);
                var looksLikeEncodedJsonString = false;
                if (trimmedValue.charAt(0) === '"') {
                    try {
                        var outerValue = JSON.parse(trimmedValue);
                        if (typeof outerValue === 'string') {
                            var innerValue = outerValue.trim();
                            looksLikeEncodedJsonString = innerValue.charAt(0) === '{' ||
                                innerValue.charAt(0) === '[' || /^"[^"]+"\s*:/s.test(innerValue);
                        }
                    } catch (ignoreCopyJsonError) {}
                }
                if (dataType === 'json' || looksLikeJsonContainer || looksLikePropertyFragment || looksLikeEncodedJsonString) {
                    try {
                        value = parseTypedJsonValue(trimmedValue);
                    } catch (e) {
                        // Repair common pasted separators such as literal \\n between
                        // JSON records, while preserving escaped characters inside strings.
                        try {
                            var repairedJsonText = trimmedValue.replace(/^\uFEFF/, '');
                            var repairedChars = '';
                            var inJsonString = false;
                            var escapedJsonChar = false;
                            for (var jsonCharIndex = 0; jsonCharIndex < repairedJsonText.length; jsonCharIndex++) {
                                var jsonChar = repairedJsonText.charAt(jsonCharIndex);
                                if (inJsonString) {
                                    repairedChars += jsonChar;
                                    if (escapedJsonChar) {
                                        escapedJsonChar = false;
                                    } else if (jsonChar === '\\') {
                                        escapedJsonChar = true;
                                    } else if (jsonChar === '"') {
                                        inJsonString = false;
                                    }
                                } else {
                                    if (jsonChar === '"') {
                                        inJsonString = true;
                                        repairedChars += jsonChar;
                                    } else if (jsonChar === '\\' &&
                                        (repairedJsonText.charAt(jsonCharIndex + 1) === 'n' ||
                                         repairedJsonText.charAt(jsonCharIndex + 1) === 'r' ||
                                         repairedJsonText.charAt(jsonCharIndex + 1) === 't')) {
                                        // Drop literal escaped whitespace outside JSON strings.
                                        jsonCharIndex++;
                                    } else if (jsonChar.charCodeAt(0) >= 0x20 || jsonChar === '\n' || jsonChar === '\r' || jsonChar === '\t') {
                                        repairedChars += jsonChar;
                                    }
                                }
                            }
                            value = parseTypedJsonValue(repairedChars);
                        } catch (repairCopyJsonError) {
                            // Leave malformed legacy text as text rather than losing user data.
                        }
                    }
                }
            }

            if (definition && definition.field_type === 'objects' && value && typeof value === 'object' && !Array.isArray(value)) {
                var subDefinitions = definition.fieldkeyvalue || {};
                Object.keys(value).forEach(function(subKey) {
                    var subDefinition = subDefinitions[subKey] || {};
                    if (typeof value[subKey] === 'string') {
                        var nestedText = value[subKey].trim();
                        var nestedLooksJson = nestedText.charAt(0) === '{' || nestedText.charAt(0) === '[' ||
                            /^"(?:\\.|[^"\\])*"\s*:/s.test(nestedText);
                        if (String(subDefinition.data_type || 'string').toLowerCase() === 'json' || nestedLooksJson) {
                            try { value[subKey] = parseTypedJsonValue(nestedText); } catch (e) {}
                        }
                    }
                    if ((String(subDefinition.data_type || 'string').toLowerCase() === 'int' ||
                         String(subDefinition.data_type || 'string').toLowerCase() === 'integer') &&
                        typeof value[subKey] === 'string' && /^-?\d+$/.test(value[subKey].trim())) {
                        value[subKey] = Number(value[subKey]);
                    }
                });
            }
            normalized[key] = value;
        });
        return normalized;
    }

    function copyAllStatusEntries(status, button) {
        var records = (Array.isArray(userSettings) ? userSettings : []).filter(function(entry) {
            return String((entry && entry.status) || 'pending').toLowerCase() === status;
        }).map(normalizeRecordTypesForCopy);

        if (!records.length) {
            showDialog('Info', 'There are no ' + status + ' records to copy.');
            return;
        }

        // Pending data is copied as valid, flat root-level JSON: an array of records.
        // JSON.stringify safely escapes control characters and other characters that
        // would otherwise make the copied JSON invalid.
        var formatted;
        if (status === 'pending') {
            formatted = JSON.stringify(records, null, 2);
        } else {
            // Preserve the existing copy format for completed and aborted records.
            formatted = records.map(function(record) {
                return JSON.stringify(record, null, 2);
            }).join(',\\n');
        }

        copyToClipboard(formatted, button);
    }

    // Copy one complete entry without requiring the bulk status export.
    // Use the same type normalization as the bulk copy action so JSON and integer
    // values remain native JSON values in the copied object.
    function copySingleStatusEntry(index, button) {
        var entryIndex = Number(index);
        var sourceEntry = (Array.isArray(userSettings) ? userSettings : []).find(function(item, pos) { return Number(item && (item._db_id || item._index || pos)) === entryIndex; });
        if (!Number.isInteger(entryIndex) || entryIndex <= 0 || !sourceEntry) {
            showDialog('Copy Error', 'This entry could not be found. Refresh the account and try again.');
            return;
        }

        var record = normalizeRecordTypesForCopy(sourceEntry);
        copyToClipboard(JSON.stringify(record, null, 2), button);
    }

    function renderStatusEntries(status, containerId) {
        var container = document.getElementById(containerId);
        if (!container) return;
        var entries = userSettings.filter(function(entry, index) {
            var entryStatus = (entry.status || 'pending').toLowerCase();
            if (entryStatus === status) {
                entry._index = Number(entry._db_id || entry._index || index);
                return true;
            }
            return false;
        });
        // New entries are appended to userSettings, so reverse pending entries
        // for display only; stored order and the other statuses remain unchanged.
        if (status === 'pending') {
            entries.sort(function(a, b) { return b._index - a._index; });
        }
        renderEntryList(entries, container, status);
    }

    function renderStatusEntriesWithFilter(status, containerId, tabIndex) {
        var container = document.getElementById(containerId);
        if (!container) return;
        var tab = userDynamicTabs[tabIndex];
        if (!tab) {
            container.innerHTML = '<p style="color:#6b7280; text-align:center; padding:20px;">Tab not found.</p>';
            return;
        }
        var displayFields = tab.dynamic_fields_display || [];
        var entries = userSettings.filter(function(entry, index) {
            var entryStatus = (entry.status || 'pending').toLowerCase();
            if (entryStatus === status) {
                entry._index = Number(entry._db_id || entry._index || index);
                return true;
            }
            return false;
        });
        if (status === 'pending') {
            entries.sort(function(a, b) { return b._index - a._index; });
        }
        var filteredEntries = entries.map(function(entry) {
            var filtered = {};
            displayFields.forEach(function(fieldKey) {
                if (entry[fieldKey] !== undefined) {
                    filtered[fieldKey] = entry[fieldKey];
                }
            });
            filtered.status = entry.status;
            filtered.operation_status = entry.operation_status;
            filtered._index = entry._index;
            return filtered;
        });
        renderEntryList(filteredEntries, container, status);
    }

    function copyObjectValueFromAttr(button) {
        var text = button.getAttribute('data-copy-text');
        if (!text) {
            showDialog('Error', 'No text to copy');
            return;
        }
        var decodedText = text.replace(/\\n/g, '\n').replace(/\\"/g, '"').replace(/\\\\/g, '\\');
        copyToClipboard(decodedText);
    }

    // ============================================================
    // ===== RENDER ENTRY FIELDS =====
    // ============================================================

    function renderEntryFields(entry, index) {
        // Repair legacy values at render time too, so the field-level Copy button
        // copies a real JSON object/array instead of the old escaped string.
        entry = normalizeRecordTypesForCopy(entry || {});
        var displayFields = Object.keys(entry).filter(function(k) {
            return k !== 'status' && k !== 'operation_status' && k !== '_user' && k !== '_user_id' && k !== '_index' && k !== '_object';
        });
        if (displayFields.length === 0) {
            return '<div class="entry-details"><p style="color:#6b7280; text-align:center; padding:10px;">No fields available</p></div>';
        }
        var html = '<div class="entry-details">';
        var flattenedGroups = {};
        var individualFields = [];
        displayFields.forEach(function(key) {
            if (key.includes('_')) {
                var parts = key.split('_');
                var mainKey = parts[0];
                var subKey = parts.slice(1).join('_');
                if (!flattenedGroups[mainKey]) {
                    flattenedGroups[mainKey] = {};
                }
                flattenedGroups[mainKey][subKey] = entry[key];
            } else {
                individualFields.push(key);
            }
        });
        var hasFlattenedGroups = Object.keys(flattenedGroups).some(function(mainKey) {
            return Object.keys(flattenedGroups[mainKey]).length > 1;
        });
        if (hasFlattenedGroups) {
            for (var mainKey in flattenedGroups) {
                var subFields = flattenedGroups[mainKey];
                var subKeys = Object.keys(subFields);
                if (subKeys.length > 0) {
                    var objectId = 'obj_' + index + '_' + mainKey;
                    html += '<div class="entry-field-group object-field" id="' + objectId + '">';
                    html += '<span class="object-label">' + mainKey + '</span>';
                    html += '<div class="sub-fields-container">';
                    subKeys.forEach(function(subKey) {
                        var subValue = subFields[subKey];
                        var displayValue = typeof subValue === 'object' ? JSON.stringify(subValue, null, 2) : subValue;
                        var escapedValue = typeof displayValue === 'string' ? displayValue.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : displayValue;
                        var rawValue = typeof subValue === 'object' ? JSON.stringify(subValue) : subValue;
                        html +=
                            '<div class="sub-field-item" data-raw-value="' + encodeURIComponent(rawValue == null ? '' : String(rawValue)) + '">' +
                            '<span class="sub-label">' + subKey + '</span>' +
                            '<div class="sub-value">' + escapedValue + '</div>' +
                            '</div>';
                    });
                    html += '</div>';
                    var combinedText = subKeys.map(function(sk) {
                        var val = subFields[sk];
                        return typeof val === 'object' ? JSON.stringify(val) : val;
                    }).filter(function(v) { return v && v !== '' && v !== '{}'; }).join('\n');
                    html += '</div>';
                }
            }
        }
        displayFields.forEach(function(key) {
            var value = entry[key];
            if (key.includes('_')) {
                var parts = key.split('_');
                var mainKey = parts[0];
                if (flattenedGroups[mainKey] && Object.keys(flattenedGroups[mainKey]).length > 1) {
                    return;
                }
            }
            var isObjectField = (
                (typeof value === 'object' && 
                value !== null && 
                !Array.isArray(value) && 
                value.hasOwnProperty('_object') && 
                value._object === true)
                ||
                (typeof value === 'object' && 
                value !== null && 
                !Array.isArray(value) && 
                Object.keys(value).length > 0 && 
                !value.hasOwnProperty('_object'))
            );
            if (isObjectField) {
                var objectData = value;
                var subKeys = Object.keys(objectData).filter(function(k) { return k !== '_object'; });
                var objectId = 'obj_' + index + '_' + key;
                html += '<div class="entry-field-group object-field" id="' + objectId + '">';
                html += '<span class="object-label">' + key + '</span>';
                html += '<div class="sub-fields-container">';
                if (subKeys.length > 0) {
                    subKeys.forEach(function(subKey) {
                        var subValue = objectData[subKey];
                        var displayValue = typeof subValue === 'object' ? JSON.stringify(subValue, null, 2) : subValue;
                        var escapedValue = typeof displayValue === 'string' ?
                            displayValue.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') :
                            displayValue;
                        var rawValue = typeof subValue === 'object' ? JSON.stringify(subValue) : subValue;
                        html +=
                            '<div class="sub-field-item" data-raw-value="' + encodeURIComponent(rawValue == null ? '' : String(rawValue)) + '">' +
                            '<span class="sub-label">' + subKey + '</span>' +
                            '<div class="sub-value">' + escapedValue + '</div>' +
                            '</div>';
                    });
                } else {
                    html += 
                        '<div class="sub-field-item">' +
                        '<span class="sub-label">No sub-fields</span>' +
                        '<span class="sub-value">-</span>' +
                        '</div>';
                }
                html += '</div>';
                var combinedText = subKeys.map(function(sk) {
                    var val = objectData[sk];
                    return typeof val === 'object' ? JSON.stringify(val) : val;
                }).filter(function(v) { return v && v !== '' && v !== '{}'; }).join('\n');
                html += '</div>';
            } else if (!key.includes('_') || !hasFlattenedGroups) {
                var displayValue = typeof value === 'object' ? JSON.stringify(value, null, 2) : value;
                var escapedValue = typeof displayValue === 'string' ? displayValue.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : displayValue;
                var rawValue = typeof value === 'object' ? JSON.stringify(value) : value;
                var fieldId = 'field_' + index + '_' + key;
                html +=
                    '<div class="entry-field-group individual-field" id="' + fieldId + '" data-raw-value="' + encodeURIComponent(rawValue == null ? '' : String(rawValue)) + '">' +
                    '<span class="field-label">' + key + '</span>' +
                    '<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">' +
                    '<div class="field-value" style="flex:1;min-width:0;">' + escapedValue + '</div>' +
                    '<button class="btn btn-secondary btn-sm" onclick="copyIndividualFromDiv(\'' + fieldId + '\')">📋 Copy</button>' +
                    '</div>' +
                    '</div>';
            }
        });
        html += '</div>';
        return html;
    }

    function copyIndividualFromDiv(elementId) {
        var container = document.getElementById(elementId);
        if (!container) {
            showDialog('Error', 'Field not found');
            return;
        }
        var rawValue = container.getAttribute('data-raw-value');
        if (!rawValue) {
            showDialog('Info', 'No value to copy');
            return;
        }
        var textToCopy = decodeURIComponent(rawValue);
        if (textToCopy.trim().startsWith('{') || textToCopy.trim().startsWith('[')) {
            try {
                var parsed = JSON.parse(textToCopy);
                textToCopy = JSON.stringify(parsed, null, 2);
            } catch(e) {}
        }
        if (!textToCopy || textToCopy.trim() === '') {
            showDialog('Info', 'No value to copy');
            return;
        }
        copyToClipboard(textToCopy);
    }

    function copyObjectValuesFromDiv(containerId) {
        var container = document.getElementById(containerId);
        if (!container) {
            showDialog('Error', 'Object container not found');
            return;
        }
        var subItems = container.querySelectorAll('.sub-field-item');
        if (subItems.length === 0) {
            showDialog('Info', 'No sub-fields to copy');
            return;
        }
        var copyTexts = [];
        subItems.forEach(function(item) {
            var rawValue = item.getAttribute('data-raw-value');
            if (rawValue) {
                var value = decodeURIComponent(rawValue);
                if (value.trim().startsWith('{') || value.trim().startsWith('[')) {
                    try {
                        var parsed = JSON.parse(value);
                        value = JSON.stringify(parsed, null, 2);
                    } catch(e) {}
                }
                if (value && value.trim() !== '' && value.trim() !== '{}') {
                    copyTexts.push(value);
                }
            }
        });
        if (copyTexts.length === 0) {
            showDialog('Info', 'No values to copy');
            return;
        }
        var textToCopy = copyTexts.join('\n');
        copyToClipboard(textToCopy);
    }

    function renderEntryList(entries, container, status) {
        container.innerHTML = '';
        if (entries.length === 0) {
            container.innerHTML = '<p style="color:#6b7280; text-align:center; padding:20px;">No ' + status + ' entries found.</p>';
            return;
        }
        var statusColors = {
            'pending': '#f59e0b',
            'completed': '#10b981',
            'aborted': '#ef4444'
        };
        var statusLabels = {
            'pending': 'Pending',
            'completed': 'Completed',
            'aborted': 'Aborted'
        };
        entries.forEach(function(entry) {
            var div = document.createElement('div');
            div.className = 'config-item';
            var index = entry._index;
            var entryStatus = (entry.status || 'pending').toLowerCase();
            var fieldsHtml = renderEntryFields(entry, index);
            div.innerHTML = 
                '<div class="item-header">' +
                '<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">' +
                '<span class="item-id">Entry #' + (index + 1) + '</span>' +
                '</div>' +
                '<span class="item-status" style="background: ' + (statusColors[entryStatus] || '#6b7280') + '">' + (statusLabels[entryStatus] || entryStatus) + '</span>' +
                '</div>' +
                fieldsHtml +
                '<div class="item-actions">' +
                '<button type="button" class="btn btn-secondary btn-sm" onclick="copySingleStatusEntry(' + index + ', this)">📋 Copy Entry</button>' +
                '<form method="POST" style="display:inline;">' +
                '<input type="hidden" name="delete_entry" value="true">' +
                '<input type="hidden" name="user_id" value="' + selectedUserId + '">' +
                '<input type="hidden" name="index" value="' + index + '">' +
                '<button type="button" class="btn btn-danger btn-sm" onclick="confirmAction(\'Delete Entry\', \'Delete this entry?\', function(){ (this.closest(\'form\').requestSubmit ? this.closest(\'form\').requestSubmit() : this.closest(\'form\').submit()); }.bind(this))"> Delete</button>' +
                '</form>' +
                '<form method="POST" style="display:inline;">' +
                '<input type="hidden" name="update_entry_status" value="true">' +
                '<input type="hidden" name="user_id" value="' + selectedUserId + '">' +
                '<input type="hidden" name="index" value="' + index + '">' +
                '<select name="new_status" class="status-select" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">' +
                '<option value="pending" ' + (entryStatus === 'pending' ? 'selected' : '') + '> Pending</option>' +
                '<option value="completed" ' + (entryStatus === 'completed' ? 'selected' : '') + '> Completed</option>' +
                '<option value="aborted" ' + (entryStatus === 'aborted' ? 'selected' : '') + '> Aborted</option>' +
                '</select>' +
                '</form>' +
                '</div>';
            container.appendChild(div);
        });
    }

    // ============================================================
    // ===== DYNAMIC TABS EDITOR =====
    // ============================================================

    function renderDynamicTabsEditor() {
        var container = document.getElementById('dynamicTabsEditor');
        if (!container) return;

        var tabs = userDynamicTabs || [];
        var globalFields = globalDynamicFields || {};
        var fieldKeys = Object.keys(globalFields);

        function esc(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function fieldToggles(selected) {
            selected = Array.isArray(selected) ? selected : [];
            if (!fieldKeys.length) {
                return '<p class="tab-editor-empty-fields">No dynamic fields are available. Add fields in Account Dynamic Fields first.</p>';
            }
            return fieldKeys.map(function(key) {
                var checked = selected.indexOf(key) !== -1;
                var title = (globalFields[key] && globalFields[key]['field-title']) || key;
                return '<label class="tab-field-option">' +
                    '<span class="tab-field-label">' + esc(title) + ' <small>(' + esc(key) + ')</small></span>' +
                    '<input type="checkbox" class="field-toggle" data-field-key="' + esc(key) + '" ' + (checked ? 'checked' : '') + '>' +
                    '</label>';
            }).join('');
        }

        function features(featureSet) {
            var f = featureSet || {};
            return '<div class="tab-feature-options">' +
                '<label><input type="checkbox" class="feature-copy" ' + (f.copy_button ? 'checked' : '') + '> <span>Copy Data</span></label>' +
                '<label><input type="checkbox" class="feature-transcript" ' + (f.transcript_detection ? 'checked' : '') + '> <span>Transcript Detection</span></label>' +
                '<label><input type="checkbox" class="feature-structured" ' + (f.transcript_with_structured_data_detection ? 'checked' : '') + '> <span>Structured Data Detection</span></label>' +
                '</div>';
        }

        function renderTab(tab, index, isSub) {
            tab = tab || {};
            var children = Array.isArray(tab.sub_tabs) ? tab.sub_tabs : [];
            var subMode = children.length ? 'add' : (tab.sub_tabs_mode || 'none');
            var isParentWithChildren = !isSub && subMode === 'add';
            var title = tab.tab_name || (isSub ? 'Sub Tab ' + (index + 1) : 'Tab ' + (index + 1));
            var html = '<section class="tab-editor-item ' + (isSub ? 'sub-tab-editor-item' : 'root-tab-editor-item') + '" data-tab-index="' + index + '" data-is-sub="' + (isSub ? '1' : '0') + '">';
            html += '<div class="tab-editor-heading" onclick="toggleNestedTabEditor(this)" role="button" tabindex="0" aria-expanded="false">';
            html += '<div class="tab-editor-heading-copy"><span class="tab-editor-kind">' + (isSub ? 'SUB TAB' : 'MAIN TAB') + '</span><strong class="tab-editor-title">' + esc(title) + '</strong></div>';
            html += '<div class="tab-editor-actions"><button type="button" class="btn btn-secondary btn-sm reorder-move" style="display:none" onclick="event.stopPropagation();moveEditorItem(this,-1)">↑</button><button type="button" class="btn btn-secondary btn-sm reorder-move" style="display:none" onclick="event.stopPropagation();moveEditorItem(this,1)">↓</button><button type="button" class="btn btn-danger btn-sm tab-delete-button" onclick="event.stopPropagation();removeNestedTabEditor(this)">Delete</button></div></div>';
            html += '<div class="nested-tab-editor-body" style="display:none;">';
            html += '<div class="form-group"><label>' + (isSub ? 'Sub Tab Name' : 'Tab Name') + '</label><input type="text" class="tab-name-input" value="' + esc(tab.tab_name || '') + '" placeholder="' + (isSub ? 'Enter sub-tab name' : 'Enter main tab name') + '"></div>';
            var displayTab = tab.display_tab !== undefined ? !!tab.display_tab : true;
            html += '<div class="form-group display-tab-setting"><label>Display Tab in New Project</label><select class="display-tab-input"><option value="yes" ' + (displayTab ? 'selected' : '') + '>Yes</option><option value="no" ' + (!displayTab ? 'selected' : '') + '>No</option></select></div>';

            if (!isSub) {
                html += '<div class="form-group"><label>Add Sub Tabs</label><select class="sub-tabs-mode" onchange="toggleNestedSubTabs(this)">' +
                    '<option value="none" ' + (subMode === 'none' ? 'selected' : '') + '>No Sub Tabs</option>' +
                    '<option value="add" ' + (subMode === 'add' ? 'selected' : '') + '>Add New</option></select>' +
                    '<p class="tab-editor-help">When sub-tabs are enabled, the main tab is only a container. Its content is configured inside each sub-tab.</p></div>';
            }

            html += '<div class="root-content-settings" style="display:' + (isParentWithChildren ? 'none' : 'block') + ';">';
            html += '<div class="form-group"><label>Header</label><input type="text" class="tab-header-input" value="' + esc(tab.header || '') + '" placeholder="Enter header text..."></div>';
            html += '<div class="form-group"><label>Description</label><textarea class="tab-description-input" rows="2" placeholder="Enter description...">' + esc(tab.description || '') + '</textarea></div>';
            html += '<div class="form-group"><label>Fields to Display</label><div class="tab-fields-toggle-list">' + fieldToggles(tab.dynamic_fields_display) + '</div></div>';
            html += features(tab.additional_features);
            html += '</div>';

            if (!isSub) {
                html += '<div class="nested-sub-tabs-container" style="display:' + (subMode === 'add' ? 'block' : 'none') + ';">';
                html += '<div class="nested-sub-tabs-heading"><div><strong>Sub-tabs</strong><p>Add and configure the content for each sub-tab below.</p></div><div style="display:flex;gap:6px;flex-wrap:wrap"><button type="button" class="btn btn-secondary btn-sm" onclick="toggleSubTabReorder(this)">Reorder Sub Tabs</button><button type="button" class="btn btn-primary btn-sm" onclick="addNestedSubTab(this)">+ Add Sub Tab</button></div></div>';
                html += '<div class="nested-sub-tabs-list">';
                children.forEach(function(child, childIndex) { html += renderTab(child, childIndex, true); });
                html += '</div><p class="nested-sub-tabs-empty" style="display:' + (children.length ? 'none' : 'block') + ';">No sub-tabs yet. Select “Add Sub Tab” to create the first one.</p></div>';
            }
            html += '</div></section>';
            return html;
        }

        var html = '<div class="dynamic-tabs-editor-header"><div><h3>Dynamic Tabs</h3><p>Keep each tab simple. Main tabs with sub-tabs act as containers; their fields and features are configured on the sub-tabs.</p></div><div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="btn btn-secondary" onclick="toggleTabReorder(this)">Reorder Tabs</button><button type="button" class="btn btn-primary" onclick="addDynamicTab()">+ Add Tab</button></div></div>';
        if (!tabs.length) html += '<div class="tab-editor-empty-state"><strong>No dynamic tabs configured</strong><span>Click “Add Tab” to create your first tab.</span></div>';
        html += '<form method="POST" id="dynamicTabsForm"><input type="hidden" name="save_dynamic_tabs" value="true"><input type="hidden" name="user_id" value="' + esc(selectedUserId) + '"><input type="hidden" name="tabs_data" id="tabsDataInput"><div class="dynamic-tabs-editor-list">';
        // New tabs are inserted at the beginning so the newest editor is always first.
        tabs.forEach(function(tab, index) { html += renderTab(tab, index, false); });
        html += '</div><div class="dynamic-tabs-save-bar"><button type="button" class="btn btn-success" onclick="saveDynamicTabs()">Save All Tabs</button></div></form>';
        container.innerHTML = html;
    }

    // Display Tab in New Project is a simple Yes/No select for main and sub-tabs.
    // Do not attach toggle behavior to form controls; card expansion is handled by its heading.

    function toggleTabReorder(button) {
        var list = document.querySelector('#dynamicTabsForm .dynamic-tabs-editor-list');
        if (!list) return;
        var active = button.dataset.reordering !== '1';
        button.dataset.reordering = active ? '1' : '0';
        button.textContent = active ? 'Done Reordering' : 'Reorder Tabs';
        list.querySelectorAll(':scope > .root-tab-editor-item .reorder-move').forEach(function(b) { b.style.display = active ? 'inline-block' : 'none'; });
    }
    function toggleSubTabReorder(button) {
        var container = button.closest('.nested-sub-tabs-container');
        if (!container) return;
        var active = button.dataset.reordering !== '1';
        button.dataset.reordering = active ? '1' : '0';
        button.textContent = active ? 'Done Reordering' : 'Reorder Sub Tabs';
        container.querySelectorAll(':scope > .nested-sub-tabs-list > .sub-tab-editor-item .reorder-move').forEach(function(b) { b.style.display = active ? 'inline-block' : 'none'; });
    }
    function moveEditorItem(button, direction) {
        var item = button.closest('.tab-editor-item');
        if (!item) return;
        var parent = item.parentElement;
        var siblings = Array.from(parent.children).filter(function(el) { return el.classList.contains('tab-editor-item'); });
        var index = siblings.indexOf(item);
        var target = siblings[index + direction];
        if (!target) return;
        if (direction < 0) parent.insertBefore(item, target);
        else parent.insertBefore(target, item);
    }

    function toggleNestedTabEditor(source) {
        var item = source && source.closest ? source.closest('.tab-editor-item') : null;
        if (!item) return;
        var body = item.querySelector(':scope > .nested-tab-editor-body');
        var heading = item.querySelector(':scope > .tab-editor-heading');
        if (!body) return;
        var opening = body.style.display === 'none' || body.style.display === '';
        body.style.display = opening ? 'block' : 'none';
        item.classList.toggle('is-expanded', opening);
        item.setAttribute('aria-expanded', opening ? 'true' : 'false');
        if (heading) heading.setAttribute('aria-expanded', opening ? 'true' : 'false');
    }

    function removeNestedTabEditor(button) {
        var item = button.closest('.tab-editor-item');
        if (!item) return;
        if (item.classList.contains('root-tab-editor-item')) {
            var idx = parseInt(item.getAttribute('data-tab-index'), 10);
            deleteDynamicTab(idx);
        } else {
            var parentList = item.parentElement;
            item.remove();
            if (parentList && parentList.classList.contains('nested-sub-tabs-list')) {
                var emptyState = parentList.parentElement ? parentList.parentElement.querySelector('.nested-sub-tabs-empty') : null;
                if (emptyState && parentList.querySelectorAll(':scope > .sub-tab-editor-item').length === 0) {
                    emptyState.style.display = 'block';
                }
            }
        }
    }

    function toggleNestedSubTabs(select) {
        var item = select.closest('.tab-editor-item');
        if (!item) return;
        var enabled = select.value === 'add';
        var container = item.querySelector(':scope > .nested-tab-editor-body > .nested-sub-tabs-container');
        var ownSettings = item.querySelector(':scope > .nested-tab-editor-body > .root-content-settings');
        if (container) container.style.display = enabled ? 'block' : 'none';
        if (ownSettings) ownSettings.style.display = enabled ? 'none' : 'block';
        if (enabled) {
            item.querySelectorAll(':scope > .nested-tab-editor-body > .root-content-settings .field-toggle').forEach(function(el) { el.checked = false; });
            item.querySelectorAll(':scope > .nested-tab-editor-body > .root-content-settings .feature-copy, :scope > .nested-tab-editor-body > .root-content-settings .feature-transcript, :scope > .nested-tab-editor-body > .root-content-settings .feature-structured').forEach(function(el) { el.checked = false; });
            item.querySelectorAll(':scope > .nested-tab-editor-body > .root-content-settings .tab-header-input, :scope > .nested-tab-editor-body > .root-content-settings .tab-description-input').forEach(function(el) { el.value = ''; });
        }
    }

    function addNestedSubTab(button) {
        var list = button.closest('.nested-sub-tabs-container').querySelector('.nested-sub-tabs-list');
        if (!list) return;
        var index = list.querySelectorAll(':scope > .sub-tab-editor-item').length;
        var item = document.createElement('div');
        item.innerHTML = renderTemporarySubTab(index);
        list.appendChild(item.firstElementChild);
        var emptyState = button.closest('.nested-sub-tabs-container').querySelector('.nested-sub-tabs-empty');
        if (emptyState) emptyState.style.display = 'none';
    }

    function renderTemporarySubTab(index) {
        var fields = globalDynamicFields || {};
        var keys = Object.keys(fields);
        var toggles = keys.map(function(key) {
            var title = (fields[key] && fields[key]['field-title']) || key;
            return '<label class="tab-field-option"><span class="tab-field-label">' + String(title).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + ' <small>(' + String(key).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + ')</small></span><input type="checkbox" class="field-toggle" data-field-key="' + String(key).replace(/"/g,'&quot;') + '"></label>';
        }).join('');
        if (!toggles) toggles = '<p class="tab-editor-empty-fields">No dynamic fields are available. Add fields in Account Dynamic Fields first.</p>';
        return '<section class="tab-editor-item sub-tab-editor-item" data-tab-index="' + index + '" data-is-sub="1">' +
            '<div class="tab-editor-heading" onclick="toggleNestedTabEditor(this)" role="button" tabindex="0" aria-expanded="false"><div class="tab-editor-heading-copy"><span class="tab-editor-kind">SUB TAB</span><strong class="tab-editor-title">New Sub Tab</strong></div>' +
            '<div class="tab-editor-actions"><button type="button" class="btn btn-secondary btn-sm reorder-move" style="display:none" onclick="event.stopPropagation();moveEditorItem(this,-1)">↑</button><button type="button" class="btn btn-secondary btn-sm reorder-move" style="display:none" onclick="event.stopPropagation();moveEditorItem(this,1)">↓</button><button type="button" class="btn btn-danger btn-sm tab-delete-button" onclick="event.stopPropagation();removeNestedTabEditor(this)">Delete</button></div></div>' +
            '<div class="nested-tab-editor-body" style="display:none;"><div class="form-group"><label>Sub Tab Name</label><input type="text" class="tab-name-input" value="Sub Tab ' + (index + 1) + '" placeholder="Enter sub-tab name"></div>' +
            '<div class="form-group display-tab-setting"><label>Display Tab in New Project</label><select class="display-tab-input"><option value="yes" selected>Yes</option><option value="no">No</option></select></div>' +
            '<div class="form-group"><label>Header</label><input type="text" class="tab-header-input" value="" placeholder="Enter header text..."></div>' +
            '<div class="form-group"><label>Description</label><textarea class="tab-description-input" rows="2" placeholder="Enter description..."></textarea></div>' +
            '<div class="form-group"><label>Fields to Display</label><div class="tab-fields-toggle-list">' + toggles + '</div></div>' +
            '<div class="tab-feature-options"><label><input type="checkbox" class="feature-copy"> <span>Copy Data</span></label><label><input type="checkbox" class="feature-transcript"> <span>Transcript Detection</span></label><label><input type="checkbox" class="feature-structured"> <span>Structured Data Detection</span></label></div></div></section>';
    }

    function cleanEmptyTabs() {
        if (!userDynamicTabs || userDynamicTabs.length === 0) return;
        
        var cleaned = userDynamicTabs.filter(function(tab) {
            var hasName = tab.tab_name && tab.tab_name.trim() !== '';
            var hasFields = tab.dynamic_fields_display && tab.dynamic_fields_display.length > 0;
            return hasName || hasFields;
        });
        
        if (cleaned.length !== userDynamicTabs.length) {
            userDynamicTabs = cleaned;
            saveCleanTabs();
        }
    }

    function saveCleanTabs() {
        if (!selectedUserId || selectedUserId === 0) return;
        
        var formData = new FormData();
        formData.append('save_dynamic_tabs', 'true');
        formData.append('user_id', selectedUserId);
        formData.append('tabs_data', JSON.stringify(userDynamicTabs));
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-SceneIQ-Request': 'save' },
            credentials: 'same-origin'
        }).catch(function(error) {
            console.log('Error saving cleaned tabs:', error);
        });
    }

    function toggleTabEdit(index) {
        var content = document.getElementById('tabEditContent_' + index);
        var btn = document.getElementById('toggleTabBtn_' + index);
        if (content) {
            if (content.style.display === 'none' || content.style.display === '') {
                content.style.display = 'block';
                if (btn) btn.textContent = ' Hide Editor';
                if (userDynamicTabs[index]) {
                    userDynamicTabs[index]._expanded = true;
                }
            } else {
                content.style.display = 'none';
                if (btn) btn.textContent = ' Edit Tab';
                if (userDynamicTabs[index]) {
                    userDynamicTabs[index]._expanded = false;
                }
            }
        }
    }

    function addDynamicTab() {
        var tabs = userDynamicTabs || [];
        tabs.unshift({
            tab_name: 'New Tab ' + (tabs.length + 1),
            header: '',
            description: '',
            dynamic_fields_display: [],
            _expanded: false,
            submit_tab: true,
            display_tab: true,
            additional_features: {
                copy_button: false,
                transcript_detection: false,
                transcript_with_structured_data_detection: false
            }
        });
        userDynamicTabs = tabs;
        renderDynamicTabsEditor();
    }

    function deleteDynamicTab(index) {
        confirmAction('Delete Tab', 'Delete this tab? This action cannot be undone.', function() {
            var tabs = userDynamicTabs || [];
            if (index >= 0 && index < tabs.length) {
                tabs.splice(index, 1);
                userDynamicTabs = tabs;
                renderDynamicTabsEditor();
            }
        });
    }

    function saveDynamicTabs() {
        function collectTab(item, isSub) {
            var nameInput = item.querySelector(':scope > .nested-tab-editor-body > .form-group .tab-name-input') || item.querySelector('.tab-name-input');
            var body = item.querySelector('.nested-tab-editor-body');
            var headerInput = body ? body.querySelector('.tab-header-input') : item.querySelector('.tab-header-input');
            var descInput = body ? body.querySelector('.tab-description-input') : item.querySelector('.tab-description-input');
            var dynamicFieldsDisplay = [];
            ((body || item).querySelector('.tab-fields-toggle-list') || body || item).querySelectorAll('.field-toggle').forEach(function(toggle) { if (toggle.checked) dynamicFieldsDisplay.push(toggle.getAttribute('data-field-key')); });
            var featureRoot = body || item;
            var additionalFeatures = {
                copy_button: !!featureRoot.querySelector('.feature-copy:checked'),
                transcript_detection: !!featureRoot.querySelector('.feature-transcript:checked'),
                transcript_with_structured_data_detection: !!featureRoot.querySelector('.feature-structured:checked')
            };
            var result = {
                tab_name: nameInput ? nameInput.value.trim() : '',
                header: headerInput ? headerInput.value.trim() : '',
                description: descInput ? descInput.value.trim() : '',
                dynamic_fields_display: dynamicFieldsDisplay,
                display_tab: !!(item.querySelector('.display-tab-input') && item.querySelector('.display-tab-input').value === 'yes'),
                additional_features: additionalFeatures
            };
            var old = {};
            if (!isSub) {
                var idx = parseInt(item.getAttribute('data-tab-index'), 10);
                old = userDynamicTabs[idx] || {};
                var mode = item.querySelector('.sub-tabs-mode');
                result.sub_tabs_mode = mode ? mode.value : 'none';
                result.sub_tabs = [];
                if (result.sub_tabs_mode === 'add') {
                    // A parent with sub-tabs is a container only; its fields and feature toggles belong to children.
                    result.header = '';
                    result.description = '';
                    result.dynamic_fields_display = [];
                    result.additional_features = {
                        copy_button: false,
                        transcript_detection: false,
                        transcript_with_structured_data_detection: false
                    };
                    item.querySelectorAll(':scope > .nested-tab-editor-body > .nested-sub-tabs-container > .nested-sub-tabs-list > .sub-tab-editor-item').forEach(function(child) {
                        var c = collectTab(child, true); if (c.tab_name) result.sub_tabs.push(c);
                    });
                }
                result.submit_tab = old.submit_tab !== undefined ? old.submit_tab : true;
                result._expanded = old._expanded || false;
            } else {
                // The data-tab-index is the original child index, and survives DOM reordering.
                // Use it to retain the relational row identity when the editor is saved.
                var rootItem = item.closest('.root-tab-editor-item');
                var rootIndex = rootItem ? parseInt(rootItem.getAttribute('data-tab-index'), 10) : -1;
                var childIndex = parseInt(item.getAttribute('data-tab-index'), 10);
                var oldRoot = (rootIndex >= 0 && userDynamicTabs[rootIndex]) ? userDynamicTabs[rootIndex] : {};
                var oldChildren = Array.isArray(oldRoot.sub_tabs) ? oldRoot.sub_tabs : [];
                old = (childIndex >= 0 && oldChildren[childIndex]) ? oldChildren[childIndex] : {};
            }
            // Retain DB identity and any extension properties not currently represented
            // by visible controls. This prevents every Save Tabs operation from creating
            // replacement rows or silently dropping future tab metadata.
            ['_table_id','_tab_key'].forEach(function(key) {
                if (old[key] !== undefined) result[key] = old[key];
            });
            Object.keys(old).forEach(function(key) {
                if (key.charAt(0) === '_' || ['tab_name','header','description','dynamic_fields_display','display_tab','additional_features','sub_tabs','sub_tabs_mode','submit_tab','_expanded'].indexOf(key) !== -1) return;
                if (result[key] === undefined) result[key] = old[key];
            });
            return result;
        }
        var tabs = [];
        document.querySelectorAll('#dynamicTabsForm > .dynamic-tabs-editor-list > .root-tab-editor-item').forEach(function(item) {
            var tab = collectTab(item, false);
            if (tab.tab_name) tabs.push(tab);
        });
        userDynamicTabs = tabs;
        var input = document.getElementById('tabsDataInput');
        var form = document.getElementById('dynamicTabsForm');
        if (input && form) { input.value = JSON.stringify(tabs); if (form.requestSubmit) form.requestSubmit(); else form.submit(); }
    }

    // ============================================================
    // ===== SWITCH NEW PROJECT SUB TAB =====
    // ============================================================

    // Show Save Entry only when the currently displayed main tab is enabled
    // in Submit Tabs. Nested child tabs inherit their parent tab's setting.
    // Centralized diagnostics for why Save Entry is visible or hidden.
    // Works with both the original tab UI and the folder-navigation UI.
    function getActiveNewProjectContext() {
        var root = document.getElementById('user_tab_add_entry');
        if (!root) return { root: null, rootContent: null, scope: null, tabId: null, tabButton: null };

        // The folder UI marks the selected root/child with folder-active-content;
        // the classic UI uses .active. Resolve the root tab first.
        var rootContent = root.querySelector('.dynamic-sub-tab-content.folder-active-content[id^="newProjectSubTab_tab_"], .dynamic-sub-tab-content.active[id^="newProjectSubTab_tab_"]');
        if (!rootContent) {
            rootContent = root.querySelector('.dynamic-sub-tab-content.folder-active-content[id^="newProjectSubTab_"], .dynamic-sub-tab-content.active[id^="newProjectSubTab_"]');
        }

        var scope = rootContent;
        if (rootContent) {
            // For nested tabs, only collect/show the button belonging to the selected child.
            var child = rootContent.querySelector('.nested-child-content.folder-active-content') ||
                        rootContent.querySelector('.nested-child-content.active');
            if (child) scope = child;
        }

        var tabId = null;
        var tabButton = null;
        if (rootContent && rootContent.id && rootContent.id !== 'newProjectSubTab_all_fields') {
            tabId = rootContent.id.replace('newProjectSubTab_', '');
            var nav = document.getElementById('newProjectSubTabs');
            tabButton = nav ? Array.from(nav.querySelectorAll('.dynamic-sub-tab-btn')).find(function(button) {
                return button.getAttribute('data-tab') === tabId;
            }) : null;
        }
        return { root: root, rootContent: rootContent, scope: scope, tabId: tabId, tabButton: tabButton };
    }

    function updateSaveEntryButtonVisibility() {
        var context = getActiveNewProjectContext();
        var root = context.root;
        var activeContent = context.rootContent;
        var activeScope = context.scope;
        var tabsBar = document.getElementById('newProjectSubTabs');
        var tabButton = context.tabButton;
        var tabId = context.tabId;
        var enabled = !!(tabButton && tabButton.getAttribute('data-submit-enabled') === '1');

        // There is one Save Entry button per rendered form. Never use getElementById:
        // it would always select the first button (inside the hidden All Fields form).
        var saveButton = activeScope ? activeScope.querySelector('.saveEntryButton') : null;
        var reason = '';
        if (!root) reason = 'New Project container #user_tab_add_entry was not found.';
        else if (!activeContent) reason = 'No active main dynamic tab was found.';
        else if (activeContent.id === 'newProjectSubTab_all_fields') reason = 'All Fields view is selected; only configured Submit Tabs main tabs can save.';
        else if (!tabButton) reason = 'Active content has no matching main tab button in #newProjectSubTabs.';
        else if (!saveButton) reason = 'The selected tab/child has no rendered form or Save Entry button.';
        else reason = enabled ? 'Active tab is enabled in Submit Tabs.' : 'Active tab data-submit-enabled is not 1.';

        // Hide buttons in all other (inactive) forms so stale visibility cannot leak
        // when navigating between root tabs, nested tabs, or the folder interface.
        if (root) {
            root.querySelectorAll('.saveEntryButton').forEach(function(button) {
                var showThisButton = !!(enabled && saveButton && button === saveButton);
                button.style.display = showThisButton ? 'inline-flex' : 'none';
                button.hidden = !showThisButton;
                button.setAttribute('aria-hidden', showThisButton ? 'false' : 'true');
            });
        }

        var debugState = {
            selectedUserId: selectedUserId,
            folderNavigationActive: !!document.getElementById('sceneiqFolderRoot'),
            addEntryRootFound: !!root,
            tabsBarFound: !!tabsBar,
            activeRootContentId: activeContent ? activeContent.id : null,
            activeRootContentClasses: activeContent ? activeContent.className : null,
            activeScopeId: activeScope ? activeScope.id : null,
            activeScopeClasses: activeScope ? activeScope.className : null,
            matchingTabId: tabId,
            matchingTabFound: !!tabButton,
            matchingTabSubmitEnabled: tabButton ? tabButton.getAttribute('data-submit-enabled') : null,
            saveButtonFoundInActiveScope: !!saveButton,
            saveButtonDisplay: saveButton ? saveButton.style.display : null,
            saveButtonComputedDisplay: saveButton ? getComputedStyle(saveButton).display : null,
            saveButtonHidden: saveButton ? saveButton.hidden : null,
            enabled: enabled,
            reason: reason
        };
        console.groupCollapsed('[SceneIQ Save Entry Debug] Visibility check');
        console.log(debugState);
        if (!enabled || !saveButton) console.warn('[SceneIQ Save Entry Debug] Save Entry is hidden:', reason);
        else console.info('[SceneIQ Save Entry Debug] Save Entry is enabled for active tab:', tabId);
        console.groupEnd();
    }

    function switchNewProjectNestedSubTab(btn) {
        var wrapper = btn.closest('.nested-project-tabs');
        if (!wrapper) return;
        wrapper.querySelectorAll('.nested-child-btn').forEach(function(b) { b.classList.remove('active'); });
        wrapper.querySelectorAll('.nested-child-content').forEach(function(c) { c.classList.remove('active'); c.style.display = 'none'; });
        btn.classList.add('active');
        var index = Array.prototype.indexOf.call(wrapper.querySelectorAll('.nested-child-btn'), btn);
        var contents = wrapper.querySelectorAll('.nested-child-content');
        if (contents[index]) { contents[index].classList.add('active'); contents[index].style.display = 'block'; }
        console.info('[SceneIQ Save Entry Debug] Nested tab switched', {
            label: (btn.textContent || '').trim(),
            activeChildId: contents[index] ? contents[index].id : null,
            parentContentId: (wrapper.closest('.dynamic-sub-tab-content') || {}).id || null
        });
        updateSaveEntryButtonVisibility();

        var resolvedFeatures = (typeof getActiveTabFeatures === 'function')
            ? getActiveTabFeatures()
            : { copy_button: false, transcript_detection: false, transcript_with_structured_data_detection: false };
        console.info('[SceneIQ Transcript Detection] CHILD TAB CLICKED', {
            child_tab_name: (btn.textContent || '').trim(),
            child_index: index,
            active_root_id: (wrapper.closest('.dynamic-sub-tab-content') || {}).id || null,
            active_child_id: contents[index] ? contents[index].id : null,
            feature_flags: resolvedFeatures,
            transcript_detection_enabled: sceneIQFeatureEnabled(resolvedFeatures.transcript_detection)
        });
    }

    function switchNewProjectSubTab(tabId, btn) {
        document.querySelectorAll('#user_tab_add_entry .dynamic-sub-tab-content').forEach(function(el) { el.classList.remove('active'); });
        document.querySelectorAll('#user_tab_add_entry #newProjectSubTabs .dynamic-sub-tab-btn').forEach(function(el) { el.classList.remove('active'); });
        var target = document.getElementById('newProjectSubTab_' + tabId);
        if (target) {
            target.classList.add('active');
            // Selecting a main tab always starts on its first child sub-tab.
            var nested = target.querySelector('.nested-project-tabs');
            if (nested) {
                var childButtons = nested.querySelectorAll('.nested-child-btn');
                var childContents = nested.querySelectorAll('.nested-child-content');
                childButtons.forEach(function(childButton, index) {
                    childButton.classList.toggle('active', index === 0);
                });
                childContents.forEach(function(childContent, index) {
                    childContent.classList.toggle('active', index === 0);
                    childContent.style.display = index === 0 ? 'block' : 'none';
                });
            }
        }
        if (btn) btn.classList.add('active');
        console.info('[SceneIQ Save Entry Debug] Main tab switched', {
            requestedTabId: tabId,
            targetFound: !!target,
            targetId: target ? target.id : null,
            buttonDataTab: btn ? btn.getAttribute('data-tab') : null,
            buttonSubmitEnabled: btn ? btn.getAttribute('data-submit-enabled') : null,
            targetClasses: target ? target.className : null
        });
        updateSaveEntryButtonVisibility();

        // Explicit diagnostic: confirm which root tab was clicked and which features
        // the application resolves for that tab after the active state has changed.
        var resolvedFeatures = (typeof getActiveTabFeatures === 'function')
            ? getActiveTabFeatures()
            : { copy_button: false, transcript_detection: false, transcript_with_structured_data_detection: false };
        console.info('[SceneIQ Transcript Detection] ROOT TAB CLICKED', {
            tab_id: tabId,
            tab_name: btn ? (btn.textContent || '').trim() : '(button not supplied)',
            active_content_id: target ? target.id : null,
            feature_flags: resolvedFeatures,
            transcript_detection_enabled: sceneIQFeatureEnabled(resolvedFeatures.transcript_detection)
        });
    }

    // ============================================================
    // ===== SUBMIT USER ENTRY =====
    // ============================================================

    document.addEventListener('DOMContentLoaded', function() {
        /*
         * FIX: If the first visible tab is not enabled in Submit Tabs, automatically
         * select the first enabled main tab. Previously the page always opened on
         * the first visible tab (e.g. "Pov Niches") even when only a later tab
         * (e.g. "Submit Project") was enabled, so Save Entry correctly stayed hidden
         * and appeared to be missing.
         *
         * This does not bypass Submit Tabs. It only navigates to an enabled tab;
         * disabled tabs still keep Save Entry hidden when selected manually.
         */
        var initialTabsBar = document.getElementById('newProjectSubTabs');
        var initialActiveContent = document.querySelector(
            '#user_tab_add_entry .dynamic-sub-tab-content.folder-active-content, ' +
            '#user_tab_add_entry .dynamic-sub-tab-content.active'
        );
        var initialActiveId = initialActiveContent && initialActiveContent.id
            ? initialActiveContent.id.replace('newProjectSubTab_', '')
            : null;
        var initialActiveButton = initialTabsBar
            ? Array.from(initialTabsBar.querySelectorAll('.dynamic-sub-tab-btn')).find(function(button) {
                return button.getAttribute('data-tab') === initialActiveId;
            })
            : null;

        if (initialTabsBar && (!initialActiveButton ||
            initialActiveButton.getAttribute('data-submit-enabled') !== '1')) {
            var firstEnabledTabButton = Array.from(
                initialTabsBar.querySelectorAll('.dynamic-sub-tab-btn')
            ).find(function(button) {
                return button.getAttribute('data-submit-enabled') === '1';
            });

            if (firstEnabledTabButton) {
                var enabledTabId = firstEnabledTabButton.getAttribute('data-tab');
                console.info('[SceneIQ Save Entry Debug] Initial tab is not submit-enabled; opening first enabled tab.', {
                    previousTabId: initialActiveId,
                    selectedTabId: enabledTabId,
                    selectedTabName: (firstEnabledTabButton.textContent || '').trim()
                });
                switchNewProjectSubTab(enabledTabId, firstEnabledTabButton);
            }
        }

        console.info('[SceneIQ Save Entry Debug] DOMContentLoaded initial state', {
            selectedUserId: selectedUserId,
            dynamicTabsCount: Array.isArray(userDynamicTabs) ? userDynamicTabs.length : null,
            dynamicFieldCount: Object.keys(globalDynamicFields || {}).length,
            tabs: (Array.isArray(userDynamicTabs) ? userDynamicTabs : []).map(function(tab, index) {
                return {
                    index: index,
                    name: tab.tab_name || null,
                    display_tab: tab.display_tab,
                    submit_tab: tab.submit_tab,
                    fieldCount: Array.isArray(tab.dynamic_fields_display) ? tab.dynamic_fields_display.length : 0,
                    subTabsCount: Array.isArray(tab.sub_tabs) ? tab.sub_tabs.length : 0
                };
            }),
            saveButtonExists: !!document.getElementById('saveEntryButton'),
            tabsBarExists: !!document.getElementById('newProjectSubTabs')
        });
        updateSaveEntryButtonVisibility();
        // Run again after folder navigation has had a chance to initialize/move content.
        window.setTimeout(function() {
            updateSaveEntryButtonVisibility();
        }, 150);
    });

    function parseTypedJsonValue(value) {
        if (value !== null && typeof value === 'object') return value;
        var text = String(value == null ? '' : value).trim();
        if (text === '') return null;
        try {
            var parsed = JSON.parse(text);
            // Some form/browser paths may submit JSON as a JSON-encoded string
            // (for example, "{\\\"script_json\\\": {...}}"). Unwrap that extra
            // string layer only when its contents are themselves JSON.
            if (typeof parsed === 'string') {
                var inner = parsed.trim();
                if (inner.charAt(0) === '{' || inner.charAt(0) === '[' || /^"[^"]+"\s*:/s.test(inner)) {
                    return parseTypedJsonValue(inner);
                }
            }
            return parsed;
        } catch (firstError) {
            // Accept pasted JSON property fragments such as:
            //   "script_json": { ... }
            // and turn them into a proper object instead of saving a string.
            if (/^"[^"]+"\s*:/s.test(text)) {
                try { return JSON.parse('{' + text + '}'); } catch (secondError) {}
            }
            throw new Error('Enter valid JSON. Use a complete object/array/value, or a property fragment that can be wrapped in { }. Check quotes, commas, braces and brackets.');
        }
    }

    function convertEntryValueByType(value, type) {
        type = String(type || 'string').toLowerCase();
        if (typeof value === 'string') {
            var trimmed = value.trim();
            var looksLikeJsonContainer = trimmed.charAt(0) === '{' || trimmed.charAt(0) === '[';
            var looksLikePropertyFragment = /^"[^"]+"\s*:/s.test(trimmed);
            var looksLikeEncodedJsonString = false;
            if (trimmed.charAt(0) === '"') {
                try {
                    var outerParsed = JSON.parse(trimmed);
                    if (typeof outerParsed === 'string') {
                        var innerParsedText = outerParsed.trim();
                        looksLikeEncodedJsonString = innerParsedText.charAt(0) === '{' ||
                            innerParsedText.charAt(0) === '[' || /^"[^"]+"\s*:/s.test(innerParsedText);
                    }
                } catch (ignoreEncodedJsonError) {}
            }
            if (type === 'json' || looksLikeJsonContainer || looksLikePropertyFragment || looksLikeEncodedJsonString) {
                return parseTypedJsonValue(trimmed);
            }
        }
        if (type === 'json') return parseTypedJsonValue(value);
        if (type === 'int' || type === 'integer') {
            if (value === '') return '';
            if (!/^-?\d+$/.test(String(value).trim())) {
                throw new Error('Enter a whole number without decimals or other characters.');
            }
            var n = Number(value);
            if (!Number.isSafeInteger(n)) throw new Error('Integer is outside the supported safe range.');
            return n;
        }
        return String(value);
    }

    async function postEntryUploadRequest(formData) {
        var response = await fetch(window.location.href.split('#')[0], {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        var responseText = await response.text();
        var result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            throw new Error('The server returned an invalid response while saving. Check PHP errors and hosting limits.');
        }

        if (!response.ok || !result || result.success !== true) {
            throw new Error((result && result.message) ? result.message : 'The server rejected an upload request.');
        }
        return result;
    }

    async function uploadEntryInChunks(activeForm, jsonText, saveButton) {
        if (activeForm.dataset.entryUploadRunning === '1') return;
        activeForm.dataset.entryUploadRunning = '1';

        var originalButtonText = saveButton ? saveButton.textContent : 'Save Entry';
        if (saveButton) {
            saveButton.disabled = true;
            saveButton.textContent = 'Preparing upload...';
        }
        var uploadId = null;
        var csrfInput = activeForm.querySelector('.entryUploadCsrf');
        var userIdInput = activeForm.querySelector('input[name="user_id"]');
        var csrfToken = csrfInput ? csrfInput.value : '';
        var userId = userIdInput ? userIdInput.value : '';
        var maxBytes = 64 * 1024 * 1024;

        try {
            if (!csrfToken || !userId) {
                throw new Error('The upload security token or selected account is missing. Refresh the page and try again.');
            }

            var uploadBlob = new Blob([jsonText], { type: 'application/octet-stream' });
            if (uploadBlob.size <= 0) throw new Error('There is no entry data to save.');
            if (uploadBlob.size > maxBytes) throw new Error('This entry is larger than the 64 MB upload limit. Reduce the content and try again.');

            var chunkSize = 48 * 1024;
            var totalChunks = Math.ceil(uploadBlob.size / chunkSize);

            var startData = new FormData();
            startData.append('entry_upload_start', '1');
            startData.append('user_id', userId);
            startData.append('total_bytes', String(uploadBlob.size));
            startData.append('total_chunks', String(totalChunks));
            startData.append('csrf_token', csrfToken);

            var started = await postEntryUploadRequest(startData);
            uploadId = started.upload_id;
            if (!uploadId) throw new Error('The server did not create an upload session.');

            for (var chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                var startOffset = chunkIndex * chunkSize;
                var endOffset = Math.min(startOffset + chunkSize, uploadBlob.size);
                var chunkBlob = uploadBlob.slice(startOffset, endOffset);

                var chunkData = new FormData();
                chunkData.append('entry_upload_chunk', '1');
                chunkData.append('upload_id', uploadId);
                chunkData.append('chunk_index', String(chunkIndex));
                chunkData.append('csrf_token', csrfToken);
                chunkData.append('chunk_data', chunkBlob, 'entry-chunk.bin');

                await postEntryUploadRequest(chunkData);

                var percent = Math.round((endOffset / uploadBlob.size) * 100);
                if (saveButton) saveButton.textContent = 'Saving ' + percent + '%...';
            }

            var finishData = new FormData();
            finishData.append('entry_upload_finish', '1');
            finishData.append('upload_id', uploadId);
            finishData.append('csrf_token', csrfToken);
            await postEntryUploadRequest(finishData);

            if (saveButton) saveButton.textContent = 'Saved';
            await sceneIQRefreshAfterSave(null, 'Project saved successfully.', { refreshProjectMarkup: false });
            activeForm.dataset.entryUploadRunning = '0';
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = originalButtonText;
            }
        } catch (error) {
            if (uploadId && csrfToken) {
                try {
                    var cancelData = new FormData();
                    cancelData.append('entry_upload_cancel', '1');
                    cancelData.append('upload_id', uploadId);
                    cancelData.append('csrf_token', csrfToken);
                    await postEntryUploadRequest(cancelData);
                } catch (ignoreCancelError) {}
            }

            activeForm.dataset.entryUploadRunning = '0';
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = originalButtonText;
            }
            showDialog('Save failed', error && error.message ? error.message : 'The entry could not be saved. Please try again.');
        }
    }

    function submitUserEntry() {
        var context = getActiveNewProjectContext();
        var activeContent = context.rootContent;
        var activeScope = context.scope;
        if (!activeContent || !activeScope) {
            showDialog('Error', 'No active project tab or form found.');
            return;
        }

        updateSaveEntryButtonVisibility();
        var saveButton = activeScope.querySelector('.saveEntryButton');
        if (!saveButton || saveButton.hidden || saveButton.style.display === 'none') {
            showDialog('Info', 'Saving entries is disabled for the currently displayed tab in Submit Tabs, or this tab has no form.');
            return;
        }

        var activeForm = activeScope.querySelector('form.addEntryForm');
        if (!activeForm) {
            showDialog('Error', 'The selected tab does not contain a project form.');
            return;
        }

        var inputs = activeScope.querySelectorAll('input, textarea, select');
        var entryData = {};
        var objectContainers = activeScope.querySelectorAll('[id^="object_"]');
        inputs.forEach(function(input) {
            // Object sub-fields are serialized together under their parent object below.
            // Do not also treat entry_parent_subfield as an unrelated top-level field.
            if (input.closest && input.closest('[id^="object_"][id$="_container"]')) return;
            if (input.id && input.id.startsWith('entry_')) {
                var key = input.id.replace('entry_', '');
                if (key.includes('_date') || key.includes('_hour_12') || key.includes('_minute_12') || key.includes('_ampm')) return;
                var fieldDefinition = globalDynamicFields[key] || {};
                var valueType = fieldDefinition.data_type || 'string';
                try {
                    if (fieldDefinition.element_type === 'multiple-selections') {
                        var selectedValues = JSON.parse(input.value || '[]');
                        if (!Array.isArray(selectedValues)) throw new Error('Multiple selections must be stored as a JSON array.');
                        selectedValues = selectedValues.map(function(value) { return String(value); }).filter(function(value) { return value.trim() !== ''; });
                        if (selectedValues.length) entryData[key] = selectedValues;
                    } else {
                        entryData[key] = convertEntryValueByType(input.value, valueType);
                    }
                } catch (typeError) {
                    showDialog('Invalid ' + (fieldDefinition['field-title'] || key), typeError.message);
                    throw typeError;
                }
            }
        });
        objectContainers.forEach(function(container) {
            var objectKey = container.id.replace('object_', '').replace('_container', '');
            var subInputs = container.querySelectorAll('input, textarea, select');
            var objectData = { _object: true };
            subInputs.forEach(function(input) {
                if (input.id && input.id.startsWith('entry_' + objectKey + '_')) {
                    var subKey = input.id.replace('entry_' + objectKey + '_', '');
                    if (subKey.includes('_date') || subKey.includes('_hour_12') || subKey.includes('_minute_12') || subKey.includes('_ampm')) return;
                    var parentDefinition = globalDynamicFields[objectKey] || {};
                    var subDefinition = (parentDefinition.fieldkeyvalue || {})[subKey] || {};
                    try {
                        if (subDefinition.element_type === 'multiple-selections') {
                            var nestedSelectedValues = JSON.parse(input.value || '[]');
                            if (!Array.isArray(nestedSelectedValues)) {
                                throw new Error('Multiple selections must be stored as a JSON array.');
                            }
                            objectData[subKey] = nestedSelectedValues.map(function(value) {
                                return String(value);
                            }).filter(function(value) { return value.trim() !== ''; });
                        } else {
                            objectData[subKey] = convertEntryValueByType(input.value, subDefinition.data_type || 'string');
                        }
                    } catch (typeError) {
                        showDialog('Invalid ' + (subDefinition['field-title'] || subKey), typeError.message);
                        throw typeError;
                    }
                }
            });
            var hasObjectData = Object.keys(objectData).some(function(key) {
                return key !== '_object' && objectData[key] !== '';
            });
            if (hasObjectData) entryData[objectKey] = objectData;
        });

        var hasData = Object.values(entryData).some(function(value) {
            return value !== '';
        });
        if (!hasData) {
            showDialog('Error', 'Please enter at least one field value.');
            return;
        }

        var entryDataInput = activeForm.querySelector('.entryDataInput');
        if (!entryDataInput) {
            showDialog('Error', 'The selected form is missing its entry data input.');
            return;
        }
        var serializedEntry = JSON.stringify(entryData);
        // Keep the hidden field for compatibility, but upload the actual payload in
        // 48 KB chunks so large transcripts do not exceed PHP's POST request limit.
        entryDataInput.value = '';
        uploadEntryInChunks(activeForm, serializedEntry, saveButton);
    }

    // Legacy renderer retained for compatibility with account-scoped field editor helpers.
    // The global dashboard Dynamic Fields tab has been removed.
    function renderGlobalDynamicFields() {
        var container = document.getElementById('globalDynamicFieldsList');
        if (!container) return;
        container.innerHTML = '';
        var fields = globalDynamicFields;
        var fieldKeys = Object.keys(fields);
        if (fieldKeys.length === 0) {
            container.innerHTML = '<p style="color:#6b7280; text-align:center; padding:20px;">No dynamic fields configured.</p>';
            return;
        }
        for (var i = 0; i < fieldKeys.length; i++) {
            var key = fieldKeys[i];
            var field = fields[key];
            var div = document.createElement('div');
            div.className = 'form-group';
            div.style.border = '1px solid #e5e7eb';
            div.style.borderRadius = '10px';
            div.style.padding = '15px';
            div.style.marginBottom = '12px';
            div.style.background = '#fafafa';
            var fieldInfo = '';
            var editFieldsHtml = '';
            if (field.field_type === 'objects') {
                var subFields = field.fieldkeyvalue || {};
                var subKeys = Object.keys(subFields);
                fieldInfo = 
                    '<div><strong>Type:</strong> Objects Field</div>' +
                    '<div><strong>Sub-fields:</strong> ' + subKeys.length + '</div>' +
                    subKeys.map(function(sk) { 
                        var sub = subFields[sk];
                        var subElement = sub.element_type || 'input';
                        var extraInfo = subElement === 'button' ? ' (Button → ' + (sub.button_url || 'No URL') + ')' : '';
                        return '<div style="font-size:12px; color:#6b7280; margin-left:10px;">• ' + sk + ' (' + subElement + extraInfo + ')</div>'; 
                    }).join('');
                editFieldsHtml = 
                    '<div style="margin-top:10px; padding-top:10px; border-top:1px solid #e5e7eb;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="editGlobalDynamicField(\'' + key + '\')"> Edit Field</button>' +
                    '<button type="button" class="btn btn-danger btn-sm" onclick="deleteGlobalDynamicField(\'' + key + '\')" style="margin-left:8px;"> Delete</button>' +
                    '</div>';
            } else {
                var defaultValue = field.default_value || '';
                var defaultValues = field.default_values || [];
                var buttonUrl = field.button_url || '';
                var elementType = field.element_type || 'input';
                var extraInfo = elementType === 'button' ? ' (URL: ' + buttonUrl + ')' : '';
                fieldInfo = 
                    '<div><strong>Type:</strong> Individual Field</div>' +
                    '<div><strong>Element:</strong> ' + elementType + extraInfo + '</div>' +
                    (defaultValue ? '<div><strong>Default Value:</strong> ' + defaultValue + '</div>' : '') +
                    (defaultValues.length > 0 ? '<div><strong>' + (elementType === 'multiple-selections' ? 'Available values:' : 'Select options:') + '</strong> ' + defaultValues.map(function(v) { return escapeHtml(String(v)); }).join(', ') + '</div>' : '');
                editFieldsHtml = 
                    '<div style="margin-top:10px; padding-top:10px; border-top:1px solid #e5e7eb;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="editGlobalDynamicField(\'' + key + '\')"> Edit Field</button>' +
                    '<button type="button" class="btn btn-danger btn-sm" onclick="deleteGlobalDynamicField(\'' + key + '\')" style="margin-left:8px;"> Delete</button>' +
                    '</div>';
            }
            div.innerHTML = 
                '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">' +
                '<strong style="color:#1a1a2e;">' + (field['field-title'] || key) + '</strong>' +
                '<div>' +
                '<span style="font-size:12px; color:#6b7280; background:#e5e7eb; padding:2px 10px; border-radius:12px;">' + (field.field_type || 'individual') + '</span>' +
                '</div>' +
                '</div>' +
                '<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:13px; color:#6b7280;">' +
                '<div><strong>Field Key:</strong> ' + key + '</div>' +
                fieldInfo +
                '</div>' +
                editFieldsHtml;
            container.appendChild(div);
        }
        if (document.getElementById('userGlobalDynamicFieldsList')) {
            renderUserGlobalDynamicFields();
        }
    }

    // ============================================================
    // ===== EDIT GLOBAL DYNAMIC FIELD =====
    // ============================================================

    var subFields = [];
    var nextSubFieldIndex = 0;

    function editGlobalDynamicField(fieldKey) {
        var field = globalDynamicFields && globalDynamicFields[fieldKey];
        if (!field) {
            showDialog('Error', 'Field not found. Refresh Dynamic Content and try again.');
            return;
        }

        subFields = [];
        nextSubFieldIndex = 0;
        window._dfDefaultValues = Array.isArray(field.default_values) ? field.default_values.slice() : [];

        var isObjects = field.field_type === 'objects';
        var subFieldData = isObjects && field.fieldkeyvalue && typeof field.fieldkeyvalue === 'object'
            ? field.fieldkeyvalue : {};
        Object.keys(subFieldData).forEach(function(subKey) {
            var sub = subFieldData[subKey] || {};
            var index = nextSubFieldIndex++;
            subFields[index] = {
                key: subKey,
                defaultValues: Array.isArray(sub.default_values) ? sub.default_values.slice() : [],
                default_value: sub.default_value || '',
                copy_button_for_each_of: sub.copy_button_for_each_of || {}
            };
        });

        renderEditFieldDialog(
            fieldKey,
            field,
            field['field-title'] || '',
            isObjects,
            field.element_type || 'input',
            field.default_value || ''
        );
    }

    function buildSubFieldEditorMarkup(subKey, sub, index) {
        sub = sub || {};
        var safeKey = escapeHtml(String(subKey || ''));
        var safeTitle = escapeHtml(String(sub['field-title'] || subKey || ''));
        var safeDefault = escapeHtml(String(sub.default_value || ''));
        var safeUrl = escapeHtml(String(sub.button_url || ''));
        var safeCopy = sub.copy_button_for_each_of && typeof sub.copy_button_for_each_of === 'object'
            ? Object.keys(sub.copy_button_for_each_of).sort(function(a, b) { return Number(a) - Number(b); })
                .map(function(k) { return String(sub.copy_button_for_each_of[k] || ''); }).filter(Boolean).join('\n')
            : '';
        var element = String(sub.element_type || 'input');
        var dataType = String(sub.data_type || 'string');
        var values = Array.isArray(sub.default_values) ? sub.default_values : [];

        return '<div class="sub-field-group" id="sub_field_' + index + '" data-index="' + index + '">' +
            '<button type="button" class="remove-sub-field" onclick="removeSubField(' + index + ')" aria-label="Remove sub-field">×</button>' +
            '<div class="form-group" style="margin-bottom:10px;"><label>Display Title</label>' +
            '<input type="text" id="sub_title_' + index + '" value="' + safeTitle + '" placeholder="Enter display title..." style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;"></div>' +
            '<div class="form-group" style="margin-bottom:10px;"><label>Field Key</label>' +
            '<input type="text" id="sub_key_' + index + '" value="' + safeKey + '" placeholder="Enter field key..." style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;"></div>' +
            '<div class="form-group"><label>Element Type</label>' +
            '<select id="sub_element_' + index + '" onchange="toggleSubDefaultValueInput(' + index + ')" style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' +
            '<option value="input" ' + (element === 'input' ? 'selected' : '') + '>Input</option>' +
            '<option value="select" ' + (element === 'select' ? 'selected' : '') + '>Select</option>' +
            '<option value="multiple-selections" ' + (element === 'multiple-selections' ? 'selected' : '') + '>Multiple selections</option>' +
            '<option value="textarea" ' + (element === 'textarea' ? 'selected' : '') + '>Textarea</option>' +
            '<option value="date-time-input" ' + (element === 'date-time-input' ? 'selected' : '') + '>Date &amp; Time</option>' +
            '<option value="button" ' + (element === 'button' ? 'selected' : '') + '>Button</option>' +
            '<option value="copy_button_for_each_of" ' + (element === 'copy_button_for_each_of' ? 'selected' : '') + '>Copy Button For Each Sentence</option>' +
            '</select></div>' +
            '<div class="form-group"><label>Saved Data Type</label>' +
            '<select id="sub_data_type_' + index + '" style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' +
            '<option value="string" ' + (dataType === 'string' ? 'selected' : '') + '>String</option>' +
            '<option value="int" ' + (dataType === 'int' || dataType === 'integer' ? 'selected' : '') + '>Integer</option>' +
            '<option value="json" ' + (dataType === 'json' ? 'selected' : '') + '>JSON</option>' +
            '</select></div>' +
            '<div class="form-group" id="sub_default_value_container_' + index + '"><label>Default Value</label>' +
            '<div id="sub_default_value_input_container_' + index + '"><input type="text" id="sub_default_value_' + index + '" value="' + safeDefault + '" placeholder="Enter default value..." style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;"></div>' +
            '<div id="sub_default_value_textarea_container_' + index + '" style="display:none;"><textarea id="sub_default_value_textarea_' + index + '" rows="3" placeholder="Enter default value..." style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' + safeDefault + '</textarea></div></div>' +
            '<div class="form-group" id="sub_select_values_container_' + index + '" style="display:none;"><label>Select Options</label>' +
            '<div style="display:flex;gap:8px;"><input type="text" id="sub_default_value_input_' + index + '" placeholder="Enter option..." style="flex:1;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' +
            '<button type="button" class="btn btn-secondary" onclick="addSubDefaultValue(' + index + ')">Add</button></div>' +
            '<div id="sub_default_values_list_' + index + '" style="margin-top:8px;"></div></div>' +
            '<div class="form-group" id="sub_multiple_selection_container_' + index + '" style="display:none;"><label>Multiple selections values</label>' +
            '<textarea id="sub_multiple_selection_text_' + index + '" rows="3" placeholder="Value A, Value B, Value C..." style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;"></textarea>' +
            '<button type="button" class="btn btn-secondary" style="margin-top:8px;" onclick="addSubMultipleSelectionValues(' + index + ')">Add</button>' +
            '<small style="display:block;color:#6b7280;margin-top:6px;">Separate values with commas. Without a comma, the entire text is added as one value.</small>' +
            '<div id="sub_multiple_selection_values_list_' + index + '" style="margin-top:8px;"></div></div>' +
            '<div class="form-group" id="sub_url_container_' + index + '" style="display:none;"><label>Button URL (onclick value)</label>' +
            '<input type="url" id="sub_url_value_' + index + '" value="' + safeUrl + '" placeholder="https://example.com" style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' +
            '<small style="color:#6b7280;">This URL opens in a new tab when the button is clicked.</small></div>' +
            '<div class="form-group" id="sub_separate_paragraphs_container_' + index + '" style="display:none;"><label>Separate paragraphs into individual display items?</label>' +
            '<select id="sub_separate_paragraphs_' + index + '" style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' +
            '<option value="no" ' + (sub.separate_paragraphs !== 'yes' ? 'selected' : '') + '>No</option><option value="yes" ' + (sub.separate_paragraphs === 'yes' ? 'selected' : '') + '>Yes</option></select></div>' +
            '<div class="form-group" id="sub_copy_sentences_container_' + index + '" style="display:none;"><label>Copy sentences (one per line)</label>' +
            '<textarea id="sub_copy_sentences_' + index + '" rows="4" placeholder="Enter one sentence per line..." style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' + escapeHtml(safeCopy) + '</textarea></div>' +
            '</div>';
    }

    function renderEditFieldDialog(fieldKey, field, title, isObjects, elementType, defaultValue) {
        var buttonUrl = escapeHtml(String(field.button_url || ''));
        var safeTitle = escapeHtml(String(title || ''));
        var safeKey = escapeHtml(String(fieldKey || ''));
        var safeDefault = escapeHtml(String(defaultValue || ''));
        var defaultValues = Array.isArray(field.default_values) ? field.default_values : [];
        var subFieldData = isObjects && field.fieldkeyvalue && typeof field.fieldkeyvalue === 'object' ? field.fieldkeyvalue : {};
        var subFieldsHtml = '';
        Object.keys(subFieldData).forEach(function(subKey, idx) {
            subFieldsHtml += buildSubFieldEditorMarkup(subKey, subFieldData[subKey] || {}, idx);
        });

        var defaultValuesHtml = defaultValues.map(function(value) {
            return '<span class="value-tag">' + escapeHtml(String(value)) + '</span>';
        }).join('');
        var content =
            '<div class="form-group"><label>Display Title</label>' +
                '<input type="text" id="df_title" value="' + safeTitle + '" placeholder="Enter display title..." style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;"></div>' +
            '<div class="form-group"><label>Field Key</label>' +
                '<input type="text" id="df_key" value="' + safeKey + '" placeholder="Enter field key (no spaces)..." style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' +
                '<small style="color:#6b7280;">Used for storage. Spaces are converted to underscores.</small></div>' +
            '<div class="form-group"><label>Field Category</label>' +
                '<select id="df_category" onchange="toggleFieldCategory()" style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' +
                    '<option value="individual" ' + (!isObjects ? 'selected' : '') + '>Individual Field</option>' +
                    '<option value="objects" ' + (isObjects ? 'selected' : '') + '>Objects Field</option>' +
                '</select></div>' +
            '<div id="individual_fields_container" style="display:' + (isObjects ? 'none' : 'block') + ';">' +
                '<div class="form-group"><label>Element Type</label>' +
                    '<select id="df_element_type" onchange="toggleDefaultValueInput();toggleCopySentencesConfig()" style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' +
                        '<option value="input" ' + (elementType === 'input' ? 'selected' : '') + '>Input</option>' +
                        '<option value="select" ' + (elementType === 'select' ? 'selected' : '') + '>Select</option>' +
                        '<option value="multiple-selections" ' + (elementType === 'multiple-selections' ? 'selected' : '') + '>Multiple selections</option>' +
                        '<option value="textarea" ' + (elementType === 'textarea' ? 'selected' : '') + '>Textarea</option>' +
                        '<option value="date-time-input" ' + (elementType === 'date-time-input' ? 'selected' : '') + '>Date &amp; Time</option>' +
                        '<option value="button" ' + (elementType === 'button' ? 'selected' : '') + '>Button</option>' +
                        '<option value="copy_button_for_each_of" ' + (elementType === 'copy_button_for_each_of' ? 'selected' : '') + '>Copy Button For Each Sentence</option>' +
                    '</select></div>' +
                '<div class="form-group"><label>Saved Data Type</label>' +
                    '<select id="df_data_type" style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' +
                        '<option value="string" ' + ((field.data_type || 'string') === 'string' ? 'selected' : '') + '>String (text)</option>' +
                        '<option value="int" ' + (field.data_type === 'int' || field.data_type === 'integer' ? 'selected' : '') + '>Integer</option>' +
                        '<option value="json" ' + (field.data_type === 'json' ? 'selected' : '') + '>JSON</option>' +
                    '</select><small style="color:#6b7280;">Choose JSON for structured values. JSON input must be valid.</small></div>' +
                '<div class="form-group" id="df_default_value_container"><label>Default Value</label>' +
                    '<div id="df_default_value_input_container"><input type="text" id="df_default_value" value="' + (elementType !== 'textarea' && elementType !== 'button' ? safeDefault : '') + '" placeholder="Enter default value..." style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;"></div>' +
                    '<div id="df_default_value_textarea_container"><textarea id="df_default_value_textarea" oninput="toggleSeparateParagraphOption()" rows="3" placeholder="Enter default value..." style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' + (elementType === 'textarea' ? safeDefault : '') + '</textarea></div>' +
                    '<div class="form-group" id="df_separate_paragraphs_container"><label>Separate paragraphs into individual display items?</label>' +
                        '<select id="df_separate_paragraphs" style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;"><option value="no" ' + (field.separate_paragraphs !== 'yes' ? 'selected' : '') + '>No</option><option value="yes" ' + (field.separate_paragraphs === 'yes' ? 'selected' : '') + '>Yes</option></select>' +
                        '<small style="color:#6b7280;">When enabled, each non-empty paragraph is displayed separately.</small></div>' +
                '</div>' +
                '<div class="form-group" id="df_copy_sentences_container" style="display:none;border:1px solid #cbd5e1;border-radius:10px;padding:12px;background:#f8fafc;">' +
                    '<label>Add sentences with options of</label><select id="df_copy_sentence_mode" onchange="toggleCopySentenceMode()" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;margin:8px 0 12px;"><option value="individually">Individually</option><option value="bulk">Bulk</option></select>' +
                    '<div id="df_copy_individual_panel"><button type="button" class="btn btn-secondary btn-sm" onclick="addCopySentenceInput()">+ Add individual element</button><div id="df_copy_individual_list" style="margin-top:10px;"></div></div>' +
                    '<div id="df_copy_bulk_panel" style="display:none;"><label>Add Bulk sentences</label><textarea id="df_copy_bulk_text" rows="6" placeholder="One sentence per line..." style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></textarea></div></div>' +
                '<div class="form-group" id="df_select_values_container" style="display:none;"><label>Select Options</label>' +
                    '<div style="display:flex;gap:8px;"><input type="text" id="df_default_value_input" placeholder="Enter option..." style="flex:1;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' +
                    '<button type="button" class="btn btn-secondary" onclick="addDefaultValue()">Add</button></div><div id="df_default_values_list" style="margin-top:8px;">' + defaultValuesHtml + '</div></div>' +
                '<div class="form-group" id="df_multiple_selection_container" style="display:none;"><label>Multiple selections values</label>' +
                    '<textarea id="df_multiple_selection_text" rows="3" placeholder="Value A, Value B, Value C..." style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;"></textarea>' +
                    '<button type="button" class="btn btn-secondary" style="margin-top:8px;" onclick="addMultipleSelectionValues()">Add</button>' +
                    '<small style="display:block;color:#6b7280;margin-top:6px;">Separate values with commas. Without a comma, the entire text is added as one value.</small>' +
                    '<div id="df_multiple_selection_values_list" style="margin-top:8px;">' + defaultValuesHtml + '</div></div>' +
                '<div class="form-group" id="df_url_container" style="display:none;"><label>Button URL (onclick value)</label>' +
                    '<input type="url" id="df_url_value" value="' + buttonUrl + '" placeholder="https://example.com" style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' +
                    '<small style="color:#6b7280;">This URL opens in a new tab when the button is clicked.</small></div>' +
            '</div>' +
            '<div id="objects_fields_container" style="display:' + (isObjects ? 'block' : 'none') + ';">' +
                '<h4 style="margin-bottom:10px;">Sub Fields</h4><div id="sub_fields_list">' + subFieldsHtml + '</div>' +
                '<div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;"><button type="button" class="btn btn-secondary btn-sm" onclick="addSubField()">+ Add Sub Field</button></div>' +
            '</div>' +
            '<div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;">' +
                '<button type="button" class="btn btn-success" id="df_update_field_button">Update Field</button>' +
                '<button type="button" class="btn btn-secondary" onclick="closeDialog()">Cancel</button>' +
            '</div>';

        showDialog('Edit Dynamic Field', '', null, content);
        var updateButton = document.getElementById('df_update_field_button');
        if (updateButton) updateButton.addEventListener('click', function() { updateDynamicField(fieldKey, false); });
        var editDataTypeControl = document.getElementById('df_data_type');
        if (editDataTypeControl && elementType === 'multiple-selections') {
            editDataTypeControl.dataset.previousType = field.data_type && field.data_type !== 'json' ? field.data_type : 'string';
        }
        toggleFieldCategory();
        toggleDefaultValueInput();
        loadCopySentenceInputs(field);
        // Render existing select/multiple-selection options with working remove
        // controls; the edit dialog must make saved values editable, not read-only.
        renderDefaultValuesList();
        document.querySelectorAll('#sub_fields_list .sub-field-group').forEach(function(group) {
            var index = Number(group.getAttribute('data-index'));
            renderSubFieldDefaultValues(index);
            toggleSubDefaultValueInput(index);
        });
    }

    function setDynamicFieldEditorCategory(category) {
        var select = document.getElementById('df_category');
        if (!select) return;
        select.value = category === 'objects' ? 'objects' : 'individual';
        toggleFieldCategory();
    }

    function addSubFieldFromEditor() {
        setDynamicFieldEditorCategory('objects');
        addSubField();
    }

    function toggleFieldCategory() {
        var category = document.getElementById('df_category').value;
        document.getElementById('individual_fields_container').style.display = category === 'individual' ? 'block' : 'none';
        document.getElementById('objects_fields_container').style.display = category === 'objects' ? 'block' : 'none';
    }


    function toggleCopySentencesConfig() {
        var t=document.getElementById('df_element_type'), b=document.getElementById('df_copy_sentences_container');
        if(t&&b)b.style.display=t.value==='copy_button_for_each_of'?'block':'none';
    }
    function toggleCopySentenceMode() {
        var m=document.getElementById('df_copy_sentence_mode'), i=document.getElementById('df_copy_individual_panel'), b=document.getElementById('df_copy_bulk_panel');
        if(i)i.style.display=m&&m.value==='individually'?'block':'none';
        if(b)b.style.display=m&&m.value==='bulk'?'block':'none';
    }
    function addCopySentenceInput(value) {
        var list=document.getElementById('df_copy_individual_list'); if(!list)return;
        var row=document.createElement('div'); row.className='new-copy-sentence-row'; row.style.cssText='display:flex;gap:8px;margin-bottom:8px;';
        var a=document.createElement('textarea'); a.className='new-copy-sentence'; a.rows=2; a.value=value||''; a.placeholder='Add a new sentence...'; a.style.cssText='flex:1;min-width:0;padding:10px;border:1px solid #cbd5e1;border-radius:8px;';
        var x=document.createElement('button'); x.type='button'; x.className='btn btn-secondary btn-sm'; x.textContent='Remove'; x.onclick=function(){row.remove();};
        row.appendChild(a);row.appendChild(x);list.appendChild(row);
    }
    function addExistingCopySentence(value) {
        var list=document.getElementById('df_copy_individual_list'); if(!list)return;
        var row=document.createElement('div'); row.className='existing-copy-sentence-row'; row.style.cssText='display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;padding:9px;border:1px solid #dbe3ea;border-radius:8px;background:#fff;';
        var text=document.createElement('div'); text.className='existing-copy-sentence-text'; text.textContent=value; text.style.cssText='flex:1;min-width:0;white-space:pre-wrap;overflow-wrap:anywhere;padding:5px 0;';
        var x=document.createElement('button'); x.type='button'; x.className='btn btn-secondary btn-sm'; x.textContent='Delete'; x.setAttribute('aria-label','Delete this existing sentence'); x.onclick=function(){row.remove();};
        row.appendChild(text);row.appendChild(x);list.appendChild(row);
    }
    function getCopySentenceInputs() {
        var t=document.getElementById('df_element_type');if(!t||t.value!=='copy_button_for_each_of')return [];
        var m=(document.getElementById('df_copy_sentence_mode')||{}).value||'individually';
        if(m==='bulk')return ((document.getElementById('df_copy_bulk_text')||{}).value||'').split(/\r?\n/).map(function(v){return v.trim();}).filter(Boolean);
        return Array.prototype.slice.call(document.querySelectorAll('#df_copy_individual_list .new-copy-sentence')).map(function(a){return a.value.trim();}).filter(Boolean);
    }
    function getRemainingExistingCopySentences() {
        return Array.prototype.slice.call(document.querySelectorAll('#df_copy_individual_list .existing-copy-sentence-row .existing-copy-sentence-text')).map(function(el){return el.textContent.trim();}).filter(Boolean);
    }
    function loadCopySentenceInputs(field) {
        var o=field&&field.copy_button_for_each_of?field.copy_button_for_each_of:{}, vals=Object.keys(o).sort(function(a,b){return +a- +b;}).map(function(k){return String(o[k]||'').trim();}).filter(Boolean);
        var l=document.getElementById('df_copy_individual_list');if(l)l.innerHTML='';
        vals.forEach(addExistingCopySentence);
        addCopySentenceInput('');
        var m=document.getElementById('df_copy_sentence_mode');if(m)m.value=field&&field.copy_button_mode?field.copy_button_mode:'individually';
        var b=document.getElementById('df_copy_bulk_text');if(b)b.value=vals.join('\n');
        toggleCopySentenceMode();toggleCopySentencesConfig();
    }
    function mergeCopySentenceRecords(old, added) {
        var r={};
        (old||[]).concat(added||[]).forEach(function(v){v=String(v||'').trim();if(v)r[String(Object.keys(r).length+1)]=v;});
        return r;
    }

    function toggleDefaultValueInput() {
        var elementControl = document.getElementById('df_element_type');
        if (!elementControl) return;
        var elementType = elementControl.value;
        var defaultContainer = document.getElementById('df_default_value_container');
        var inputContainer = document.getElementById('df_default_value_input_container');
        var textareaContainer = document.getElementById('df_default_value_textarea_container');
        var selectContainer = document.getElementById('df_select_values_container');
        var multipleContainer = document.getElementById('df_multiple_selection_container');
        var urlContainer = document.getElementById('df_url_container');
        var separateContainer = document.getElementById('df_separate_paragraphs_container');
        var copyContainer = document.getElementById('df_copy_sentences_container');
        var dataType = document.getElementById('df_data_type');

        [defaultContainer, selectContainer, multipleContainer, urlContainer, separateContainer, copyContainer].forEach(function(node) {
            if (node) node.style.display = 'none';
        });
        if (inputContainer) inputContainer.style.display = 'none';
        if (textareaContainer) textareaContainer.style.display = 'none';

        if (dataType) {
            if (elementType === 'multiple-selections') {
                if (!dataType.dataset.previousType) dataType.dataset.previousType = dataType.value || 'string';
                dataType.value = 'json';
                dataType.disabled = true;
            } else {
                dataType.disabled = false;
                if (dataType.dataset.previousType) {
                    dataType.value = dataType.dataset.previousType;
                    delete dataType.dataset.previousType;
                }
            }
        }

        if (elementType === 'select') {
            if (defaultContainer) defaultContainer.style.display = 'block';
            if (inputContainer) inputContainer.style.display = 'block';
            if (selectContainer) selectContainer.style.display = 'block';
        } else if (elementType === 'multiple-selections') {
            if (multipleContainer) multipleContainer.style.display = 'block';
        } else if (elementType === 'textarea') {
            if (defaultContainer) defaultContainer.style.display = 'block';
            if (textareaContainer) textareaContainer.style.display = 'block';
            if (separateContainer) separateContainer.style.display = 'block';
        } else if (elementType === 'button') {
            if (urlContainer) urlContainer.style.display = 'block';
        } else if (elementType === 'copy_button_for_each_of') {
            if (copyContainer) copyContainer.style.display = 'block';
        } else {
            if (defaultContainer) defaultContainer.style.display = 'block';
            if (inputContainer) inputContainer.style.display = 'block';
        }
    }

    // ===== UPDATE addSubField FUNCTION =====
    // Replace the entire function with this version

    function addSubField() {
        var index = nextSubFieldIndex++;
        subFields[index] = { defaultValues: [], default_value: '', copy_button_for_each_of: {} };
        var list = document.getElementById('sub_fields_list');
        if (!list) return;
        list.insertAdjacentHTML('beforeend', buildSubFieldEditorMarkup('', {
            'field-title': '',
            element_type: 'input',
            data_type: 'string',
            default_value: '',
            default_values: [],
            button_url: '',
            separate_paragraphs: 'no',
            copy_button_for_each_of: {}
        }, index));
        renderSubFieldDefaultValues(index);
        toggleSubDefaultValueInput(index);
    }

    function toggleSubDefaultValueInput(index) {
        var elementControl = document.getElementById('sub_element_' + index);
        if (!elementControl) return;
        var elementType = elementControl.value;
        var defaultContainer = document.getElementById('sub_default_value_container_' + index);
        var inputContainer = document.getElementById('sub_default_value_input_container_' + index);
        var textareaContainer = document.getElementById('sub_default_value_textarea_container_' + index);
        var selectContainer = document.getElementById('sub_select_values_container_' + index);
        var multipleContainer = document.getElementById('sub_multiple_selection_container_' + index);
        var urlContainer = document.getElementById('sub_url_container_' + index);
        var separateContainer = document.getElementById('sub_separate_paragraphs_container_' + index);
        var copyContainer = document.getElementById('sub_copy_sentences_container_' + index);
        var dataType = document.getElementById('sub_data_type_' + index);

        [defaultContainer, selectContainer, multipleContainer, urlContainer, separateContainer, copyContainer].forEach(function(node) {
            if (node) node.style.display = 'none';
        });
        if (inputContainer) inputContainer.style.display = 'none';
        if (textareaContainer) textareaContainer.style.display = 'none';

        if (dataType) {
            if (elementType === 'multiple-selections') {
                if (!dataType.dataset.previousType) dataType.dataset.previousType = dataType.value || 'string';
                dataType.value = 'json';
                dataType.disabled = true;
            } else {
                dataType.disabled = false;
                if (dataType.dataset.previousType) {
                    dataType.value = dataType.dataset.previousType;
                    delete dataType.dataset.previousType;
                }
            }
        }

        if (elementType === 'select') {
            if (defaultContainer) defaultContainer.style.display = 'block';
            if (inputContainer) inputContainer.style.display = 'block';
            if (selectContainer) selectContainer.style.display = 'block';
        } else if (elementType === 'multiple-selections') {
            if (multipleContainer) multipleContainer.style.display = 'block';
        } else if (elementType === 'textarea') {
            if (defaultContainer) defaultContainer.style.display = 'block';
            if (textareaContainer) textareaContainer.style.display = 'block';
            if (separateContainer) separateContainer.style.display = 'block';
        } else if (elementType === 'button') {
            if (urlContainer) urlContainer.style.display = 'block';
        } else if (elementType === 'copy_button_for_each_of') {
            if (copyContainer) copyContainer.style.display = 'block';
        } else {
            if (defaultContainer) defaultContainer.style.display = 'block';
            if (inputContainer) inputContainer.style.display = 'block';
        }
    }

    function removeSubField(index) {
        var element = document.getElementById('sub_field_' + index);
        if (element) element.remove();
        if (Object.prototype.hasOwnProperty.call(subFields, index)) subFields[index] = null;
    }

    function addSubDefaultValue(index) {
        var input = document.getElementById('sub_default_value_input_' + index);
        if (!input) return;
        var value = input.value.trim();
        if (value) {
            if (!subFields[index]) subFields[index] = { defaultValues: [], default_value: '' };
            if (!subFields[index].defaultValues) subFields[index].defaultValues = [];
            if (!subFields[index].defaultValues.includes(value)) {
                subFields[index].defaultValues.push(value);
                renderSubFieldDefaultValues(index);
                input.value = '';
            }
        }
    }

    function removeSubDefaultValue(index, valueIndex) {
        if (subFields[index] && subFields[index].defaultValues) {
            subFields[index].defaultValues.splice(valueIndex, 1);
            renderSubFieldDefaultValues(index);
        }
    }

    function renderSubFieldDefaultValues(index) {
        if (!subFields[index]) subFields[index] = { defaultValues: [], default_value: '', copy_button_for_each_of: {} };
        if (!Array.isArray(subFields[index].defaultValues)) subFields[index].defaultValues = [];
        var containers = [
            document.getElementById('sub_default_values_list_' + index),
            document.getElementById('sub_multiple_selection_values_list_' + index)
        ].filter(Boolean);
        var values = subFields[index] ? subFields[index].defaultValues || [] : [];
        containers.forEach(function(container) {
            container.innerHTML = '';
            if (!values.length) {
                var empty = document.createElement('span');
                empty.style.cssText = 'color:#6b7280;font-size:13px;';
                empty.textContent = container.id.indexOf('multiple_selection') !== -1 ? 'No values added.' : 'No options added.';
                container.appendChild(empty);
                return;
            }
            values.forEach(function(value, valueIndex) {
                var tag = document.createElement('span');
                tag.className = 'value-tag';
                var label = document.createElement('span');
                label.textContent = String(value);
                var remove = document.createElement('span');
                remove.className = 'remove-value';
                remove.textContent = '×';
                remove.style.cursor = 'pointer';
                remove.addEventListener('click', function() {
                    removeSubDefaultValue(index, valueIndex);
                });
                tag.appendChild(label);
                tag.appendChild(document.createTextNode(' '));
                tag.appendChild(remove);
                container.appendChild(tag);
            });
        });
    }

    function addSubMultipleSelectionValues(index) {
        var textarea = document.getElementById('sub_multiple_selection_text_' + index);
        if (!textarea) return;
        var values = parseMultipleSelectionOptionText(textarea.value);
        if (!values.length) return;
        if (!subFields[index]) subFields[index] = { defaultValues: [], default_value: '' };
        if (!Array.isArray(subFields[index].defaultValues)) subFields[index].defaultValues = [];
        values.forEach(function(value) {
            if (subFields[index].defaultValues.indexOf(value) === -1) {
                subFields[index].defaultValues.push(value);
            }
        });
        textarea.value = '';
        renderSubFieldDefaultValues(index);
    }

    function addDefaultValue() {
        var input = document.getElementById('df_default_value_input');
        var value = input.value.trim();
        if (value) {
            if (!window._dfDefaultValues) window._dfDefaultValues = [];
            if (!window._dfDefaultValues.includes(value)) {
                window._dfDefaultValues.push(value);
                renderDefaultValuesList();
                input.value = '';
            }
        }
    }

    function parseMultipleSelectionOptionText(text) {
        var value = String(text == null ? '' : text).trim();
        if (!value) return [];
        // Commas are the ONLY bulk delimiter. Text with no comma stays one value,
        // even when it contains several words or line breaks.
        var parts = value.indexOf(',') !== -1 ? value.split(',') : [value];
        return parts.map(function(part) { return part.trim(); }).filter(function(part) { return part !== ''; });
    }

    function addMultipleSelectionValues() {
        var textarea = document.getElementById('df_multiple_selection_text');
        if (!textarea) return;
        var values = parseMultipleSelectionOptionText(textarea.value);
        if (!values.length) return;
        if (!window._dfDefaultValues) window._dfDefaultValues = [];
        values.forEach(function(value) {
            if (window._dfDefaultValues.indexOf(value) === -1) window._dfDefaultValues.push(value);
        });
        textarea.value = '';
        renderDefaultValuesList();
    }

    function removeDefaultValue(index) {
        if (window._dfDefaultValues) {
            window._dfDefaultValues.splice(index, 1);
            renderDefaultValuesList();
        }
    }

    function renderDefaultValuesList() {
        var containers = [
            document.getElementById('df_default_values_list'),
            document.getElementById('df_multiple_selection_values_list')
        ].filter(Boolean);
        var values = window._dfDefaultValues || [];
        containers.forEach(function(container) {
            container.innerHTML = '';
            if (values.length === 0) {
                var empty = document.createElement('span');
                empty.style.cssText = 'color:#6b7280;font-size:13px;';
                empty.textContent = container.id === 'df_default_values_list' ? 'No options added.' : 'No values added.';
                container.appendChild(empty);
                return;
            }
            values.forEach(function(value, index) {
                var tag = document.createElement('span');
                tag.className = 'value-tag';
                var label = document.createElement('span');
                label.textContent = String(value);
                var remove = document.createElement('span');
                remove.className = 'remove-value';
                remove.textContent = '×';
                remove.setAttribute('role', 'button');
                remove.setAttribute('tabindex', '0');
                remove.setAttribute('aria-label', 'Remove ' + value);
                remove.addEventListener('click', function() { removeDefaultValue(index); });
                remove.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); removeDefaultValue(index); }
                });
                tag.appendChild(label);
                tag.appendChild(remove);
                container.appendChild(tag);
            });
        });
    }

    // ===== UPDATE updateDynamicField FUNCTION =====
    // Replace the entire function with this version

    function collectDynamicFieldEditorData() {
        var titleControl = document.getElementById('df_title');
        var keyControl = document.getElementById('df_key');
        var categoryControl = document.getElementById('df_category');
        if (!titleControl || !keyControl || !categoryControl) throw new Error('The Dynamic Content editor is not fully loaded.');

        var title = titleControl.value.trim();
        var key = keyControl.value.trim().toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
        if (!title || !key) throw new Error('Please enter both a display title and a valid field key.');
        if (key.length > 191) throw new Error('Field keys must not exceed 191 characters.');

        if (categoryControl.value === 'individual') {
            var elementControl = document.getElementById('df_element_type');
            if (!elementControl) throw new Error('Choose an element type before saving.');
            var elementType = elementControl.value;
            var defaultValue = '';
            var buttonUrl = '';
            if (elementType === 'textarea') {
                var textarea = document.getElementById('df_default_value_textarea');
                defaultValue = textarea ? textarea.value : '';
            } else if (elementType === 'button') {
                var url = document.getElementById('df_url_value');
                buttonUrl = url ? url.value.trim() : '';
                if (buttonUrl && !/^https?:\/\//i.test(buttonUrl)) buttonUrl = 'https://' + buttonUrl;
            } else if (elementType !== 'select' && elementType !== 'multiple-selections' && elementType !== 'copy_button_for_each_of') {
                var input = document.getElementById('df_default_value');
                defaultValue = input ? input.value : '';
            }

            var copyMode = (document.getElementById('df_copy_sentence_mode') || {}).value || 'individually';
            var copyRecord = {};
            if (elementType === 'copy_button_for_each_of') {
                var additions = getCopySentenceInputs();
                var existing = copyMode === 'individually' ? getRemainingExistingCopySentences() : [];
                copyRecord = mergeCopySentenceRecords(copyMode === 'bulk' ? [] : existing, additions);
            }
            var paragraphControl = document.getElementById('df_separate_paragraphs');
            return {
                'field-title': title,
                'field-name': key,
                'field_type': 'individual',
                'element_type': elementType,
                'data_type': elementType === 'multiple-selections' ? 'json' : ((document.getElementById('df_data_type') || {}).value || 'string'),
                'default_value': defaultValue,
                'default_values': Array.isArray(window._dfDefaultValues) ? window._dfDefaultValues.slice() : [],
                'button_url': buttonUrl,
                'separate_paragraphs': elementType === 'textarea' && paragraphControl ? paragraphControl.value : 'no',
                'copy_button_mode': copyMode,
                'copy_button_for_each_of': copyRecord
            };
        }

        var subFieldData = {};
        var groups = document.querySelectorAll('#sub_fields_list .sub-field-group');
        groups.forEach(function(group) {
            var index = Number(group.getAttribute('data-index'));
            if (!Number.isFinite(index)) {
                var match = String(group.id || '').match(/sub_field_(\d+)/);
                index = match ? Number(match[1]) : -1;
            }
            if (index < 0) return;

            var titleInput = document.getElementById('sub_title_' + index);
            var keyInput = document.getElementById('sub_key_' + index);
            var elementInput = document.getElementById('sub_element_' + index);
            if (!titleInput || !keyInput || !elementInput) return;

            var subTitle = titleInput.value.trim();
            var subKey = keyInput.value.trim().toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
            if (!subTitle || !subKey) throw new Error('Every sub-field needs both a display title and a valid field key.');
            if (subKey.length > 191) throw new Error('Sub-field keys must not exceed 191 characters.');
            if (Object.prototype.hasOwnProperty.call(subFieldData, subKey)) throw new Error('Duplicate sub-field key: ' + subKey);

            var subElement = elementInput.value;
            var subDefault = '';
            var subUrl = '';
            if (subElement === 'textarea') {
                var subTextarea = document.getElementById('sub_default_value_textarea_' + index);
                subDefault = subTextarea ? subTextarea.value : '';
            } else if (subElement === 'button') {
                var subUrlInput = document.getElementById('sub_url_value_' + index);
                subUrl = subUrlInput ? subUrlInput.value.trim() : '';
                if (subUrl && !/^https?:\/\//i.test(subUrl)) subUrl = 'https://' + subUrl;
            } else if (subElement !== 'select' && subElement !== 'multiple-selections' && subElement !== 'copy_button_for_each_of') {
                var subDefaultInput = document.getElementById('sub_default_value_' + index);
                subDefault = subDefaultInput ? subDefaultInput.value : '';
            }

            var copyRecord = {};
            if (subElement === 'copy_button_for_each_of') {
                var copyText = document.getElementById('sub_copy_sentences_' + index);
                var lines = copyText ? copyText.value.split(/\r?\n/).map(function(value) { return value.trim(); }).filter(Boolean) : [];
                copyRecord = mergeCopySentenceRecords([], lines);
            }
            var paragraph = document.getElementById('sub_separate_paragraphs_' + index);
            var subDefaults = subFields[index] && Array.isArray(subFields[index].defaultValues) ? subFields[index].defaultValues.slice() : [];
            var subDataType = (document.getElementById('sub_data_type_' + index) || {}).value || 'string';

            subFieldData[subKey] = {
                'field-title': subTitle,
                'field-name': subKey,
                'field_type': 'individual',
                'element_type': subElement,
                'data_type': subElement === 'multiple-selections' ? 'json' : subDataType,
                'default_value': subDefault,
                'default_values': subDefaults,
                'button_url': subUrl,
                'separate_paragraphs': subElement === 'textarea' && paragraph ? paragraph.value : 'no',
                'copy_button_for_each_of': copyRecord
            };
        });

        return {
            'field-title': title,
            'field-name': key,
            'field_type': 'objects',
            'fieldkeyvalue': subFieldData
        };
    }

    async function persistDynamicFieldDefinition(action, fieldData, oldKey, addAnotherAfterSave) {
        var formData = new FormData();
        formData.append(action, 'true');
        formData.append('user_id', String(selectedUserId));
        formData.append('field_data', JSON.stringify(fieldData));
        if (action === 'update_global_field') {
            formData.append('old_key', String(oldKey || ''));
            formData.append('new_key', String(fieldData['field-name'] || ''));
        }

        sceneIQBeginLoading('Saving Dynamic Content', 'Saving the field definition and refreshing the account configuration...');
        try {
            var response = await fetch(window.location.href.split('#')[0], {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-SceneIQ-Request': 'save' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            var data;
            try { data = await response.json(); }
            catch (parseError) { throw new Error('The server did not return a valid save response. Check the PHP error log.'); }
            if (!response.ok || !data || data.success !== true) {
                throw new Error((data && data.message) || 'The Dynamic Content field could not be saved.');
            }

            closeDialog();
            await sceneIQRefreshAfterSave(data.state || null, data.message || 'Dynamic Content saved successfully.', { refreshProjectMarkup: true });
            if (addAnotherAfterSave) addGlobalDynamicField();
            return true;
        } catch (error) {
            console.error('SceneIQ Dynamic Content save failed:', error);
            showDialog('Save failed', error.message || 'The Dynamic Content field could not be saved.');
            return false;
        } finally {
            sceneIQEndLoading();
        }
    }

    async function updateDynamicField(oldKey, addAnotherAfterSave) {
        var fieldData;
        try {
            fieldData = collectDynamicFieldEditorData();
        } catch (error) {
            showDialog('Check field details', error.message);
            return;
        }
        await persistDynamicFieldDefinition('update_global_field', fieldData, oldKey, !!addAnotherAfterSave);
    }

    function deleteGlobalDynamicField(key) {
        showDialog('Delete Field', 'Are you sure you want to delete the field "' + key + '"? This action cannot be undone.', [
            { label: 'Cancel', class: 'btn-secondary', callback: function() { closeDialog(); } },
            { label: 'Delete', class: 'btn-confirm', callback: function() {
                var formData = new FormData();
                formData.append('delete_global_dynamic_field', 'true');
                formData.append('user_id', selectedUserId);
                formData.append('field_key', key);
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-SceneIQ-Request': 'save' },
                    credentials: 'same-origin'
                })
                .then(function(response) { return response.json(); })
                .then(async function(data) {
                    if (!data.success) throw new Error(data.message || 'The field could not be deleted.');
                    closeDialog();
                    await sceneIQRefreshAfterSave(data.state, data.message, { refreshProjectMarkup: true });
                })
                .catch(function(error) {
                    showDialog('Error', 'Failed to delete field: ' + error.message);
                });
            }}
        ]);
    }

    function addGlobalDynamicField() {
        subFields = [];
        nextSubFieldIndex = 0;
        window._dfDefaultValues = [];
        renderAddFieldDialog();
    }
    // ===== UPDATE toggleDefaultValueInput FUNCTION =====
    // Add this complete function with the new "button" case

    function toggleSeparateParagraphOption() {
        var box = document.getElementById('df_separate_paragraphs_container');
        var textarea = document.getElementById('df_default_value_textarea');
        var type = document.getElementById('df_element_type');
        if (box) box.style.display = (type && type.value === 'textarea' && textarea && textarea.value.trim() !== '') ? 'block' : 'none';
    }

    function toggleDefaultValueInput() {
        var elementType = document.getElementById('df_element_type').value;
        var separateBox = document.getElementById('df_separate_paragraphs_container');
        if (separateBox) separateBox.style.display = (elementType === 'textarea' && document.getElementById('df_default_value_textarea') && document.getElementById('df_default_value_textarea').value.trim() !== '') ? 'block' : 'none';
        var defaultValueContainer = document.getElementById('df_default_value_container');
        var defaultValueInput = document.getElementById('df_default_value_input_container');
        var defaultValueTextarea = document.getElementById('df_default_value_textarea_container');
        var selectValuesContainer = document.getElementById('df_select_values_container');
        var multipleSelectionContainer = document.getElementById('df_multiple_selection_container');
        var urlContainer = document.getElementById('df_url_container');
        var urlInput = document.getElementById('df_url_input_container');
        var dataTypeSelect = document.getElementById('df_data_type');
        
        // Hide all optional containers first
        if (defaultValueContainer) defaultValueContainer.style.display = 'none';
        if (selectValuesContainer) selectValuesContainer.style.display = 'none';
        if (multipleSelectionContainer) multipleSelectionContainer.style.display = 'none';
        if (urlContainer) urlContainer.style.display = 'none';
        if (defaultValueInput) defaultValueInput.style.display = 'none';
        if (defaultValueTextarea) defaultValueTextarea.style.display = 'none';

        if (dataTypeSelect) {
            if (elementType === 'multiple-selections') {
                if (!dataTypeSelect.dataset.previousType) dataTypeSelect.dataset.previousType = dataTypeSelect.value || 'string';
                dataTypeSelect.value = 'json';
                dataTypeSelect.disabled = true;
            } else {
                dataTypeSelect.disabled = false;
                if (dataTypeSelect.dataset.previousType) {
                    dataTypeSelect.value = dataTypeSelect.dataset.previousType;
                    delete dataTypeSelect.dataset.previousType;
                }
            }
        }
        
        if (elementType === 'multiple-selections') {
            if (multipleSelectionContainer) multipleSelectionContainer.style.display = 'block';
        } else if (elementType === 'select') {
            if (defaultValueContainer) defaultValueContainer.style.display = 'block';
            if (selectValuesContainer) selectValuesContainer.style.display = 'block';
        } else if (elementType === 'textarea') {
            if (defaultValueContainer) defaultValueContainer.style.display = 'block';
            if (defaultValueInput) defaultValueInput.style.display = 'none';
            if (defaultValueTextarea) defaultValueTextarea.style.display = 'block';
        } else if (elementType === 'copy_button_for_each_of') {
            // Sentences entered in the sentence editor are the defaults; no separate default-value control is relevant.
            if (defaultValueContainer) defaultValueContainer.style.display = 'none';
            if (selectValuesContainer) selectValuesContainer.style.display = 'none';
            if (urlContainer) urlContainer.style.display = 'none';
            if (separateBox) separateBox.style.display = 'none';
        } else if (elementType === 'button') {
            // For button type, show URL input instead of default value
            if (urlContainer) urlContainer.style.display = 'block';
            if (urlInput) urlInput.style.display = 'block';
        } else {
            // Input, date-time-input, etc.
            if (defaultValueContainer) defaultValueContainer.style.display = 'block';
            if (defaultValueInput) defaultValueInput.style.display = 'block';
            if (defaultValueTextarea) defaultValueTextarea.style.display = 'none';
        }
    }

    // ===== UPDATE renderAddFieldDialog FUNCTION =====
    // Replace the entire function with this version

    function renderAddFieldDialog() {
        var content = 
            '<div class="form-group">' +
            '<label>Display Title</label>' +
            '<input type="text" id="df_title" placeholder="Enter display title..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Field Key</label>' +
            '<input type="text" id="df_key" placeholder="Enter field key (no spaces)..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<small style="color:#6b7280;">Used for storage. Spaces will be replaced with underscores.</small>' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Field Category</label>' +
            '<select id="df_category" onchange="toggleFieldCategory()" style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<option value="individual">Individual Field</option>' +
            '<option value="objects">Objects Field</option>' +
            '</select>' +
            '</div>' +
            '<div id="individual_fields_container">' +
            '<div class="form-group">' +
            '<label>Element Type</label>' +
            '<select id="df_element_type" onchange="toggleDefaultValueInput(); toggleCopySentencesConfig()" style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<option value="input">Input</option>' +
            '<option value="select">Select</option>' +
            '<option value="multiple-selections">Multiple selections</option>' +
            '<option value="textarea">Textarea</option>' +
            '<option value="date-time-input">Date & Time</option>' +
            '<option value="button">Button</option>' +
            '<option value="copy_button_for_each_of">Copy Button For Each Sentence</option>' +
            '</select>' +
            '</div>' +
            '<div class="form-group"><label>Saved Data Type</label>' +
            '<select id="df_data_type" style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;">' +
            '<option value="string" selected>String (text)</option><option value="int">Integer (whole number)</option><option value="json">JSON (object, array, number, boolean or null)</option>' +
            '</select><small style="color:#6b7280;">Choose JSON for structured values. JSON input must be valid JSON.</small></div>' +
            '<div class="form-group" id="df_default_value_container">' +
            '<label>Default Value</label>' +
            '<div id="df_default_value_input_container">' +
            '<input type="text" id="df_default_value" placeholder="Enter default value..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '</div>' +
            '<div id="df_default_value_textarea_container" style="display:none;">' +
            '<textarea id="df_default_value_textarea" oninput="toggleSeparateParagraphOption()" rows="3" placeholder="Enter default value..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;"></textarea>' +
            '</div>' +
            '<div class="form-group" id="df_separate_paragraphs_container" style="display:none;">' +
            '<label>Separate paragraphs into individual display items?</label>' +
            '<select id="df_separate_paragraphs" style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;"><option value="no" selected>No</option><option value="yes">Yes</option></select>' +
            '<small style="color:#6b7280;">When enabled, each non-empty paragraph is displayed separately.</small>' +
            '</div>' +
            '</div>' +
                        '<div class="form-group" id="df_copy_sentences_container" style="display:none;border:1px solid #cbd5e1;border-radius:10px;padding:12px;background:#f8fafc;">' +
            '<label>Add sentences with options of</label><select id="df_copy_sentence_mode" onchange="toggleCopySentenceMode()" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;margin:8px 0 12px;"><option value="individually">Individually</option><option value="bulk">Bulk</option></select>' +
            '<div id="df_copy_individual_panel"><button type="button" class="btn btn-secondary btn-sm" onclick="addCopySentenceInput()">+ Add individual element</button><div id="df_copy_individual_list" style="margin-top:10px;"></div></div>' +
            '<div id="df_copy_bulk_panel" style="display:none;"><label>Add Bulk sentences</label><textarea id="df_copy_bulk_text" rows="6" placeholder="I am sentence a&#10;I am sentence b" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></textarea><small>Each non-empty line is saved as a separate sentence.</small></div></div>' +
            '<div class="form-group" id="df_select_values_container" style="display:none;">' +
            '<label>Select Options</label>' +
            '<div style="display:flex; gap:8px;">' +
            '<input type="text" id="df_default_value_input" placeholder="Enter option..." style="flex:1; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<button type="button" class="btn btn-secondary" onclick="addDefaultValue()">Add</button>' +
            '</div>' +
            '<div id="df_default_values_list" style="margin-top:8px;"></div>' +
            '</div>' +
            '<div class="form-group" id="df_multiple_selection_container" style="display:none;">' +
            '<label>Multiple selections values</label>' +
            '<textarea id="df_multiple_selection_text" rows="3" placeholder="I am value a, I am value b, I am value c..." style="width:100%;padding:10px;border:2px solid #e5e7eb;border-radius:8px;"></textarea>' +
            '<button type="button" class="btn btn-secondary" style="margin-top:8px;" onclick="addMultipleSelectionValues()">Add</button>' +
            '<small style="display:block;color:#6b7280;margin-top:6px;">Separate values with commas. Without a comma, the entire text is added as one value.</small>' +
            '<div id="df_multiple_selection_values_list" style="margin-top:8px;"></div>' +
            '</div>' +
            '<div class="form-group" id="df_url_container" style="display:none;">' +
            '<label>Button URL (onclick value)</label>' +
            '<div id="df_url_input_container">' +
            '<input type="url" id="df_url_value" placeholder="Enter URL (e.g., https://example.com)..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<small style="color:#6b7280;">This URL will be opened in a new window/tab when the button is clicked.</small>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '<div id="objects_fields_container" style="display:none;">' +
            '<h4 style="margin-bottom:10px;"> Sub Fields</h4>' +
            '<div id="sub_fields_list"></div>' +
            '<div style="display:flex; gap:10px; margin-top:10px; flex-wrap:wrap;">' +
            '<button type="button" class="btn btn-secondary btn-sm" onclick="addSubField()">+ Add Sub Field</button>' +
            '</div>' +
            '</div>' +
            '<div style="display:flex; gap:10px; margin-top:20px;">' +
            '<button type="button" class="btn btn-success" onclick="saveDynamicField()"> Save Field</button>' +
            '<button type="button" class="btn btn-secondary" onclick="closeDialog()">Cancel</button>' +
            '</div>';
        
        showDialog('Add Dynamic Field', '', null, content);
        toggleFieldCategory();
        toggleDefaultValueInput();
        loadCopySentenceInputs(null);
        renderDefaultValuesList();
    }

    async function saveDynamicField(addAnotherAfterSave) {
        var fieldData;
        try {
            fieldData = collectDynamicFieldEditorData();
        } catch (error) {
            showDialog('Check field details', error.message);
            return;
        }
        await persistDynamicFieldDefinition('save_global_single_field', fieldData, null, !!addAnotherAfterSave);
    }

    // ============================================================
    // ===== USER SEARCH DIALOG =====
    // ============================================================

    function openUserSearchDialog() {
        var userSearchDialog = document.getElementById('userSearchDialog');
        moveSceneIQModalToBody(userSearchDialog);
        lockSceneIQBackground(userSearchDialog);
        userSearchDialog.classList.add('active');
        document.getElementById('userSearchInput').value = '';
        document.querySelectorAll('#userSearchResults .user-search-item').forEach(function(item) {
            item.style.display = 'block';
        });
        setTimeout(function() { document.getElementById('userSearchInput').focus(); }, 100);
    }

    function closeUserSearchDialog() {
        var userSearchDialog = document.getElementById('userSearchDialog');
        if (!userSearchDialog) return;
        userSearchDialog.classList.remove('active');
        unlockSceneIQBackground(userSearchDialog);
        restoreSceneIQModal(userSearchDialog);
    }

    function filterUserSearch() {
        var query = document.getElementById('userSearchInput').value.toLowerCase().trim();
        var items = document.querySelectorAll('#userSearchResults .user-search-item');
        items.forEach(function(item) {
            var name = item.getAttribute('data-user-name') || '';
            var email = item.getAttribute('data-user-email') || '';
            var match = name.includes(query) || email.includes(query);
            item.style.display = match ? 'block' : 'none';
        });
    }

    function selectUserFromSearch(userId) {
        var form = document.createElement('form');
        form.method = 'POST';
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'select_user';
        input.value = 'true';
        var input2 = document.createElement('input');
        input2.type = 'hidden';
        input2.name = 'user_id';
        input2.value = userId;
        form.appendChild(input);
        form.appendChild(input2);
        document.body.appendChild(form);
        form.submit();
    }

    document.getElementById('userSearchDialog').addEventListener('click', function(e) {
        if (e.target === this) closeUserSearchDialog();
    });

    // ============================================================
    // ===== SYNC ON INPUT =====
    // ============================================================

    document.addEventListener('input', function(e) {
        var addEntryTab = document.getElementById('user_tab_add_entry');
        if (addEntryTab && addEntryTab.contains(e.target)) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                syncFieldValues();
            }
        }
    });

    document.addEventListener('paste', function(e) {
        var addEntryTab = document.getElementById('user_tab_add_entry');
        if (addEntryTab && addEntryTab.contains(e.target)) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                setTimeout(function() { syncFieldValues(); }, 100);
            }
        }
    });
    // ============================================================
    // ===== TRANSCRIPT DETECTION AND CONVERSION ENGINE =====
    // ============================================================

   
</script>
<script>
    // ============================================================
    // ===== COPY BUTTON VISIBILITY BASED ON TAB FEATURES =====
    // ============================================================

    // Feature flags must be read from the actual active root/child tab.
    function sceneIQFeatureEnabled(value) {
        return value === true || value === 1 || value === '1' ||
            (typeof value === 'string' && ['true', 'yes', 'on', 'enabled'].indexOf(value.trim().toLowerCase()) !== -1);
    }

    function updateCopyButtonsVisibility() {
        var addEntryTab = document.getElementById('user_tab_add_entry');
        if (!addEntryTab) return;

        var activeContent = addEntryTab.querySelector('.dynamic-sub-tab-content.active');
        if (!activeContent) {
            console.debug('[SceneIQ Transcript Detection] Copy-button visibility skipped: no active root tab.');
            return;
        }

        var features = (typeof getActiveTabFeatures === 'function')
            ? getActiveTabFeatures()
            : { copy_button: false, transcript_detection: false, transcript_with_structured_data_detection: false };

        var copyButtonEnabled = sceneIQFeatureEnabled(features.copy_button);
        var transcriptEnabled = sceneIQFeatureEnabled(features.transcript_detection);
        var isConversionTab = false;

        try {
            var rootMatch = (activeContent.id || '').match(/^newProjectSubTab_tab_(\d+)$/);
            var rootTab = rootMatch && window.userDynamicTabs ? window.userDynamicTabs[parseInt(rootMatch[1], 10)] : null;
            var activeChild = activeContent.querySelector('.nested-child-content.active');
            if (activeChild && rootTab && Array.isArray(rootTab.sub_tabs)) {
                var wrapper = activeChild.closest('.nested-project-tabs');
                var children = wrapper ? Array.prototype.slice.call(wrapper.querySelectorAll('.nested-child-content')) : [];
                var childIndex = children.indexOf(activeChild);
                var shownChildren = rootTab.sub_tabs.filter(function(child) {
                    return !Object.prototype.hasOwnProperty.call(child, 'display_tab') || sceneIQFeatureEnabled(child.display_tab);
                });
                if (childIndex >= 0 && shownChildren[childIndex]) rootTab = shownChildren[childIndex];
            }
            var activeTabButton = addEntryTab.querySelector('#newProjectSubTabs .dynamic-sub-tab-btn.active');
            var tabText = [
                rootTab && rootTab.tab_name,
                activeTabButton && activeTabButton.textContent,
                activeContent.id,
                activeContent.textContent
            ].join(' ').toLowerCase();
            isConversionTab = /convert.*(transcrib|json)|(transcrib|json).*convert|tojson/.test(tabText);
        } catch (e) {
            console.warn('[SceneIQ Transcript Detection] Could not resolve active tab name:', e);
        }

        // The dedicated transcript-to-JSON workflow should not be cluttered by the
        // unrelated generic "Copy Data" buttons. Its result has its own Copy JSON button.
        var allowGenericCopy = copyButtonEnabled && !isConversionTab;
        // Match containers whose IDs start with field_/object_ and end with _container.
        // Keep the broad .btn-secondary fallback, but use valid CSS selector syntax.
        var buttons = activeContent.querySelectorAll(
            '[id^="field_"][id$="_container"] .btn-secondary, [id^="object_"][id$="_container"] .btn-secondary, .btn-secondary'
        );

        buttons.forEach(function(btn) {
            if (btn.classList.contains('individual-sentence-copy-button')) {
                btn.style.display = 'inline-flex';
                return;
            }
            var text = (btn.textContent || '').trim();
            if (/copy/i.test(text) || text.indexOf('📋') !== -1) {
                btn.style.display = allowGenericCopy ? 'inline-block' : 'none';
            }
        });

        console.debug('[SceneIQ Transcript Detection] Copy-button policy', {
            copy_button_enabled: copyButtonEnabled,
            transcript_detection_enabled: transcriptEnabled,
            conversion_tab_detected: isConversionTab,
            generic_copy_buttons_visible: allowGenericCopy
        });
    }

    // Override switchNewProjectSubTab to also update copy buttons
    var _originalSwitchNewProjectSubTab2 = switchNewProjectSubTab;
    switchNewProjectSubTab = function(tabId, btn) {
        _originalSwitchNewProjectSubTab2(tabId, btn);
        setTimeout(function() { 
            detectTranscripts();
            updateCopyButtonsVisibility();
        }, 200);
    };

    // Run copy button visibility check after DOM changes
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            updateCopyButtonsVisibility();
        }, 500);
    });

    // Also run after any AJAX-like updates
    var _originalRenderDynamicTabsEditor = renderDynamicTabsEditor;
    renderDynamicTabsEditor = function() {
        _originalRenderDynamicTabsEditor();
        setTimeout(function() {
            updateCopyButtonsVisibility();
        }, 300);
    };

    console.log('✅ Copy button visibility based on tab features initialized');
</script>
<script>
    // ============================================================
    // ===== TRANSCRIPT DETECTION AND CONVERSION ENGINE =====
    // ============================================================

    // Store transcript data for each element
    window._transcriptData = {};

    // Store character data from the separate element
    window._characterData = null;

    // Store conversion results for each element
    window._conversionResults = {};

    // Function to get the currently active tab index
    function getActiveTabIndex() {
        var activeTabBtn = document.querySelector('#user_tab_add_entry .dynamic-sub-tab-btn.active');
        if (!activeTabBtn) return -1;
        
        var tabId = activeTabBtn.getAttribute('data-tab');
        if (!tabId) {
            var onclickAttr = activeTabBtn.getAttribute('onclick');
            if (onclickAttr) {
                var match = onclickAttr.match(/switchNewProjectSubTab\('([^']+)'/);
                if (match) tabId = match[1];
            }
        }
        
        if (!tabId) return -1;
        var tabIndex = parseInt(tabId.replace('tab_', ''));
        return isNaN(tabIndex) ? -1 : tabIndex;
    }

    // Get feature flags for the currently active New Project tab, including nested tabs.
    function getActiveTabFeatures() {
        var enabledDefaults = {
            copy_button: true,
            transcript_detection: true,
            transcript_with_structured_data_detection: true
        };

        // Database rows may contain JSON booleans, integers, or legacy string values.
        // Normalize all common enabled representations instead of requiring === true.
        function featureIsEnabled(value) {
            return value === true || value === 1 || value === '1' ||
                   (typeof value === 'string' && ['true', 'yes', 'on', 'enabled'].indexOf(value.trim().toLowerCase()) !== -1);
        }

        function normalizeFeatures(tab) {
            var f = (tab && tab.additional_features) || {};
            return {
                copy_button: featureIsEnabled(f.copy_button),
                transcript_detection: featureIsEnabled(f.transcript_detection),
                transcript_with_structured_data_detection: featureIsEnabled(f.transcript_with_structured_data_detection)
            };
        }

        // The All Fields view intentionally enables all three helpers.
        var allFieldsButton = document.getElementById('newProjectSubTabBtn_all_fields') ||
                              document.getElementById('newProjectSubTab_all_fields');
        if ((allFieldsButton && allFieldsButton.classList.contains('active')) ||
            (document.getElementById('newProjectSubTab_all_fields') &&
             document.getElementById('newProjectSubTab_all_fields').classList.contains('active'))) {
            return enabledDefaults;
        }

        var addEntryTab = document.getElementById('user_tab_add_entry');
        if (!addEntryTab) return enabledDefaults;

        // Resolve the actual visible content first. Root content IDs are rendered as
        // newProjectSubTab_tab_N, where N is the index in userDynamicTabs.
        var activeRootContent = addEntryTab.querySelector('.dynamic-sub-tab-content.active');
        if (activeRootContent) {
            var rootMatch = (activeRootContent.id || '').match(/^newProjectSubTab_tab_(\d+)$/);
            if (rootMatch) {
                var rootIndex = parseInt(rootMatch[1], 10);
                var rootTab = userDynamicTabs && userDynamicTabs[rootIndex];

                if (rootTab) {
                    // Nested child tabs have no IDs/data attributes in the markup.
                    // Their position in the active wrapper maps directly to sub_tabs.
                    var activeChildContent = activeRootContent.querySelector('.nested-child-content.active');
                    var activeNestedWrapper = activeChildContent ? activeChildContent.closest('.nested-project-tabs') : null;

                    if (activeChildContent && activeNestedWrapper && Array.isArray(rootTab.sub_tabs)) {
                        var visibleChildContents = Array.prototype.slice.call(
                            activeNestedWrapper.querySelectorAll('.nested-child-content')
                        );
                        var childIndex = visibleChildContents.indexOf(activeChildContent);
                        var visibleChildButtons = Array.prototype.slice.call(
                            activeNestedWrapper.querySelectorAll('.nested-child-btn')
                        );
                        var activeChildButton = activeNestedWrapper.querySelector('.nested-child-btn.active');

                        if (childIndex < 0 && activeChildButton) {
                            childIndex = visibleChildButtons.indexOf(activeChildButton);
                        }

                        // PHP filters hidden children before rendering, so map by the
                        // displayed child order, matching the rendered child content.
                        var displayedChildren = rootTab.sub_tabs.filter(function(child) {
                            return !Object.prototype.hasOwnProperty.call(child, 'display_tab') || !!child.display_tab;
                        });
                        if (childIndex >= 0 && displayedChildren[childIndex]) {
                            return normalizeFeatures(displayedChildren[childIndex]);
                        }
                    }

                    // A root without a nested active child uses the root's own features.
                    return normalizeFeatures(rootTab);
                }
            }
        }

        // Fallback for legacy/All Fields layouts.
        var activeButton = addEntryTab.querySelector('#newProjectSubTabs .dynamic-sub-tab-btn.active');
        if (activeButton) {
            var onclick = activeButton.getAttribute('onclick') || '';
            var match = onclick.match(/switchNewProjectSubTab\(['"]tab_(\d+)['"]/);
            if (match && userDynamicTabs && userDynamicTabs[parseInt(match[1], 10)]) {
                return normalizeFeatures(userDynamicTabs[parseInt(match[1], 10)]);
            }
        }

        return enabledDefaults;
    }

    // Detect if character data is in structured JSON format with improved detection
    function detectStructuredCharacterData(text) {
        if (!text || typeof text !== 'string') return false;
        
        try {
            // Structured conversion JSON is identified by its script property.
            var hasScriptArray = text.match(/"script"\s*:\s*\[/);
            var hasScriptObject = text.match(/"script"\s*:\s*\{/);
            var hasValidScript = hasScriptArray !== null || hasScriptObject !== null;

            if (hasValidScript) {
                return true;
            }

            // Fallback: try to find the JSON object and validate it
            var jsonStart = text.indexOf('{');
            if (jsonStart !== -1) {
                var braceCount = 0;
                var jsonEnd = -1;
                for (var i = jsonStart; i < text.length; i++) {
                    if (text[i] === '{') braceCount++;
                    else if (text[i] === '}') {
                        braceCount--;
                        if (braceCount === 0) {
                            jsonEnd = i + 1;
                            break;
                        }
                    }
                }
                if (jsonEnd !== -1) {
                    var jsonStr = text.substring(jsonStart, jsonEnd);
                    var parsed = JSON.parse(jsonStr);
                    if (parsed && parsed.script) {
                        return true;
                    }
                }
            }
            
            return false;
        } catch (e) {
            return false;
        }
    }

    // Detect transcripts across rendered dynamic-tab fields, including fields inside
    // inactive tabs. Inactive tab panels are often display:none, so scanning only the
    // active child panel misses the conversion field entirely.
    function detectTranscripts() {
        var addEntryTab = document.getElementById('user_tab_add_entry');
        if (!addEntryTab) {
            console.debug('[SceneIQ Transcript Detection] Not run: #user_tab_add_entry was not found.');
            return;
        }

        function tabDetailsForElement(element) {
            var rootContent = element.closest('.dynamic-sub-tab-content');
            var rootIndexMatch = rootContent && (rootContent.id || '').match(/^newProjectSubTab_tab_(\d+)$/);
            var rootIndex = rootIndexMatch ? parseInt(rootIndexMatch[1], 10) : -1;
            var rootTab = rootIndex >= 0 && Array.isArray(userDynamicTabs) ? userDynamicTabs[rootIndex] : null;
            var rootName = rootTab ? (rootTab.tab_name || rootTab.name || '') : '';
            var childContent = element.closest('.nested-child-content');
            var childName = '';
            var tabConfig = rootTab;

            if (childContent && rootTab && Array.isArray(rootTab.sub_tabs)) {
                var wrapper = childContent.closest('.nested-project-tabs');
                var contents = wrapper ? Array.prototype.slice.call(wrapper.querySelectorAll('.nested-child-content')) : [];
                var childIndex = contents.indexOf(childContent);
                var displayedChildren = rootTab.sub_tabs.filter(function(child) {
                    return !Object.prototype.hasOwnProperty.call(child, 'display_tab') || !!child.display_tab;
                });
                if (childIndex >= 0 && displayedChildren[childIndex]) {
                    tabConfig = displayedChildren[childIndex];
                    childName = tabConfig.tab_name || tabConfig.name || '';
                }
            }

            var f = (tabConfig && tabConfig.additional_features) || {};
            // Use the shared top-level normalizer here. The similarly named
            // featureIsEnabled() inside getActiveTabFeatures() is locally scoped
            // and cannot be called from this function; doing so previously threw
            // a ReferenceError and aborted the entire transcript scan.
            var features = {
                copy_button: sceneIQFeatureEnabled(f.copy_button),
                transcript_detection: sceneIQFeatureEnabled(f.transcript_detection),
                transcript_with_structured_data_detection: sceneIQFeatureEnabled(f.transcript_with_structured_data_detection)
            };
            var dedicated = /convert.*(transcrib|json)|(transcrib|json).*convert|to\s*json/i.test(rootName + ' ' + childName);
            return {
                root_index: rootIndex,
                root_name: rootName,
                child_name: childName || null,
                root_content_id: rootContent ? rootContent.id : null,
                features: features,
                dedicated: dedicated
            };
        }

        var activeRoot = addEntryTab.querySelector('.dynamic-sub-tab-content.active');
        var activeChild = activeRoot ? activeRoot.querySelector('.nested-child-content.active') : null;
        var activeRootButton = addEntryTab.querySelector('#newProjectSubTabs .dynamic-sub-tab-btn.active');
        var activeChildButton = activeChild && activeChild.closest('.nested-project-tabs')
            ? activeChild.closest('.nested-project-tabs').querySelector('.nested-child-btn.active') : null;

        // Scan all configured tab panels, not only the currently active panel. This
        // also detects text already present in a hidden conversion field before the
        // user switches to that tab; hidden controls are not excluded from detection.
        var allElements = Array.prototype.slice.call(addEntryTab.querySelectorAll(
            'textarea, input, [contenteditable="true"], [contenteditable=""], [role="textbox"], .ql-editor'
        ));
        var elements = allElements.filter(function(el) {
            var type = (el.getAttribute('type') || '').toLowerCase();
            if (el.tagName === 'INPUT' && ['hidden', 'button', 'submit', 'reset', 'checkbox', 'radio', 'file', 'image'].indexOf(type) !== -1) return false;
            // Only fields belonging to a configured dynamic root or child are relevant.
            return !!el.closest('.dynamic-sub-tab-content');
        });

        var activeRootName = activeRootButton ? (activeRootButton.textContent || '').trim() : '(no active root button)';
        var activeChildName = activeChildButton ? (activeChildButton.textContent || '').trim() : null;
        var activeFeatures = (typeof getActiveTabFeatures === 'function') ? getActiveTabFeatures() : {};
        var report = {
            event: 'scan',
            active_root_id: activeRoot ? activeRoot.id : null,
            active_root_name: activeRootName,
            active_child_found: !!activeChild,
            active_child_name: activeChildName,
            feature_flags: {
                transcript_detection: sceneIQFeatureEnabled(activeFeatures.transcript_detection),
                structured_data_detection: sceneIQFeatureEnabled(activeFeatures.transcript_with_structured_data_detection),
                copy_button: sceneIQFeatureEnabled(activeFeatures.copy_button)
            },
            visible_fields_scanned: elements.length,
            fields_scanned_across_tabs: true,
            dedicated_conversion_tab: /convert.*(transcrib|json)|(transcrib|json).*convert|to\s*json/i.test(activeRootName + ' ' + (activeChildName || '')),
            fields: [],
            timestamped_fields: [],
            matches: []
        };

        elements.forEach(function(element, elementIndex) {
            var value = typeof element.value === 'string' ? element.value : (element.innerText || element.textContent || '');
            var fieldId = element.id || '';
            var fieldKey = element.getAttribute('name') || element.getAttribute('data-field-key') || '';
            var labelEl = element.labels && element.labels.length ? element.labels[0] : null;
            var labelText = labelEl ? labelEl.textContent.trim() : '';
            if (!fieldId) {
                fieldId = 'sceneiq_transcript_field_' + elementIndex;
                element.id = fieldId;
            }

            var tabDetails = tabDetailsForElement(element);
            var transcriptEnabled = !!tabDetails.features.transcript_detection;
            var structuredEnabled = !!tabDetails.features.transcript_with_structured_data_detection;
            var copyEnabled = !!tabDetails.features.copy_button;
            var dedicatedConversionTab = !!tabDetails.dedicated;
            var normalizedValue = value.replace(/[０-９]/g, function(ch) {
                return String.fromCharCode(ch.charCodeAt(0) - 0xFEE0);
            });
            var timestampMatches = normalizedValue.match(/(?:^|[^\d])\(?\s*\d{1,2}:\d{2}(?:\:\d{2})?\s*\)?/gm) || [];
            var hasTime = timestampMatches.length > 0;
            var hasStructuredData = typeof detectStructuredCharacterData === 'function' && detectStructuredCharacterData(value);
            var shouldShowTranscript = (hasTime && transcriptEnabled) ||
                (hasStructuredData && structuredEnabled) ||
                (dedicatedConversionTab && transcriptEnabled && value.trim().length > 0);
            var elementId = fieldId + '__transcript__' + (tabDetails.root_content_id || 'active_root') + '__' + elementIndex;

            var fieldReport = {
                id: fieldId,
                name: fieldKey,
                label: labelText,
                tab_name: tabDetails.root_name,
                child_tab_name: tabDetails.child_name,
                root_content_id: tabDetails.root_content_id,
                element_type: element.tagName.toLowerCase(),
                characters: value.length,
                has_timestamp: hasTime,
                timestamp_matches: timestampMatches.length,
                has_structured_data: hasStructuredData,
                enabled_for_transcript_detection: transcriptEnabled,
                dedicated_conversion_tab: dedicatedConversionTab,
                should_show_convert_action: shouldShowTranscript,
                preview: value.slice(0, 180)
            };
            report.fields.push(fieldReport);
            if (hasTime) {
                report.timestamped_fields.push({
                    id: fieldId,
                    tab_name: tabDetails.root_name,
                    child_tab_name: tabDetails.child_name,
                    timestamp_matches: timestampMatches.length,
                    transcript_detection_enabled: transcriptEnabled,
                    convert_json_button_expected: transcriptEnabled,
                    preview: value.slice(0, 180)
                });
            }

            var existingIndicator = document.getElementById('transcript_indicator_' + elementId);
            var existingResult = document.getElementById('transcript_result_' + elementId);

            if (!shouldShowTranscript) {
                if (existingIndicator) existingIndicator.remove();
                if (existingResult) existingResult.remove();
                if (window._transcriptData) delete window._transcriptData[elementId];
                if (window._conversionResults) delete window._conversionResults[elementId];
                return;
            }

            window._transcriptData = window._transcriptData || {};
            window._conversionResults = window._conversionResults || {};
            window._transcriptData[elementId] = { value: value, element: element, features: tabDetails.features, tabDetails: tabDetails };
            if (typeof detectCharacterData === 'function') detectCharacterData();

            var indicator = existingIndicator;
            if (!indicator) {
                indicator = document.createElement('div');
                indicator.id = 'transcript_indicator_' + elementId;
                indicator.className = 'sceneiq-transcript-detection-indicator';
                indicator.style.cssText = 'margin-top:8px;padding:8px 12px;background:#d1fae5;border-radius:8px;border-left:4px solid #00695c;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;';
                var container = element.closest('.form-group') || element.closest('.sub-field-group') || element.parentElement;
                if (container) container.appendChild(indicator);
                else if (element.parentNode) element.parentNode.insertBefore(indicator, element.nextSibling);
            }

            var label = hasStructuredData && structuredEnabled
                ? '📝 Structured JSON Data Detected'
                : (hasTime ? '📝 Timestamped Transcript Detected (' + timestampMatches.length + ' timestamp matches)' : '📝 Transcript Text Detected');
            var elementIdArg = "'" + elementId.replace(/\\/g, "\\\\").replace(/'/g, "\\'") + "'";
            var buttonsHtml = '<div style="display:flex;gap:8px;flex-wrap:wrap;">';

            if (transcriptEnabled && value.trim().length > 0 && (hasTime || dedicatedConversionTab)) {
                buttonsHtml += '<button type="button" class="btn btn-success btn-sm sceneiq-convert-json-button" onclick="convertAndDisplayInline(' +
                    elementIdArg +
                    ')" style="padding:5px 12px;font-size:12px;">🔄 Convert to JSON</button>';
                buttonsHtml += '<button type="button" class="btn btn-primary btn-sm" onclick="openTranscriptDialog(' +
                    elementIdArg +
                    ')" style="padding:5px 12px;font-size:12px;">View Transcript</button>';
            } else if (hasStructuredData && structuredEnabled) {
                buttonsHtml += '<button type="button" class="btn btn-primary btn-sm" onclick="convertToText(' +
                    elementIdArg +
                    ')" style="padding:5px 12px;font-size:12px;">📄 Convert to Text</button>';
            }
            if (copyEnabled && !dedicatedConversionTab) {
                buttonsHtml += '<button type="button" class="btn btn-secondary btn-sm" onclick="copyTranscriptValue(' +
                    elementIdArg +
                    ')" style="padding:5px 12px;font-size:12px;">📋 Copy Transcript</button>';
            }
            buttonsHtml += '</div>';
            indicator.innerHTML = '<span style="color:#065f46;font-weight:600;">' + label + '</span>' + buttonsHtml;
            indicator.style.display = 'flex';

            report.matches.push({
                id: fieldId,
                detection_key: elementId,
                tab_name: tabDetails.root_name,
                child_tab_name: tabDetails.child_name,
                timestamp_matches: timestampMatches.length,
                label: labelText,
                preview: value.slice(0, 180)
            });
        });

        var signature = JSON.stringify({
            root: report.active_root_id,
            rootName: report.active_root_name,
            fields: report.fields.map(function(f) {
                return [f.id, f.tab_name, f.child_tab_name, f.characters, f.has_timestamp, f.timestamp_matches, f.should_show_convert_action];
            }),
            matches: report.matches.map(function(m) { return [m.id, m.timestamp_matches, m.tab_name]; })
        });

        window._sceneIQTranscriptLastScan = report;
        if (window._sceneIQLastTranscriptDebugSignature !== signature) {
            window._sceneIQLastTranscriptDebugSignature = signature;
            console.groupCollapsed('%c[SceneIQ Transcript Detection] Scan report', 'color:#00695c;font-weight:bold;');
            console.info('ACTIVE TAB / FEATURE STATUS', {
                root_tab: report.active_root_name,
                child_tab: report.active_child_name,
                active_feature_flags: report.feature_flags,
                configured_fields_scanned_across_tabs: report.visible_fields_scanned
            });
            console.log('Copyable debug report:');
            console.log(JSON.stringify(report, null, 2));
            console.table(report.fields);
            if (report.matches.length) {
                console.info('TRANSCRIPT ACTIONS ADDED:', report.matches.length, 'matching field(s).');
                console.table(report.matches);
            } else {
                console.warn('NO CONVERSION BUTTONS ADDED. Check fields[].preview, tab_name, enabled_for_transcript_detection, and should_show_convert_action.');
            }
            console.groupEnd();
        }
    }

    // Helper function to copy transcript value
    function copyTranscriptValue(elementId) {
        var data = window._transcriptData[elementId];
        if (data) {
            copyToClipboard(data.value);
        } else {
            showDialog('Info', 'No transcript data to copy.');
        }
    }

    // Function to detect character data from other elements
    function detectCharacterData() {
        var addEntryTab = document.getElementById('user_tab_add_entry');
        if (!addEntryTab) return;
        
        var activeContent = addEntryTab.querySelector('.dynamic-sub-tab-content.active');
        if (!activeContent) return;
        
        var elements = activeContent.querySelectorAll('input, textarea, select');
        
        var foundCharacterData = null;
        
        elements.forEach(function(element) {
            var value = element.value || element.textContent || '';
            if (!value) return;
            
            // Look for JSON character data
            var charData = extractCharacterDataFromString(value);
            if (charData && Object.keys(charData).length > 0) {
                foundCharacterData = charData;
                console.log('✅ Found character data in element:', element.id || 'unnamed');
            }
        });
        
        // If found character data, store it globally
        if (foundCharacterData) {
            window._characterData = foundCharacterData;
            console.log('✅ Character data stored globally with ' + Object.keys(foundCharacterData).length + ' characters');
        }
    }

    // Extract character data from a string
    function extractCharacterDataFromString(text) {
        var characterData = {};
        
        if (!text) return characterData;
        
        // Look for JSON array format: [{"character": "Name", ...}, ...]
        var jsonArrayMatch = text.match(/\[\s*\{[^}]*"character"\s*:\s*"[^"]*"[^}]*\}\s*(?:,\s*\{[^}]*"character"\s*:\s*"[^"]*"[^}]*\}\s*)*\]/);
        if (jsonArrayMatch) {
            try {
                var charArray = JSON.parse(jsonArrayMatch[0]);
                if (Array.isArray(charArray)) {
                    charArray.forEach(function(charObj) {
                        var characterName = charObj.character || '';
                        if (characterName) {
                            var relation = charObj.relation || '';
                            var castDetails = charObj.character_details || '';
                            var prompt = charObj.character_visual_prompt || '';
                            
                            // Extract clean name from character field (remove parentheses, special chars)
                            var cleanName = extractCleanName(characterName);
                            
                            var fullDetails = "**Story Details:**\n" + castDetails + "\n\n**Character Image Generation Prompt:**\n\"" + prompt + "\"";
                            
                            characterData[characterName] = {
                                name: characterName,
                                clean_name: cleanName,
                                relation: relation,
                                character_details: castDetails,
                                details: fullDetails,
                                prompt: prompt,
                                // Store all possible variations for matching
                                variations: generateNameVariations(cleanName, characterName)
                            };
                        }
                    });
                    if (Object.keys(characterData).length > 0) {
                        return characterData;
                    }
                }
            } catch (e) {
                console.log('⚠️ Failed to parse JSON array:', e.message);
            }
        }
        
        // Look for individual character objects
        var charObjects = text.match(/\{[^}]*"character"\s*:\s*"[^"]*"[^}]*"relation"\s*:\s*"[^"]*"[^}]*"character_details"\s*:\s*"[^"]*"[^}]*"character_visual_prompt"\s*:\s*"[^"]*"[^}]*\}/g);
        if (charObjects) {
            charObjects.forEach(function(charObjStr) {
                try {
                    var charObj = JSON.parse(charObjStr);
                    var characterName = charObj.character || '';
                    if (characterName) {
                        var relation = charObj.relation || '';
                        var castDetails = charObj.character_details || '';
                        var prompt = charObj.character_visual_prompt || '';
                        
                        var cleanName = extractCleanName(characterName);
                        
                        var fullDetails = "**Story Details:**\n" + castDetails + "\n\n**Character Image Generation Prompt:**\n\"" + prompt + "\"";
                        
                        characterData[characterName] = {
                            name: characterName,
                            clean_name: cleanName,
                            relation: relation,
                            character_details: castDetails,
                            details: fullDetails,
                            prompt: prompt,
                            variations: generateNameVariations(cleanName, characterName)
                        };
                    }
                } catch (e) {
                    // Skip invalid JSON objects
                }
            });
            if (Object.keys(characterData).length > 0) {
                return characterData;
            }
        }
        
        // Look for JSON code block
        var jsonCodeMatch = text.match(/```json\s*(\[[\s\S]*?\])\s*```/);
        if (jsonCodeMatch) {
            try {
                var charArray2 = JSON.parse(jsonCodeMatch[1]);
                if (Array.isArray(charArray2)) {
                    charArray2.forEach(function(charObj) {
                        var characterName = charObj.character || '';
                        if (characterName) {
                            var relation = charObj.relation || '';
                            var castDetails = charObj.character_details || '';
                            var prompt = charObj.character_visual_prompt || '';
                            
                            var cleanName = extractCleanName(characterName);
                            
                            var fullDetails = "**Story Details:**\n" + castDetails + "\n\n**Character Image Generation Prompt:**\n\"" + prompt + "\"";
                            
                            characterData[characterName] = {
                                name: characterName,
                                clean_name: cleanName,
                                relation: relation,
                                character_details: castDetails,
                                details: fullDetails,
                                prompt: prompt,
                                variations: generateNameVariations(cleanName, characterName)
                            };
                        }
                    });
                    if (Object.keys(characterData).length > 0) {
                        return characterData;
                    }
                }
            } catch (e) {
                console.log('⚠️ Failed to parse JSON code block:', e.message);
            }
        }
        
        return characterData;
    }

    // Extract clean name from character field (remove parentheses and special chars)
    function extractCleanName(characterName) {
        // Remove anything in parentheses
        var cleanName = characterName.replace(/\([^)]*\)/g, '').trim();
        // Remove extra spaces
        cleanName = cleanName.replace(/\s+/g, ' ').trim();
        // If cleanName is empty, use the original
        if (!cleanName) cleanName = characterName;
        return cleanName;
    }

    // Generate variations of a name for intelligent matching
    function generateNameVariations(cleanName, fullName) {
        var variations = new Set();
        
        // Add clean name
        if (cleanName) {
            variations.add(cleanName.toLowerCase());
            // Add without spaces
            variations.add(cleanName.toLowerCase().replace(/\s+/g, ''));
        }
        
        // Add full name variations
        if (fullName) {
            var fullLower = fullName.toLowerCase();
            variations.add(fullLower);
            // Remove parentheses and commas
            var stripped = fullLower.replace(/[(),]/g, '').replace(/\s+/g, ' ').trim();
            variations.add(stripped);
            variations.add(stripped.replace(/\s+/g, ''));
        }
        
        // Split by commas, spaces, and extract individual words
        var parts = fullName.split(/[,\s]+/);
        parts.forEach(function(part) {
            part = part.trim().toLowerCase();
            if (part.length > 2) { // Only consider meaningful words
                variations.add(part);
                // Remove parentheses from part
                var cleanPart = part.replace(/[()]/g, '').trim();
                if (cleanPart.length > 2) {
                    variations.add(cleanPart);
                }
            }
        });
        
        // Generate combinations of words (2-word, 3-word combos)
        var words = [];
        parts.forEach(function(part) {
            part = part.trim().toLowerCase().replace(/[()]/g, '');
            if (part.length > 2) {
                words.push(part);
            }
        });
        
        // Generate 2-word combinations
        for (var i = 0; i < words.length; i++) {
            for (var j = i + 1; j < words.length; j++) {
                var combo = words[i] + words[j];
                variations.add(combo);
                var comboSpace = words[i] + ' ' + words[j];
                variations.add(comboSpace);
            }
        }
        
        // Generate 3-word combinations
        for (var i = 0; i < words.length; i++) {
            for (var j = i + 1; j < words.length; j++) {
                for (var k = j + 1; k < words.length; k++) {
                    var combo = words[i] + words[j] + words[k];
                    variations.add(combo);
                    var comboSpace = words[i] + ' ' + words[j] + ' ' + words[k];
                    variations.add(comboSpace);
                }
            }
        }
        
        // Return as array
        return Array.from(variations);
    }

    // Function to open transcript dialog
    function openTranscriptDialog(elementId) {
        // Validate against the tab that owns this transcript field, not whichever
        // tab happens to be active when the dialog is opened.
        var data = window._transcriptData && window._transcriptData[elementId];
        var features = data && data.features ? data.features : getActiveTabFeatures();
        if (!sceneIQFeatureEnabled(features.transcript_detection)) {
            console.warn('[SceneIQ Transcript Detection] Convert to JSON blocked: transcript_detection is disabled for the active tab.', features);
            showDialog('Info', 'Transcript detection is not enabled for this tab. Enable it in the server-side tab configuration and save.');
            return;
        }
        
        if (!data) {
            showDialog('Error', 'Transcript data not found.');
            return;
        }
        
        var characterStatus = window._characterData ? 
            '<span style="color:#065f46;">✅ Character data found (' + Object.keys(window._characterData).length + ' characters)</span>' : 
            '<span style="color:#f59e0b;">⚠️ No character data found - converting transcript only</span>';
        
        var content = 
            '<div style="margin-bottom:15px;">' +
            '<label style="display:block; font-weight:600; color:#1a1a2e; margin-bottom:8px;">Transcript Content</label>' +
            '<textarea id="transcriptTextarea" rows="12" style="width:100%; padding:12px; border:2px solid #e5e7eb; border-radius:8px; font-family:monospace; font-size:14px; background:#fafafa; resize:vertical;">' + 
            data.value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + 
            '</textarea>' +
            '</div>' +
            '<div style="margin-bottom:15px; padding:10px; background:#f8fafc; border-radius:8px; border:1px solid #e5e7eb;">' +
            '<div style="font-size:13px; color:#1a1a2e;">' + characterStatus + '</div>' +
            '</div>' +
            '<div style="margin-top:10px;">' +
            '<button type="button" class="btn btn-primary" onclick="convertTranscriptToJson(\'' + elementId + '\')" style="width:100%; padding:12px;">🔄 Convert Transcript to JSON</button>' +
            '</div>' +
            '<div id="transcriptConversionResult" style="margin-top:15px; display:none;"></div>' +
            '<div id="transcriptJsonResult" style="margin-top:15px; display:none;"></div>';
        
        showDialog('Transcript Viewer', '', null, content);
    }

    // NEW FUNCTION: Convert to Text format with batches
    function convertToText(elementId) {
        // Check if structured data detection is enabled for this tab
        var features = getActiveTabFeatures();
        if (!(features.transcript_with_structured_data_detection === true || features.transcript_with_structured_data_detection === 1)) {
            showDialog('Info', 'Structured data detection is not enabled for this tab.');
            return;
        }
        
        var data = window._transcriptData[elementId];
        if (!data) {
            showDialog('Error', 'Transcript data not found.');
            return;
        }
        
        var transcriptText = data.value;
        if (!transcriptText || transcriptText.trim() === '') {
            showDialog('Error', 'Transcript is empty.');
            return;
        }
        
        // Show loading indicator
        var indicator = document.getElementById('transcript_indicator_' + elementId);
        if (indicator) {
            var convertBtn = indicator.querySelector('.btn-primary');
            if (convertBtn && convertBtn.textContent.includes('Convert to Text')) {
                convertBtn.textContent = '⏳ Processing...';
                convertBtn.disabled = true;
            }
        }
        
        setTimeout(function() {
            try {
                var result = processStructuredTextConversion(transcriptText);
                
                // Store result
                window._conversionResults[elementId + '_text'] = result;
                
                // Remove any existing result div first
                var existingResultDiv = document.getElementById('transcript_result_' + elementId);
                if (existingResultDiv) {
                    existingResultDiv.remove();
                }
                
                // Create result display
                var resultDiv = document.createElement('div');
                resultDiv.id = 'transcript_result_' + elementId;
                resultDiv.style.cssText = 'margin-top:8px; padding:12px; background:#f0fdf4; border-radius:8px; border:1px solid #a7f3d0;';
                
                resultDiv.innerHTML = 
                    '<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:10px;">' +
                    '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">' +
                    '<span style="color:#065f46; font-weight:600;">✅ Text Conversion Complete</span>' +
                    '<span style="font-size:12px; color:#065f46; background:#d1fae5; padding:2px 8px; border-radius:12px;">📊 ' + result.totalEntries + ' entries</span>' +
                    '<span style="font-size:12px; color:#065f46; background:#d1fae5; padding:2px 8px; border-radius:12px;">📦 ' + result.batches + ' batches</span>' +
                    '</div>' +
                    '<div style="display:flex; gap:6px; flex-wrap:wrap;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="copyTextResult(\'' + elementId + '\')" style="padding:2px 10px; font-size:11px;">📋 Copy Text</button>' +
                    '<button type="button" class="btn btn-sm" onclick="toggleInlineResult(\'' + elementId + '\')" style="padding:2px 10px; font-size:11px; background:#e5e7eb; color:#374151;">✕</button>' +
                    '</div>' +
                    '</div>' +
                    '<div style="background:#1a1a2e; color:#e5e7eb; padding:12px; border-radius:6px; overflow:auto; max-height:400px; font-size:12px; font-family:monospace; white-space:pre-wrap; word-break:break-word;">' + 
                    result.text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + 
                    '</div>';
                
                // Insert after indicator
                var indicatorEl = document.getElementById('transcript_indicator_' + elementId);
                if (indicatorEl) {
                    indicatorEl.parentNode.insertBefore(resultDiv, indicatorEl.nextSibling);
                }
                
                // Reset button
                if (indicatorEl) {
                    var convertBtn2 = indicatorEl.querySelector('.btn-primary');
                    if (convertBtn2 && convertBtn2.textContent.includes('Convert to Text')) {
                        convertBtn2.textContent = '📄 Convert to Text';
                        convertBtn2.disabled = false;
                    }
                }
                
            } catch (error) {
                console.error('[SceneIQ Transcript Detection] CONVERSION FAILED', {
                    element_id: elementId,
                    message: error && error.message ? error.message : String(error),
                    stack: error && error.stack ? error.stack : ''
                });
                // Reset button
                var indicatorEl2 = document.getElementById('transcript_indicator_' + elementId);
                if (indicatorEl2) {
                    var convertBtn3 = indicatorEl2.querySelector('.btn-primary');
                    if (convertBtn3 && convertBtn3.textContent.includes('Convert to Text')) {
                        convertBtn3.textContent = '📄 Convert to Text';
                        convertBtn3.disabled = false;
                    }
                }
                
                // Show error
                var errorDiv = document.createElement('div');
                errorDiv.id = 'transcript_result_' + elementId;
                errorDiv.style.cssText = 'margin-top:8px; padding:10px; background:#fef2f2; border-radius:8px; border:1px solid #fecaca; color:#dc2626;';
                errorDiv.textContent = '❌ Error: ' + error.message;
                
                var indicatorEl3 = document.getElementById('transcript_indicator_' + elementId);
                if (indicatorEl3) {
                    indicatorEl3.parentNode.insertBefore(errorDiv, indicatorEl3.nextSibling);
                }
            }
        }, 100);
    }

    // Copy text result
    function copyTextResult(elementId) {
        var result = window._conversionResults[elementId + '_text'];
        if (!result) {
            showDialog('Error', 'No conversion result found.');
            return;
        }
        copyToClipboard(result.text);
    }

    // Find characters mentioned in a text (scene_Image_generation or dialogue)
    function findMentionedCharacters(text, characterPrompts) {
        var mentioned = [];
        if (!text || !characterPrompts) return mentioned;
        
        var textUpper = text.toUpperCase();
        
        for (var key in characterPrompts) {
            if (characterPrompts.hasOwnProperty(key)) {
                var keyUpper = key.toUpperCase();
                // Check if character name appears in the text
                if (textUpper.includes(keyUpper)) {
                    mentioned.push(key);
                }
                // Also check for variations
                var charInfo = characterPrompts[key];
                if (charInfo.clean_name) {
                    var cleanUpper = charInfo.clean_name.toUpperCase();
                    if (cleanUpper !== keyUpper && textUpper.includes(cleanUpper)) {
                        if (mentioned.indexOf(key) === -1) {
                            mentioned.push(key);
                        }
                    }
                }
            }
        }
        
        return mentioned;
    }

    // Process structured text conversion - handles both array and batch formats
    function processStructuredTextConversion(transcriptText) {
        // Parse the full JSON from the text
        var parsedData = null;
        try {
            var startIdx = transcriptText.indexOf('{');
            if (startIdx === -1) {
                throw new Error('No JSON object found in text');
            }
            
            var braceCount = 0;
            var endIdx = -1;
            for (var i = startIdx; i < transcriptText.length; i++) {
                if (transcriptText[i] === '{') braceCount++;
                else if (transcriptText[i] === '}') {
                    braceCount--;
                    if (braceCount === 0) {
                        endIdx = i + 1;
                        break;
                    }
                }
            }
            
            if (endIdx === -1) {
                throw new Error('Could not find matching closing brace for JSON object');
            }
            
            var jsonStr = transcriptText.substring(startIdx, endIdx);
            parsedData = JSON.parse(jsonStr);
        } catch (e) {
            console.log('⚠️ Failed to parse JSON:', e.message);
            throw new Error('Failed to parse structured JSON data: ' + e.message);
        }
        
        if (!parsedData || !parsedData.script) {
            throw new Error('Invalid structured data: missing script');
        }
        
        var scriptEntries = [];
        
        // Check if script is an array or object with batches
        if (Array.isArray(parsedData.script)) {
            // Array format - use as is
            scriptEntries = parsedData.script;
        } else if (typeof parsedData.script === 'object') {
            // Batch format - extract entries from all batches
            for (var batchKey in parsedData.script) {
                if (parsedData.script.hasOwnProperty(batchKey)) {
                    var batch = parsedData.script[batchKey];
                    if (batch && batch.entries && Array.isArray(batch.entries)) {
                        scriptEntries = scriptEntries.concat(batch.entries);
                    }
                }
            }
        }
        
        if (scriptEntries.length === 0) {
            throw new Error('No script entries found');
        }
        
        // Process all entries WITHOUT distributing characters to characters_reference
        var updatedScriptEntries = [];
        var charPrompts = {};
        
        for (var idx = 0; idx < scriptEntries.length; idx++) {
            var entry = scriptEntries[idx];
            
            // Extract characters_reference - if it exists, use it, otherwise extract from view_focus
            var viewFocusChars = '';
            if (entry.characters_reference) {
                viewFocusChars = entry.characters_reference;
            } else if (entry.view_focus) {
                // Try to extract from view_focus
                viewFocusChars = entry.view_focus;
            }
            
            // Parse view focus characters - handle "and" and commas
            var charNames = [];
            if (viewFocusChars) {
                // Split by "and" or commas
                var parts = viewFocusChars.split(/\s+and\s+|\s*,\s*/);
                parts.forEach(function(part) {
                    var trimmed = part.trim();
                    if (trimmed) {
                        charNames.push(trimmed);
                    }
                });
            }
            
            // Also check for characters in scene_Image_generation and dialogue
            var scenePov = entry.scene_Image_generation || entry.scene_Image_generation || '';
            var dialogue = entry.dialogue || '';
            var allText = scenePov + ' ' + dialogue;
            
            // Find mentioned characters
            var mentionedChars = findMentionedCharacters(allText, charPrompts);
            
            // Combine with view focus characters
            var allCharNames = charNames.slice();
            mentionedChars.forEach(function(charName) {
                if (allCharNames.indexOf(charName) === -1) {
                    allCharNames.push(charName);
                }
            });
            
            // Create updated entry WITHOUT characters_reference distribution
            var updatedEntry = {
                start: entry.start || '',
                stop: entry.stop || '',
                duration: entry.duration || '',
                characters_reference: {
        "reference_required": "",
        "reference_assets": "repeat the detail here don't just mention by tag but detail must be completely here",
        "protagonist_stage": "",
        "appearance_at_this_point": ""
      }, // Always empty - no distribution
                dialogue: entry.dialogue || '',
                scene_Image_generation: entry.scene_Image_generation || entry.scene_Image_generation || '',
                // Store character names for details display
                _characters: allCharNames
            };
            
            updatedScriptEntries.push(updatedEntry);
        }
        
        // Generate text output with batches of 20
        var textOutput = 'SCENES DIALOGUE\n\n';
        var batchSize = 20;
        var totalEntries = updatedScriptEntries.length;
        var totalBatches = Math.ceil(totalEntries / batchSize);
        
        for (var b = 0; b < totalBatches; b++) {
            var startIdx2 = b * batchSize;
            var endIdx2 = Math.min((b + 1) * batchSize, totalEntries);
            var batchStart = startIdx2 + 1;
            var batchEnd = endIdx2;
            
            textOutput += 'BATCH ' + batchStart + ' - ' + batchEnd + '\n\n';
            
            for (var j = startIdx2; j < endIdx2; j++) {
                var entry = updatedScriptEntries[j];
                var entryNum = j + 1;
                
                textOutput += entryNum + '.\n';
                
                // Add View Focus Characters
                if (entry._characters && entry._characters.length > 0) {
                    textOutput += 'View Focus Characters: ' + entry._characters.join(' and ') + '\n';
                } else {
                    textOutput += 'View Focus Characters: (none)\n';
                }
                
                // Add character details for each character
                if (entry._characters && entry._characters.length > 0) {
                    for (var c = 0; c < entry._characters.length; c++) {
                        var charName = entry._characters[c];
                        var charInfo = charPrompts[charName];
                        if (charInfo) {
                            textOutput += charName + ' Details:\n';
                            textOutput += '    relation: ' + (charInfo.relation || '') + '\n';
                            textOutput += '    details: ' + (charInfo.character_details || '') + '\n';
                        }
                    }
                }
                
                textOutput += 'Dialogue: ' + (entry.dialogue || '') + '\n';
                textOutput += 'Scene Pov Promt: ' + (entry.scene_Image_generation || '') + '\n\n';
            }
        }
        
        return {
            text: textOutput,
            totalEntries: totalEntries,
            batches: totalBatches,
            script: updatedScriptEntries
        };
    }

    // NEW FUNCTION: Convert and display inline without modal
    function convertAndDisplayInline(elementId) {
        // Validate against the feature flags captured for this field during scanning.
        var data = window._transcriptData && window._transcriptData[elementId];
        var features = data && data.features ? data.features : getActiveTabFeatures();
        console.info('[SceneIQ Transcript Detection] CONVERT TO JSON CLICKED', {
            element_id: elementId,
            owning_tab: data && data.tabDetails ? data.tabDetails.root_name : null,
            feature_flags: features,
            transcript_detection_enabled: sceneIQFeatureEnabled(features.transcript_detection),
            transcript_payload_available: !!data
        });
        if (!sceneIQFeatureEnabled(features.transcript_detection)) {
            console.warn('[SceneIQ Transcript Detection] Convert to JSON blocked: transcript_detection is disabled for the active tab.', features);
            showDialog('Info', 'Transcript detection is not enabled for this tab. Enable it in the server-side tab configuration and save.');
            return;
        }
        
        if (!data) {
            showDialog('Error', 'Transcript data not found.');
            return;
        }
        
        var transcriptText = data.value;
        if (!transcriptText || transcriptText.trim() === '') {
            showDialog('Error', 'Transcript is empty.');
            return;
        }
        
        // Check if result already exists
        var existingResult = document.getElementById('transcript_result_' + elementId);
        if (existingResult) {
            // If exists, just toggle visibility or refresh
            if (existingResult.style.display === 'none') {
                existingResult.style.display = 'block';
            } else {
                // Re-convert
                existingResult.remove();
                delete window._conversionResults[elementId];
            }
        }
        
        // Show loading indicator
        var indicator = document.getElementById('transcript_indicator_' + elementId);
        if (indicator) {
            var convertBtn = indicator.querySelector('.btn-success');
            if (convertBtn) {
                convertBtn.textContent = '⏳ Processing...';
                convertBtn.disabled = true;
            }
        }
        
        setTimeout(function() {
            try {
                var characterData = window._characterData || null;
                var result = processTranscriptConversion(transcriptText, characterData);
                
                // Store result
                window._conversionResults[elementId] = result;
                
                // Remove any existing result div first
                var existingResultDiv = document.getElementById('transcript_result_' + elementId);
                if (existingResultDiv) {
                    existingResultDiv.remove();
                }
                
                // Create result display
                var resultDiv = document.createElement('div');
                resultDiv.id = 'transcript_result_' + elementId;
                resultDiv.style.cssText = 'margin-top:8px; padding:12px; background:#f0fdf4; border-radius:8px; border:1px solid #a7f3d0;';
                
                // Build character status
                var charStatus = characterData && Object.keys(characterData).length > 0 ? 
                    '👤 ' + Object.keys(characterData).length + ' characters' : 
                    '👤 No character data';
                
                resultDiv.innerHTML = 
                    '<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:10px;">' +
                    '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">' +
                    '<span style="color:#065f46; font-weight:600;">✅ Conversion Complete</span>' +
                    '<span style="font-size:12px; color:#065f46; background:#d1fae5; padding:2px 8px; border-radius:12px;">📊 ' + result.script.length + ' entries</span>' +
                    '<span style="font-size:12px; color:#065f46; background:#d1fae5; padding:2px 8px; border-radius:12px;">' + charStatus + '</span>' +
                    '</div>' +
                    '<div style="display:flex; gap:6px; flex-wrap:wrap;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="copyInlineJson(\'' + elementId + '\')" style="padding:2px 10px; font-size:11px;">📋 Copy JSON</button>' +
                    '<button type="button" class="btn btn-sm" onclick="toggleInlineResult(\'' + elementId + '\')" style="padding:2px 10px; font-size:11px; background:#e5e7eb; color:#374151;">✕</button>' +
                    '</div>' +
                    '</div>' +
                    '<div style="background:#1a1a2e; color:#e5e7eb; padding:12px; border-radius:6px; overflow:auto; max-height:250px; font-size:11px; font-family:monospace; white-space:pre-wrap; word-break:break-all;">' + 
                    result.jsonString.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + 
                    '</div>';
                
                // Insert after indicator
                var indicatorEl = document.getElementById('transcript_indicator_' + elementId);
                if (indicatorEl) {
                    indicatorEl.parentNode.insertBefore(resultDiv, indicatorEl.nextSibling);
                } else {
                    // Fallback: find container
                    var container = document.querySelector('.form-group') || document.body;
                    container.appendChild(resultDiv);
                }
                
                // Reset button
                if (indicatorEl) {
                    var convertBtn2 = indicatorEl.querySelector('.btn-success');
                    if (convertBtn2) {
                        convertBtn2.textContent = '🔄 Convert to JSON';
                        convertBtn2.disabled = false;
                    }
                }
                
            } catch (error) {
                // Reset button
                var indicatorEl2 = document.getElementById('transcript_indicator_' + elementId);
                if (indicatorEl2) {
                    var convertBtn3 = indicatorEl2.querySelector('.btn-success');
                    if (convertBtn3) {
                        convertBtn3.textContent = '🔄 Convert to JSON';
                        convertBtn3.disabled = false;
                    }
                }
                
                // Show error
                var errorDiv = document.createElement('div');
                errorDiv.id = 'transcript_result_' + elementId;
                errorDiv.style.cssText = 'margin-top:8px; padding:10px; background:#fef2f2; border-radius:8px; border:1px solid #fecaca; color:#dc2626;';
                errorDiv.textContent = '❌ Error: ' + error.message;
                
                var indicatorEl3 = document.getElementById('transcript_indicator_' + elementId);
                if (indicatorEl3) {
                    indicatorEl3.parentNode.insertBefore(errorDiv, indicatorEl3.nextSibling);
                }
            }
        }, 100);
    }

    // Toggle inline result visibility
    function toggleInlineResult(elementId) {
        var resultDiv = document.getElementById('transcript_result_' + elementId);
        if (resultDiv) {
            if (resultDiv.style.display === 'none') {
                resultDiv.style.display = 'block';
            } else {
                resultDiv.style.display = 'none';
            }
        }
    }

    // Copy inline JSON result
    function copyInlineJson(elementId) {
        var result = window._conversionResults[elementId];
        if (!result) {
            showDialog('Error', 'No conversion result found.');
            return;
        }
        copyToClipboard(result.jsonString);
    }

    // Download inline JSON result
    function downloadInlineJson(elementId) {
        var result = window._conversionResults[elementId];
        if (!result) {
            showDialog('Error', 'No conversion result found.');
            return;
        }
        var blob = new Blob([result.jsonString], {type: 'application/json'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'transcript_converted.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // Function to convert transcript to JSON (for modal)
    function convertTranscriptToJson(elementId) {
        var textarea = document.getElementById('transcriptTextarea');
        if (!textarea) {
            showDialog('Error', 'Transcript content not found.');
            return;
        }
        
        var transcriptText = textarea.value;
        if (!transcriptText || transcriptText.trim() === '') {
            showDialog('Error', 'Transcript is empty.');
            return;
        }
        
        var resultDiv = document.getElementById('transcriptConversionResult');
        if (resultDiv) {
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div style="color:#00695c; padding:10px; text-align:center;">⏳ Processing transcript...</div>';
        }
        
        setTimeout(function() {
            try {
                var characterData = window._characterData || null;
                var result = processTranscriptConversion(transcriptText, characterData);
                
                // Build detailed log message
                var logMessages = [];
                logMessages.push('📁 [CONVERT] Processing transcript...');
                logMessages.push('📖 [CONVERT] Reading transcript content...');
                logMessages.push('✅ [CONVERT] Found ' + result.timestamps + ' timestamps');
                
                if (characterData && Object.keys(characterData).length > 0) {
                    var charNames = Object.keys(characterData);
                    logMessages.push('📋 [CONVERT] Found character data: ' + JSON.stringify(charNames));
                    logMessages.push('✅ [CONVERT] Extracted ' + charNames.length + ' characters from character data');
                    logMessages.push('📋 [CONVERT] Character data extracted: ' + JSON.stringify(charNames));
                    // Log variations for each character
                    for (var charName in characterData) {
                        if (characterData[charName].variations) {
                            logMessages.push('🔍 [CONVERT] "' + charName + '" variations: ' + JSON.stringify(characterData[charName].variations));
                        }
                    }
                } else {
                    logMessages.push('⚠️ [CONVERT] No character data found, proceeding without character prompts');
                }
                
                logMessages.push('✅ [CONVERT] Parsed ' + result.script.length + ' dialogue entries');
                logMessages.push('✅ [CONVERT] JSON conversion complete');
                logMessages.push('📊 [CONVERT] Total dialogue entries: ' + result.script.length);
                
                var logHtml = logMessages.map(function(msg) {
                    var color = '#6b7280';
                    if (msg.indexOf('✅') !== -1) color = '#065f46';
                    else if (msg.indexOf('📋') !== -1) color = '#00695c';
                    else if (msg.indexOf('⚠️') !== -1) color = '#f59e0b';
                    else if (msg.indexOf('❌') !== -1) color = '#ef4444';
                    else if (msg.indexOf('🔍') !== -1) color = '#8b5cf6';
                    else if (msg.indexOf('📁') !== -1 || msg.indexOf('📖') !== -1) color = '#2563eb';
                    else if (msg.indexOf('📊') !== -1) color = '#7c3aed';
                    return '<div style="color:' + color + '; font-family:monospace; font-size:13px; padding:2px 0;">' + msg + '</div>';
                }).join('');
                
                var jsonResultDiv = document.getElementById('transcriptJsonResult');
                if (jsonResultDiv) {
                    jsonResultDiv.style.display = 'block';
                    jsonResultDiv.innerHTML = 
                        '<div style="background:#f0fdf4; border-radius:8px; padding:15px; border:1px solid #a7f3d0;">' +
                        '<div style="color:#065f46; font-weight:600; margin-bottom:8px;">✅ Conversion Complete</div>' +
                        '<div style="font-size:13px; color:#065f46; margin-bottom:10px;">' +
                        '📊 ' + result.script.length + ' dialogue entries parsed' +
                        (characterData && Object.keys(characterData).length > 0 ? ' | 👤 ' + Object.keys(characterData).length + ' characters extracted' : ' | 👤 No character data') +
                        '</div>' +
                        '<div style="background:#f8fafc; border-radius:8px; padding:12px; margin-bottom:10px; border:1px solid #e5e7eb; max-height:200px; overflow-y:auto;">' +
                        logHtml +
                        '</div>' +
                        '<div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">' +
                        '<button type="button" class="btn btn-primary btn-sm" onclick="copyJsonResult()">📋 Copy JSON</button>' +
                        '</div>' +
                        '<pre style="background:#1a1a2e; color:#e5e7eb; padding:15px; border-radius:8px; overflow:auto; max-height:300px; font-size:12px; white-space:pre-wrap; word-break:break-all; margin-top:10px;">' + 
                        result.jsonString.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + 
                        '</pre>' +
                        '</div>';
                }
                
                if (resultDiv) {
                    resultDiv.style.display = 'none';
                }
                
                window._transcriptResult = result;
                
            } catch (error) {
                if (resultDiv) {
                    resultDiv.innerHTML = '<div style="color:#ef4444; padding:10px; text-align:center;">❌ Error: ' + error.message + '</div>';
                }
            }
        }, 100);
    }

    // Normalize transcript text - remove special characters around timestamps
    function normalizeTranscriptText(text) {
        var normalized = text;
        var jsonArrayMatch = text.match(/^[\s\n\r]*\[\s*\{[^]*\}\s*\]\s*/);
        if (jsonArrayMatch) {
            normalized = text.substring(jsonArrayMatch[0].length);
        }
        normalized = normalized.replace(/[\(\[\{\-\_\s]*(\d{1,2}:\d{2})[\)\]\}\-\_\s]*/g, '$1');
        normalized = normalized.replace(/^[\(\[\{\-\_\s]*(\d{1,2}:\d{2})[\)\]\}\-\_\s]*/gm, '$1');
        normalized = normalized.replace(/(\d{1,2}:\d{2})[\)\]\}\-\_]/g, '$1');
        normalized = normalized.replace(/[\(\[\{\-\_]( \d{1,2}:\d{2})/g, '$1');
        return normalized;
    }

    // Main conversion engine - WITHOUT characters_reference and WITHOUT remaining_nonfeatured_characters
    function processTranscriptConversion(transcriptText, characterData) {
        // ===== Extract character data from JSON array at the beginning if not provided =====
        if (!characterData || Object.keys(characterData).length === 0) {
            characterData = extractCharacterDataFromJson(transcriptText);
        }
        
        // ===== Extract only the transcript part (remove JSON) =====
        var transcriptOnly = extractTranscriptOnly(transcriptText);
        
        // ===== Parse the transcript - find all timestamps =====
        var normalizedText = normalizeTranscriptText(transcriptOnly);
        var timestampPattern = /(\d{1,2}:\d{2})/g;
        var matches = [];
        var match;
        
        while ((match = timestampPattern.exec(normalizedText)) !== null) {
            var timeParts = match[1].split(':');
            var minutes = parseInt(timeParts[0]);
            var seconds = parseInt(timeParts[1]);
            var timeStr = minutes + ':' + String(seconds).padStart(2, '0');
            var timeSeconds = minutes * 60 + seconds;
            matches.push({
                time_str: timeStr,
                time_seconds: timeSeconds,
                start_pos: match.index,
                end_pos: match.index + match[0].length
            });
        }
        
        if (matches.length === 0) {
            throw new Error('No timestamps found in transcript');
        }
        
        // ===== Build script entries WITHOUT characters_reference =====
        var scriptLines = [];
        
        // Include every timestamp, including the final timestamp. Preserve all trailing text.
        for (var i = 0; i < matches.length; i++) {
            var current = matches[i];
            var nextMatch = (i + 1 < matches.length) ? matches[i + 1] : null;
            var dialogueStart = current.end_pos;
            var dialogueEnd = nextMatch ? nextMatch.start_pos : normalizedText.length;
            var dialogueText = normalizedText.substring(dialogueStart, dialogueEnd).trim();
            dialogueText = dialogueText.replace(/\d{1,2}:\d{2}/g, '').replace(/\s+/g, ' ').trim();

            if (dialogueText) {
                var scriptEntry = {
                    start: current.time_str,
                    stop: nextMatch ? nextMatch.time_str : 'the end',
                    duration: nextMatch ? (nextMatch.time_seconds - current.time_seconds) + ' seconds' : 'until the end',
                    characters_reference: {
        "reference_required": "",
        "reference_assets": "repeat the detail here don't just mention by tag but detail must be completely here",
        "protagonist_stage": "",
        "appearance_at_this_point": ""
      },
                    dialogue: dialogueText,
                    scene_Image_generation: ''
                };
                scriptLines.push(scriptEntry);
            }
        }

        if (scriptLines.length === 0) {
            throw new Error('No dialogue entries could be parsed');
        }
        
        // ===== Create JSON structure containing ONLY the script =====
        var jsonData = {
            Thumbnail: '',
            script: scriptLines
        };
        
        return {
            script: scriptLines,
            characters: characterData,
            remaining: {}, // Always empty
            timestamps: matches.length,
            json: jsonData,
            jsonString: JSON.stringify(jsonData, null, 4)
        };
    }

    // Extract character data from JSON array at the beginning (kept for backward compatibility)
    function extractCharacterDataFromJson(transcriptText) {
        var characterData = {};
        
        // Look for the JSON array format: [{"character": "Name", ...}, ...]
        var jsonPattern = /\[\s*\{[^}]*"character"\s*:\s*"[^"]*"[^}]*\}\s*(?:,\s*\{[^}]*"character"\s*:\s*"[^"]*"[^}]*\}\s*)*\]/;
        var jsonMatch = transcriptText.match(jsonPattern);
        
        if (jsonMatch) {
            try {
                var jsonStr = jsonMatch[0];
                var characterArray = JSON.parse(jsonStr);
                
                if (Array.isArray(characterArray)) {
                    characterArray.forEach(function(charObj) {
                        var characterName = charObj.character || '';
                        if (characterName) {
                            var relation = charObj.relation || '';
                            var castDetails = charObj.character_details || '';
                            var prompt = charObj.character_visual_prompt || '';
                            
                            var cleanName = extractCleanName(characterName);
                            
                            var fullDetails = "**Story Details:**\n" + castDetails + "\n\n**Character Image Generation Prompt:**\n\"" + prompt + "\"";
                            
                            characterData[characterName] = {
                                name: characterName,
                                clean_name: cleanName,
                                relation: relation,
                                character_details: castDetails,
                                details: fullDetails,
                                prompt: prompt,
                                variations: generateNameVariations(cleanName, characterName)
                            };
                        }
                    });
                    
                    if (Object.keys(characterData).length > 0) {
                        console.log('✅ [CONVERT] Extracted ' + Object.keys(characterData).length + ' characters from JSON array');
                        return characterData;
                    }
                }
            } catch (e) {
                console.log('⚠️ [CONVERT] Failed to parse JSON array:', e.message);
            }
        }
        
        // Method 2: Try to find individual character objects
        var charObjects = transcriptText.match(/\{[^}]*"character"\s*:\s*"[^"]*"[^}]*"relation"\s*:\s*"[^"]*"[^}]*"character_details"\s*:\s*"[^"]*"[^}]*"character_visual_prompt"\s*:\s*"[^"]*"[^}]*\}/g);
        
        if (charObjects) {
            charObjects.forEach(function(charObjStr) {
                try {
                    var charObj = JSON.parse(charObjStr);
                    var characterName = charObj.character || '';
                    if (characterName) {
                        var relation = charObj.relation || '';
                        var castDetails = charObj.character_details || '';
                        var prompt = charObj.character_visual_prompt || '';
                        
                        var cleanName = extractCleanName(characterName);
                        
                        var fullDetails = "**Story Details:**\n" + castDetails + "\n\n**Character Image Generation Prompt:**\n\"" + prompt + "\"";
                        
                        characterData[characterName] = {
                            name: characterName,
                            clean_name: cleanName,
                            relation: relation,
                            character_details: castDetails,
                            details: fullDetails,
                            prompt: prompt,
                            variations: generateNameVariations(cleanName, characterName)
                        };
                    }
                } catch (e) {
                    // Skip invalid JSON objects
                }
            });
            
            if (Object.keys(characterData).length > 0) {
                console.log('✅ [CONVERT] Extracted ' + Object.keys(characterData).length + ' characters from individual JSON objects');
                return characterData;
            }
        }
        
        // Method 3: Look for JSON code block
        var jsonCodeMatch = transcriptText.match(/```json\s*(\[[\s\S]*?\])\s*```/);
        if (jsonCodeMatch) {
            try {
                var charArray2 = JSON.parse(jsonCodeMatch[1]);
                if (Array.isArray(charArray2)) {
                    charArray2.forEach(function(charObj) {
                        var characterName = charObj.character || '';
                        if (characterName) {
                            var relation = charObj.relation || '';
                            var castDetails = charObj.character_details || '';
                            var prompt = charObj.character_visual_prompt || '';
                            
                            var cleanName = extractCleanName(characterName);
                            
                            var fullDetails = "**Story Details:**\n" + castDetails + "\n\n**Character Image Generation Prompt:**\n\"" + prompt + "\"";
                            
                            characterData[characterName] = {
                                name: characterName,
                                clean_name: cleanName,
                                relation: relation,
                                character_details: castDetails,
                                details: fullDetails,
                                prompt: prompt,
                                variations: generateNameVariations(cleanName, characterName)
                            };
                        }
                    });
                    
                    if (Object.keys(characterData).length > 0) {
                        console.log('✅ [CONVERT] Extracted ' + Object.keys(characterData).length + ' characters from JSON code block');
                        return characterData;
                    }
                }
            } catch (e) {
                console.log('⚠️ [CONVERT] Failed to parse JSON code block:', e.message);
            }
        }
        
        console.log('⚠️ [CONVERT] No character data found in JSON format');
        return characterData;
    }

    // Extract only the transcript part (remove JSON)
    function extractTranscriptOnly(transcriptText) {
        // Find where the transcript starts - look for the first timestamp
        var timestampPattern = /(?:^|[^\d])(\d{1,2}:\d{2})(?!\d)/m;
        var firstTimestamp = timestampPattern.exec(transcriptText);
        
        if (firstTimestamp) {
            // Start at the timestamp itself (the match may include a leading delimiter).
            var timestampOffset = firstTimestamp.index + (firstTimestamp[0].length - firstTimestamp[1].length);
            return transcriptText.substring(timestampOffset);
        }
        
        return transcriptText;
    }

    // Legacy function for backward compatibility
    function findCharactersInDialogue(dialogueText, characterData) {
        return [];
    }

    // Copy JSON result (from modal)
    function copyJsonResult() {
        var result = window._transcriptResult;
        if (!result) {
            showDialog('Error', 'No conversion result found.');
            return;
        }
        copyToClipboard(result.jsonString);
    }

    // Download JSON result (from modal)
    function downloadJsonResult() {
        var result = window._transcriptResult;
        if (!result) {
            showDialog('Error', 'No conversion result found.');
            return;
        }
        var blob = new Blob([result.jsonString], {type: 'application/json'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'transcript_converted.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // Helper: Copy to clipboard
    function setCopyButtonFeedback(button) {
        if (!button) {
            var active = document.activeElement;
            if (active && active.tagName === 'BUTTON' && /copy/i.test(active.textContent || '')) button = active;
        }
        if (!button || !button.tagName) return;
        if (!button.dataset.originalCopyLabel) button.dataset.originalCopyLabel = button.innerHTML;
        button.innerHTML = '&#10003; Copied';
        button.classList.add('copy-button-copied');
        button.setAttribute('aria-label', 'Copied');
        if (button._copyFeedbackTimer) clearTimeout(button._copyFeedbackTimer);
        button._copyFeedbackTimer = setTimeout(function() {
            button.innerHTML = button.dataset.originalCopyLabel;
            button.classList.remove('copy-button-copied');
            button.removeAttribute('aria-label');
        }, 1800);
    }

    // When copied content is a single parent wrapper such as
    // { "keynametitlevalue": { ... } }, copy only the inner JSON value.
    // Leave normal objects, arrays, plain text, and comma-separated records unchanged.
    function removeSingleJsonParentWrapper(text) {
        if (typeof text !== 'string') return text;
        var trimmed = text.trim();
        if (!trimmed || trimmed.charAt(0) !== '{' || trimmed.charAt(trimmed.length - 1) !== '}') {
            return text;
        }

        try {
            var parsed = JSON.parse(trimmed);
            if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') return text;

            var keys = Object.keys(parsed);
            if (keys.length !== 1) return text;

            var innerValue = parsed[keys[0]];
            // Only unwrap a parent key whose value is itself an object/array.
            if (innerValue && typeof innerValue === 'object') {
                return JSON.stringify(innerValue, null, 2);
            }
        } catch (e) {
            // Not a complete JSON object; preserve the original clipboard text.
        }
        return text;
    }

    function copyToClipboard(text, button) {
        // Empty strings are valid values too; do not silently skip copying
        // when the current field has intentionally been cleared.
        var textToCopy = typeof text === 'string' ? text : String(text == null ? '' : text);
        textToCopy = removeSingleJsonParentWrapper(textToCopy);
        if (!button) {
            var active = document.activeElement;
            if (active && active.tagName === 'BUTTON' && /copy/i.test(active.textContent || '')) button = active;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textToCopy).then(function() {
                setCopyButtonFeedback(button);
            }).catch(function() {
                fallbackCopy(textToCopy, button);
            });
        } else {
            fallbackCopy(textToCopy, button);
        }
    }

    function fallbackCopy(text, button) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        textarea.style.pointerEvents = 'none';
        document.body.appendChild(textarea);
        textarea.select();
        var copied = false;
        try { copied = document.execCommand('copy'); } catch (e) { copied = false; }
        document.body.removeChild(textarea);
        if (copied) setCopyButtonFeedback(button);
    }

    // Report every configured root and child tab at page load, not just the
    // currently active tab. This lets us verify the saved feature flags before
    // testing transcript detection in any particular tab.
    function logConfiguredTranscriptTabs() {
        var tabs = (typeof userDynamicTabs !== 'undefined' && Array.isArray(userDynamicTabs))
            ? userDynamicTabs
            : (Array.isArray(window.userDynamicTabs) ? window.userDynamicTabs : []);

        function enabled(value) {
            return value === true || value === 1 || value === '1' ||
                (typeof value === 'string' && ['true', 'yes', 'on', 'enabled'].indexOf(value.trim().toLowerCase()) !== -1);
        }

        function featureSummary(tab) {
            var features = (tab && tab.additional_features) || {};
            return {
                transcript_detection: enabled(features.transcript_detection),
                structured_data_detection: enabled(features.transcript_with_structured_data_detection),
                copy_button: enabled(features.copy_button)
            };
        }

        var rows = [];
        tabs.forEach(function(root, rootIndex) {
            var rootName = root.tab_name || root.name || ('Root tab ' + (rootIndex + 1));
            var rootFeatures = featureSummary(root);
            rows.push({
                level: 'root',
                root_index: rootIndex,
                child_index: null,
                tab_name: rootName,
                visible: !Object.prototype.hasOwnProperty.call(root, 'display_tab') || !!root.display_tab,
                transcript_detection: rootFeatures.transcript_detection,
                structured_data_detection: rootFeatures.structured_data_detection,
                copy_button: rootFeatures.copy_button
            });

            (Array.isArray(root.sub_tabs) ? root.sub_tabs : []).forEach(function(child, childIndex) {
                var childFeatures = featureSummary(child);
                rows.push({
                    level: 'child',
                    root_index: rootIndex,
                    child_index: childIndex,
                    root_tab_name: rootName,
                    tab_name: child.tab_name || child.name || ('Child tab ' + (childIndex + 1)),
                    visible: !Object.prototype.hasOwnProperty.call(child, 'display_tab') || !!child.display_tab,
                    transcript_detection: childFeatures.transcript_detection,
                    structured_data_detection: childFeatures.structured_data_detection,
                    copy_button: childFeatures.copy_button
                });
            });
        });

        var enabledRows = rows.filter(function(row) {
            return row.transcript_detection || row.structured_data_detection;
        });

        console.group('[SceneIQ Transcript Detection] CONFIGURED DYNAMIC TABS — PAGE LOAD');
        console.info('Dynamic root tabs found:', tabs.length);
        console.info('Total root and child tabs inspected:', rows.length);
        console.info('Tabs with transcript or structured-data detection enabled:', enabledRows.length);

        if (rows.length) {
            console.table(rows);
        } else {
            console.warn('No dynamic tab configuration was available in userDynamicTabs when the page-load report ran.');
        }

        if (enabledRows.length) {
            console.info('ENABLED CONFIGURATION DETAILS:', enabledRows);
        } else {
            console.warn('No root or child tab in the loaded userDynamicTabs configuration has transcript detection or structured-data detection enabled. If you checked it in the server configuration, verify it was saved and loaded into this page.');
        }
        console.groupEnd();

        return rows;
    }

    // Start transcript detection
    function startTranscriptDetection() {
        console.info('[SceneIQ Transcript Detection] Engine started. It will report the active tab, feature flags, timestamp detection, and whether conversion buttons were added.');
        logConfiguredTranscriptTabs();
        detectTranscripts();
        if (window._transcriptDetectionInterval) clearInterval(window._transcriptDetectionInterval);
        window._transcriptDetectionInterval = setInterval(function() {
            detectTranscripts();
        }, 1000);
    }

    // Override switchUserConfigTab to re-detect transcripts when switching to add_entry
    var originalSwitchUserConfigTab = window.switchUserConfigTab || function() {};
    switchUserConfigTab = function(tabName, btn) {
        originalSwitchUserConfigTab(tabName, btn);
        if (tabName === 'add_entry') {
            setTimeout(function() { 
                detectTranscripts();
                updateCopyButtonsVisibility();
            }, 100);
        }
    };

    // Override switchNewProjectSubTab to re-detect transcripts when switching sub-tabs
    var originalSwitchNewProjectSubTab = window.switchNewProjectSubTab || function() {};
    switchNewProjectSubTab = function(tabId, btn) {
        originalSwitchNewProjectSubTab(tabId, btn);
        setTimeout(function() { 
            detectTranscripts();
            updateCopyButtonsVisibility();
        }, 100);
    };

    // Re-detect immediately when transcript fields are edited or pasted.
    document.addEventListener('input', function(event) {
        if (event.target && event.target.matches && event.target.matches('#user_tab_add_entry input, #user_tab_add_entry textarea, #user_tab_add_entry select')) {
            setTimeout(detectTranscripts, 50);
        }
    });
    document.addEventListener('change', function(event) {
        if (event.target && event.target.matches && event.target.matches('#user_tab_add_entry input, #user_tab_add_entry textarea, #user_tab_add_entry select')) {
            setTimeout(detectTranscripts, 50);
        }
    });
    document.addEventListener('paste', function(event) {
        if (event.target && event.target.matches && event.target.matches('#user_tab_add_entry input, #user_tab_add_entry textarea')) {
            // The paste event fires before the browser inserts clipboard text.
            setTimeout(detectTranscripts, 80);
            setTimeout(detectTranscripts, 250);
        }
    });

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        startTranscriptDetection();
        setTimeout(function() {
            if (typeof updateCopyButtonsVisibility === 'function') {
                updateCopyButtonsVisibility();
            }
        }, 500);
    });
</script>

<script>
/* Coordinate sticky navigation so exactly one row owns the top position. */
(function () {
    function initSingleWinnerStickyTabs() {
        var root = document.getElementById('user_tab_add_entry');
        var scrollHost = document.querySelector('.scroll-body');
        var defaultNav = document.querySelector('.user-config-tabs');
        if (!root || !scrollHost || !defaultNav) return;

        var childSentinels = new Map();
        var mainSentinel = null;
        var scheduled = false;

        function visible(el) {
            return !!(el && el.getClientRects().length &&
                getComputedStyle(el).display !== 'none' &&
                getComputedStyle(el).visibility !== 'hidden');
        }

        function ensureMainSentinel() {
            var nav = root.querySelector('#newProjectSubTabs');
            if (!nav) return null;
            if (mainSentinel && mainSentinel.isConnected && mainSentinel.nextElementSibling === nav) {
                return mainSentinel;
            }
            mainSentinel = document.createElement('span');
            mainSentinel.className = 'main-tab-sticky-sentinel';
            mainSentinel.setAttribute('aria-hidden', 'true');
            mainSentinel.style.cssText = 'display:block;height:1px;width:100%;margin:0;padding:0;pointer-events:none;';
            nav.parentNode.insertBefore(mainSentinel, nav);
            return mainSentinel;
        }

        function ensureChildSentinel(nav) {
            var sentinel = childSentinels.get(nav);
            if (sentinel && sentinel.isConnected && sentinel.nextElementSibling === nav) return sentinel;
            sentinel = document.createElement('span');
            sentinel.className = 'nested-tab-sticky-sentinel';
            sentinel.setAttribute('aria-hidden', 'true');
            sentinel.style.cssText = 'display:block;height:1px;width:100%;margin:0;padding:0;pointer-events:none;';
            nav.parentNode.insertBefore(sentinel, nav);
            childSentinels.set(nav, sentinel);
            return sentinel;
        }

        function updatePriority() {
            scheduled = false;
            var hostTop = scrollHost.getBoundingClientRect().top;
            var rootVisible = visible(root);
            var mainNav = root.querySelector('#newProjectSubTabs');
            var mainHasReachedTop = false;

            if (rootVisible && mainNav && visible(mainNav)) {
                var mainSentinelEl = ensureMainSentinel();
                if (mainSentinelEl) {
                    var mainRect = mainSentinelEl.getBoundingClientRect();
                    mainHasReachedTop = mainRect.bottom <= hostTop;
                }
            }

            defaultNav.classList.toggle('default-nav-released', mainHasReachedTop);

            var childHasReachedTop = false;
            if (rootVisible) {
                root.querySelectorAll('.nested-project-tabs > .dynamic-sub-tabs').forEach(function (nav) {
                    var content = nav.closest('.dynamic-sub-tab-content');
                    if (!visible(nav) || (content && !content.classList.contains('active'))) return;
                    var sentinel = ensureChildSentinel(nav);
                    var rect = sentinel.getBoundingClientRect();
                    if (rect.bottom <= hostTop) childHasReachedTop = true;
                });
            }

            root.classList.toggle('child-nav-priority', childHasReachedTop);
        }

        function scheduleUpdate() {
            if (scheduled) return;
            scheduled = true;
            requestAnimationFrame(updatePriority);
        }

        function refreshSentinels() {
            ensureMainSentinel();
            root.querySelectorAll('.nested-project-tabs > .dynamic-sub-tabs').forEach(ensureChildSentinel);
            scheduleUpdate();
        }

        scrollHost.addEventListener('scroll', scheduleUpdate, { passive: true });
        window.addEventListener('resize', scheduleUpdate, { passive: true });

        if ('MutationObserver' in window) {
            var observer = new MutationObserver(function () {
                refreshSentinels();
            });
            observer.observe(root, {
                subtree: true,
                childList: true,
                attributes: true,
                attributeFilter: ['class']
            });
            observer.observe(defaultNav, {
                attributes: true,
                attributeFilter: ['class']
            });
        }

        refreshSentinels();
        setTimeout(refreshSentinels, 150);
        setTimeout(refreshSentinels, 500);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSingleWinnerStickyTabs);
    } else {
        initSingleWinnerStickyTabs();
    }
})();</script>



<script>
/* Enforce the exact sticky-row height equation:
   default tabs = main dynamic tabs - 1px
   main dynamic tabs = nested sub-tabs - 1px
   The main dynamic row remains the reference height; sticky hand-off is unchanged.
*/
(function () {
    function initStickyNavHeightHierarchy() {
        var root = document.getElementById('user_tab_add_entry');
        var defaultNav = document.querySelector('.user-config-tabs');
        if (!root || !defaultNav) return;

        var scheduled = false;

        function syncHeights() {
            scheduled = false;
            var mainNav = root.querySelector('#newProjectSubTabs');
            if (!mainNav) return;

            var mainHeight = mainNav.getBoundingClientRect().height;
            if (!mainHeight || !isFinite(mainHeight)) return;

            var defaultHeight = Math.max(0, mainHeight - 1);
            var nestedHeight = mainHeight + 1;

            function setPx(el, property, value) {
                var px = value + 'px';
                if (el.style.getPropertyValue(property) !== px) {
                    el.style.setProperty(property, px);
                }
            }

            setPx(defaultNav, 'height', defaultHeight);
            setPx(defaultNav, 'min-height', defaultHeight);
            defaultNav.style.setProperty('align-items', 'center', 'important');

            root.querySelectorAll('.nested-project-tabs > .dynamic-sub-tabs').forEach(function (childNav) {
                setPx(childNav, '--nested-sticky-nav-height', nestedHeight);
                setPx(childNav, 'height', nestedHeight);
                setPx(childNav, 'min-height', nestedHeight);
                childNav.style.setProperty('align-items', 'center', 'important');
            });
        }

        function scheduleSync() {
            if (scheduled) return;
            scheduled = true;
            requestAnimationFrame(syncHeights);
        }

        syncHeights();
        window.addEventListener('resize', scheduleSync, { passive: true });

        if ('ResizeObserver' in window) {
            var resizeObserver = new ResizeObserver(scheduleSync);
            var mainNav = root.querySelector('#newProjectSubTabs');
            if (mainNav) resizeObserver.observe(mainNav);
        }

        if ('MutationObserver' in window) {
            var mutationObserver = new MutationObserver(scheduleSync);
            mutationObserver.observe(root, {
                subtree: true,
                childList: true,
                attributes: true,
                attributeFilter: ['class', 'style']
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initStickyNavHeightHierarchy, { once: true });
    } else {
        initStickyNavHeightHierarchy();
    }
})();
</script>


<script id="sceneiq-folder-navigation">
    (function(){
    'use strict';
    function initSceneIQFolderNavigation(){
    const header=document.querySelector('.main-header');
    const contentArea=document.querySelector('.content-area');
    const originalTabs=document.querySelector('.user-config-tabs');
    if(!header||!contentArea||!originalTabs||!document.getElementById('user_tab_add_entry')) { document.body.classList.remove('sceneiq-folder-pending'); document.body.classList.add('sceneiq-folder-ready'); return; }
    if(document.getElementById('sceneiqFolderRoot')) return;

    const contentIds=['add_entry','pending','completed','aborted','dynamic_tabs','submit_tabs','dynamic_fields'];
    const labels={add_entry:'New Project',pending:'Pending',completed:'Complete',aborted:'Aborted',dynamic_tabs:'Dynamic Tabs',submit_tabs:'Submit Tabs',dynamic_fields:'Dynamic Content'};
    const icons={add_entry:'✦',pending:'◷',completed:'✓',aborted:'×',dynamic_tabs:'▦',submit_tabs:'⇧',dynamic_fields:'▤'};
    const accents={add_entry:['#0f8b80','#e5f7f3'],pending:['#d99416','#fff5dd'],completed:['#169b72','#e4f8ef'],aborted:['#e05252','#fff0f0'],dynamic_tabs:['#6575db','#eef0ff'],submit_tabs:['#9a62c7','#f6edff'],dynamic_fields:['#2686c8','#e9f5ff']};
    const mainHeaderContent=header.innerHTML;
    const scrollBody=document.querySelector('.scroll-body');

    function renderAccountSwitcher(){
        if(!header.classList.contains('account-selected-header')) return;
        let host=header.querySelector('.account-switcher-host');
        if(!host){host=document.createElement('div');host.className='account-switcher-host';header.appendChild(host);}
        host.innerHTML='';
        const accounts=Array.isArray(window.sceneIQAccountSwitcherUsers)?window.sceneIQAccountSwitcherUsers:[];
        const currentId=Number(window.sceneIQSelectedAccountId || header.dataset.accountId || 0);
        const current=accounts.find(function(a){return Number(a.id)===currentId;});
        const wrapper=document.createElement('div');wrapper.className='account-switcher';
        const toggle=document.createElement('button');toggle.type='button';toggle.className='account-switcher-toggle';
        toggle.setAttribute('aria-haspopup','true');toggle.setAttribute('aria-expanded','false');
        const label=document.createElement('span');label.className='account-switcher-toggle-label';
        label.textContent=(current&&current.username)||header.dataset.accountUsername||'Accounts';
        const chevron=document.createElement('span');chevron.className='account-switcher-chevron';chevron.textContent='▾';
        toggle.appendChild(label);toggle.appendChild(chevron);
        const dropdown=document.createElement('div');dropdown.className='account-switcher-dropdown';dropdown.hidden=true;
        const search=document.createElement('input');search.type='search';search.className='account-switcher-search';search.placeholder='Search accounts...';search.setAttribute('aria-label','Search accounts');
        const list=document.createElement('div');list.className='account-switcher-list';
        if(!accounts.length){const empty=document.createElement('div');empty.className='account-switcher-empty';empty.textContent='No other accounts found.';list.appendChild(empty);}
        accounts.forEach(function(account){
            const form=document.createElement('form');form.method='POST';form.className='account-switcher-user-form';
            const selectInput=document.createElement('input');selectInput.type='hidden';selectInput.name='select_user';selectInput.value='1';
            const idInput=document.createElement('input');idInput.type='hidden';idInput.name='user_id';idInput.value=String(account.id);
            form.appendChild(selectInput);form.appendChild(idInput);
            const button=document.createElement('button');button.type='submit';button.className='account-switcher-user'+(Number(account.id)===currentId?' is-current':'');
            const name=document.createElement('span');name.className='account-switcher-user-name';name.textContent=account.username||('Account '+account.id);
            const email=document.createElement('span');email.className='account-switcher-user-email';email.textContent=account.email||'';
            button.appendChild(name);button.appendChild(email);form.appendChild(button);list.appendChild(form);
        });
        dropdown.appendChild(search);dropdown.appendChild(list);wrapper.appendChild(toggle);wrapper.appendChild(dropdown);host.appendChild(wrapper);
        toggle.addEventListener('click',function(event){
            event.preventDefault();event.stopPropagation();
            const opening=dropdown.hidden;dropdown.hidden=!opening;toggle.setAttribute('aria-expanded',opening?'true':'false');
            if(opening){search.value='';Array.from(list.children).forEach(function(row){row.style.display='';});search.focus();}
        });
        search.addEventListener('input',function(){
            const term=search.value.trim().toLowerCase();let visible=0;
            Array.from(list.querySelectorAll('.account-switcher-user-form')).forEach(function(form){
                const match=form.textContent.toLowerCase().includes(term);form.style.display=match?'':'none';if(match)visible++;
            });
            let empty=list.querySelector('.account-switcher-filter-empty');
            if(!empty){empty=document.createElement('div');empty.className='account-switcher-empty account-switcher-filter-empty';empty.textContent='No matching accounts.';list.appendChild(empty);}
            empty.style.display=visible===0?'':'none';
        });
        if(!window.__sceneIQAccountSwitcherDocumentEvents){
            document.addEventListener('click',function(event){
                document.querySelectorAll('.account-switcher').forEach(function(node){
                    if(!node.contains(event.target)){const menu=node.querySelector('.account-switcher-dropdown');const btn=node.querySelector('.account-switcher-toggle');if(menu)menu.hidden=true;if(btn)btn.setAttribute('aria-expanded','false');}
                });
            });
            document.addEventListener('keydown',function(event){
                if(event.key==='Escape')document.querySelectorAll('.account-switcher-dropdown').forEach(function(menu){menu.hidden=true;const btn=menu.parentElement&&menu.parentElement.querySelector('.account-switcher-toggle');if(btn)btn.setAttribute('aria-expanded','false');});
            });
            window.__sceneIQAccountSwitcherDocumentEvents=true;
        }
    }
    renderAccountSwitcher();
    const root=document.createElement('div'); root.id='sceneiqFolderRoot';root.className='folder-nav-root';
    const page=document.createElement('div');page.id='sceneiqFolderPage';page.className='folder-page-content';
    root.appendChild(page);
    contentArea.insertBefore(root,originalTabs);
    const parking=document.createElement('div');parking.id='sceneiqFolderParking';parking.hidden=true;contentArea.appendChild(parking);
    originalTabs.style.display='none';
    contentIds.forEach(id=>{const el=document.getElementById('user_tab_'+id);if(el){el.classList.remove('active');el.style.display='none';parking.appendChild(el);}});
    function clearPage(){
    contentIds.forEach(id=>{
        const node=document.getElementById('user_tab_'+id);
        if(node && node.parentNode!==parking){node.classList.remove('active');node.style.display='none';parking.appendChild(node);}
    });
    page.innerHTML='';
    }
    // Ensure only the selected account's own content is presented in this view.
    const flashNodes=scrollBody?Array.from(scrollBody.querySelectorAll('.notification')):[];
    flashNodes.forEach(n=>n.style.display='none');

    let stack=[];
    let currentTitle='';
    let currentRender=null;
    function setHeader(title,rootMode){
    header.classList.toggle('folder-mode',!rootMode);
    if(rootMode){header.innerHTML=mainHeaderContent;renderAccountSwitcher();return;}

    // On every page inside the selected account, replace the hamburger menu
    // with a Back arrow in exactly the same left-side position. Keep the account
    // username and email centered, and do not render a second Back control at right.
    if(header.classList.contains('account-selected-header')){
        header.innerHTML='<div class="folder-header-left"><button type="button" class="folder-back-btn account-page-back-btn" aria-label="Go back" title="Go back">←</button></div>'+
            '<div class="folder-header-title account-folder-identity"><div class="account-header-username"></div><div class="account-header-email"></div></div>'+
            '<div class="account-switcher-host"></div>';
        header.querySelector('.account-header-username').textContent=header.dataset.accountUsername||'';
        header.querySelector('.account-header-email').textContent=header.dataset.accountEmail||'';
        header.querySelector('.account-page-back-btn').addEventListener('click',goBack);
        renderAccountSwitcher();
        return;
    }

    header.innerHTML='<div class="folder-header-left"><button type="button" class="folder-back-btn" aria-label="Go back" title="Go back">‹</button></div><div class="folder-header-title"></div>';
    header.querySelector('.folder-header-title').textContent=title;
    header.querySelector('.folder-back-btn').addEventListener('click',goBack);
    }
    function showCards(title,description,cards,rootMode){
    currentTitle=title;setHeader(title,rootMode);
    clearPage();
    const h=document.createElement('h2');h.className='folder-nav-heading';h.textContent=title;page.appendChild(h);
    if(description){const p=document.createElement('p');p.className='folder-nav-description';p.textContent=description;page.appendChild(p);}
    const grid=document.createElement('div');grid.className='folder-card-grid';
    cards.forEach(c=>{
        const b=document.createElement('button');b.type='button';b.className='folder-card'+(c.wide?' wide':'');
        b.style.setProperty('--folder-accent',c.accent||'#0f8b80');b.style.setProperty('--folder-tint',c.tint||'#e5f7f3');
        b.innerHTML='<div class="folder-card-top"><span class="folder-card-icon" aria-hidden="true"></span><span class="folder-card-arrow" aria-hidden="true">↗</span></div><div class="folder-card-copy"><div class="folder-card-title"></div><div class="folder-card-subtitle"></div></div>';
        b.querySelector('.folder-card-icon').textContent=c.icon||'▰';
        b.querySelector('.folder-card-title').textContent=c.title;
        b.querySelector('.folder-card-subtitle').textContent=c.subtitle||'Open';
        if(c.count!==undefined){const count=document.createElement('span');count.className='folder-card-count';count.textContent=String(c.count);b.querySelector('.folder-card-top').appendChild(count);}
        b.addEventListener('click',c.open);grid.appendChild(b);
    });
    page.appendChild(grid);
    }
    function navigate(render){if(currentRender)stack.push(currentRender);currentRender=render;render();}
    function goBack(){if(!stack.length)return;currentRender=stack.pop();currentRender();}
    function baseCards(){
    const cards=contentIds.map(id=>{
        const pair=accents[id];let subtitle='';
        if(id==='add_entry')subtitle='Start a new project';
        if(id==='pending')subtitle='Projects awaiting action';
        if(id==='completed')subtitle='Finished projects';
        if(id==='aborted')subtitle='Stopped or cancelled projects';
        if(id==='dynamic_tabs')subtitle='Organise project steps';
        if(id==='submit_tabs')subtitle='Choose which tab data is submitted';
        if(id==='dynamic_fields')subtitle='Manage account fields';
        return {title:labels[id],subtitle,icon:icons[id],accent:pair[0],tint:pair[1],wide:id==='dynamic_fields',open:()=>openTop(id)};
    });
    return cards;
    }
    function rootPage(){stack=[];currentRender=rootPage;showCards('Workspace','Choose a folder to continue. Each view opens on its own, with a back path to the previous location.',baseCards(),true);document.body.classList.remove('sceneiq-folder-pending');document.body.classList.add('sceneiq-folder-ready');}
    function openTop(id){
    if(id==='add_entry'){openNewProjectRoot();return;}
    const render=()=> {
        const el=document.getElementById('user_tab_'+id);
        if(!el)return;
        // Use existing application renderers so dynamic editors and status lists remain functional.
        if(typeof switchUserConfigTab==='function')switchUserConfigTab(id,null);
        showContentPage(labels[id],el,()=>{});
    };
    navigate(render);
    }
    function showContentPage(title,el,after){
    setHeader(title,false);clearPage();
    const shell=document.createElement('div');shell.className='folder-page-content';page.appendChild(shell);
    contentIds.forEach(id=>{const node=document.getElementById('user_tab_'+id);if(node){node.classList.remove('active');node.style.display='none';}});
    el.style.display='block';el.classList.add('active');shell.appendChild(el);
    after&&after();
    }
    function escapeHtml(s){return String(s).replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));}
    function openNewProjectRoot(){
    const render=()=>{
        const buttons=Array.from(document.querySelectorAll('#newProjectSubTabs .dynamic-sub-tab-btn'));
        const cards=[];
        buttons.forEach((btn,index)=>{
        if(btn.offsetParent===null && getComputedStyle(btn).display==='none')return;
        const tabId=btn.getAttribute('data-tab')||'';
        const title=(btn.textContent||'').trim()||('Project section '+(index+1));
        cards.push({title,subtitle:'Open this project section',icon:'▰',accent:'#0f8b80',tint:'#e5f7f3',open:()=>openDynamicTab(tabId,title)});
        });
        if(!cards.length){
        const el=document.getElementById('user_tab_add_entry');
        showContentPage('New Project',el,()=>{});
        return;
        }
        showCards('New Project','Choose a project section. The global header now shows only your current location and the back button.',cards,false);
    };
    navigate(render);
    }
    function openDynamicTab(tabId,title){
    navigate(()=>{
        const el=document.getElementById('user_tab_add_entry');
        showContentPage(title,el,()=>{
        const nav=document.getElementById('newProjectSubTabs');if(nav)nav.style.display='none';
        document.querySelectorAll('#user_tab_add_entry .dynamic-sub-tab-content').forEach(n=>{n.classList.remove('folder-active-content');n.style.display='none';});
        const target=document.getElementById('newProjectSubTab_'+tabId);
        if(!target)return;
        target.classList.add('folder-active-content');target.style.display='block';
        const nested=target.querySelector('.nested-project-tabs');
        if(nested){
            const childButtons=Array.from(nested.querySelectorAll('.nested-child-btn'));
            const childContents=Array.from(nested.querySelectorAll('.nested-child-content'));
            if(childButtons.length){
            const cards=childButtons.map((btn,i)=>({title:(btn.textContent||'').trim()||('Section '+(i+1)),subtitle:'Open this section',icon:'▰',accent:'#6575db',tint:'#eef0ff',open:()=>openNested(target,nested,btn,i,title)}));
            showCards(title,'Choose a section within '+title+'.',cards,false);
            return;
            }
        }
        // If no nested navigation exists, display the selected tab content directly.
        target.style.display='block';
        const headerTitle=header.querySelector('.folder-header-title');if(headerTitle)headerTitle.textContent=title;
        });
    });
    }
    function openNested(parent,nested,btn,index,parentTitle){
    navigate(()=>{
        const children=Array.from(nested.querySelectorAll('.nested-child-content'));
        const child=children[index];
        const title=(btn.textContent||'').trim()||'Section';
        const el=document.getElementById('user_tab_add_entry');
        showContentPage(title,el,()=>{
        const nav=document.getElementById('newProjectSubTabs');if(nav)nav.style.display='none';
        document.querySelectorAll('#user_tab_add_entry .dynamic-sub-tab-content').forEach(n=>{n.classList.remove('folder-active-content');n.style.display='none';});
        parent.classList.add('folder-active-content');parent.style.display='block';
        nested.querySelectorAll('.dynamic-sub-tabs').forEach(n=>n.style.display='none');
        children.forEach(n=>{n.classList.remove('folder-active-content');n.style.display='none';});
        if(child){child.classList.add('folder-active-content');child.style.display='block';}
        const headerTitle=header.querySelector('.folder-header-title');if(headerTitle)headerTitle.textContent=title;
        });
    });
    }
    // Re-render the currently open folder/child view after background saves while
    // preserving the navigation stack and the user's current location.
    window.sceneIQRefreshCurrentFolderView=function(){
        if(typeof currentRender==='function') currentRender();
    };
    rootPage();
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initSceneIQFolderNavigation,{once:true});
    else initSceneIQFolderNavigation();
    })();
</script>


<script id="sceneiq-live-save-and-refresh">
(function () {
    'use strict';

    function showLiveSaveToast(message, isError) {
        var toast = document.getElementById('sceneiqLiveSaveToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'sceneiqLiveSaveToast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:2147483000;max-width:min(420px,calc(100vw - 36px));padding:12px 16px;border-radius:12px;font:600 13px/1.45 Segoe UI,Arial,sans-serif;box-shadow:0 12px 34px rgba(15,23,42,.22);opacity:0;transform:translateY(8px);pointer-events:none;transition:opacity .16s ease,transform .16s ease;';
            document.body.appendChild(toast);
        }
        toast.textContent = message || (isError ? 'Save failed.' : 'Saved successfully.');
        toast.style.background = isError ? '#fff1f2' : '#ecfdf5';
        toast.style.border = isError ? '1px solid #fecaca' : '1px solid #a7f3d0';
        toast.style.color = isError ? '#991b1b' : '#065f46';
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
        if (toast._sceneIQHideTimer) clearTimeout(toast._sceneIQHideTimer);
        toast._sceneIQHideTimer = setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(8px)';
        }, 2600);
    }

    async function fetchLiveState() {
        var formData = new FormData();
        formData.append('sceneiq_live_state', '1');
        var response = await fetch(window.location.href.split('#')[0], {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-SceneIQ-Request': 'save' },
            credentials: 'same-origin',
            cache: 'no-store'
        });
        var data = await response.json();
        if (!response.ok || !data || data.success !== true) {
            throw new Error((data && data.message) || 'Could not retrieve the latest saved account data.');
        }
        return data.state;
    }

    function applyLiveState(state) {
        if (!state || Number(state.userId || 0) !== Number(window.sceneIQSelectedAccountId || window.selectedUserId || 0)) return;
        if (typeof window.userSettings !== 'undefined') window.userSettings = Array.isArray(state.userSettings) ? state.userSettings : [];
        if (typeof window.globalDynamicFields !== 'undefined') window.globalDynamicFields = state.dynamicFields || {};
        if (typeof window.userDynamicTabs !== 'undefined') window.userDynamicTabs = Array.isArray(state.dynamicTabs) ? state.dynamicTabs : [];

        // Top-level var declarations in the legacy app are window globals. Assign
        // explicitly as well so every editor and renderer sees the same live state.
        try { userSettings = Array.isArray(state.userSettings) ? state.userSettings : []; } catch (ignoreSettings) {}
        try { globalDynamicFields = state.dynamicFields || {}; } catch (ignoreFields) {}
        try { userDynamicTabs = Array.isArray(state.dynamicTabs) ? state.dynamicTabs : []; } catch (ignoreTabs) {}
    }

    async function refreshServerRenderedProjectForm() {
        var liveProjectNode = document.getElementById('user_tab_add_entry');
        if (!liveProjectNode) return;
        // Do not replace an actively displayed New Project form: this preserves
        // partially entered content and open nested-tab references.
        if (liveProjectNode.closest('.folder-page-content')) return;

        var response = await fetch(window.location.href.split('#')[0], {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-SceneIQ-Partial': 'project-form' }
        });
        if (!response.ok) throw new Error('The saved project form could not be refreshed.');
        var html = await response.text();
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        var freshProjectNode = parsed.getElementById('user_tab_add_entry');
        if (freshProjectNode) liveProjectNode.innerHTML = freshProjectNode.innerHTML;
    }

    function rerenderLiveSections() {
        if (typeof renderUserGlobalDynamicFields === 'function') renderUserGlobalDynamicFields();
        if (typeof renderDynamicTabsEditor === 'function') renderDynamicTabsEditor();
        if (typeof renderSubmitTabsEditor === 'function') renderSubmitTabsEditor();
        if (typeof renderStatusEntries === 'function') {
            renderStatusEntries('pending', 'pendingEntriesList');
            renderStatusEntries('completed', 'completedEntriesList');
            renderStatusEntries('aborted', 'abortedEntriesList');
        }
        if (typeof updateSaveEntryButtonVisibility === 'function') updateSaveEntryButtonVisibility();
    }

    async function sceneIQRefreshAfterSave(state, message, options) {
        options = options || {};
        var scrollHost = document.querySelector('.scroll-body');
        var oldScrollTop = scrollHost ? scrollHost.scrollTop : 0;
        try {
            if (!state) state = await fetchLiveState();
            applyLiveState(state);
            if (options.refreshProjectMarkup) await refreshServerRenderedProjectForm();
            rerenderLiveSections();
            if (typeof window.sceneIQRefreshCurrentFolderView === 'function') {
                window.sceneIQRefreshCurrentFolderView();
            }
            // Folder rendering may reflow the workspace. Restore the user's exact
            // scroll position instead of sending them back to the top of the page.
            if (scrollHost) scrollHost.scrollTop = oldScrollTop;
            showLiveSaveToast(message || 'Saved successfully.', false);
            return true;
        } catch (error) {
            console.error('SceneIQ live refresh failed:', error);
            showLiveSaveToast('Saved, but the view could not refresh: ' + error.message, true);
            return false;
        }
    }
    window.sceneIQRefreshAfterSave = sceneIQRefreshAfterSave;

    function getSaveAction(form) {
        if (!form || form.tagName !== 'FORM') return '';
        if (form.id === 'copyAccountDataForm' || form.id === 'changePasswordForm') return form.id;
        var names = [
            'save_global_dynamic_fields','save_settings','add_entry','delete_entry','update_entry_status',
            'update_entry_field','save_dynamic_tabs','save_submit_tabs','delete_dynamic_tab',
            'delete_global_dynamic_field','delete_account_dynamic_data','copy_account_data'
        ];
        for (var i = 0; i < names.length; i++) {
            if (form.querySelector('[name="' + names[i] + '"]')) return names[i];
        }
        return '';
    }

    async function submitSaveFormAjax(form, submitter) {
        if (form.dataset.sceneiqAjaxBusy === '1') return;
        form.dataset.sceneiqAjaxBusy = '1';
        var buttons = Array.from(form.querySelectorAll('button[type="submit"],input[type="submit"]'));
        var previous = buttons.map(function (button) { return { disabled: button.disabled, text: button.textContent }; });
        buttons.forEach(function (button) { button.disabled = true; });
        var action = getSaveAction(form);
        var projectMarkupChanges = [
            'save_global_dynamic_fields','save_dynamic_tabs','save_submit_tabs',
            'delete_dynamic_tab','delete_global_dynamic_field','delete_account_dynamic_data'
        ].indexOf(action) !== -1;
        sceneIQBeginLoading('Saving configuration', 'Applying your changes and refreshing the account workspace...');
        try {
            var formData = new FormData(form);
            if (submitter && submitter.name && !formData.has(submitter.name)) formData.append(submitter.name, submitter.value || '1');
            var response = await fetch(form.action || window.location.href.split('#')[0], {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-SceneIQ-Request': 'save' }
            });
            var data;
            try { data = await response.json(); }
            catch (parseError) { throw new Error('The server did not return a valid save response. Check the PHP error log.'); }
            if (!response.ok || !data || data.success !== true) {
                throw new Error((data && data.message) || 'The server rejected this save.');
            }
            await sceneIQRefreshAfterSave(data.state, data.message, { refreshProjectMarkup: projectMarkupChanges });
        } catch (error) {
            console.error('SceneIQ background save failed:', error);
            showLiveSaveToast(error.message || 'The save failed.', true);
        } finally {
            sceneIQEndLoading();
            delete form.dataset.sceneiqAjaxBusy;
            buttons.forEach(function (button, index) {
                button.disabled = previous[index] ? previous[index].disabled : false;
                if (previous[index] && previous[index].text && button.tagName === 'BUTTON') button.textContent = previous[index].text;
            });
        }
    }

    // Central interception covers settings, Dynamic Content/Tabs, project edits,
    // status changes, and deletions. Account switching and dashboard navigation are
    // deliberately excluded so genuine navigation still behaves normally.
    document.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;
        var form = event.target;
        if (!getSaveAction(form)) return;
        event.preventDefault();
        submitSaveFormAjax(form, event.submitter || null);
    });

})();
</script>
<script id="sceneiq-folder-loader-fallback">
(function () {
    'use strict';
    window.addEventListener('load', function () {
        window.setTimeout(function () {
            if (document.body.classList.contains('sceneiq-folder-pending')) {
                var title = document.getElementById('sceneiq-loading-title');
                var detail = document.getElementById('sceneiq-loading-detail');
                if (title) title.textContent = 'Workspace is taking longer than expected';
                if (detail) detail.textContent = 'The page is still waiting for its workspace controls. Refresh the page if this message remains.';
                // Keep the loader visible while startup is genuinely pending.
                // Hiding it here previously exposed an empty workspace and made
                // a slow configuration load look like a failed/blank page.
                document.body.classList.add('sceneiq-folder-pending');
                var loader = document.getElementById('sceneiq-loading-indicator');
                if (loader) loader.style.display = 'flex';
            }
        }, 12000);
    }, { once: true });
})();
</script>

<script id="sceneiq-account-copy-controller">
(function () {
    'use strict';

    var accounts = <?php echo json_encode(array_map(function ($user) { return ['id' => (int)$user['id'], 'username' => (string)$user['username'], 'email' => (string)$user['email']]; }, $users), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var csrfToken = <?php echo json_encode((string)$_SESSION['sceneiq_copy_csrf_token']); ?>;
    var form = document.getElementById('copyAccountDataForm');
    var sourceSelect = document.getElementById('copyDataFromAccount');

    var scopeInput = document.getElementById('copyDataScope');
    var modeButtons = Array.prototype.slice.call(document.querySelectorAll('[data-copy-scope]'));
    var selectionModal = document.getElementById('copySelectionModal');
    var destinationModal = document.getElementById('copyDestinationModal');
    var configList = document.getElementById('copyConfigList');
    var configTabs = document.getElementById('copyConfigTabs');
    var currentConfigTab = 'dynamic_tabs';
    var copyOptions = null;
    var sourceOptionsAccountId = '';
    var selectedTabKeys = {};
    var selectedFieldKeys = {};
    var bulkDestinationIds = [];
    var destinationModalMode = 'bulk';
    var currentFieldCopyKey = '';

    function htmlEscape(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];
        });
    }
    function openModal(modal) { if (modal) { modal.classList.add('active'); modal.setAttribute('aria-hidden', 'false'); } }
    function closeModal(modal) { if (modal) { modal.classList.remove('active'); modal.setAttribute('aria-hidden', 'true'); } }
    function notify(title, message) {
        if (typeof window.showDialog === 'function') window.showDialog(title, message);
        else window.alert(message);
    }
    function accountLabel(account) { return (account.username || ('Account ' + account.id)) + ' — ' + (account.email || ''); }
    function currentSourceId() { return sourceSelect ? String(sourceSelect.value || '') : ''; }
    function ensureHiddenValues(name, values) {
        if (!form) return;
        Array.prototype.forEach.call(form.querySelectorAll('input[data-copy-generated="' + name + '"]'), function (node) { node.remove(); });
        (values || []).forEach(function (value) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = name + '[]'; input.value = String(value);
            input.setAttribute('data-copy-generated', name);
            form.appendChild(input);
        });
    }
    function selectedKeys(map) { return Object.keys(map).filter(function (key) { return map[key] === true; }); }
    function updateBulkSummary() {
        if (!scopeInput) return;
        var count = bulkDestinationIds.length;
        var label = document.getElementById('copyDestinationLabel');
        var countNode = document.getElementById('copyDestinationCount');
        var button = document.getElementById('copyDataSubmit');
        if (label) label.textContent = count ? (count + ' destination account' + (count === 1 ? '' : 's') + ' selected') : 'Choose destination accounts';
        if (countNode) countNode.textContent = count + ' selected';
        if (button) button.disabled = !(scopeInput.value && count && currentSourceId());
        ensureHiddenValues('destination_user_ids', bulkDestinationIds);
        ensureHiddenValues('selected_dynamic_tab_keys', selectedKeys(selectedTabKeys));
        ensureHiddenValues('selected_dynamic_field_keys', selectedKeys(selectedFieldKeys));
        var summary = document.getElementById('copySelectionSummary');
        if (summary) {
            if (!scopeInput.value) summary.textContent = 'No data category selected.';
            else if (scopeInput.value === 'dynamic_tabs') summary.textContent = selectedKeys(selectedTabKeys).length + ' Dynamic tab(s) selected.';
            else if (scopeInput.value === 'dynamic_fields') summary.textContent = selectedKeys(selectedFieldKeys).length + ' Dynamic field(s) selected.';
            else summary.textContent = selectedKeys(selectedTabKeys).length + ' Dynamic tab(s) and ' + selectedKeys(selectedFieldKeys).length + ' Dynamic field(s) selected.';
        }
    }
    function setScope(scope) {
        scopeInput.value = scope;
        modeButtons.forEach(function (button) { button.classList.toggle('active', button.getAttribute('data-copy-scope') === scope); });
        ensureHiddenValues('selected_dynamic_tab_keys', []);
        ensureHiddenValues('selected_dynamic_field_keys', []);
        if (scope === 'dynamic_tabs') currentConfigTab = 'dynamic_tabs';
        else if (scope === 'dynamic_fields') currentConfigTab = 'dynamic_fields';
        else currentConfigTab = 'dynamic_tabs';
        renderConfigList();
        updateBulkSummary();
    }
    function initializeSelections() {
        selectedTabKeys = {};
        selectedFieldKeys = {};
        (copyOptions && copyOptions.dynamicTabs || []).forEach(function (tab) { selectedTabKeys[String(tab.key)] = true; });
        (copyOptions && copyOptions.dynamicFields || []).forEach(function (field) { selectedFieldKeys[String(field.key)] = true; });
    }
    async function loadOptions() {
        var sourceId = currentSourceId();
        if (!sourceId) throw new Error('Select a source account first.');
        if (copyOptions && sourceOptionsAccountId === sourceId) return copyOptions;
        var data = new FormData();
        data.append('load_account_copy_options', '1');
        data.append('source_user_id', sourceId);
        data.append('sceneiq_copy_csrf_token', csrfToken);
        var response = await fetch(window.location.href.split('#')[0], { method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store', headers: {'X-Requested-With':'XMLHttpRequest'} });
        var result = await response.json();
        if (!response.ok || !result || result.success !== true) throw new Error((result && result.message) || 'Could not load source data.');
        copyOptions = { dynamicTabs: result.dynamicTabs || [], dynamicFields: result.dynamicFields || [] };
        sourceOptionsAccountId = sourceId;
        initializeSelections();
        return copyOptions;
    }
    function renderConfigList() {
        if (!configList) return;
        var scope = scopeInput.value;
        var isBoth = scope === 'dynamic_tabs_and_fields';
        configTabs.hidden = !isBoth;
        var isTabs = currentConfigTab === 'dynamic_tabs';
        var records = isTabs ? (copyOptions && copyOptions.dynamicTabs || []) : (copyOptions && copyOptions.dynamicFields || []);
        var map = isTabs ? selectedTabKeys : selectedFieldKeys;
        document.querySelectorAll('[data-copy-config-tab]').forEach(function (button) {
            button.classList.toggle('active', button.getAttribute('data-copy-config-tab') === currentConfigTab);
        });
        if (!records.length) {
            configList.innerHTML = '<div class="copy-config-empty">No ' + (isTabs ? 'Dynamic tabs' : 'Dynamic fields') + ' are configured on this source account.</div>';
        } else {
            configList.innerHTML = records.map(function (record) {
                var key = String(record.key);
                var title = isTabs ? record.label : record.title;
                var meta = isTabs ? 'Dynamic tab' : (record.type + ' · Key: ' + key);
                return '<label class="copy-config-option"><span class="copy-config-option-copy"><span class="copy-config-option-title">' + htmlEscape(title) + '</span><span class="copy-config-option-meta">' + htmlEscape(meta) + '</span></span><input type="checkbox" data-copy-item-type="' + (isTabs ? 'tab' : 'field') + '" data-copy-item-key="' + htmlEscape(key) + '" ' + (map[key] ? 'checked' : '') + '></label>';
            }).join('');
        }
        var count = selectedKeys(map).length;
        var countNode = document.getElementById('copyConfigCount');
        if (countNode) countNode.textContent = count + ' selected of ' + records.length;
    }
    function updateConfigCount() {
        renderConfigList();
        updateBulkSummary();
    }
    async function chooseScope(scope) {
        if (!currentSourceId()) { notify('Select source account', 'Choose the source account before choosing what to copy.'); return; }
        try {
            var modal = document.getElementById('copySelectionModal');
            var title = document.getElementById('copySelectionModalTitle');
            var description = document.getElementById('copySelectionModalDescription');
            await loadOptions();
            setScope(scope);
            title.textContent = scope === 'dynamic_tabs' ? 'Choose Dynamic tabs' : (scope === 'dynamic_fields' ? 'Choose Dynamic field' : 'Choose Dynamic tabs and Dynamic field');
            description.textContent = 'All source items are selected initially. Uncheck any tab or field you do not want copied.';
            openModal(modal);
        } catch (error) { notify('Could not load source data', error.message); }
    }
    function openDestinationPicker(mode, fieldKey) {
        destinationModalMode = mode;
        currentFieldCopyKey = fieldKey || '';
        var sourceId = mode === 'bulk' ? currentSourceId() : String(window.sceneIQSelectedAccountId || window.selectedUserId || '');
        var list = document.getElementById('copyDestinationList');
        var title = document.getElementById('copyDestinationModalTitle');
        var description = document.getElementById('copyDestinationModalDescription');
        var selected = mode === 'bulk' ? bulkDestinationIds.map(String) : [];
        title.textContent = mode === 'field' ? 'Copy field to accounts' : 'Choose destination accounts';
        description.textContent = mode === 'field' ? 'Choose one or more accounts to receive this complete Dynamic Content field. The source account is excluded.' : 'Choose one or more accounts. The source account is excluded.';
        var candidates = accounts.filter(function (account) { return String(account.id) !== String(sourceId); });
        if (!candidates.length) list.innerHTML = '<div class="copy-config-empty">No other accounts are available.</div>';
        else list.innerHTML = candidates.map(function (account) {
            var checked = selected.indexOf(String(account.id)) !== -1;
            return '<label class="copy-config-option"><span class="copy-config-option-copy"><span class="copy-config-option-title">' + htmlEscape(account.username || ('Account ' + account.id)) + '</span><span class="copy-config-option-meta">' + htmlEscape(account.email || '') + '</span></span><input type="checkbox" data-destination-account="' + String(account.id) + '" ' + (checked ? 'checked' : '') + '></label>';
        }).join('');
        updateDestinationCount();
        openModal(destinationModal);
    }
    function getDestinationModalSelection() {
        return Array.prototype.slice.call(destinationModal.querySelectorAll('[data-destination-account]:checked')).map(function (node) { return node.getAttribute('data-destination-account'); });
    }
    function updateDestinationCount() {
        var count = getDestinationModalSelection().length;
        var node = document.getElementById('copyDestinationModalCount');
        if (node) node.textContent = count + ' selected';
    }
    function closeCopyModals() {
        closeModal(selectionModal);
        closeModal(destinationModal);
    }
    async function performSingleFieldCopy(fieldKey, destinationIds) {
        if (!destinationIds.length) { notify('Choose destination accounts', 'Select at least one account to receive this field.'); return; }
        var sourceId = String(window.sceneIQSelectedAccountId || window.selectedUserId || '');
        if (!sourceId) { notify('Source account unavailable', 'Select an account before copying a Dynamic Content field.'); return; }
        var data = new FormData();
        data.append('copy_dynamic_field_to_accounts', '1');
        data.append('source_user_id', sourceId);
        data.append('field_key', fieldKey);
        data.append('destination_user_ids_json', JSON.stringify(destinationIds));
        data.append('sceneiq_copy_csrf_token', csrfToken);
        var response;
        var result;
        if (typeof window.sceneIQBeginLoading === 'function') {
            window.sceneIQBeginLoading('Copying Dynamic Content', 'Copying the selected field and refreshing the account data...');
        }
        try {
            response = await fetch(window.location.href.split('#')[0], { method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store', headers: {'X-Requested-With':'XMLHttpRequest','X-SceneIQ-Request':'save'} });
            result = await response.json();
            if (!response.ok || !result || result.success !== true) throw new Error((result && result.message) || 'The field could not be copied.');
            if (typeof window.sceneIQRefreshAfterSave === 'function') await window.sceneIQRefreshAfterSave(result.state, result.message);
            else notify('Field copied', result.message);
        } catch (error) {
            notify('Copy failed', error.message || 'The field could not be copied.');
        } finally {
            if (typeof window.sceneIQEndLoading === 'function') window.sceneIQEndLoading();
        }
    }

    modeButtons.forEach(function (button) {
        button.addEventListener('click', function () { chooseScope(button.getAttribute('data-copy-scope')); });
    });
    if (sourceSelect) sourceSelect.addEventListener('change', function () {
        copyOptions = null;
        sourceOptionsAccountId = '';
        selectedTabKeys = {}; selectedFieldKeys = {};
        bulkDestinationIds = [];
        scopeInput.value = '';
        modeButtons.forEach(function (button) { button.classList.remove('active'); });
        modeButtons.forEach(function (button) { button.disabled = !currentSourceId(); });
        var destinationTrigger = document.getElementById('copyDestinationTrigger');
        if (destinationTrigger) destinationTrigger.disabled = !currentSourceId();
        updateBulkSummary();
    });
    modeButtons.forEach(function (button) { button.disabled = !currentSourceId(); });
    var destinationTrigger = document.getElementById('copyDestinationTrigger');
    if (destinationTrigger) {
        destinationTrigger.disabled = !currentSourceId();
        destinationTrigger.addEventListener('click', function () {
            if (!scopeInput.value) { notify('Choose data first', 'Choose Dynamic tabs, Dynamic field, or Both before selecting destinations.'); return; }
            openDestinationPicker('bulk');
        });
    }

    document.querySelectorAll('[data-copy-modal-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            var which = button.getAttribute('data-copy-modal-close');
            closeModal(which === 'selection' ? selectionModal : destinationModal);
        });
    });
    document.querySelectorAll('[data-copy-config-tab]').forEach(function (button) {
        button.addEventListener('click', function () { currentConfigTab = button.getAttribute('data-copy-config-tab'); renderConfigList(); });
    });
    configList.addEventListener('change', function (event) {
        var control = event.target.closest('[data-copy-item-type]');
        if (!control) return;
        var map = control.getAttribute('data-copy-item-type') === 'tab' ? selectedTabKeys : selectedFieldKeys;
        map[control.getAttribute('data-copy-item-key')] = !!control.checked;
        updateConfigCount();
    });
    document.getElementById('copyConfigSelectAll').addEventListener('click', function () {
        var map = currentConfigTab === 'dynamic_tabs' ? selectedTabKeys : selectedFieldKeys;
        var records = currentConfigTab === 'dynamic_tabs' ? (copyOptions.dynamicTabs || []) : (copyOptions.dynamicFields || []);
        records.forEach(function (record) { map[String(record.key)] = true; }); renderConfigList(); updateBulkSummary();
    });
    document.getElementById('copyConfigClearAll').addEventListener('click', function () {
        var map = currentConfigTab === 'dynamic_tabs' ? selectedTabKeys : selectedFieldKeys;
        Object.keys(map).forEach(function (key) { map[key] = false; }); renderConfigList(); updateBulkSummary();
    });
    document.getElementById('copyConfigApply').addEventListener('click', function () {
        var scope = scopeInput.value;
        var tabCount = selectedKeys(selectedTabKeys).length;
        var fieldCount = selectedKeys(selectedFieldKeys).length;
        if ((scope === 'dynamic_tabs' && !tabCount) || (scope === 'dynamic_fields' && !fieldCount) || (scope === 'dynamic_tabs_and_fields' && !tabCount && !fieldCount)) {
            notify('Nothing selected', 'Select at least one item to copy.'); return;
        }
        ensureHiddenValues('selected_dynamic_tab_keys', selectedKeys(selectedTabKeys));
        ensureHiddenValues('selected_dynamic_field_keys', selectedKeys(selectedFieldKeys));
        updateBulkSummary();
        closeModal(selectionModal);
    });
    destinationModal.addEventListener('change', function (event) { if (event.target.matches('[data-destination-account]')) updateDestinationCount(); });
    document.getElementById('copyDestinationSelectAll').addEventListener('click', function () {
        destinationModal.querySelectorAll('[data-destination-account]').forEach(function (node) { node.checked = true; }); updateDestinationCount();
    });
    document.getElementById('copyDestinationClearAll').addEventListener('click', function () {
        destinationModal.querySelectorAll('[data-destination-account]').forEach(function (node) { node.checked = false; }); updateDestinationCount();
    });
    document.getElementById('copyDestinationApply').addEventListener('click', function () {
        var ids = getDestinationModalSelection();
        if (!ids.length) { notify('No accounts selected', 'Select at least one destination account.'); return; }
        if (destinationModalMode === 'bulk') {
            bulkDestinationIds = ids;
            updateBulkSummary();
            closeModal(destinationModal);
        } else {
            closeModal(destinationModal);
            performSingleFieldCopy(currentFieldCopyKey, ids);
        }
    });
    if (form) form.addEventListener('submit', function (event) {
        if (!scopeInput.value) { event.preventDefault(); notify('Choose what to copy', 'The default is None. Select Dynamic tabs, Dynamic field, or Both first.'); return; }
        if (!bulkDestinationIds.length) { event.preventDefault(); notify('Choose destination accounts', 'Select at least one destination account.'); return; }
        if (!selectedKeys(selectedTabKeys).length && !selectedKeys(selectedFieldKeys).length) { event.preventDefault(); notify('Nothing selected', 'Select at least one tab or field to copy.'); return; }
        var sourceName = sourceSelect.options[sourceSelect.selectedIndex] ? sourceSelect.options[sourceSelect.selectedIndex].text : 'source account';
        var destinationNames = accounts.filter(function (account) { return bulkDestinationIds.indexOf(String(account.id)) !== -1; }).map(accountLabel);
        if (!window.confirm('Copy the selected data from ' + sourceName + ' to ' + destinationNames.join(', ') + '?\n\nOnly selected items are copied. Matching keys are replaced in full; all unrelated destination data remains.')) event.preventDefault();
    });

    // Public function used by the Copy field to button on each Dynamic Content card.
    window.openCopyFieldToUsers = function (fieldKey) {
        if (!fieldKey) return;
        currentFieldCopyKey = String(fieldKey);
        openDestinationPicker('field', currentFieldCopyKey);
    };
    updateBulkSummary();
})();
</script>

</body>
</html>

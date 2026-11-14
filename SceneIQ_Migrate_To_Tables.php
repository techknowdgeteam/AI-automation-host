<?php
/**
 * SceneIQ one-time migration: sceneiq_config JSON columns -> relational tables.
 * Run a safe preview: php SceneIQ_Migrate_To_Tables.php (dry-run by default).
 * Apply changes: php SceneIQ_Migrate_To_Tables.php --apply (optionally --force).
 * Browser use is supported only when migration_token is set in sceneiq_db_config.php.
 * The original sceneiq_config table is NEVER updated or deleted by this script.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(0);

$configPath = __DIR__ . '/sceneiq_db_config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Missing sceneiq_db_config.php. Copy the example config and enter your rotated database password.');
}
$db = require $configPath;
$host = (string)($db['host'] ?? '');
$dbname = (string)($db['dbname'] ?? '');
$username = (string)($db['username'] ?? '');
$password = (string)($db['password'] ?? '');
$migrationToken = (string)($db['migration_token'] ?? '');
if ($host === '' || $dbname === '' || $username === '' || $password === '') {
    http_response_code(500);
    exit('Database configuration is incomplete.');
}

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    error_log('SceneIQ migration DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed. Check the hosting error log and sceneiq_db_config.php.');
}

function migrationJsonEncode($value) {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

function migrationDecodeLegacyColumn($raw, $columnName) {
    if (is_array($raw)) return $raw;
    if (!is_string($raw) || trim($raw) === '') return [];
    try {
        $outer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new RuntimeException("Legacy {$columnName} column is not valid JSON: " . $e->getMessage());
    }
    if (!is_array($outer)) throw new RuntimeException("Legacy {$columnName} did not contain a JSON object or array.");
    if (!array_key_exists('__sceneiq_storage', $outer)) return $outer;

    if (($outer['__sceneiq_storage'] ?? null) !== 'gzip-json-v1') {
        throw new RuntimeException("Legacy {$columnName} uses an unsupported SceneIQ storage marker.");
    }
    $expectedBytes = filter_var($outer['original_bytes'] ?? null, FILTER_VALIDATE_INT);
    $expectedHash = strtolower((string)($outer['sha256'] ?? ''));
    $payload = $outer['payload'] ?? null;
    if ($expectedBytes === false || $expectedBytes <= 0 || $expectedBytes > 64 * 1024 * 1024) {
        throw new RuntimeException("Legacy {$columnName} has invalid original_bytes metadata.");
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $expectedHash) || !is_string($payload) || $payload === '') {
        throw new RuntimeException("Legacy {$columnName} has an invalid checksum or missing payload.");
    }
    $binary = base64_decode($payload, true);
    if ($binary === false) throw new RuntimeException("Legacy {$columnName} payload is not valid Base64.");
    $plain = false;
    $limit = (int)$expectedBytes + 1;
    if (function_exists('gzuncompress')) $plain = @gzuncompress($binary, $limit);
    if ($plain === false && function_exists('gzdecode') && substr($binary, 0, 2) === "\x1f\x8b") $plain = @gzdecode($binary, $limit);
    if ($plain === false && function_exists('gzinflate')) $plain = @gzinflate($binary, $limit);
    if ($plain === false && function_exists('gzinflate') && strlen($binary) > 6) $plain = @gzinflate(substr($binary, 2, -4), $limit);
    if ($plain === false) throw new RuntimeException("Legacy {$columnName} could not be decompressed.");
    if (strlen($plain) !== (int)$expectedBytes) throw new RuntimeException("Legacy {$columnName} decompressed byte count did not match.");
    if (!hash_equals($expectedHash, hash('sha256', $plain))) throw new RuntimeException("Legacy {$columnName} SHA-256 verification failed.");
    try {
        $decoded = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new RuntimeException("Verified legacy {$columnName} content was not valid JSON: " . $e->getMessage());
    }
    if (!is_array($decoded) || array_key_exists('__sceneiq_storage', $decoded)) {
        throw new RuntimeException("Verified legacy {$columnName} content was not a valid configuration array.");
    }
    return $decoded;
}

function migrationAsList($value) {
    if (is_string($value)) {
        try { $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR); $value = $decoded; }
        catch (Throwable $e) { $value = ($value === '') ? [] : [$value]; }
    }
    return is_array($value) ? array_values($value) : [];
}

function migrationRepairProjects($settings) {
    if (!is_array($settings) || $settings === []) return [];
    if (isset($settings[0]) && is_array($settings[0])) {
        return array_values(array_filter($settings, 'is_array'));
    }
    if (array_key_exists('status', $settings) || array_key_exists('dynamic_values', $settings) || array_key_exists('operation_status', $settings)) {
        return [$settings];
    }
    $records = [];
    foreach ($settings as $value) {
        if (is_array($value) && (array_key_exists('status', $value) || array_key_exists('dynamic_values', $value) || array_key_exists('operation_status', $value))) $records[] = $value;
    }
    return $records ?: [$settings];
}

function migrationNormalizeFields($fields) {
    if (!is_array($fields) || $fields === []) return [];
    if (isset($fields['field_type']) || isset($fields['field-title']) || isset($fields['field-name'])) {
        $key = (string)($fields['field-name'] ?? 'field_1');
        $fields = [$key => $fields];
    }
    $out = [];
    foreach ($fields as $key => $definition) {
        $key = (string)$key;
        if ($key === '') continue;
        if (is_string($definition)) {
            $decoded = json_decode($definition, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $definition = $decoded;
        }
        if (!is_array($definition)) $definition = ['field-title'=>$key,'field_type'=>'individual','element_type'=>'input','data_type'=>'string','default_value'=>(string)$definition];
        $definition['field-title'] = (string)($definition['field-title'] ?? $key);
        $type = strtolower((string)($definition['field_type'] ?? 'individual'));
        $definition['field_type'] = in_array($type,['object','objects'],true) ? 'objects' : 'individual';
        $definition['element_type'] = (string)($definition['element_type'] ?? 'input');
        $dtype = strtolower((string)($definition['data_type'] ?? 'string'));
        $definition['data_type'] = in_array($dtype,['string','int','integer','json'],true) ? ($dtype === 'integer' ? 'int' : $dtype) : 'string';
        $definition['default_value'] = is_scalar($definition['default_value'] ?? '') ? (string)($definition['default_value'] ?? '') : migrationJsonEncode($definition['default_value']);
        if (!is_array($definition['default_values'] ?? null)) $definition['default_values'] = migrationAsList($definition['default_values'] ?? []);
        if (!is_array($definition['copy_button_for_each_of'] ?? null)) $definition['copy_button_for_each_of'] = migrationAsList($definition['copy_button_for_each_of'] ?? []);
        $definition['button_url'] = (string)($definition['button_url'] ?? '');
        $definition['separate_paragraphs'] = (string)($definition['separate_paragraphs'] ?? 'no');
        $subfields = $definition['fieldkeyvalue'] ?? [];
        if (is_string($subfields)) {
            $decoded = json_decode($subfields, true);
            $subfields = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        }
        if (!is_array($subfields)) $subfields = [];
        $normalizedSubs = [];
        foreach ($subfields as $subKey => $subDefinition) {
            $subKey = (string)$subKey;
            if ($subKey === '') continue;
            if (is_string($subDefinition)) {
                $decoded = json_decode($subDefinition, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $subDefinition = $decoded;
            }
            if (!is_array($subDefinition)) $subDefinition = ['field-title'=>$subKey,'element_type'=>'input','data_type'=>'string','default_value'=>(string)$subDefinition];
            $subDefinition['field-title'] = (string)($subDefinition['field-title'] ?? $subKey);
            $subDefinition['element_type'] = (string)($subDefinition['element_type'] ?? 'input');
            $subType = strtolower((string)($subDefinition['data_type'] ?? 'string'));
            $subDefinition['data_type'] = in_array($subType,['string','int','integer','json'],true) ? ($subType === 'integer' ? 'int' : $subType) : 'string';
            $subDefinition['default_value'] = is_scalar($subDefinition['default_value'] ?? '') ? (string)($subDefinition['default_value'] ?? '') : migrationJsonEncode($subDefinition['default_value']);
            if (!is_array($subDefinition['default_values'] ?? null)) $subDefinition['default_values'] = migrationAsList($subDefinition['default_values'] ?? []);
            if (!is_array($subDefinition['copy_button_for_each_of'] ?? null)) $subDefinition['copy_button_for_each_of'] = migrationAsList($subDefinition['copy_button_for_each_of'] ?? []);
            $subDefinition['button_url'] = (string)($subDefinition['button_url'] ?? '');
            $subDefinition['separate_paragraphs'] = (string)($subDefinition['separate_paragraphs'] ?? 'no');
            $normalizedSubs[$subKey] = $subDefinition;
        }
        if ($definition['field_type'] === 'objects') $definition['fieldkeyvalue'] = $normalizedSubs;
        $out[$key] = $definition;
    }
    return $out;
}

function migrationNormalizeTabs($tabs) {
    if (is_string($tabs)) {
        $decoded = json_decode($tabs, true);
        if (json_last_error() === JSON_ERROR_NONE) $tabs = $decoded;
        else $tabs = ($tabs === '') ? [] : [['tab_name'=>$tabs]];
    }
    if (!is_array($tabs) || $tabs === []) return [];
    if (isset($tabs['tab_name']) || isset($tabs['dynamic_fields_display'])) $tabs = [$tabs];
    $normalizeOne = function($tab, $fallback) use (&$normalizeOne) {
        if (is_string($tab)) {
            $decoded = json_decode($tab,true);
            $tab = (json_last_error()===JSON_ERROR_NONE && is_array($decoded)) ? $decoded : ['tab_name'=>$tab];
        }
        if (!is_array($tab)) $tab = ['tab_name'=>(string)$fallback];
        $tab['tab_name'] = (string)($tab['tab_name'] ?? $fallback);
        $tab['submit_tab'] = array_key_exists('submit_tab',$tab) ? (bool)$tab['submit_tab'] : true;
        $display = $tab['dynamic_fields_display'] ?? [];
        if (is_string($display)) { $decoded=json_decode($display,true); $display=(json_last_error()===JSON_ERROR_NONE && is_array($decoded))?$decoded:[$display]; }
        $tab['dynamic_fields_display'] = is_array($display) ? array_values(array_map('strval',$display)) : [];
        $children = $tab['sub_tabs'] ?? [];
        if (is_string($children)) { $decoded=json_decode($children,true); $children=(json_last_error()===JSON_ERROR_NONE && is_array($decoded))?$decoded:[]; }
        $tab['sub_tabs_mode'] = (string)($tab['sub_tabs_mode'] ?? (is_array($children) && count($children) ? 'add' : 'none'));
        $tab['sub_tabs'] = [];
        if (is_array($children)) {
            $i=0;
            foreach ($children as $child) { $i++; $tab['sub_tabs'][]=$normalizeOne($child,'Sub Tab '.$i); }
        }
        if (!is_array($tab['additional_features'] ?? null)) $tab['additional_features'] = [
            'copy_button'=>false,
            'transcript_detection'=>false,
            'transcript_with_structured_data_detection'=>false
        ];
        return $tab;
    };
    $out=[]; $i=0;
    foreach ($tabs as $tab) { $i++; $out[]=$normalizeOne($tab,'Tab '.$i); }
    return $out;
}

function migrationCheckKeyLength($key, $kind) {
    if (strlen((string)$key) > 191) throw new RuntimeException("A {$kind} key exceeds the 191-byte database key limit: " . substr((string)$key,0,80));
}

function migrationCountTabs(array $tabs) {
    $count = 0;
    foreach ($tabs as $tab) {
        if (!is_array($tab)) continue;
        $count++;
        $children = is_array($tab['sub_tabs'] ?? null) ? $tab['sub_tabs'] : [];
        $count += migrationCountTabs($children);
    }
    return $count;
}

function migrationValidateNormalizedData(array $fields, array $tabs, array $projects) {
    foreach ($fields as $fieldKey => $definition) {
        migrationCheckKeyLength((string)$fieldKey, 'Dynamic Content field');
        foreach (($definition['fieldkeyvalue'] ?? []) as $subKey => $subDefinition) {
            migrationCheckKeyLength((string)$subKey, 'Dynamic Content subfield');
            migrationJsonEncode($subDefinition);
        }
        migrationJsonEncode($definition);
    }
    $validateTabs = function(array $items) use (&$validateTabs) {
        foreach ($items as $tab) {
            if (!is_array($tab)) continue;
            foreach (($tab['dynamic_fields_display'] ?? []) as $fieldKey) {
                migrationCheckKeyLength((string)$fieldKey, 'Tab field mapping');
            }
            migrationJsonEncode($tab);
            $children = is_array($tab['sub_tabs'] ?? null) ? $tab['sub_tabs'] : [];
            $validateTabs($children);
        }
    };
    $validateTabs($tabs);
    foreach ($projects as $project) {
        if (is_array($project)) migrationJsonEncode($project);
    }
}

function migrationSaveField(PDO $pdo, $userId, $fieldKey, array $definition, &$fieldCount) {
    migrationCheckKeyLength($fieldKey,'Dynamic Content field');
    $subfields = is_array($definition['fieldkeyvalue'] ?? null) ? $definition['fieldkeyvalue'] : [];
    $definitionJson = migrationJsonEncode($definition);
    $defaultValues = migrationJsonEncode($definition['default_values'] ?? []);
    $copyFor = migrationJsonEncode($definition['copy_button_for_each_of'] ?? []);
    $sql = "INSERT INTO `sceneiq_dynamic_fields`
        (`user_id`,`field_key`,`field_title`,`field_type`,`element_type`,`data_type`,`default_value`,`default_values_json`,`button_url`,`separate_paragraphs`,`copy_button_for_each_of_json`,`definition_json`,`sort_order`,`deleted_at`)
        VALUES (:user_id,:field_key,:field_title,:field_type,:element_type,:data_type,:default_value,:default_values,:button_url,:separate_paragraphs,:copy_for,:definition,:sort_order,NULL)
        ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`),`field_title`=VALUES(`field_title`),`field_type`=VALUES(`field_type`),`element_type`=VALUES(`element_type`),`data_type`=VALUES(`data_type`),`default_value`=VALUES(`default_value`),`default_values_json`=VALUES(`default_values_json`),`button_url`=VALUES(`button_url`),`separate_paragraphs`=VALUES(`separate_paragraphs`),`copy_button_for_each_of_json`=VALUES(`copy_button_for_each_of_json`),`definition_json`=VALUES(`definition_json`),`sort_order`=VALUES(`sort_order`),`deleted_at`=NULL";
    $stmt=$pdo->prepare($sql);
    $stmt->execute([
        ':user_id'=>$userId,':field_key'=>$fieldKey,':field_title'=>$definition['field-title'] ?? $fieldKey,
        ':field_type'=>$definition['field_type'] ?? 'individual',':element_type'=>$definition['element_type'] ?? 'input',
        ':data_type'=>$definition['data_type'] ?? 'string',':default_value'=>$definition['default_value'] ?? '',
        ':default_values'=>$defaultValues,':button_url'=>$definition['button_url'] ?? '',
        ':separate_paragraphs'=>$definition['separate_paragraphs'] ?? 'no',':copy_for'=>$copyFor,
        ':definition'=>$definitionJson,':sort_order'=>$fieldCount
    ]);
    $fieldId=(int)$pdo->lastInsertId();
    $subPosition=0;
    foreach ($subfields as $subKey=>$subDefinition) {
        migrationCheckKeyLength($subKey,'Dynamic Content subfield');
        $subPosition++;
        if (!is_array($subDefinition)) $subDefinition=['field-title'=>(string)$subKey,'default_value'=>(string)$subDefinition];
        $subDefinition['field-title']=(string)($subDefinition['field-title']??$subKey);
        $subDefinition['element_type']=(string)($subDefinition['element_type']??'input');
        $subDefinition['data_type']=(string)($subDefinition['data_type']??'string');
        $subJson=migrationJsonEncode($subDefinition);
        $subSql="INSERT INTO `sceneiq_dynamic_field_subfields`
            (`user_id`,`field_id`,`subfield_key`,`field_title`,`element_type`,`data_type`,`default_value`,`default_values_json`,`button_url`,`separate_paragraphs`,`copy_button_for_each_of_json`,`definition_json`,`sort_order`,`deleted_at`)
            VALUES (:user_id,:field_id,:subfield_key,:field_title,:element_type,:data_type,:default_value,:default_values,:button_url,:separate_paragraphs,:copy_for,:definition,:sort_order,NULL)
            ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`),`field_title`=VALUES(`field_title`),`element_type`=VALUES(`element_type`),`data_type`=VALUES(`data_type`),`default_value`=VALUES(`default_value`),`default_values_json`=VALUES(`default_values_json`),`button_url`=VALUES(`button_url`),`separate_paragraphs`=VALUES(`separate_paragraphs`),`copy_button_for_each_of_json`=VALUES(`copy_button_for_each_of_json`),`definition_json`=VALUES(`definition_json`),`sort_order`=VALUES(`sort_order`),`deleted_at`=NULL";
        $subStmt=$pdo->prepare($subSql);
        $subStmt->execute([
            ':user_id'=>$userId,':field_id'=>$fieldId,':subfield_key'=>$subKey,
            ':field_title'=>$subDefinition['field-title'],':element_type'=>$subDefinition['element_type'],
            ':data_type'=>$subDefinition['data_type'],':default_value'=>$subDefinition['default_value']??'',
            ':default_values'=>migrationJsonEncode($subDefinition['default_values']??[]),
            ':button_url'=>$subDefinition['button_url']??'',':separate_paragraphs'=>$subDefinition['separate_paragraphs']??'no',
            ':copy_for'=>migrationJsonEncode($subDefinition['copy_button_for_each_of']??[]),
            ':definition'=>$subJson,':sort_order'=>$subPosition
        ]);
    }
    $fieldCount++;
}

function migrationSaveTabTree(PDO $pdo, $userId, array $tabs, &$tabCount) {
    $saveOne = function(array $tab, $parentId, $parentPath, $position) use (&$saveOne,$pdo,$userId,&$tabCount) {
        $name=(string)($tab['tab_name']??('Tab '.$position));
        $path=$parentPath.'/'.$position.'-'.substr(hash('sha256',$name),0,20);
        $tabKey='legacy_'.substr(hash('sha256',$userId.'|'.$path),0,55);
        $submit=!empty($tab['submit_tab'])?1:0;
        $mode=(string)($tab['sub_tabs_mode']??'none');
        $additional=migrationJsonEncode($tab['additional_features']??[]);
        $config=$tab;
        unset($config['sub_tabs'],$config['dynamic_fields_display'],$config['_table_id'],$config['_tab_key'],$config['_expanded']);
        $sql="INSERT INTO `sceneiq_dynamic_tabs`
            (`user_id`,`parent_tab_id`,`tab_key`,`tab_name`,`submit_tab`,`sub_tabs_mode`,`additional_features_json`,`tab_config_json`,`sort_order`,`deleted_at`)
            VALUES (:user_id,:parent_id,:tab_key,:tab_name,:submit_tab,:mode,:additional,:config,:position,NULL)
            ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`),`parent_tab_id`=VALUES(`parent_tab_id`),`tab_name`=VALUES(`tab_name`),`submit_tab`=VALUES(`submit_tab`),`sub_tabs_mode`=VALUES(`sub_tabs_mode`),`additional_features_json`=VALUES(`additional_features_json`),`tab_config_json`=VALUES(`tab_config_json`),`sort_order`=VALUES(`sort_order`),`deleted_at`=NULL";
        $stmt=$pdo->prepare($sql);
        $stmt->execute([':user_id'=>$userId,':parent_id'=>$parentId,':tab_key'=>$tabKey,':tab_name'=>$name,':submit_tab'=>$submit,':mode'=>$mode,':additional'=>$additional,':config'=>migrationJsonEncode($config),':position'=>$position]);
        $tabId=(int)$pdo->lastInsertId();
        $mapDelete=$pdo->prepare('DELETE FROM `sceneiq_dynamic_tab_fields` WHERE `tab_id`=:tab_id');
        $mapDelete->execute([':tab_id'=>$tabId]);
        $fieldLookup=$pdo->prepare('SELECT `id` FROM `sceneiq_dynamic_fields` WHERE `user_id`=:user_id AND `field_key`=:field_key AND `deleted_at` IS NULL LIMIT 1');
        $mapInsert=$pdo->prepare('INSERT INTO `sceneiq_dynamic_tab_fields` (`user_id`,`tab_id`,`field_id`,`field_key`,`sort_order`) VALUES (:user_id,:tab_id,:field_id,:field_key,:sort_order)');
        $unique=[];
        foreach (array_values($tab['dynamic_fields_display']??[]) as $i=>$fieldKey) {
            $fieldKey=(string)$fieldKey; if ($fieldKey==='') continue;
            migrationCheckKeyLength($fieldKey,'Tab field mapping');
            if (isset($unique[$fieldKey])) continue; $unique[$fieldKey]=true;
            $fieldLookup->execute([':user_id'=>$userId,':field_key'=>$fieldKey]);
            $mappedFieldId=$fieldLookup->fetchColumn();
            $mapInsert->execute([':user_id'=>$userId,':tab_id'=>$tabId,':field_id'=>($mappedFieldId===false?null:(int)$mappedFieldId),':field_key'=>$fieldKey,':sort_order'=>$i]);
        }
        $tabCount++;
        foreach (array_values($tab['sub_tabs']??[]) as $i=>$child) if (is_array($child)) $saveOne($child,$tabId,$path,$i+1);
    };
    foreach (array_values($tabs) as $i=>$tab) if (is_array($tab)) $saveOne($tab,null,'root',$i+1);
}

function migrationLog(PDO $pdo, $userId, $configId, $status, $fields, $tabs, $projects, $message) {
    $sql="INSERT INTO `sceneiq_table_migration_log` (`user_id`,`legacy_config_id`,`migration_status`,`fields_migrated`,`tabs_migrated`,`projects_migrated`,`message`,`completed_at`)
        VALUES (:user_id,:config_id,:status,:fields,:tabs,:projects,:message,CASE WHEN :status_done='completed' THEN NOW() ELSE NULL END)
        ON DUPLICATE KEY UPDATE `migration_status`=VALUES(`migration_status`),`fields_migrated`=VALUES(`fields_migrated`),`tabs_migrated`=VALUES(`tabs_migrated`),`projects_migrated`=VALUES(`projects_migrated`),`message`=VALUES(`message`),`completed_at`=VALUES(`completed_at`)";
    $stmt=$pdo->prepare($sql);
    $stmt->execute([':user_id'=>$userId,':config_id'=>$configId,':status'=>$status,':fields'=>$fields,':tabs'=>$tabs,':projects'=>$projects,':message'=>$message,':status_done'=>$status]);
}

function runSceneIQMigration(PDO $pdo, $force = false, $dryRun = true) {
    $pdo->query('SELECT `id`,`user_id`,`settings`,`dynamic_fields`,`dynamic_tabs` FROM `sceneiq_config` LIMIT 1');
    $rows=$pdo->query('SELECT `id`,`user_id`,`settings`,`dynamic_fields`,`dynamic_tabs` FROM `sceneiq_config` WHERE `user_id`>0 ORDER BY `user_id` ASC,`id` DESC')->fetchAll();
    $latest=[]; $olderRows=0;
    foreach ($rows as $row) {
        $uid=(int)$row['user_id'];
        if (!isset($latest[$uid])) $latest[$uid]=$row;
        else $olderRows++;
    }
    $summary=['mode'=>$dryRun?'dry_run':'apply','accounts_seen'=>count($latest),'accounts_validated'=>0,'accounts_migrated'=>0,'accounts_skipped'=>0,'accounts_failed'=>0,'fields'=>0,'tabs'=>0,'projects'=>0,'older_config_rows_ignored'=>$olderRows,'details'=>[]];

    foreach ($latest as $userId=>$row) {
        $configId=(int)$row['id'];
        $statusStmt=$pdo->prepare('SELECT `migration_status` FROM `sceneiq_table_migration_log` WHERE `user_id`=:user_id AND `legacy_config_id`=:config_id LIMIT 1');
        $statusStmt->execute([':user_id'=>$userId,':config_id'=>$configId]);
        $previousStatus=$statusStmt->fetchColumn();
        if (!$force && $previousStatus==='completed') {
            $summary['accounts_skipped']++;
            $summary['details'][]="User {$userId}: already migrated (config {$configId}); skipped.";
            continue;
        }

        $fieldsCount=0; $tabsCount=0; $projectsCount=0;
        try {
            $settings=migrationRepairProjects(migrationDecodeLegacyColumn($row['settings']??'[]','settings'));
            $fields=migrationNormalizeFields(migrationDecodeLegacyColumn($row['dynamic_fields']??'[]','dynamic_fields'));
            $tabs=migrationNormalizeTabs(migrationDecodeLegacyColumn($row['dynamic_tabs']??'[]','dynamic_tabs'));
            $fieldsCount=count($fields);
            $tabsCount=migrationCountTabs($tabs);
            $projectsCount=count(array_filter($settings,'is_array'));
            migrationValidateNormalizedData($fields,$tabs,$settings);

            if ($dryRun) {
                $summary['accounts_validated']++;
                $summary['fields']+=$fieldsCount;
                $summary['tabs']+=$tabsCount;
                $summary['projects']+=$projectsCount;
                $summary['details'][]="User {$userId}: VALIDATED - would migrate {$fieldsCount} fields, {$tabsCount} tabs and {$projectsCount} projects (config {$configId}); no database rows changed.";
                continue;
            }

            $fieldsCount=0; $tabsCount=0; $projectsCount=0;
            $pdo->beginTransaction();
            migrationLog($pdo,$userId,$configId,'started',0,0,0,'Migration started.');

            if ($force) {
                // Synchronize this account to the legacy snapshot when explicitly
                // re-running before the table-based application goes live. Keep
                // rows soft-deleted so matching field/tab keys can be reactivated.
                $pdo->prepare('UPDATE `sceneiq_dynamic_field_subfields` SET `deleted_at`=NOW() WHERE `user_id`=:user_id')->execute([':user_id'=>$userId]);
                $pdo->prepare('UPDATE `sceneiq_dynamic_fields` SET `deleted_at`=NOW() WHERE `user_id`=:user_id')->execute([':user_id'=>$userId]);
                $pdo->prepare('UPDATE `sceneiq_dynamic_tabs` SET `deleted_at`=NOW() WHERE `user_id`=:user_id')->execute([':user_id'=>$userId]);
                $pdo->prepare('UPDATE `sceneiq_project_entries` SET `deleted_at`=NOW() WHERE `user_id`=:user_id AND `legacy_config_id`=:config_id')->execute([':user_id'=>$userId,':config_id'=>$configId]);
            }

            foreach ($fields as $fieldKey=>$definition) migrationSaveField($pdo,$userId,(string)$fieldKey,$definition,$fieldsCount);
            migrationSaveTabTree($pdo,$userId,$tabs,$tabsCount);

            $insertProject=$pdo->prepare("INSERT INTO `sceneiq_project_entries`
                (`user_id`,`status`,`entry_data_json`,`operation_status`,`display_order`,`legacy_config_id`,`legacy_entry_index`,`deleted_at`)
                VALUES (:user_id,:status,:data,:operation_status,:position,:config_id,:entry_index,NULL)
                ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`),`status`=VALUES(`status`),`entry_data_json`=VALUES(`entry_data_json`),`operation_status`=VALUES(`operation_status`),`display_order`=VALUES(`display_order`),`deleted_at`=NULL");
            foreach (array_values($settings) as $index=>$entry) {
                if (!is_array($entry)) continue;
                $rawStatus=$entry['status']??'pending';
                $status=is_scalar($rawStatus)?strtolower(trim((string)$rawStatus)):'pending';
                if (!in_array($status,['pending','completed','aborted'],true)) {
                    $entry['legacy_status_original']=$rawStatus;
                    $status='pending';
                }
                $entry['status']=$status;
                $operationStatus=is_scalar($entry['operation_status']??'')?(string)($entry['operation_status']??''):'';
                $insertProject->execute([
                    ':user_id'=>$userId,':status'=>$status,':data'=>migrationJsonEncode($entry),
                    ':operation_status'=>$operationStatus,':position'=>$index,':config_id'=>$configId,':entry_index'=>$index
                ]);
                $projectsCount++;
            }

            migrationLog($pdo,$userId,$configId,'completed',$fieldsCount,$tabsCount,$projectsCount,'Validated and migrated from legacy config row. Original sceneiq_config row retained.');
            $pdo->commit();
            $summary['accounts_migrated']++;
            $summary['fields']+=$fieldsCount; $summary['tabs']+=$tabsCount; $summary['projects']+=$projectsCount;
            $summary['details'][]="User {$userId}: migrated {$fieldsCount} fields, {$tabsCount} tabs and {$projectsCount} projects (config {$configId}).";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            try { migrationLog($pdo,$userId,$configId,'failed',$fieldsCount,$tabsCount,$projectsCount,$e->getMessage()); } catch (Throwable $logError) { error_log('SceneIQ migration log failed: '.$logError->getMessage()); }
            $summary['accounts_failed']++;
            $summary['details'][]="User {$userId}: FAILED - {$e->getMessage()} (config {$configId}).";
            error_log("SceneIQ table migration failed for user {$userId}, config {$configId}: ".$e->getMessage());
        }
    }
    return $summary;
}

$isCli = (PHP_SAPI === 'cli');
$force = $isCli && in_array('--force', $argv ?? [], true);
$dryRun = $isCli ? !in_array('--apply', $argv ?? [], true) : true;
if (!$isCli) {
    if ($migrationToken === '' || $migrationToken === 'CHANGE_THIS_TO_A_LONG_RANDOM_TOKEN') {
        http_response_code(403);
        exit('Browser migration is disabled. Set a private migration_token in sceneiq_db_config.php, or run this utility using PHP CLI.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>SceneIQ Data Migration</title></head><body style="font:15px Segoe UI,Arial,sans-serif;max-width:760px;margin:40px auto;padding:20px">';
        echo '<h2>SceneIQ relational storage migration</h2><p>This inspects every account\'s latest legacy configuration. A dry run is selected by default and does not write to the database. Existing sceneiq_config rows are never changed or deleted.</p>';
        echo '<form method="post"><label>Migration token<br><input type="password" name="migration_token" required style="width:100%;padding:10px;margin:8px 0"></label><p><label><input type="checkbox" name="apply_migration" value="1"> APPLY migration (write validated records into the new tables). Leave unchecked for a safe dry run.</label></p><p><label><input type="checkbox" name="force" value="1"> Re-run accounts already marked completed (only before switching the new app live)</label></p><button style="padding:10px 16px">Run migration check</button></form></body></html>';
        exit;
    }
    $provided=(string)($_POST['migration_token']??'');
    if (!hash_equals($migrationToken,$provided)) { http_response_code(403); exit('Migration token check failed.'); }
    $force=!empty($_POST['force']);
    $dryRun=empty($_POST['apply_migration']);
}

try {
    $summary=runSceneIQMigration($pdo,$force,$dryRun);
    header('Content-Type: text/plain; charset=utf-8');
    echo "SceneIQ table migration finished.\n";
    foreach ($summary as $key=>$value) {
        if ($key==='details') continue;
        echo $key . ': ' . $value . "\n";
    }
    echo "\nAccount details:\n" . implode("\n",$summary['details']) . "\n";
    if ($dryRun) {
        echo "\nDRY RUN ONLY: no target data or migration-log rows were written. To apply from CLI, run: php SceneIQ_Migrate_To_Tables.php --apply (add --force only when you intentionally need to re-run completed accounts).\n";
    } else {
        echo "\nMigration applied. The old sceneiq_config table has not been modified. Check the verification queries in SceneIQ_Table_Schema.sql before switching the site over.\n";
    }
} catch (Throwable $e) {
    error_log('SceneIQ migration stopped: '.$e->getMessage());
    http_response_code(500);
    echo 'Migration stopped: '.$e->getMessage();
}

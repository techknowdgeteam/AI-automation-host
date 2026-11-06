<?php
    // serenum.php - Serenum Configuration
    session_start();

    // Check if user is authenticated
    $isAuthenticated = isset($_SESSION['jpgsvault_authenticated']) && $_SESSION['jpgsvault_authenticated'] === true;

    // Check inactivity timeout (30 minutes)
    if ($isAuthenticated && isset($_SESSION['jpgsvault_last_activity'])) {
        if (time() - $_SESSION['jpgsvault_last_activity'] > 1800) {
            session_destroy();
            $isAuthenticated = false;
        } else {
            $_SESSION['jpgsvault_last_activity'] = time();
        }
    }

    // If not authenticated, redirect to login page
    if (!$isAuthenticated) {
        header('Location: login.php');
        exit;
    }
    // Handle Logout
    if (isset($_POST['action']) && $_POST['action'] === 'logout') {
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }

    // Handle Check Auth
    if (isset($_GET['action']) && $_GET['action'] === 'check_auth') {
        $authenticated = isset($_SESSION['jpgsvault_authenticated']) && $_SESSION['jpgsvault_authenticated'] === true;
        
        if ($authenticated && isset($_SESSION['jpgsvault_last_activity'])) {
            if (time() - $_SESSION['jpgsvault_last_activity'] > 1800) {
                session_destroy();
                $authenticated = false;
            } else {
                $_SESSION['jpgsvault_last_activity'] = time();
            }
        }
        
        echo json_encode(['authenticated' => $authenticated]);
        exit;
    }
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

    // Read the actual selected engine value from dynamic_values when older or
    // customized forms do not send the separate root-level engine property.
    function serenumExtractEngineValue($dynamicValues) {
        if (!is_array($dynamicValues)) return '';
        foreach ($dynamicValues as $key => $value) {
            $normalizedKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$key));
            if (strpos($normalizedKey, 'engine') === false) continue;
            if (is_array($value)) {
                foreach ($value as $nestedValue) {
                    if (is_scalar($nestedValue) && trim((string)$nestedValue) !== '') return trim((string)$nestedValue);
                }
                continue;
            }
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $nestedValue) {
                        if (is_scalar($nestedValue) && trim((string)$nestedValue) !== '') return trim((string)$nestedValue);
                    }
                }
            }
            if (is_scalar($value) && trim((string)$value) !== '') return trim((string)$value);
        }
        return '';
    }

    // Handle Add/Edit Configuration. Persist the selected engine only when one
    // was actually submitted; never invent an engine default.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
        try {
            $payload = json_decode($_POST['config_data'] ?? '', true);
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid configuration data received.');
            }
            $dynamicValues = $payload['dynamic_values'] ?? [];
            if (!is_array($dynamicValues)) $dynamicValues = [];
            $author = trim((string)($payload['author'] ?? ''));
            if ($author === '') {
                foreach ($dynamicValues as $key => $value) {
                    if (preg_match('/^author$/i', (string)$key) && is_scalar($value)) {
                        $author = trim((string)$value);
                        break;
                    }
                }
            }
            if ($author === '') throw new RuntimeException('Please provide an author/name value.');

            $engine = trim((string)($payload['engine'] ?? ''));
            if ($engine === '') $engine = serenumExtractEngineValue($dynamicValues);
            $editIndex = isset($payload['edit_index']) && $payload['edit_index'] !== '' ? (int)$payload['edit_index'] : null;

            // Serialize read/modify/write operations so simultaneous saves cannot
            // overwrite one another's pending records.
            $pdo->beginTransaction();
            $stmt = $pdo->query("SELECT id, settings FROM serenum_config ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Configuration storage is not initialized. Please save Setup first.');

            $rawSettings = trim((string)($row['settings'] ?? '[]'));
            if ($rawSettings === '') $rawSettings = '[]';
            $settings = json_decode($rawSettings, true);
            if (!is_array($settings)) {
                throw new RuntimeException('Stored configuration records are invalid JSON. No records were changed.');
            }
            // Use the same canonical record ordering as the page renderer and
            // the status/delete handlers. This keeps every action index aligned.
            $settings = repairSettings($settings);

            $newConfig = [
                'author' => $author,
                'status' => 'pending',
                'dynamic_values' => $dynamicValues
            ];
            if ($engine !== '') $newConfig['engine'] = $engine;

            // New submissions always append as independent records. Editing targets
            // the exact original array index, never an author-name match (authors
            // can repeat and must not cause one engine to overwrite another).
            if ($editIndex !== null) {
                if (!array_key_exists($editIndex, $settings) || !is_array($settings[$editIndex])) {
                    throw new RuntimeException('The configuration being edited could not be found. Refresh and try again.');
                }
                $existing = $settings[$editIndex];
                $newConfig['status'] = $existing['status'] ?? 'pending';
                if (isset($existing['operation_status'])) {
                    $newConfig['operation_status'] = $existing['operation_status'];
                }
                foreach ($existing as $k => $v) {
                    // Form fields in dynamic_values are the canonical current values.
                    // Do not retain an older flattened copy at the root, because that
                    // legacy value could otherwise override the value just submitted.
                    if (array_key_exists((string)$k, $dynamicValues)) continue;
                    if (!in_array($k, ['author', 'status', 'dynamic_values', 'operation_status'], true)
                        && ($k !== 'engine' || $engine === '')) {
                        $newConfig[$k] = $v;
                    }
                }
                $settings[$editIndex] = $newConfig;
            } else {
                $settings[] = $newConfig;
            }

            $stmt = $pdo->prepare("UPDATE serenum_config SET settings = :settings WHERE id = :id");
            $stmt->execute([
                ':settings' => json_encode(array_values($settings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':id' => $row['id']
            ]);
            $pdo->commit();
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Save configuration failed: ' . $e->getMessage());
            http_response_code(400);
            echo 'Unable to save configuration: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit;
        }
    }

    // ===== GET COPIED LINKS DATA FROM JPGSVAULT =====
    function getCopiedLinksData($pdo) {
        try {
            // STEP 1: Get ALL columns from jpgsvault table (exactly like debug script)
            $stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
            $allColumns = [];
            while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $field = $col['Field'];
                // Skip system columns (same as debug script)
                if ($field !== 'id' && $field !== 'copied_links' && $field !== 'server_passkey' && 
                    !str_starts_with($field, 'all_urls')) {
                    $allColumns[] = $field;
                }
            }
            
            // STEP 2: Build folder data from ALL columns - reading each column's data
            $folderData = [];
            
            foreach ($allColumns as $folder) {
                // Get the images from this column (exactly like debug script's getImagesInFolder)
                $stmt = $pdo->prepare("SELECT `$folder` FROM jpgsvault WHERE id = 1");
                $stmt->execute();
                $json = $stmt->fetchColumn();
                $images = $json ? json_decode($json, true) : [];
                if (!is_array($images)) $images = [];
                
                // Build URLs array from images
                $urls = [];
                foreach ($images as $image) {
                    if (is_string($image)) {
                        $urls[] = $image;
                    }
                }
                
                $folderData[$folder] = [
                    'folder' => $folder,
                    'urls' => $urls,
                    'count' => count($urls)
                ];
            }
            
            // STEP 3: Sort folders by count (most URLs first) - like debug script
            uasort($folderData, function($a, $b) {
                return $b['count'] - $a['count'];
            });
            
            return $folderData;
            
        } catch (PDOException $e) {
            error_log("Error getting copied links data: " . $e->getMessage());
            return [];
        }
    }
    // Add this function near the top with other helper functions
    function cleanDynamicValues($settings) {
        if (!is_array($settings)) {
            return $settings;
        }
        
        foreach ($settings as &$config) {
            if (is_array($config) && isset($config['dynamic_values']) && is_array($config['dynamic_values'])) {
                // Remove status and operation_status from dynamic_values
                unset($config['dynamic_values']['status']);
                unset($config['dynamic_values']['operation_status']);
            }
        }
        
        return $settings;
    }

    // Save only the dynamic-field order. Definitions are loaded from the database
    // and rebuilt in the submitted order, without replacing pending records or
    // unrelated Setup data.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_dynamic_fields_order'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $fieldOrder = json_decode(
                (string)($_POST['field_order'] ?? ''),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($fieldOrder) || ($fieldOrder !== [] && array_keys($fieldOrder) !== range(0, count($fieldOrder) - 1))) {
                throw new RuntimeException('Invalid dynamic-field order received.');
            }

            $fieldOrder = array_map(static function ($key) {
                if (!is_string($key) && !is_int($key)) {
                    throw new RuntimeException('A dynamic-field key is invalid.');
                }
                return (string)$key;
            }, $fieldOrder);

            if (count($fieldOrder) !== count(array_unique($fieldOrder))) {
                throw new RuntimeException('The dynamic-field order contains duplicate keys.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->query(
                "SELECT id, settings_dynamic_fields
                 FROM serenum_config
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new RuntimeException('Configuration storage was not found. Save Setup first.');
            }

            $currentFields = json_decode(
                (string)($row['settings_dynamic_fields'] ?? '{}'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($currentFields)) {
                throw new RuntimeException('Stored dynamic-field definitions are invalid.');
            }

            $currentKeys = array_map('strval', array_keys($currentFields));
            $submittedSorted = $fieldOrder;
            $currentSorted = $currentKeys;
            sort($submittedSorted, SORT_STRING);
            sort($currentSorted, SORT_STRING);

            // Refuse stale browser orders rather than dropping concurrently changed fields.
            if ($submittedSorted !== $currentSorted) {
                throw new RuntimeException(
                    'The dynamic fields changed in another session. Refresh the page and try again.'
                );
            }

            $orderedFields = [];
            foreach ($fieldOrder as $fieldKey) {
                if (!array_key_exists($fieldKey, $currentFields)) {
                    throw new RuntimeException('A dynamic field in the requested order no longer exists.');
                }
                $orderedFields[$fieldKey] = $currentFields[$fieldKey];
            }

            $encodedFields = json_encode(
                $orderedFields,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );

            $update = $pdo->prepare(
                'UPDATE serenum_config
                 SET settings_dynamic_fields = :dynamic_fields
                 WHERE id = :id'
            );
            $update->execute([
                ':dynamic_fields' => $encodedFields,
                ':id' => (int)$row['id']
            ]);

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Dynamic-field order saved successfully.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Serenum dynamic-field order save failed: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    // Handle Save All. Setup saves update setup columns only; pending/completed/aborted
    // records are changed exclusively by the dedicated configuration/status handlers.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {
        try {
            $hasSettingsPayload = array_key_exists('settings', $_POST);

            // A malformed settings payload must never silently become an empty list.
            $settingsData = [];
            if ($hasSettingsPayload) {
                $settingsData = json_decode((string)$_POST['settings'], true);
                if (!is_array($settingsData)) {
                    throw new RuntimeException('Invalid settings data received. Pending records were not changed.');
                }
                $settingsData = cleanDynamicValues($settingsData);
                $settingsData = normalizeSettings($settingsData);
                $settingsData = repairSettings($settingsData);
            }

            $accountsData = json_decode((string)($_POST['accounts'] ?? '[]'), true);
            $captionsData = json_decode((string)($_POST['captions'] ?? '[]'), true);
            $timeordersData = json_decode((string)($_POST['timeorders'] ?? '[]'), true);
            $filtersData = json_decode((string)($_POST['filters'] ?? '[]'), true);
            $dynamicFieldsData = json_decode((string)($_POST['dynamic_fields'] ?? '[]'), true);

            if (!is_array($accountsData)) $accountsData = [];
            if (!is_array($captionsData)) $captionsData = [];
            if (!is_array($timeordersData)) $timeordersData = [];
            if (!is_array($filtersData)) $filtersData = [];
            if (!is_array($dynamicFieldsData)) $dynamicFieldsData = [];

            $pdo->beginTransaction();
            $stmt = $pdo->query("SELECT id FROM serenum_config ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $latestId = $stmt->fetchColumn();

            if ($latestId) {
                $setParts = [];
                $params = [
                    ':accounts_url' => json_encode($accountsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':captions' => json_encode($captionsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':time_orders' => json_encode($timeordersData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':filters' => json_encode($filtersData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':dynamic_fields' => json_encode($dynamicFieldsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':id' => (int)$latestId
                ];

                // Only callers explicitly sending settings may replace the record list.
                // The current Save Setup UI intentionally omits this field.
                if ($hasSettingsPayload) {
                    $setParts[] = 'settings = :settings';
                    $params[':settings'] = json_encode($settingsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }

                $setParts[] = 'accounts_url = :accounts_url';
                $setParts[] = 'captions = :captions';
                $setParts[] = 'time_orders = :time_orders';
                $setParts[] = 'filters = :filters';
                $setParts[] = 'settings_dynamic_fields = :dynamic_fields';

                $stmt = $pdo->prepare('UPDATE serenum_config SET ' . implode(', ', $setParts) . ' WHERE id = :id');
                $stmt->execute($params);
            } else {
                // First-time setup initializes an empty record list. Later setup saves
                // never transmit or replace settings/pending records.
                $stmt = $pdo->prepare("INSERT INTO serenum_config
                    (settings, accounts_url, captions, time_orders, filters, settings_dynamic_fields)
                    VALUES (:settings, :accounts_url, :captions, :time_orders, :filters, :dynamic_fields)");
                $stmt->execute([
                    ':settings' => json_encode($hasSettingsPayload ? $settingsData : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':accounts_url' => json_encode($accountsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':captions' => json_encode($captionsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':time_orders' => json_encode($timeordersData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':filters' => json_encode($filtersData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ':dynamic_fields' => json_encode($dynamicFieldsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                ]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'All setup data saved successfully. Existing pending records were preserved.']);
            exit;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Serenum setup save failed: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Error saving data: ' . $e->getMessage()]);
            exit;
        }
    }

    // ===== NEW FUNCTION: Normalize nested JSON strings =====
    function normalizeSettings($settings) {
        if (!is_array($settings)) {
            return $settings;
        }
        
        // Process each config in the settings array
        foreach ($settings as &$config) {
            if (!is_array($config)) continue;
            
            // List of fields that contain nested JSON strings
            $jsonFields = ['account_url', 'author_caption', 'Jpgsurl', 'jpgsurl', 'time_order_type'];
            
            foreach ($jsonFields as $field) {
                if (isset($config[$field]) && is_string($config[$field])) {
                    // Try to decode the JSON string
                    $decoded = json_decode($config[$field], true);
                    if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                        // It's valid JSON, replace the string with the decoded array/object
                        $config[$field] = $decoded;
                    }
                    // If it's not valid JSON, leave it as is
                }
            }
            
            // Also handle dynamic_values if present (for backward compatibility)
            if (isset($config['dynamic_values']) && is_array($config['dynamic_values'])) {
                foreach ($jsonFields as $field) {
                    if (isset($config['dynamic_values'][$field]) && is_string($config['dynamic_values'][$field])) {
                        $decoded = json_decode($config['dynamic_values'][$field], true);
                        if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                            $config['dynamic_values'][$field] = $decoded;
                        }
                    }
                }
            }
        }
        
        return $settings;
    }

    // Helper function to repair settings structure
    // Helper function to repair settings structure
    function repairSettings($settings) {
        // If not an array, return empty array
        if (!is_array($settings)) {
            return [];
        }
        
        // If it's already an indexed array of objects, just filter out non-objects
        if (isset($settings[0]) && is_array($settings[0])) {
            return array_values(array_filter($settings, function($item) {
                return is_array($item);
            }));
        }
        
        // If it's a single object, wrap it in an array
        if (isset($settings['status']) || isset($settings['dynamic_values'])) {
            return [$settings];
        }
        
        // Try to extract objects with 'status' or 'dynamic_values' key
        $result = [];
        foreach ($settings as $key => $value) {
            if (is_array($value) && (isset($value['status']) || isset($value['dynamic_values']))) {
                $result[] = $value;
            }
        }
        
        // If we couldn't find any valid configs, return the original as-is
        if (empty($result)) {
            return $settings;
        }
        
        return $result;
    }

    // Handle Update Status by exact array index (authors are not unique).
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $index = isset($_POST['index']) ? (int)$_POST['index'] : -1;
            $status = strtolower(trim((string)($_POST['status'] ?? '')));
            $allowedStatuses = ['pending', 'completed', 'aborted'];
            if ($id <= 0 || $index < 0 || !in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('Invalid status update request.');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT settings FROM serenum_config WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Configuration storage was not found.');
            $settings = json_decode($row['settings'] ?? '[]', true);
            if (!is_array($settings)) $settings = [];
            // Match the exact normalization used to render the pending cards.
            $settings = repairSettings($settings);
            if (!isset($settings[$index]) || !is_array($settings[$index])) {
                throw new RuntimeException('Configuration index no longer exists. Refresh and try again.');
            }
            $settings[$index]['status'] = $status;
            $settings[$index]['operation_status'] = "change_status: Status updated to '$status'";
            if (isset($settings[$index]['dynamic_values']) && is_array($settings[$index]['dynamic_values'])) {
                unset($settings[$index]['dynamic_values']['status'], $settings[$index]['dynamic_values']['operation_status']);
            }
            $stmt = $pdo->prepare("UPDATE serenum_config SET settings = :settings WHERE id = :id");
            $stmt->execute([
                ':settings' => json_encode(array_values($settings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':id' => $id
            ]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
            exit;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    // Handle Delete Configuration by exact array index. Author/name is not unique.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_config'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $index = isset($_POST['index']) ? (int)$_POST['index'] : -1;
            $expectedAuthor = trim((string)($_POST['expected_author'] ?? ''));
            if ($id <= 0 || $index < 0 || $expectedAuthor === '') {
                throw new RuntimeException('Invalid delete request. Refresh the page and try again.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT settings FROM serenum_config WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Configuration storage was not found.');

            $settings = json_decode($row['settings'] ?? '[]', true);
            if (!is_array($settings)) $settings = [];
            // Keep action indexes identical to the server-rendered card indexes.
            $settings = repairSettings($settings);
            if (!array_key_exists($index, $settings) || !is_array($settings[$index])) {
                throw new RuntimeException('Configuration index no longer exists. Refresh and try again.');
            }

            // A stale/mismatched card must never delete a different author's record.
            $actualAuthor = resolveSerenumAuthorLabel($settings[$index]);
            if (!hash_equals($expectedAuthor, $actualAuthor)) {
                throw new RuntimeException('The selected record no longer matches this card. No record was deleted. Refresh the page and try again.');
            }

            array_splice($settings, $index, 1);
            $stmt = $pdo->prepare("UPDATE serenum_config SET settings = :settings WHERE id = :id");
            $stmt->execute([
                ':settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':id' => $id
            ]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Configuration deleted successfully.']);
            exit;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    // Handle Get Config for Editing by exact index; author is only a legacy fallback.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_config'])) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $index = isset($_POST['index']) && $_POST['index'] !== '' ? (int)$_POST['index'] : null;
            $author = trim((string)($_POST['author'] ?? ''));
            $stmt = $pdo->query("SELECT id, settings FROM serenum_config ORDER BY id DESC LIMIT 1");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Configuration storage was not found.');
            $settings = json_decode($row['settings'] ?? '[]', true);
            if (!is_array($settings)) $settings = [];
            // Keep the edit lookup index aligned with the page's card indexes.
            $settings = repairSettings($settings);
            $foundConfig = null;
            if ($index !== null && isset($settings[$index]) && is_array($settings[$index])) {
                $foundConfig = $settings[$index];
            } elseif ($index === null && $author !== '') {
                foreach ($settings as $config) {
                    if (is_array($config) && ($config['author'] ?? '') === $author) {
                        $foundConfig = $config;
                        break;
                    }
                }
            }
            if ($foundConfig !== null) {
                echo json_encode(['success' => true, 'config' => $foundConfig, 'index' => $index]);
                exit;
            }
            throw new RuntimeException('Configuration not found.');
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    // Fetch existing data - handle null values properly
    $settings_data = [];
    $accounts_data = [];
    $captions_data = [];
    $timeorders_data = [];
    $filters_data = [];
    $dynamic_fields_data = [];

    try {
        $stmt = $pdo->query("SELECT id, settings, accounts_url, captions, time_orders, filters, settings_dynamic_fields FROM serenum_config ORDER BY id DESC LIMIT 1");
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings_data = json_decode($row['settings'], true) ?: [];
            $accounts_data = json_decode($row['accounts_url'], true) ?: [];
            $captions_data = json_decode($row['captions'], true) ?: [];
            $timeorders_data = json_decode($row['time_orders'], true) ?: [];
            $filters_data = json_decode($row['filters'], true) ?: [];
            $dynamic_fields_data = json_decode($row['settings_dynamic_fields'], true) ?: [];
            $config_id = $row['id'];
        }
    } catch(PDOException $e) {
        // Table might be empty
        $config_id = 0;
    }

    // Ensure data is always an object/array
    if (!is_array($settings_data)) $settings_data = [];
    if (!is_array($accounts_data)) $accounts_data = [];
    if (!is_array($captions_data)) $captions_data = [];
    if (!is_array($timeorders_data)) $timeorders_data = [];
    if (!is_array($filters_data)) $filters_data = [];
    if (!is_array($dynamic_fields_data)) $dynamic_fields_data = [];

    // Auto-repair settings data
    $settings_data = repairSettings($settings_data);

    // Get configurations list from settings
    $configurations = [];
    if (!empty($settings_data)) {
        if (isset($settings_data[0]) && is_array($settings_data[0])) {
            $configurations = $settings_data;
        } else if (isset($settings_data['author'])) {
            $configurations = [$settings_data];
        }
    }

    // Ensure each config has a status, engine and operation_status
    foreach ($configurations as &$config) {
        if (!isset($config['status']) || empty($config['status'])) {
            $config['status'] = 'pending';
        }
        if (!isset($config['author_caption']) || empty($config['author_caption'])) {
            $config['author_caption'] = [];
        }
        if (!isset($config['author_account_url']) || empty($config['author_account_url'])) {
            $config['author_account_url'] = [];
        }
        if (!isset($config['dynamic_values']) || empty($config['dynamic_values'])) {
            $config['dynamic_values'] = [];
        }
        if (!isset($config['operation_status']) || empty($config['operation_status'])) {
            $config['operation_status'] = '';
        }
        // Ensure author exists - if not, create from dynamic_values or use a default
        if (!isset($config['author']) || empty($config['author'])) {
            if (isset($config['dynamic_values']['author'])) {
                $config['author'] = $config['dynamic_values']['author'];
            } else {
                $config['author'] = 'Config_' . uniqid();
            }
        }
    }
    // IMPORTANT: break the reference before iterating this array again.
    // Without this, the next foreach can overwrite the final record, causing
    // a card's displayed author to differ from configData.settings at that index.
    unset($config);

    // ===== GROUP CONFIGURATIONS BY STATUS (using root-level status) =====
    $grouped_configs = [
        'pending' => [],
        'completed' => [],
        'aborted' => []
    ];

    foreach ($configurations as $index => $configRecord) {
        // Get status from root level, NOT from dynamic_values
        $status = isset($configRecord['status']) ? strtolower((string)$configRecord['status']) : 'pending';
        if (!isset($grouped_configs[$status])) {
            $grouped_configs[$status] = [];
        }
        // Store the original canonical array index with the record.
        $configRecord['_index'] = $index;
        $grouped_configs[$status][] = $configRecord;
    }

    // Display newest submissions first in every status tab.
    // `_index` remains the original position in the canonical settings array,
    // so Copy, Edit, Delete and Status actions continue targeting the correct row.
    foreach (array_keys($grouped_configs) as $statusKey) {
        usort($grouped_configs[$statusKey], static function ($left, $right) {
            return ((int)($right['_index'] ?? -1)) <=> ((int)($left['_index'] ?? -1));
        });
    }

    // Status labels for display
    $status_labels = [
        'pending' => 'Pending Operations',
        'completed' => 'Completed Operations',
        'aborted' => 'Aborted Operations'
    ];

    $status_colors = [
        'pending' => '#f6ad55',
        'completed' => '#48bb78',
        'aborted' => '#fc8181'
    ];

    // Get copied links data for use in JavaScript
    $copiedLinksData = getCopiedLinksData($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Serenum Configuration</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { 
            height: 100%; 
            overflow: hidden;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f8fafc;
        }
        
        input, select, textarea {
            font-size: 16px !important;
        }
        
        /* ===== HEADER ===== */
        .main-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: linear-gradient(135deg, #00695c 0%, #00897b 50%, #26a69a 100%);
            color: white;
            padding: 12px 20px;
            box-shadow: 0 4px 20px rgba(0,105,92,0.3);
            height: 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .main-header h1 { 
            font-size: 20px; 
            font-weight: 600; 
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .main-header .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .main-header .header-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            padding: 6px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s;
            backdrop-filter: blur(5px);
            white-space: nowrap;
        }
        
        .main-header .header-btn:hover {
            background: rgba(255,255,255,0.35);
            transform: translateY(-2px);
        }
        
        /* ===== MAIN TABS - HORIZONTAL SCROLL ===== */
        .main-tabs {
            position: fixed;
            top: 60px;
            left: 0;
            right: 0;
            z-index: 999;
            background: white;
            border-bottom: 2px solid #e5e7eb;
            display: flex;
            overflow-x: auto;
            overflow-y: hidden;
            white-space: nowrap;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
            align-items: stretch;
            min-height: 60px;
            height: 60px;
            flex-wrap: nowrap;
            padding: 0 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .main-tabs::-webkit-scrollbar {
            height: 3px;
        }
        
        .main-tabs::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .main-tabs::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 10px;
        }
        
        .main-tabs::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        .main-tab-btn {
            padding: 10px 20px;
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
            min-height: 60px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .main-tab-btn:hover {
            color: #1a1a2e;
            background: #f3f4f6;
        }
        
        .main-tab-btn.active {
            color: #00695c;
            border-bottom-color: #00695c;
        }
        
        .main-tab-btn .badge {
            display: inline-block;
            background: #e5e7eb;
            color: #4b5563;
            padding: 1px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .main-tab-btn.active .badge {
            background: #00695c;
            color: white;
        }
        
        .main-tab-btn .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 4px;
            flex-shrink: 0;
        }
        
        /* ===== SUB TABS (for Setup Config) ===== */
        .sub-tabs {
            display: none;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            padding: 0 10px;
            overflow-x: auto;
            overflow-y: hidden;
            white-space: nowrap;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
            align-items: stretch;
            height: 60px;
            flex-wrap: nowrap;
            position: sticky;
            top: 108px;
            z-index: 100;
        }
        
        .sub-tabs.visible {
            display: flex;
        }
        
        .sub-tabs::-webkit-scrollbar {
            height: 2px;
        }
        
        .sub-tabs::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .sub-tabs::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 10px;
        }
        
        .sub-tab-btn {
            margin-top: 10px;
            padding: 8px 16px;
            background: none;
            border: none;
            font-size: 13px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            white-space: nowrap;
            flex-shrink: 0;
            height: 60px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .sub-tab-btn:hover {
            color: #1a1a2e;
            background: rgba(0,105,92,0.05);
        }
        
        .sub-tab-btn.active {
            color: #00695c;
            border-bottom-color: #00695c;
        }
        
        /* ===== SCROLLABLE BODY ===== */
        .scroll-body {
            position: fixed;
            top: 108px;
            left: 0;
            right: 0;
            bottom: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 20px;
            padding-bottom: 100px;
            scroll-behavior: smooth;
            background: #f8fafc;
        }
        
        .scroll-body.setup-visible {
            top: 148px;
        }
        
        .scroll-body::-webkit-scrollbar {
            width: 8px;
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
        
        /* ===== CONTENT AREAS ===== */
        .tab-content-area {
            display: none;
            max-width: 1120px;
            width: 100%;
            margin: 0 auto;
        }
        
        .tab-content-area.active {
            display: block;
        }
        
        /* ===== CONFIG LIST ITEMS ===== */
        .config-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .config-item {
            width: 100%;
            min-width: 0;
            background: #ffffff;
            border-radius: 12px;
            padding: 14px 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 4px solid #00695c;
            transition: box-shadow 0.2s ease, transform 0.2s ease;
            border-top: 1px solid rgba(0,0,0,0.04);
            border-right: 1px solid rgba(0,0,0,0.04);
            border-bottom: 1px solid rgba(0,0,0,0.04);
        }
        
        .config-item:hover {
            box-shadow: 0 4px 14px rgba(0,0,0,0.07);
            transform: translateX(2px);
        }
        
        .config-item .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 7px;
        }
        
        .config-item .item-author {
            min-width: 0;
            font-size: 14px;
            font-weight: 650;
            color: #1a1a2e;
            overflow-wrap: anywhere;
        }
        
        .config-item .item-status {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            color: white;
            white-space: nowrap;
        }
        
        /* ===== STATUS ENTRY DETAILS ===== */
        .status-copy-toolbar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            width: 100%;
            max-width: 1000px;
            margin: 0 auto 10px;
        }

        .status-copy-button {
            border: 1px solid #d1d5db;
            background: #ffffff;
            color: #374151;
            border-radius: 7px;
            padding: 6px 10px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s ease, border-color .2s ease, transform .2s ease;
            white-space: nowrap;
        }

        .status-copy-button:hover {
            background: #f0fdfa;
            border-color: #0f766e;
            color: #0f766e;
        }

        .status-copy-button:active {
            transform: translateY(1px);
        }

        .copy-button-copied {
            background: #ecfdf5 !important;
            border-color: #86efac !important;
            color: #047857 !important;
        }

        .config-item .item-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 205px), 1fr));
            gap: 8px;
            margin-top: 8px;
            width: 100%;
        }

        .config-item .item-details .detail-field {
            min-width: 0;
            max-width: 100%;
            padding: 8px 9px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f9fbfc;
        }

        .config-item .item-details .detail-label {
            min-width: 0;
            margin-bottom: 5px;
            color: #64748b;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .4px;
            line-height: 1.35;
            text-transform: uppercase;
            overflow-wrap: anywhere;
        }

        .config-item .item-details .detail-value {
            display: block;
            width: 100%;
            min-width: 0;
            color: #1e293b;
            font-size: 12px;
            line-height: 1.45;
            overflow-wrap: anywhere;
            word-break: break-word;
            max-width: 100%;
            max-height: 88px;
            overflow-y: auto;
            overflow-x: auto;
            padding: 6px 8px;
            margin: 0;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: #ffffff;
            white-space: pre-wrap;
        }

        /* Long text and JSON stay inside compact, independently scrollable fields. */
        .config-item .item-details .detail-value.json-value {
            max-height: 128px;
            overflow: auto;
            color: #334155;
            font-family: Consolas, Monaco, 'Courier New', monospace;
            font-size: 10.5px;
            line-height: 1.4;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            word-break: break-word;
            tab-size: 2;
        }

        .config-item .item-details .detail-value::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }

        .config-item .item-details .detail-value::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.05);
            border-radius: 5px;
        }

        .config-item .item-details .detail-value::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #00695c, #26a69a);
            border-radius: 5px;
        }

        .config-item .operation-status-panel {
            margin-top: 9px;
            padding: 9px 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f8fafc;
        }

        .config-item .operation-status-panel .detail-value {
            display: block;
            width: 100%;
            min-width: 0;
            margin-top: 4px;
            color: #334155;
            font-size: 11px;
            line-height: 1.45;
            overflow-wrap: anywhere;
            word-break: break-word;
            max-height: 88px;
            overflow-y: auto;
            overflow-x: auto;
            padding: 6px 8px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: #ffffff;
            white-space: pre-wrap;
        }

        .config-item .item-actions {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .config-item .item-actions .btn {
            padding: 6px 10px;
            border: none;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .status-select-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-left: auto;
            color: #64748b;
            font-size: 11px;
            font-weight: 600;
        }

        .config-item .status-select {
            min-width: 112px;
            padding: 6px 24px 6px 9px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            background: #ffffff;
            color: #334155;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
        }

        .config-item .status-select:focus {
            outline: none;
            border-color: #0f766e;
            box-shadow: 0 0 0 2px rgba(15,118,110,.12);
        }
        
        .config-item .item-actions .btn-edit {
            background: linear-gradient(135deg, #3498db, #2ecc71);
            color: white;
            box-shadow: 0 2px 10px rgba(46,204,113,0.2);
        }
        
        .config-item .item-actions .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(46,204,113,0.3);
        }
        
        .config-item .item-actions .btn-delete {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 2px 10px rgba(239,68,68,0.2);
        }
        
        .config-item .item-actions .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(239,68,68,0.3);
        }
        
        .config-item .item-actions .btn-status {
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
            color: #1a1a2e;
        }
        
        .config-item .item-actions .btn-status:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        
        .operation-status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .operation-status-badge.running {
            background: #48bb78;
            color: white;
        }
        
        .operation-status-badge.paused {
            background: #f6ad55;
            color: white;
        }
        
        .operation-status-badge.stopped {
            background: #fc8181;
            color: white;
        }
        
        .operation-status-badge.completed {
            background: #4299e1;
            color: white;
        }
        
        .operation-status-badge.failed {
            background: #e53e3e;
            color: white;
        }
        
        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        
        .empty-state .icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .empty-state h3 {
            font-size: 20px;
            color: #1a1a2e;
            margin-bottom: 8px;
        }
        
        /* ===== SETUP CONTAINER ===== */
        .setup-container {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border: 1px solid rgba(0,0,0,0.04);
        }
        
        .setup-container .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .setup-container .section-header h2 {
            font-size: 20px;
            color: #1a1a2e;
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
            gap: 6px;
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
            border: 1px solid #e5e7eb;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
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
            padding: 6px 14px;
            font-size: 12px;
        }
        
        /* ===== FORM GROUPS ===== */
        .form-group {
            margin-bottom: 18px;
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
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
            background: white;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        /* ===== ITEM ROWS ===== */
        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #e8f0ec;
        }
        
        .item-row:last-child {
            border-bottom: none;
        }
        
        .item-row .item-info {
            display: flex;
            gap: 20px;
            align-items: center;
            flex: 1;
            flex-wrap: wrap;
        }
        
        .item-row .item-info .key {
            font-weight: 600;
            color: #1a1a2e;
            min-width: 120px;
        }
        
        .item-row .item-info .value {
            color: #6b7280;
            word-break: break-word;
            overflow-wrap: break-word;
            flex: 1;
        }
        
        /* ===== CAPTION ITEMS ===== */
        .caption-item {
            background: #f8fafc;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #e5e7eb;
        }
        
        .caption-item:hover {
            background: #f0faf5;
            border-color: #00695c;
        }
        
        .caption-item .caption-content {
            flex: 1;
        }
        
        .caption-item .caption-author {
            font-weight: 600;
            color: #00695c;
            margin-right: 15px;
        }
        
        /* ===== TIME ORDER ITEMS ===== */
        .timeorder-item {
            background: #f8fafc;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            border: 1px solid #e5e7eb;
        }
        
        .timeorder-item .timeorder-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .timeorder-item .timeorder-header .key {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 16px;
        }
        
        .timeorder-item .time-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5px;
            margin-top: 10px;
        }
        
        .timeorder-item .time-list .time-badge {
            background: white;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            font-size: 14px;
            text-align: center;
            color: #1a1a2e;
        }
        
        .timeorder-item .time-list .time-badge.header {
            background: linear-gradient(135deg, #00695c, #26a69a);
            color: white;
            font-weight: 600;
            border: none;
        }
        
        .timeorder-item .add-time-area {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
        }
        
        /* ===== DYNAMIC FIELD ITEMS ===== */
        .dynamic-field-item {
            background: #f8fafc;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            border: 1px solid #e5e7eb;
            transition: all 0.3s;
        }
        
        .dynamic-field-item:hover {
            border-color: #00695c;
            box-shadow: 0 2px 12px rgba(0,105,92,0.08);
        }
        
        .dynamic-field-item .field-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .dynamic-field-reorder-controls {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            flex-shrink: 0;
        }

        .dynamic-field-position {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 25px;
            height: 25px;
            padding: 0 6px;
            border-radius: 7px;
            background: #e8f5f3;
            color: #00695c;
            font-size: 11px;
            font-weight: 700;
        }

        .dynamic-field-move-button {
            min-width: 29px;
            height: 29px;
            padding: 3px 7px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            background: #ffffff;
            color: #334155;
            font-size: 15px;
            line-height: 1;
            cursor: pointer;
        }

        .dynamic-field-move-button:hover:not(:disabled) {
            border-color: #0f766e;
            background: #f0fdfa;
            color: #0f766e;
        }

        .dynamic-field-move-button:disabled {
            opacity: .35;
            cursor: not-allowed;
        }

        #dynamic_fields_list.is-saving-order {
            opacity: .65;
            pointer-events: none;
        }
        
        .dynamic-field-item .field-name {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 16px;
        }
        
        .dynamic-field-item .field-type-badge {
            background: linear-gradient(135deg, #00695c, #26a69a);
            color: white;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .dynamic-field-item .default-values {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 8px;
        }
        
        .dynamic-field-item .default-values .value-tag {
            background: white;
            padding: 4px 12px;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            font-size: 13px;
            color: #1a1a2e;
        }
        
        /* ===== GROUP ITEMS ===== */
        .group-item {
            background: linear-gradient(135deg, #e8f4f8, #d4e9f0);
            padding: 8px 15px;
            border-radius: 20px;
            display: inline-block;
            margin: 5px;
            color: #1a1a2e;
        }
        
        .group-item .remove-group {
            cursor: pointer;
            margin-left: 8px;
            color: #ef4444;
            font-weight: 600;
        }
        
        /* ===== FLOATING ACTION BUTTON ===== */
        .fab {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 999;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #00695c, #26a69a);
            color: white;
            border: none;
            font-size: 32px;
            font-weight: 300;
            cursor: pointer;
            box-shadow: 0 6px 24px rgba(0,105,92,0.4);
            transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .fab.visible {
            display: flex;
        }
        
        .fab:hover {
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 8px 32px rgba(0,105,92,0.5);
        }
        
        .fab:active {
            transform: scale(0.95);
        }
        
        /* ===== ADD CONFIG VIEW ===== */
        #addConfigView {
            display: none;
            max-width: 900px;
            margin: 0 auto;
        }
        
        #addConfigView.active {
            display: block;
        }
        
        .detail-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .btn-back {
            padding: 10px 25px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 500;
            color: #1a1a2e;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-back:hover {
            background: #f3f4f6;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            transform: translateX(-3px);
        }
        
        .form-container {
            background: #ffffff;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .edit-mode-banner {
            display: none;
            background: linear-gradient(135deg, #00695c, #26a69a);
            color: white;
            padding: 15px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 4px 20px rgba(0,105,92,0.25);
        }
        
        .edit-mode-banner.visible {
            display: flex;
        }
        
        .edit-mode-banner .edit-info {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 16px;
        }
        
        .edit-mode-banner .edit-info strong {
            font-size: 18px;
        }
        
        .edit-mode-banner .btn-cancel-edit {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 8px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            backdrop-filter: blur(5px);
        }
        
        .edit-mode-banner .btn-cancel-edit:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        /* ===== DATE-TIME INPUT ===== */
        .datetime-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .datetime-group .date-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            box-sizing: border-box;
            background: #fafafa;
        }
        
        .datetime-group .date-input:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
            background: white;
        }
        
        .datetime-group .time-group {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            width: 100%;
            box-sizing: border-box;
        }
        
        .datetime-group .time-group input[type="number"] {
            width: 60px;
            padding: 10px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            background: #fafafa;
            box-sizing: border-box;
        }
        
        .datetime-group .time-group input[type="number"]:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
            background: white;
        }
        
        .datetime-group .time-group select {
            padding: 10px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            background: #fafafa;
            width: 80px;
            box-sizing: border-box;
        }
        
        .datetime-group .time-group select:focus {
            border-color: #00695c;
            outline: none;
        }
        
        .time-format-select {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 8px;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            flex-wrap: wrap;
        }
        
        .time-format-select label {
            margin-bottom: 0 !important;
            font-weight: 500 !important;
            color: #1a1a2e;
            font-size: 14px;
        }
        
        .time-format-select select {
            padding: 6px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            background: #fafafa;
            width: auto;
            min-width: 80px;
        }
        
        .time-format-select select:focus {
            border-color: #00695c;
            outline: none;
        }
        
        /* ===== LIST INPUT ===== */
        .list-input-container {
            display: flex;
            gap: 10px;
            margin-top: 5px;
            flex-wrap: wrap;
        }
        
        .list-input-container input {
            flex: 1;
            min-width: 150px;
        }
        
        .list-values {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 8px;
        }
        
        .list-values .value-tag {
            background: linear-gradient(135deg, #e8f4f8, #d4e9f0);
            padding: 4px 12px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #1a1a2e;
            border: 1px solid #c8dce8;
        }
        
        .list-values .value-tag .remove-value {
            cursor: pointer;
            color: #ef4444;
            font-weight: 600;
            font-size: 14px;
            line-height: 1;
        }
        
        .list-values .value-tag .remove-value:hover {
            color: #dc2626;
        }
        
        /* ===== SUBMIT AS PREVIEW ===== */
        .submit-as-preview {
            margin-top: 5px;
            padding: 6px 12px;
            background: #f8fafc;
            border-radius: 6px;
            border: 1px dashed #c8e0d8;
            font-size: 12px;
            color: #6b7280;
            font-family: monospace;
            word-break: break-all;
            max-height: 100px;
            overflow-y: auto;
        }
        
        /* ===== SAVE BUTTONS ===== */
        .save-btn-container {
            position: fixed;
            bottom: 100px;
            right: 30px;
            z-index: 1000;
            display: none;
            flex-direction: column;
            gap: 10px;
        }
        
        .save-btn-container.visible {
            display: flex;
        }
        
        .save-btn-container .btn {
            padding: 12px 30px;
            font-size: 15px;
            border-radius: 10px;
            margin: 0;
            min-width: 180px;
            justify-content: center;
        }
        
        .save-btn-container .btn-save-config {
            background: linear-gradient(135deg, #00695c, #26a69a);
            color: white;
            box-shadow: 0 4px 30px rgba(0,105,92,0.4);
        }
        
        .save-btn-container .btn-save-config:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 40px rgba(0,105,92,0.5);
        }
        
        .save-btn-container .btn-save-setup {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 30px rgba(16,185,129,0.4);
        }
        
        .save-btn-container .btn-save-setup:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 40px rgba(16,185,129,0.5);
        }
        
        /* ===== MODAL ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
            padding: 20px;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-box {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 600px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
            border: 1px solid rgba(0,105,92,0.1);
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px) scale(0.95);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }
        
        .modal-box .modal-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #1a1a2e;
        }
        
        .modal-box .modal-message {
            font-size: 16px;
            color: #6b7280;
            margin-bottom: 20px;
            line-height: 1.6;
            word-wrap: break-word;
        }
        
        .modal-box .modal-content {
            margin-bottom: 15px;
        }
        
        .modal-box .modal-content .form-group {
            margin-bottom: 15px;
        }
        
        .modal-box .modal-content .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }
        
        .modal-box .modal-content .form-group input,
        .modal-box .modal-content .form-group select,
        .modal-box .modal-content .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: #fafafa;
            box-sizing: border-box;
        }
        
        .modal-box .modal-content .form-group input:focus,
        .modal-box .modal-content .form-group select:focus,
        .modal-box .modal-content .form-group textarea:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
            background: white;
        }
        
        .modal-box .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        
        .modal-box .modal-buttons .btn {
            min-width: 80px;
            border-radius: 10px;
        }
        
        .modal-box .modal-buttons .btn-confirm {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        .modal-box .modal-buttons .btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(239,68,68,0.3);
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .form-row, .form-row-3 {
                grid-template-columns: 1fr;
            }
            
            .main-header h1 {
                font-size: 17px;
            }
            
            .main-header {
                padding: 10px 15px;
                height: 55px;
            }
            
            .main-tabs {
                top: 55px;
                min-height: 42px;
                padding: 0 5px;
            }
            
            .main-tab-btn {
                padding: 8px 14px;
                font-size: 13px;
                min-height: 40px;
            }
            
            .sub-tabs {
                top: 97px;
                min-height: 36px;
                padding: 0 5px;
            }
            
            .sub-tab-btn {
                padding: 6px 12px;
                font-size: 12px;
                min-height: 34px;
            }
            
            .scroll-body {
                top: 97px;
                padding: 15px;
                padding-bottom: 80px;
            }
            
            .scroll-body.setup-visible {
                top: 133px;
            }
            
            .config-item .item-details {
                grid-template-columns: 1fr;
            }
            
            .config-item .item-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .detail-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .form-container {
                padding: 20px;
            }
            
            .setup-container {
                padding: 18px;
            }
            
            .fab {
                width: 50px;
                height: 50px;
                font-size: 28px;
                bottom: 20px;
                right: 20px;
            }
            
            .save-btn-container {
                bottom: 80px;
                right: 20px;
            }
            
            .save-btn-container .btn {
                padding: 10px 20px;
                font-size: 13px;
                min-width: 140px;
            }
            
            .timeorder-item .time-list {
                grid-template-columns: 1fr;
            }
            
            .item-row .item-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .item-row .item-info .key {
                min-width: auto;
            }
            
            .caption-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .main-tab-btn {
                padding: 6px 10px;
                font-size: 12px;
                min-height: 36px;
            }
            
            .sub-tab-btn {
                padding: 4px 10px;
                font-size: 11px;
                min-height: 30px;
            }
            
            .config-item {
                padding: 11px 12px;
            }

            .config-item .item-details {
                grid-template-columns: 1fr;
            }

            .status-select-label {
                margin-left: 0;
            }
            
            .modal-box {
                padding: 20px;
                max-width: 95%;
            }
            
            .datetime-group .time-group input[type="number"] {
                width: 50px;
            }
            
            .datetime-group .time-group select {
                width: 70px;
            }
        }
        
        /* ===== OBJECT FIELD CONTAINER ===== */
        .object-field-container {
            background: #f8fafc;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid rgba(0,0,0,0.04);
        }
        
        .object-field-container .object-title {
            color: #00695c;
            margin-bottom: 20px;
            font-size: 17px;
            font-weight: 600;
            padding-bottom: 12px;
            border-bottom: 2px solid rgba(0,105,92,0.1);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sub-field-group {
            background: #ffffff;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid rgba(0,0,0,0.04);
        }
        
        .sub-field-group:last-child {
            margin-bottom: 0;
        }
        
        .sub-field-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #1a1a2e;
            font-size: 14px;
        }
        
        .sub-field-group input,
        .sub-field-group select,
        .sub-field-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: #fafafa;
            box-sizing: border-box;
        }
        
        .sub-field-group input:focus,
        .sub-field-group select:focus,
        .sub-field-group textarea:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.08);
            background: white;
        }
        
        .sub-field-group .datetime-group {
            width: 100%;
        }
        
        .sub-field-group .datetime-group .date-input {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: #fafafa;
            box-sizing: border-box;
        }
        
        .sub-field-group .datetime-group .date-input:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.08);
            background: white;
        }
        
        .sub-field-group .datetime-group .time-group {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        
        .sub-field-group .datetime-group .time-group input[type="number"],
        .sub-field-group .datetime-group .time-group select {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: #fafafa;
        }
        
        .sub-field-group .datetime-group .time-group input[type="number"]:focus,
        .sub-field-group .datetime-group .time-group select:focus {
            border-color: #00695c;
            outline: none;
        }
        
        .no-fields-message {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        
        .no-fields-message .icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .no-fields-message h3 {
            font-size: 20px;
            color: #1a1a2e;
            margin-bottom: 8px;
        }
        
        /* ===== FLASH MESSAGES ===== */
        .flash-message {
            padding: 12px 18px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        
        .flash-message.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .flash-message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
    </style>
</head>
<body>
    <!-- ===== HEADER ===== -->
    <div class="main-header" id="mainHeader">
        <div style="display:flex; align-items:center; gap:15px;">
            <h1>Serenum</h1>
        </div>
        <div class="header-actions">
            <button class="header-btn" onclick="window.location.href='index.php'">← Tasks</button>
        </div>
    </div>
    
    <!-- ===== MAIN TABS ===== -->
    <div class="main-tabs" id="mainTabs">
        <button class="main-tab-btn" data-tab="pending" onclick="switchMainTab('pending')">
            <span class="status-dot" style="background:#f59e0b;"></span>Pending
            <span class="badge"><?php echo count($grouped_configs['pending'] ?? []); ?></span>
        </button>
        <button class="main-tab-btn" data-tab="completed" onclick="switchMainTab('completed')">
            <span class="status-dot" style="background:#10b981;"></span>Completed
            <span class="badge"><?php echo count($grouped_configs['completed'] ?? []); ?></span>
        </button>
        <button class="main-tab-btn" data-tab="aborted" onclick="switchMainTab('aborted')">
            <span class="status-dot" style="background:#ef4444;"></span>Aborted
            <span class="badge"><?php echo count($grouped_configs['aborted'] ?? []); ?></span>
        </button>
        <button class="main-tab-btn" data-tab="setup" onclick="switchMainTab('setup')">
            ⚙️ Setup Config
        </button>
        <button class="main-tab-btn" data-tab="jpgsvault" onclick="switchMainTab('jpgsvault')">
            🖼️ JPGS Vault
        </button>
    </div>
    
    <!-- ===== SUB TABS (for Setup Config) ===== -->
    <div class="sub-tabs" id="subTabs">
        <button class="sub-tab-btn active" data-subtab="accounts" onclick="switchSubTab('accounts')">Accounts URL</button>
        <button class="sub-tab-btn" data-subtab="captions" onclick="switchSubTab('captions')">Captions</button>
        <button class="sub-tab-btn" data-subtab="timeorders" onclick="switchSubTab('timeorders')">Time Orders</button>
        <button class="sub-tab-btn" data-subtab="filters" onclick="switchSubTab('filters')">Filters</button>
        <button class="sub-tab-btn" data-subtab="dynamicfields" onclick="switchSubTab('dynamicfields')">Dynamic Fields</button>
    </div>
    
    <!-- ===== SCROLLABLE BODY ===== -->
    <div class="scroll-body" id="scrollBody">
        
        <!-- ===== PENDING TAB ===== -->
        <div id="tabContentPending" class="tab-content-area">
            <?php renderTabContent('pending', $grouped_configs['pending'] ?? []); ?>
        </div>
        
        <!-- ===== COMPLETED TAB ===== -->
        <div id="tabContentCompleted" class="tab-content-area">
            <?php renderTabContent('completed', $grouped_configs['completed'] ?? []); ?>
        </div>
        
        <!-- ===== ABORTED TAB ===== -->
        <div id="tabContentAborted" class="tab-content-area">
            <?php renderTabContent('aborted', $grouped_configs['aborted'] ?? []); ?>
        </div>
        
        <!-- ===== SETUP CONFIG TAB ===== -->
        <div id="tabContentSetup" class="tab-content-area">
            <div class="setup-container">
                <!-- Accounts URL Sub-tab -->
                <div id="accounts-tab" class="sub-tab-content">
                    <div class="section-header">
                        <h2>Accounts URL</h2>
                        <button class="btn btn-primary" onclick="showAddAccount()">Add New Account</button>
                    </div>
                    
                    <div id="add_account_form" style="display: none; background: #f8fafc; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Account Name</label>
                                <input type="text" id="new_account_name" placeholder="Enter account name...">
                            </div>
                            <div class="form-group">
                                <label>Account URL</label>
                                <input type="url" id="new_account_url" placeholder="Enter account URL...">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button class="btn btn-success" onclick="addAccount()">Add Account</button>
                            <button class="btn btn-secondary" onclick="hideAddAccount()">Cancel</button>
                        </div>
                    </div>
                    
                    <div id="accounts_list"></div>
                </div>
                
                <!-- Captions Sub-tab -->
                <div id="captions-tab" class="sub-tab-content" style="display:none;">
                    <div class="section-header">
                        <h2>Captions</h2>
                        <button class="btn btn-primary" onclick="showAddCaption()">Add New Author Captions</button>
                    </div>
                    
                    <div id="add_caption_form" style="display: none; background: #f8fafc; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label>Select Author</label>
                            <select id="caption_author_select">
                                <option value="">Select Author</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Caption Text</label>
                            <textarea id="new_caption_text" rows="3" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; resize:vertical;" placeholder="Enter caption text..."></textarea>
                        </div>
                        <div class="form-actions">
                            <button class="btn btn-success" onclick="addCaption()">Add Caption</button>
                            <button class="btn btn-secondary" onclick="hideAddCaption()">Cancel</button>
                        </div>
                    </div>
                    
                    <div id="captions_list"></div>
                </div>
                
                <!-- Time Orders Sub-tab -->
                <div id="timeorders-tab" class="sub-tab-content" style="display:none;">
                    <div class="section-header">
                        <h2>Time Orders</h2>
                        <button class="btn btn-primary" onclick="showAddTimeOrder()">Add New Time Order</button>
                    </div>
                    
                    <div id="timeorders_list"></div>
                </div>
                
                <!-- Filters Sub-tab -->
                <div id="filters-tab" class="sub-tab-content" style="display:none;">
                    <div class="section-header">
                        <h2>Filters</h2>
                    </div>
                    
                    <div class="form-group">
                        <label>Add Filter</label>
                        <div class="list-input-container">
                            <input type="text" id="filter_input" placeholder="Enter filter name...">
                            <button class="btn btn-primary" onclick="addFilter()">Add</button>
                        </div>
                        <div id="filters_list" style="margin-top: 10px;"></div>
                    </div>
                </div>
                
                <!-- Dynamic Fields Sub-tab -->
                <div id="dynamicfields-tab" class="sub-tab-content" style="display:none;">
                    <div class="section-header">
                        <h2>Dynamic Fields</h2>
                        <button class="btn btn-primary" onclick="showAddDynamicFieldModal()">Add New Field</button>
                    </div>
                    <p style="color:#64748b;font-size:12px;margin:-10px 0 14px;">Use the ↑ and ↓ arrows to reposition any field. The new order saves automatically and is used the next time the Add/Edit Configuration form opens.</p>
                    
                    <div id="dynamic_fields_list"></div>
                </div>
            </div>
        </div>
        
        <!-- ===== JPGS VAULT TAB ===== -->
        <div id="tabContentJpgsvault" class="tab-content-area">
            <div style="text-align: center; padding: 60px 20px;">
                <div style="font-size: 64px; margin-bottom: 20px;">🖼️</div>
                <h2 style="font-size: 24px; color: #1a1a2e; margin-bottom: 10px;">JPGS Vault</h2>
                <p style="color: #6b7280; font-size: 16px; margin-bottom: 30px;">Manage your image gallery with bulk upload and folder organization</p>
                <button class="btn btn-primary" onclick="window.location.href='jpgsvault.php'" style="padding: 14px 40px; font-size: 16px;">
                    Open JPGS Vault →
                </button>
            </div>
        </div>
        
        <!-- ===== ADD CONFIG VIEW ===== -->
        <div id="addConfigView">
            <div class="detail-header">
                <button class="btn-back" onclick="closeAddConfig()">← Back</button>
                <div class="detail-title" id="settingsTabTitle">New Configuration</div>
            </div>
            
            <!-- Edit Mode Banner -->
            <div class="edit-mode-banner" id="editModeBanner">
                <div class="edit-info">
                    <span>✏️</span>
                    <span>Editing: <strong id="editAuthorNameDisplay"></strong></span>
                </div>
                <button class="btn-cancel-edit" onclick="cancelEditMode()">Cancel Editing</button>
            </div>
            
            <div class="form-container">
                <form method="POST" id="configForm" onsubmit="submitConfig(event)">
                    <input type="hidden" name="save_config" value="true">
                    <input type="hidden" name="config_data" id="configDataInput">
                    <div id="dynamicFieldsContainer"></div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-success">💾 Submit Configuration</button>
                        <button type="button" class="btn btn-secondary" onclick="closeAddConfig()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        
    </div>
    
    <!-- ===== FLOATING ACTION BUTTON ===== -->
    <button class="fab" id="fabButton" onclick="openAddConfig()" title="Add New Configuration">+</button>
    
    <!-- ===== SAVE BUTTONS ===== -->
    <div class="save-btn-container" id="saveBtnContainer">
        <button class="btn btn-save-config" id="saveConfigBtn" onclick="saveConfig()" style="display:none;">💾 Save Configuration</button>
        <button class="btn btn-save-setup" id="saveSetupBtn" onclick="saveSetup()" style="display:none;">💾 Save Setup</button>
    </div>
    
    <!-- ===== MODAL ===== -->
    <div class="modal-overlay" id="customModal">
        <div class="modal-box">
            <div class="modal-title" id="modalTitle">Notification</div>
            <div class="modal-message" id="modalMessage">Message here</div>
            <div class="modal-content"></div>
            <div class="modal-buttons">
                <button class="btn btn-primary" onclick="closeModal()">OK</button>
            </div>
        </div>
    </div>

<?php
    // Convert stored keys such as account_url or key-name into readable labels.
    function formatSerenumFieldLabel($key) {
        $label = preg_replace('/[_-]+/', ' ', (string)$key);
        return ucwords(trim($label));
    }

    // Recover a readable author label from older records where a select field's
    // Submit-As JSON payload was accidentally stored as the author name.
    function resolveSerenumAuthorLabel($config) {
        $candidates = [];
        if (is_array($config)) {
            if (isset($config['author'])) $candidates[] = $config['author'];
            if (isset($config['dynamic_values']) && is_array($config['dynamic_values']) && isset($config['dynamic_values']['author'])) {
                $candidates[] = $config['dynamic_values']['author'];
            }
        }

        foreach ($candidates as $candidate) {
            if (!is_scalar($candidate)) continue;
            $label = trim((string)$candidate);
            if ($label === '') continue;

            $trimmed = $label;
            if ($trimmed[0] === '{' || $trimmed[0] === '[') {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $decodedKeys = array_keys($decoded);
                    $isList = ($decoded === [] || $decodedKeys === range(0, count($decoded) - 1));
                    if ($isList && isset($decoded[0]) && is_array($decoded[0]) && !empty($decoded[0]['key-name'])) {
                        return (string)$decoded[0]['key-name'];
                    }
                    if (!$isList && count($decoded) === 1) {
                        $firstKey = array_key_first($decoded);
                        if (is_scalar($firstKey) && (string)$firstKey !== '') return (string)$firstKey;
                    }
                }
            }
            return $label;
        }

        return 'Unknown Author';
    }

    // Decode JSON text stored inside string fields (for example jpgsurl,
    // author_caption, account_url and time_order_type). Decode recursively so
    // nested JSON is displayed as real, indented JSON instead of one long line.
    function normalizeSerenumValueForDisplay($value) {
        if (is_array($value)) {
            foreach ($value as $key => $childValue) {
                $value[$key] = normalizeSerenumValueForDisplay($childValue);
            }
            return $value;
        }

        if (is_object($value)) {
            foreach ($value as $key => $childValue) {
                $value->{$key} = normalizeSerenumValueForDisplay($childValue);
            }
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = json_decode($trimmed, true, 512, JSON_BIGINT_AS_STRING);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return normalizeSerenumValueForDisplay($decoded);
                }
            }
        }

        return $value;
    }

    // Render one compact field. Values that contain objects/arrays are shown as
    // indented JSON; CSS keeps long content inside a fixed-height scroll area.
    // Copy controls are intentionally limited to the whole entry and status tab.
    function renderSerenumDetailField($key, $value) {
        $label = formatSerenumFieldLabel($key);
        $value = normalizeSerenumValueForDisplay($value);
        $isStructured = is_array($value) || is_object($value);

        if ($isStructured) {
            $displayText = json_encode(
                $value,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($displayText === false) $displayText = '[]';
            $valueClass = 'detail-value json-value';
        } elseif (is_bool($value)) {
            $displayText = $value ? 'true' : 'false';
            $valueClass = 'detail-value';
        } elseif ($value === null) {
            $displayText = 'null';
            $valueClass = 'detail-value';
        } else {
            $displayText = (string)$value;
            $valueClass = 'detail-value';
        }

        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeDisplay = htmlspecialchars($displayText, ENT_QUOTES, 'UTF-8');

        echo '<div class="detail-field">';
        echo '<div class="detail-label">' . $safeLabel . '</div>';
        if ($isStructured) {
            echo '<pre class="' . $valueClass . '">' . $safeDisplay . '</pre>';
        } else {
            echo '<div class="' . $valueClass . '">' . ($safeDisplay === '' ? '<span style="color:#94a3b8;">(empty)</span>' : $safeDisplay) . '</div>';
        }
        echo '</div>';
    }

    // Helper function to render tab content.
    // Each status tab gets a global Copy All control, including when it is empty.
    function renderTabContent($tab, $entries) {
        $statusNames = [
            'pending' => 'Pending',
            'completed' => 'Completed',
            'aborted' => 'Aborted'
        ];
        $statusName = $statusNames[$tab] ?? ucfirst($tab);

        echo '<div class="status-copy-toolbar">';
        echo '<button type="button" class="status-copy-button" onclick="copyAllStatusEntries(\'' . htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') . '\', this)">📋 Copy All ' . htmlspecialchars($statusName, ENT_QUOTES, 'UTF-8') . ' Data</button>';
        echo '</div>';

        if (empty($entries)) {
            $messages = [
                'pending' => 'No pending configurations.',
                'completed' => 'No completed configurations.',
                'aborted' => 'No aborted configurations.'
            ];
            echo '<div class="empty-state">';
            echo '<div class="icon">📋</div>';
            echo '<h3>' . htmlspecialchars($messages[$tab] ?? 'No entries found.', ENT_QUOTES, 'UTF-8') . '</h3>';
            echo '<p>Create a new configuration using the + button.</p>';
            echo '</div>';
            return;
        }

        $statusColors = [
            'pending' => '#f59e0b',
            'completed' => '#10b981',
            'aborted' => '#ef4444'
        ];
        $statusLabels = [
            'pending' => 'Pending',
            'completed' => 'Completed',
            'aborted' => 'Aborted'
        ];

        echo '<div class="config-list">';
        foreach ($entries as $config) {
            $index = isset($config['_index']) ? (int)$config['_index'] : 0;
            $status = isset($config['status']) ? strtolower((string)$config['status']) : 'pending';
            $statusColor = $statusColors[$status] ?? '#6b7280';
            $statusLabel = $statusLabels[$status] ?? ucfirst($status);

            // Use a clean author label even for records created by the old
            // Submit-As select handling that stored a JSON wrapper as the name.
            $author = resolveSerenumAuthorLabel($config);

            // dynamic_values is the canonical source for submitted form values.
            // Legacy flattened root-level values are included only when a field is
            // absent from dynamic_values, never allowed to mask a recent edit.
            $displayValues = [];
            if (isset($config['dynamic_values']) && is_array($config['dynamic_values'])) {
                foreach ($config['dynamic_values'] as $dynamicKey => $dynamicValue) {
                    if (in_array((string)$dynamicKey, ['_object', 'status', 'operation_status'], true)) continue;
                    $displayValues[$dynamicKey] = $dynamicValue;
                }
            }
            foreach ($config as $key => $value) {
                if (in_array((string)$key, ['author', 'status', 'operation_status', '_index', '_object', '_user', '_user_id', 'dynamic_values'], true)) {
                    continue;
                }
                if (!array_key_exists($key, $displayValues)) {
                    $displayValues[$key] = $value;
                }
            }

            echo '<div class="config-item" style="border-left-color:' . $statusColor . ';">';
            echo '<div class="item-header">';
            echo '<span class="item-author">' . htmlspecialchars($author, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '<span class="item-status" style="background:' . $statusColor . ';">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '</div>';

            if (!empty($displayValues)) {
                echo '<div class="item-details">';
                foreach ($displayValues as $key => $value) {
                    renderSerenumDetailField($key, $value);
                }
                echo '</div>';
            } else {
                echo '<div style="margin-top:10px;color:#94a3b8;font-size:13px;">No configuration fields available.</div>';
            }

            // Keep operation_status separate from the ordinary configuration fields.
            if (isset($config['operation_status']) && $config['operation_status'] !== '') {
                $operationStatusText = (string)$config['operation_status'];
                echo '<div class="operation-status-panel">';
                echo '<div class="detail-label">Operation Status</div>';
                echo '<div class="detail-value">' . htmlspecialchars($operationStatusText, ENT_QUOTES, 'UTF-8') . '</div>';
                echo '</div>';
            }

            echo '<div class="item-actions">';
            echo '<button type="button" class="btn btn-secondary btn-sm" onclick="copySingleStatusEntry(' . $index . ', this)">📋 Copy Entry</button>';
            echo '<button type="button" class="btn btn-edit" onclick="showEditConfigByIndex(' . $index . ')">✏️ Edit</button>';
            // Pass the author label printed in this exact card. Do not re-resolve
            // it from a potentially stale parallel JavaScript array.
            $safeAuthorAttribute = htmlspecialchars($author, ENT_QUOTES, 'UTF-8');
            echo '<button type="button" class="btn btn-delete" data-author="' . $safeAuthorAttribute . '" onclick="deleteConfigByIndex(' . $index . ', this.dataset.author)">🗑️ Delete</button>';
            echo '<label class="status-select-label" for="statusSelect_' . $index . '">Status';
            echo '<select id="statusSelect_' . $index . '" class="status-select" data-current-status="' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '" onchange="handleStatusSelectChange(' . $index . ', this)">';
            foreach (['pending' => 'Pending', 'completed' => 'Completed', 'aborted' => 'Aborted'] as $statusOption => $statusOptionLabel) {
                $selected = ($status === $statusOption) ? ' selected' : '';
                echo '<option value="' . $statusOption . '"' . $selected . '>' . $statusOptionLabel . '</option>';
            }
            echo '</select></label>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }
?>

<script>
    // ===== DATA =====
    let configData = {
        settings: <?php echo json_encode($settings_data); ?>,
        accounts: <?php echo json_encode($accounts_data); ?>,
        captions: <?php echo json_encode($captions_data); ?>,
        timeorders: <?php echo json_encode($timeorders_data); ?>,
        filters: <?php echo json_encode($filters_data); ?>,
        dynamic_fields: <?php echo json_encode($dynamic_fields_data); ?>
    };
    
    let configId = <?php echo $config_id ?? 0; ?>;
    let isEditMode = false;
    let editingAuthor = '';
    let editingDynamicFieldKey = null;
    let editingIndex = null;
    
    // Store time format preference per field
    let timeFormatPreferences = {};
    
    // Store list values for modal
    let _newDefaultValues = [];
    let _newListValues = [];
    let _newTimeList = [];

    // ===== STATUS DATA COPY CONTROLS (matching the SceneIQ server pattern) =====
    function setSerenumCopyFeedback(button) {
        if (!button) return;
        if (!button.dataset.originalCopyLabel) {
            button.dataset.originalCopyLabel = button.innerHTML;
        }
        button.innerHTML = '&#10003; Copied';
        button.classList.add('copy-button-copied');
        if (button._serenumCopyTimer) clearTimeout(button._serenumCopyTimer);
        button._serenumCopyTimer = setTimeout(function() {
            button.innerHTML = button.dataset.originalCopyLabel;
            button.classList.remove('copy-button-copied');
        }, 1800);
    }

    function fallbackSerenumCopy(text, button) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '0';
        document.body.appendChild(textarea);
        textarea.select();
        textarea.setSelectionRange(0, textarea.value.length);
        let copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }
        document.body.removeChild(textarea);

        if (copied) {
            setSerenumCopyFeedback(button);
        } else {
            showModal('Copy Error', 'The browser could not copy this data. Please select and copy it manually.');
        }
    }

    function serenumCopyToClipboard(value, button) {
        const textToCopy = value === null || value === undefined ? '' : String(value);
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(textToCopy).then(function() {
                setSerenumCopyFeedback(button);
            }).catch(function() {
                fallbackSerenumCopy(textToCopy, button);
            });
        } else {
            fallbackSerenumCopy(textToCopy, button);
        }
    }

    // Convert JSON text stored in string fields into actual nested JSON values
    // before copying. This keeps bulk and entry exports neatly formatted too.
    function normalizeSerenumCopyValue(value) {
        if (Array.isArray(value)) {
            return value.map(normalizeSerenumCopyValue);
        }
        if (value && typeof value === 'object') {
            const normalized = {};
            Object.keys(value).forEach(function(key) {
                normalized[key] = normalizeSerenumCopyValue(value[key]);
            });
            return normalized;
        }
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (trimmed && (trimmed.charAt(0) === '{' || trimmed.charAt(0) === '[')) {
                try {
                    const parsed = JSON.parse(trimmed);
                    if (parsed && typeof parsed === 'object') {
                        return normalizeSerenumCopyValue(parsed);
                    }
                } catch (error) {
                    // Preserve the original text when it is not valid JSON.
                }
            }
        }
        return value;
    }

    // Copy all records belonging to the selected status tab as one valid,
    // consistently indented JSON array.
    function copyAllStatusEntries(status, button) {
        const allRecords = Array.isArray(configData.settings) ? configData.settings : [];
        const normalizedStatus = String(status || '').toLowerCase();
        const records = allRecords.filter(function(entry) {
            const entryStatus = String((entry && entry.status) || 'pending').toLowerCase();
            return entryStatus === normalizedStatus;
        }).map(normalizeSerenumCopyValue);

        if (!records.length) {
            showModal('No Data', 'There are no ' + normalizedStatus + ' records to copy.');
            return;
        }

        serenumCopyToClipboard(JSON.stringify(records, null, 2), button);
    }

    // Copy one complete configuration record, including status and operation status.
    function copySingleStatusEntry(index, button) {
        const entryIndex = Number(index);
        const records = Array.isArray(configData.settings) ? configData.settings : [];
        if (!Number.isInteger(entryIndex) || entryIndex < 0 || !records[entryIndex] || typeof records[entryIndex] !== 'object') {
            showModal('Copy Error', 'This configuration could not be found. Refresh the page and try again.');
            return;
        }

        const normalizedRecord = normalizeSerenumCopyValue(records[entryIndex]);
        serenumCopyToClipboard(JSON.stringify(normalizedRecord, null, 2), button);
    }

    // ===== MAIN TAB SWITCHING =====
    function switchMainTab(tab) {
        // Navigating away from the editor cancels its pending edit context.
        if (document.getElementById('addConfigView') &&
            document.getElementById('addConfigView').classList.contains('active')) {
            resetSerenumEditorState();
        }
        // Update main tabs
        document.querySelectorAll('.main-tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });
        
        // Update tab contents
        document.querySelectorAll('.tab-content-area').forEach(content => {
            content.classList.remove('active');
        });
        
        const targetContent = document.getElementById('tabContent' + tab.charAt(0).toUpperCase() + tab.slice(1));
        if (targetContent) {
            targetContent.classList.add('active');
        }
        
        // Show/hide sub tabs
        const subTabs = document.getElementById('subTabs');
        const scrollBody = document.getElementById('scrollBody');
        const saveBtnContainer = document.getElementById('saveBtnContainer');
        const saveConfigBtn = document.getElementById('saveConfigBtn');
        const saveSetupBtn = document.getElementById('saveSetupBtn');
        
        if (tab === 'setup') {
            subTabs.classList.add('visible');
            scrollBody.classList.add('setup-visible');
            saveBtnContainer.classList.add('visible');
            saveConfigBtn.style.display = 'none';
            saveSetupBtn.style.display = 'block';
            // Show first sub-tab by default
            switchSubTab('accounts');
        } else {
            subTabs.classList.remove('visible');
            scrollBody.classList.remove('setup-visible');
            saveBtnContainer.classList.remove('visible');
            saveConfigBtn.style.display = 'none';
            saveSetupBtn.style.display = 'none';
        }
        
        // Hide add config view if open
        document.getElementById('addConfigView').classList.remove('active');
        document.getElementById('addConfigView').style.display = 'none';
        
        // Show/hide FAB
        const fab = document.getElementById('fabButton');
        if (tab === 'pending' || tab === 'completed' || tab === 'aborted') {
            fab.classList.add('visible');
        } else {
            fab.classList.remove('visible');
        }
        
        // Load data for setup tab
        if (tab === 'setup') {
            loadAccounts();
            loadCaptions();
            loadTimeOrders();
            loadFilters();
            loadCaptionAuthors();
            loadDynamicFields();
        }
    }
    
    // ===== SUB TAB SWITCHING =====
    function switchSubTab(subtab) {
        // Update sub tabs
        document.querySelectorAll('.sub-tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.subtab === subtab);
        });
        
        // Update sub tab contents
        document.querySelectorAll('.sub-tab-content').forEach(content => {
            content.style.display = 'none';
        });
        
        const targetContent = document.getElementById(subtab + '-tab');
        if (targetContent) {
            targetContent.style.display = 'block';
        }
        
        // Refresh content
        if (subtab === 'accounts') loadAccounts();
        else if (subtab === 'captions') loadCaptions();
        else if (subtab === 'timeorders') loadTimeOrders();
        else if (subtab === 'filters') loadFilters();
        else if (subtab === 'dynamicfields') loadDynamicFields();
    }
    
    // ===== ADD CONFIG =====
    function resetSerenumEditorState() {
        isEditMode = false;
        editingAuthor = '';
        editingIndex = null;
        window._dynamicValuesToLoad = null;
        const banner = document.getElementById('editModeBanner');
        if (banner) banner.classList.remove('visible');
        const title = document.getElementById('settingsTabTitle');
        if (title) title.textContent = 'New Configuration';
    }

    function openAddConfig() {
        // Every Add New action starts as a clean insert, never as an old edit.
        resetSerenumEditorState();
        document.querySelectorAll('.tab-content-area').forEach(c => c.classList.remove('active'));
        document.querySelectorAll('.main-tab-btn').forEach(b => b.classList.remove('active'));
        
        document.getElementById('addConfigView').classList.add('active');
        document.getElementById('addConfigView').style.display = 'block';
        document.getElementById('settingsTabTitle').textContent = 'New Configuration';
        document.getElementById('editModeBanner').classList.remove('visible');
        
        // Hide FAB
        document.getElementById('fabButton').classList.remove('visible');
        
        renderDynamicFields();
    }
    
    function closeAddConfig() {
        resetSerenumEditorState();
        document.getElementById('addConfigView').classList.remove('active');
        document.getElementById('addConfigView').style.display = 'none';
        // Switch to pending tab
        switchMainTab('pending');
    }
    
    // ===== RENDER DYNAMIC FIELDS =====
    function renderDynamicFields() {
        const container = document.getElementById('dynamicFieldsContainer');
        container.innerHTML = '';
        
        const fields = configData.dynamic_fields || {};
        const fieldKeys = Object.keys(fields);
        
        if (fieldKeys.length === 0) {
            container.innerHTML = `
                <div class="no-fields-message">
                    <div class="icon">📋</div>
                    <h3>No Dynamic Fields Configured</h3>
                    <p>No dynamic fields have been set up yet. Go to Setup Config → Dynamic Fields to add some.</p>
                </div>
            `;
            return;
        }
        
        // Edit data is passed from the exact selected record. Never search by
        // author because author names are not unique.
        let dynamicValues = {};
        if (isEditMode && window._dynamicValuesToLoad && typeof window._dynamicValuesToLoad === 'object') {
            dynamicValues = window._dynamicValuesToLoad;
        }
        window._dynamicValuesToLoad = null;
        
        for (const key of fieldKeys) {
            const field = fields[key];
            const elementType = field.element_type || 'input';
            const title = field.title || key;
            const defaultValues = field.default_values || [];
            const sourceType = field.source_type || 'none';
            const allowList = field.allow_list || false;
            const submitAs = field.submit_as || 'key';
            
            const div = document.createElement('div');
            div.className = 'form-group';
            
            const label = document.createElement('label');
            label.textContent = title;
            div.appendChild(label);
            
            let inputElement;
            const value = dynamicValues[key] !== undefined && dynamicValues[key] !== null ? dynamicValues[key] : '';
            
            if (elementType === 'date-time-input') {
                // Create date-time input
                const wrapper = document.createElement('div');
                wrapper.className = 'datetime-group';
                wrapper.style.width = '100%';
                wrapper.style.boxSizing = 'border-box';
                
                const dateInput = document.createElement('input');
                dateInput.type = 'date';
                dateInput.id = 'dynamic_field_' + key + '_date';
                dateInput.className = 'date-input';
                dateInput.style.width = '100%';
                dateInput.style.boxSizing = 'border-box';
                
                if (value && typeof value === 'string') {
                    if (value.includes('/')) {
                        const parts = value.split(' ');
                        if (parts.length > 0 && parts[0].includes('/')) {
                            const dateParts = parts[0].split('/');
                            if (dateParts.length === 3) {
                                const formattedDate = dateParts[2] + '-' + dateParts[1].padStart(2, '0') + '-' + dateParts[0].padStart(2, '0');
                                dateInput.value = formattedDate;
                            }
                        }
                    } else if (value.includes('-')) {
                        dateInput.value = value.split(' ')[0] || '';
                    }
                }
                
                const formatDiv = document.createElement('div');
                formatDiv.className = 'time-format-select';
                formatDiv.style.cssText = 'display:flex; align-items:center; gap:12px; margin-top:8px; padding:8px 12px; background:#f8fafc; border-radius:8px; border:1px solid #e5e7eb; flex-wrap:wrap;';
                
                const formatLabel = document.createElement('label');
                formatLabel.textContent = 'Submit in format:';
                formatLabel.style.marginBottom = '0 !important';
                formatLabel.style.fontWeight = '500 !important';
                formatLabel.style.color = '#1a1a2e';
                formatLabel.style.fontSize = '14px';
                
                const formatSelect = document.createElement('select');
                formatSelect.id = 'dynamic_field_' + key + '_format';
                formatSelect.style.cssText = 'padding:6px 12px; border:2px solid #e5e7eb; border-radius:6px; font-size:14px; background:#fafafa; width:auto; min-width:80px;';
                
                const option12 = document.createElement('option');
                option12.value = '12h';
                option12.textContent = '12h';
                formatSelect.appendChild(option12);
                
                const option24 = document.createElement('option');
                option24.value = '24h';
                option24.textContent = '24h';
                formatSelect.appendChild(option24);
                
                const prefKey = key;
                if (timeFormatPreferences[prefKey] === undefined) {
                    timeFormatPreferences[prefKey] = '12h';
                }
                formatSelect.value = timeFormatPreferences[prefKey];
                
                formatDiv.appendChild(formatLabel);
                formatDiv.appendChild(formatSelect);
                
                const timeGroup = document.createElement('div');
                timeGroup.id = 'dynamic_field_' + key + '_time_group';
                timeGroup.className = 'time-group';
                timeGroup.style.cssText = 'display:flex; gap:10px; align-items:center; flex-wrap:wrap; width:100%; margin-top:10px;';
                
                const twelveHourDiv = document.createElement('div');
                twelveHourDiv.id = 'dynamic_field_' + key + '_12h_group';
                twelveHourDiv.style.cssText = 'display:flex; gap:10px; align-items:center; flex-wrap:wrap;';
                
                const hour12 = document.createElement('input');
                hour12.type = 'number';
                hour12.id = 'dynamic_field_' + key + '_hour_12';
                hour12.placeholder = 'HH';
                hour12.min = '1';
                hour12.max = '12';
                hour12.style.cssText = 'width:60px; padding:10px; border:2px solid #e5e7eb; border-radius:10px; font-size:14px; background:#fafafa;';
                
                const colon1 = document.createElement('span');
                colon1.textContent = ':';
                
                const minute12 = document.createElement('input');
                minute12.type = 'number';
                minute12.id = 'dynamic_field_' + key + '_minute_12';
                minute12.placeholder = 'MM';
                minute12.min = '0';
                minute12.max = '59';
                minute12.style.cssText = 'width:60px; padding:10px; border:2px solid #e5e7eb; border-radius:10px; font-size:14px; background:#fafafa;';
                
                const ampmSelect = document.createElement('select');
                ampmSelect.id = 'dynamic_field_' + key + '_ampm';
                ampmSelect.style.cssText = 'width:80px; padding:10px; border:2px solid #e5e7eb; border-radius:10px; font-size:14px; background:#fafafa;';
                
                const amOption = document.createElement('option');
                amOption.value = 'AM';
                amOption.textContent = 'AM';
                ampmSelect.appendChild(amOption);
                
                const pmOption = document.createElement('option');
                pmOption.value = 'PM';
                pmOption.textContent = 'PM';
                ampmSelect.appendChild(pmOption);
                
                twelveHourDiv.appendChild(hour12);
                twelveHourDiv.appendChild(colon1);
                twelveHourDiv.appendChild(minute12);
                twelveHourDiv.appendChild(ampmSelect);
                
                const twentyFourDiv = document.createElement('div');
                twentyFourDiv.id = 'dynamic_field_' + key + '_24h_group';
                twentyFourDiv.style.cssText = 'display:none; gap:10px; align-items:center; flex-wrap:wrap;';
                
                const hour24 = document.createElement('input');
                hour24.type = 'number';
                hour24.id = 'dynamic_field_' + key + '_hour_24';
                hour24.placeholder = 'HH';
                hour24.min = '0';
                hour24.max = '23';
                hour24.style.cssText = 'width:60px; padding:10px; border:2px solid #e5e7eb; border-radius:10px; font-size:14px; background:#fafafa;';
                
                const colon2 = document.createElement('span');
                colon2.textContent = ':';
                
                const minute24 = document.createElement('input');
                minute24.type = 'number';
                minute24.id = 'dynamic_field_' + key + '_minute_24';
                minute24.placeholder = 'MM';
                minute24.min = '0';
                minute24.max = '59';
                minute24.style.cssText = 'width:60px; padding:10px; border:2px solid #e5e7eb; border-radius:10px; font-size:14px; background:#fafafa;';
                
                twentyFourDiv.appendChild(hour24);
                twentyFourDiv.appendChild(colon2);
                twentyFourDiv.appendChild(minute24);
                
                timeGroup.appendChild(twelveHourDiv);
                timeGroup.appendChild(twentyFourDiv);
                
                wrapper.appendChild(dateInput);
                wrapper.appendChild(formatDiv);
                wrapper.appendChild(timeGroup);
                
                formatSelect.addEventListener('change', function() {
                    const format = this.value;
                    timeFormatPreferences[prefKey] = format;
                    
                    if (format === '12h') {
                        twelveHourDiv.style.display = 'flex';
                        twentyFourDiv.style.display = 'none';
                    } else {
                        twelveHourDiv.style.display = 'none';
                        twentyFourDiv.style.display = 'flex';
                    }
                });
                
                if (formatSelect.value === '12h') {
                    twelveHourDiv.style.display = 'flex';
                    twentyFourDiv.style.display = 'none';
                } else {
                    twelveHourDiv.style.display = 'none';
                    twentyFourDiv.style.display = 'flex';
                }
                
                if (value && typeof value === 'string') {
                    const timeMatch = value.match(/(\d{1,2}):(\d{2})\s*(AM|PM)/);
                    if (timeMatch) {
                        hour12.value = parseInt(timeMatch[1]);
                        minute12.value = parseInt(timeMatch[2]);
                        ampmSelect.value = timeMatch[3];
                        formatSelect.value = '12h';
                        twelveHourDiv.style.display = 'flex';
                        twentyFourDiv.style.display = 'none';
                    } else {
                        const timeMatch24 = value.match(/(\d{1,2}):(\d{2})/);
                        if (timeMatch24) {
                            hour24.value = parseInt(timeMatch24[1]);
                            minute24.value = parseInt(timeMatch24[2]);
                            formatSelect.value = '24h';
                            twelveHourDiv.style.display = 'none';
                            twentyFourDiv.style.display = 'flex';
                        }
                    }
                }
                
                inputElement = wrapper;
                
            } else if (elementType === 'textarea') {
                inputElement = document.createElement('textarea');
                inputElement.id = 'dynamic_field_' + key;
                inputElement.className = 'dynamic-field-input';
                inputElement.rows = 3;
                inputElement.placeholder = 'Enter ' + title + '...';
                if (typeof value === 'string') {
                    inputElement.value = value;
                }
                inputElement.style.width = '100%';
                inputElement.style.padding = '12px 16px';
                inputElement.style.border = '2px solid #e5e7eb';
                inputElement.style.borderRadius = '10px';
                inputElement.style.fontSize = '14px';
                inputElement.style.transition = 'all 0.3s';
                inputElement.style.boxSizing = 'border-box';
                inputElement.style.background = '#fafafa';
                inputElement.style.fontFamily = 'inherit';
                inputElement.style.resize = 'vertical';
            } else if (elementType === 'select') {
                const wrapper = document.createElement('div');
                wrapper.style.width = '100%';
                
                inputElement = document.createElement('select');
                inputElement.id = 'dynamic_field_' + key;
                inputElement.className = 'dynamic-field-input';
                inputElement.style.width = '100%';
                inputElement.style.padding = '12px 16px';
                inputElement.style.border = '2px solid #e5e7eb';
                inputElement.style.borderRadius = '10px';
                inputElement.style.fontSize = '14px';
                inputElement.style.transition = 'all 0.3s';
                inputElement.style.boxSizing = 'border-box';
                inputElement.style.background = '#fafafa';
                
                const emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = 'Select ' + title + '...';
                inputElement.appendChild(emptyOption);
                
                let sourceValues = [];
                
                if (sourceType !== 'none') {
                    sourceValues = getSourceValues(sourceType, submitAs);
                } else {
                    defaultValues.forEach(val => {
                        let submitData = '';
                        if (submitAs === 'key') {
                            submitData = val;
                        } else if (submitAs === 'value') {
                            submitData = val;
                        } else if (submitAs === 'both') {
                            submitData = JSON.stringify({ [val]: val });
                        }
                        
                        sourceValues.push({ 
                            key: val, 
                            value: val,
                            display: val,
                            submitData: submitData,
                            submitAs: submitAs
                        });
                    });
                }
                
                sourceValues.forEach(({ key: optionKey, value: actualValue, display, submitData }) => {
                    const option = document.createElement('option');
                    option.value = submitData;
                    option.textContent = display;
                    option.dataset.key = optionKey;
                    option.dataset.value = actualValue;
                    option.dataset.submitAs = submitAs;
                    
                    if (value === actualValue || value === submitData) {
                        option.selected = true;
                    }
                    inputElement.appendChild(option);
                });
                
                wrapper.appendChild(inputElement);
                
                const previewDiv = document.createElement('div');
                previewDiv.className = 'submit-as-preview';
                previewDiv.style.cssText = 'margin-top:5px; padding:6px 12px; background:#f8fafc; border-radius:6px; border:1px dashed #c8e0d8; font-size:12px; color:#6b7280; font-family:monospace; word-break:break-all; max-height:100px; overflow-y:auto;';
                previewDiv.textContent = 'Selected: ' + (inputElement.value || 'None');
                wrapper.appendChild(previewDiv);
                
                inputElement.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption && selectedOption.value) {
                        previewDiv.textContent = 'Selected: ' + selectedOption.value;
                    } else {
                        previewDiv.textContent = 'Selected: None';
                    }
                });
                
                inputElement = wrapper;
            } else {
                // input - could be list input or single input
                if (allowList) {
                    const wrapper = document.createElement('div');
                    wrapper.style.width = '100%';
                    
                    const listContainer = document.createElement('div');
                    listContainer.id = 'dynamic_field_' + key + '_list';
                    listContainer.className = 'list-values';
                    
                    let listValues = [];
                    if (Array.isArray(value)) {
                        listValues = value;
                    } else if (typeof value === 'string' && value) {
                        listValues = [value];
                    } else if (value && typeof value === 'object') {
                        listValues = Object.values(value);
                    }
                    
                    const inputGroup = document.createElement('div');
                    inputGroup.className = 'list-input-container';
                    inputGroup.style.display = 'flex';
                    inputGroup.style.gap = '10px';
                    inputGroup.style.marginTop = '5px';
                    inputGroup.style.flexWrap = 'wrap';
                    
                    const listInput = document.createElement('input');
                    listInput.type = 'text';
                    listInput.id = 'dynamic_field_' + key + '_input';
                    listInput.placeholder = 'Add value...';
                    listInput.style.flex = '1';
                    listInput.style.minWidth = '150px';
                    listInput.style.padding = '10px 14px';
                    listInput.style.border = '2px solid #e5e7eb';
                    listInput.style.borderRadius = '10px';
                    listInput.style.fontSize = '14px';
                    listInput.style.background = '#fafafa';
                    
                    const addBtn = document.createElement('button');
                    addBtn.type = 'button';
                    addBtn.className = 'btn btn-primary';
                    addBtn.textContent = 'Add';
                    addBtn.style.padding = '10px 20px';
                    addBtn.style.margin = '0';
                    addBtn.style.whiteSpace = 'nowrap';
                    
                    inputGroup.appendChild(listInput);
                    inputGroup.appendChild(addBtn);
                    
                    wrapper.appendChild(listContainer);
                    wrapper.appendChild(inputGroup);
                    
                    wrapper._fieldKey = key;
                    wrapper._listValues = listValues;
                    
                    function renderListValues() {
                        listContainer.innerHTML = '';
                        if (listValues.length === 0) {
                            listContainer.innerHTML = '<span style="color: #999; font-size: 13px;">No values added</span>';
                            return;
                        }
                        listValues.forEach((val, idx) => {
                            const tag = document.createElement('span');
                            tag.className = 'value-tag';
                            tag.innerHTML = val + ' <span class="remove-value" data-index="' + idx + '">×</span>';
                            tag.querySelector('.remove-value').addEventListener('click', function() {
                                listValues.splice(parseInt(this.dataset.index), 1);
                                renderListValues();
                            });
                            listContainer.appendChild(tag);
                        });
                    }
                    
                    addBtn.addEventListener('click', function() {
                        const val = listInput.value.trim();
                        if (val && !listValues.includes(val)) {
                            listValues.push(val);
                            renderListValues();
                            listInput.value = '';
                        }
                    });
                    
                    listInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            addBtn.click();
                        }
                    });
                    
                    renderListValues();
                    inputElement = wrapper;
                    
                } else {
                    inputElement = document.createElement('input');
                    inputElement.type = 'text';
                    inputElement.id = 'dynamic_field_' + key;
                    inputElement.className = 'dynamic-field-input';
                    inputElement.placeholder = 'Enter ' + title + '...';
                    if (typeof value === 'string') {
                        inputElement.value = value;
                    }
                    inputElement.style.width = '100%';
                    inputElement.style.padding = '12px 16px';
                    inputElement.style.border = '2px solid #e5e7eb';
                    inputElement.style.borderRadius = '10px';
                    inputElement.style.fontSize = '14px';
                    inputElement.style.transition = 'all 0.3s';
                    inputElement.style.boxSizing = 'border-box';
                    inputElement.style.background = '#fafafa';
                }
            }
            
            div.appendChild(inputElement);
            container.appendChild(div);
        }
    }
    
    // ===== GET SOURCE VALUES =====
    function getSourceValues(sourceType, submitAs) {
        const values = [];
        
        if (sourceType === 'accounts') {
            const accounts = configData.accounts || {};
            for (const [key, value] of Object.entries(accounts)) {
                const url = value.schedule && value.schedule[0] ? value.schedule[0] : '';
                if (url) {
                    let submitData = '';
                    let displayText = '';
                    
                    if (submitAs === 'key') {
                        submitData = key;
                        displayText = key;
                    } else if (submitAs === 'value') {
                        submitData = url;
                        displayText = url;
                    } else if (submitAs === 'both') {
                        submitData = JSON.stringify({ [key]: url });
                        displayText = key + ' → ' + url;
                    }
                    
                    values.push({ 
                        key: key, 
                        value: url,
                        display: displayText,
                        submitData: submitData,
                        submitAs: submitAs,
                        isGrouped: false
                    });
                }
            }
        } else if (sourceType === 'captions') {
            const captions = configData.captions || [];
            
            const authorCaptionMap = {};
            
            captions.forEach(item => {
                if (item.name && item.captions && item.captions.length > 0) {
                    const captionObjects = item.captions.map(caption => ({
                        'key-name': item.name,
                        'id': caption.id,
                        'description': caption.description
                    }));
                    
                    if (authorCaptionMap[item.name]) {
                        authorCaptionMap[item.name] = authorCaptionMap[item.name].concat(captionObjects);
                    } else {
                        authorCaptionMap[item.name] = captionObjects;
                    }
                } else if (item.name && (!item.captions || item.captions.length === 0)) {
                    if (!authorCaptionMap[item.name]) {
                        authorCaptionMap[item.name] = [];
                    }
                }
            });
            
            for (const [authorName, captionsList] of Object.entries(authorCaptionMap)) {
                let submitData = '';
                let displayText = '';
                
                if (submitAs === 'key') {
                    submitData = authorName;
                    displayText = authorName;
                } else if (submitAs === 'value') {
                    submitData = JSON.stringify(captionsList);
                    displayText = JSON.stringify(captionsList);
                } else if (submitAs === 'both') {
                    submitData = JSON.stringify({ [authorName]: captionsList });
                    displayText = authorName + ' (' + captionsList.length + ' captions)';
                }
                
                values.push({ 
                    key: authorName,
                    value: captionsList,
                    display: displayText,
                    submitData: submitData,
                    submitAs: submitAs,
                    isGrouped: true,
                    captionCount: captionsList.length
                });
            }
            
        } else if (sourceType === 'timeorders') {
            const timeOrders = configData.timeorders || {};
            
            for (const [orderName, timesList] of Object.entries(timeOrders)) {
                let submitData = '';
                let displayText = '';
                
                if (submitAs === 'key') {
                    submitData = orderName;
                    displayText = orderName;
                } else if (submitAs === 'value') {
                    submitData = JSON.stringify(timesList);
                    displayText = JSON.stringify(timesList);
                } else if (submitAs === 'both') {
                    submitData = JSON.stringify({ [orderName]: timesList });
                    displayText = orderName + ' (' + (Array.isArray(timesList) ? timesList.length : 0) + ' times)';
                }
                
                values.push({ 
                    key: orderName,
                    value: timesList,
                    display: displayText,
                    submitData: submitData,
                    submitAs: submitAs,
                    isGrouped: true,
                    timeCount: timesList.length
                });
            }
            
        } else if (sourceType === 'filters') {
            const filters = configData.filters || [];
            filters.forEach(filter => {
                let submitData = '';
                let displayText = '';
                
                if (submitAs === 'key') {
                    submitData = filter;
                    displayText = filter;
                } else if (submitAs === 'value') {
                    submitData = filter;
                    displayText = filter;
                } else if (submitAs === 'both') {
                    submitData = JSON.stringify({ [filter]: filter });
                    displayText = filter;
                }
                
                values.push({ 
                    key: filter, 
                    value: filter,
                    display: displayText,
                    submitData: submitData,
                    submitAs: submitAs,
                    isGrouped: false
                });
            });
        }
        return values;
    }
    
    // ===== SUBMIT CONFIG =====
    function submitConfig(event) {
        event.preventDefault();

        const fields = configData.dynamic_fields || {};
        const configDataObj = {};
        let authorValue = '';
        let engineValue = '';

        const normalizeFieldName = value => String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        for (const key of Object.keys(fields)) {
            const field = fields[key] || {};
            const elementType = field.element_type || 'input';
            const allowList = !!field.allow_list;
            let value = '';

            if (elementType === 'date-time-input') {
                const dateInput = document.getElementById('dynamic_field_' + key + '_date');
                const formatSelect = document.getElementById('dynamic_field_' + key + '_format');
                if (dateInput && formatSelect) {
                    const date = dateInput.value;
                    const format = formatSelect.value;
                    let timeStr = '';
                    if (format === '12h') {
                        const hour = document.getElementById('dynamic_field_' + key + '_hour_12');
                        const minute = document.getElementById('dynamic_field_' + key + '_minute_12');
                        const ampm = document.getElementById('dynamic_field_' + key + '_ampm');
                        if (hour && minute && ampm && hour.value !== '' && minute.value !== '') {
                            timeStr = hour.value + ':' + String(minute.value).padStart(2, '0') + ' ' + ampm.value;
                        }
                    } else {
                        const hour = document.getElementById('dynamic_field_' + key + '_hour_24');
                        const minute = document.getElementById('dynamic_field_' + key + '_minute_24');
                        if (hour && minute && hour.value !== '' && minute.value !== '') {
                            timeStr = String(hour.value).padStart(2, '0') + ':' + String(minute.value).padStart(2, '0');
                        }
                    }
                    if (date) {
                        const parts = date.split('-');
                        value = (parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : date) + (timeStr ? ' ' + timeStr : '');
                    } else if (timeStr) value = timeStr;
                }
            } else if (elementType === 'input' && allowList) {
                const listContainer = document.getElementById('dynamic_field_' + key + '_list');
                if (listContainer) {
                    const values = [];
                    listContainer.querySelectorAll('.value-tag').forEach(tag => {
                        const text = tag.textContent.replace('×', '').trim();
                        if (text) values.push(text);
                    });
                    value = values.join(', ');
                }
            } else {
                const input = document.getElementById('dynamic_field_' + key);
                if (input) value = input.value;
            }
            configDataObj[key] = value;

            const candidates = [key, field.title].map(normalizeFieldName);
            if (!authorValue && candidates.some(name => name === 'author' || name === 'authorname' || name === 'name')) {
                const authorInput = document.getElementById('dynamic_field_' + key);
                if (authorInput && authorInput.tagName === 'SELECT') {
                    const selectedAuthorOption = authorInput.options[authorInput.selectedIndex];
                    const selectedAuthorKey = selectedAuthorOption && selectedAuthorOption.dataset
                        ? selectedAuthorOption.dataset.key
                        : '';
                    authorValue = String(selectedAuthorKey || value || '').trim();
                } else if (value !== null && value !== undefined) {
                    authorValue = String(value).trim();
                }
            }
            // For select fields, .value may be a transformed submit_as payload
            // (for example JSON when submit_as is 'both'). The engine stored at
            // the root must be the actual selected option value, not that wrapper.
            if (!engineValue && candidates.some(name => name === 'engine' || name === 'enginetype' || name === 'processingengine' || name.includes('engine'))) {
                const engineInput = document.getElementById('dynamic_field_' + key);
                if (engineInput && engineInput.tagName === 'SELECT') {
                    const selectedOption = engineInput.options[engineInput.selectedIndex];
                    const actualSelectedValue = selectedOption && selectedOption.dataset
                        ? (selectedOption.dataset.value || selectedOption.dataset.key || selectedOption.value)
                        : engineInput.value;
                    engineValue = String(actualSelectedValue || '').trim();
                } else if (value !== null && value !== undefined) {
                    engineValue = String(value).trim();
                }
            }
        }

        // Support legacy setups where the author field is named differently but
        // the configured author value is present in the submitted values.
        if (!authorValue && configDataObj.author !== undefined) authorValue = String(configDataObj.author).trim();
        if (!authorValue) {
            showModal('Error', 'Please enter a value in the Author/Name field.');
            return;
        }

        const hasData = Object.values(configDataObj).some(v => v !== '' && v !== null && v !== undefined);
        if (!hasData) {
            showModal('Error', 'Please enter at least one field value.');
            return;
        }

        const engineFieldKey = Object.keys(fields).find(key => {
            const field = fields[key] || {};
            const names = [key, field.title].map(normalizeFieldName);
            return names.some(name => name === 'engine' || name === 'enginetype' || name === 'processingengine' || name.includes('engine'));
        });
        if (engineFieldKey && !engineValue) {
            showModal('Error', 'Please select a value for the Engine field.');
            return;
        }

        const payload = {
            author: authorValue,
            engine: engineValue,
            edit_index: isEditMode ? editingIndex : null,
            dynamic_values: configDataObj
        };
        document.getElementById('configDataInput').value = JSON.stringify(payload);
        document.getElementById('configForm').submit();
    }

    // ===== CUSTOM DIALOG =====
    function showModal(title, message, callback, extraContent) {
        document.getElementById('modalTitle').textContent = title;
        document.getElementById('modalMessage').textContent = message;
        
        const contentDiv = document.querySelector('.modal-content');
        if (extraContent) {
            contentDiv.innerHTML = extraContent;
        } else {
            contentDiv.innerHTML = '';
        }
        
        document.getElementById('customModal').classList.add('active');
        
        if (callback) {
            window._modalCallback = callback;
            const modalButtons = document.querySelector('.modal-buttons');
            modalButtons.innerHTML = `
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-danger" onclick="confirmModalAction()">Confirm</button>
            `;
        } else {
            window._modalCallback = null;
            const modalButtons = document.querySelector('.modal-buttons');
            modalButtons.innerHTML = `
                <button class="btn btn-primary" onclick="closeModal()">OK</button>
            `;
        }
    }
    
    function confirmModalAction() {
        if (window._modalCallback) {
            window._modalCallback();
            window._modalCallback = null;
        }
        closeModal();
    }
    
    function closeModal() {
        document.getElementById('customModal').classList.remove('active');
        window._modalCallback = null;
        const contentDiv = document.querySelector('.modal-content');
        if (contentDiv) contentDiv.innerHTML = '';
        document.querySelector('.modal-buttons').innerHTML = `
            <button class="btn btn-primary" onclick="closeModal()">OK</button>
        `;
    }
    
    document.getElementById('customModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    // Resolve author labels consistently with the PHP pending-card renderer.
    function getSerenumAuthorLabel(config) {
        if (!config || typeof config !== 'object') return 'Unknown Author';
        const candidates = [config.author, config.dynamic_values && config.dynamic_values.author];
        for (const candidate of candidates) {
            if (candidate === undefined || candidate === null || candidate === '') continue;
            const label = String(candidate).trim();
            if (!label) continue;
            if (label[0] === '{' || label[0] === '[') {
                try {
                    const parsed = JSON.parse(label);
                    if (Array.isArray(parsed) && parsed[0] && parsed[0]['key-name']) {
                        return String(parsed[0]['key-name']);
                    }
                    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                        const keys = Object.keys(parsed);
                        if (keys.length === 1) return keys[0];
                    }
                } catch (error) {
                    // Keep the original label when the stored value is not valid JSON.
                }
            }
            return label;
        }
        return 'Unknown Author';
    }

    // ===== CONFIG OPERATIONS =====
    function deleteConfigByIndex(index, displayedAuthor) {
        const recordIndex = Number(index);
        const authorName = String(displayedAuthor || '').trim();
        if (!Number.isInteger(recordIndex) || recordIndex < 0 || !authorName) {
            showModal('Error', 'Invalid configuration identity. Refresh the page and try again.');
            return;
        }

        // Use the exact name printed on this server-rendered card. The browser's
        // configData array is not used to decide which name the user is deleting.
        showModal('Confirm Delete', 'Are you sure you want to delete configuration "' + authorName + '"?', function() {
            const formData = new FormData();
            formData.append('delete_config', 'true');
            formData.append('id', String(configId));
            formData.append('index', String(recordIndex));
            formData.append('expected_author', authorName);
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showModal('Success', 'Configuration deleted successfully! Refreshing...');
                        setTimeout(() => window.location.reload(), 800);
                    } else {
                        showModal('Error', data.message || 'Unable to delete configuration.');
                    }
                })
                .catch(error => {
                    console.error('Delete configuration failed:', error);
                    showModal('Error', 'Error deleting configuration: ' + error.message);
                });
        });
    }

    function showEditConfigByIndex(index) {
        if (index === undefined || index === null || index === -1) {
            showModal('Error', 'Invalid configuration index.');
            return;
        }
        
        const settings = configData.settings;
        if (!Array.isArray(settings) || index < 0 || index >= settings.length) {
            showModal('Error', 'Configuration not found.');
            return;
        }
        
        const config = settings[index];
        const author = getSerenumAuthorLabel(config);
        
        isEditMode = true;
        editingAuthor = author;
        editingIndex = index;

        const editValues = {};
        if (config && config.dynamic_values && typeof config.dynamic_values === 'object' && !Array.isArray(config.dynamic_values)) {
            Object.assign(editValues, config.dynamic_values);
        }
        Object.keys(configData.dynamic_fields || {}).forEach(function(fieldKey) {
            if (!Object.prototype.hasOwnProperty.call(editValues, fieldKey) &&
                config && Object.prototype.hasOwnProperty.call(config, fieldKey)) {
                editValues[fieldKey] = config[fieldKey];
            }
        });
        loadDynamicValues(editValues);
        
        document.querySelectorAll('.tab-content-area').forEach(c => c.classList.remove('active'));
        document.querySelectorAll('.main-tab-btn').forEach(b => b.classList.remove('active'));
        
        document.getElementById('addConfigView').classList.add('active');
        document.getElementById('addConfigView').style.display = 'block';
        document.getElementById('settingsTabTitle').textContent = 'Edit Configuration - ' + author;
        document.getElementById('editModeBanner').classList.add('visible');
        document.getElementById('editAuthorNameDisplay').textContent = author;
        
        document.getElementById('fabButton').classList.remove('visible');
        
        renderDynamicFields();
    }
    
    function loadDynamicValues(dynamicValues) {
        window._dynamicValuesToLoad = (dynamicValues && typeof dynamicValues === 'object' && !Array.isArray(dynamicValues))
            ? Object.assign({}, dynamicValues)
            : {};
    }
    
    function cancelEditMode() {
        closeAddConfig();
    }
    
    function handleStatusSelectChange(index, selectElement) {
        if (!selectElement) return;
        const previousStatus = String(selectElement.dataset.currentStatus || 'pending').toLowerCase();
        const selectedStatus = String(selectElement.value || '').toLowerCase();
        const allowedStatuses = ['pending', 'completed', 'aborted'];

        if (!allowedStatuses.includes(selectedStatus)) {
            selectElement.value = previousStatus;
            return;
        }
        if (selectedStatus === previousStatus) return;

        changeStatusByIndex(index, selectedStatus, selectElement, previousStatus);
    }

    function changeStatusByIndex(index, status, statusSelect, previousStatus) {
        const allowedStatuses = ['pending', 'completed', 'aborted'];
        status = String(status || '').toLowerCase();

        if (index === undefined || index === null || index === -1) {
            if (statusSelect && previousStatus) statusSelect.value = previousStatus;
            showModal('Error', 'Invalid configuration index.');
            return;
        }

        if (!allowedStatuses.includes(status)) {
            if (statusSelect && previousStatus) statusSelect.value = previousStatus;
            showModal('Error', 'Invalid status selected.');
            return;
        }

        if (!configId) {
            if (statusSelect && previousStatus) statusSelect.value = previousStatus;
            showModal('Error', 'No configuration found.');
            return;
        }

        const settings = configData.settings;
        if (!Array.isArray(settings) || index < 0 || index >= settings.length) {
            if (statusSelect && previousStatus) statusSelect.value = previousStatus;
            showModal('Error', 'Configuration not found at index ' + index);
            return;
        }

        const config = settings[index];
        if (!config || typeof config !== 'object') {
            if (statusSelect && previousStatus) statusSelect.value = previousStatus;
            showModal('Error', 'Invalid configuration.');
            return;
        }

        const originalStatus = String(previousStatus || config.status || 'pending').toLowerCase();
        const hadOperationStatus = Object.prototype.hasOwnProperty.call(config, 'operation_status');
        const originalOperationStatus = config.operation_status;
        const hasDynamicValues = config.dynamic_values && typeof config.dynamic_values === 'object';
        const hadDynamicStatus = hasDynamicValues && Object.prototype.hasOwnProperty.call(config.dynamic_values, 'status');
        const originalDynamicStatus = hasDynamicValues ? config.dynamic_values.status : undefined;
        const hadDynamicOperationStatus = hasDynamicValues && Object.prototype.hasOwnProperty.call(config.dynamic_values, 'operation_status');
        const originalDynamicOperationStatus = hasDynamicValues ? config.dynamic_values.operation_status : undefined;

        if (status === originalStatus) {
            if (statusSelect) statusSelect.value = originalStatus;
            return;
        }

        function rollbackStatusChange() {
            config.status = originalStatus;
            if (hadOperationStatus) {
                config.operation_status = originalOperationStatus;
            } else {
                delete config.operation_status;
            }
            if (hasDynamicValues) {
                if (hadDynamicStatus) config.dynamic_values.status = originalDynamicStatus;
                else delete config.dynamic_values.status;
                if (hadDynamicOperationStatus) config.dynamic_values.operation_status = originalDynamicOperationStatus;
                else delete config.dynamic_values.operation_status;
            }
            if (statusSelect) {
                statusSelect.value = originalStatus;
                statusSelect.dataset.currentStatus = originalStatus;
                statusSelect.disabled = false;
            }
        }

        config.status = status;
        config.operation_status = "change_status: Status updated to '" + status + "'";

        if (hasDynamicValues) {
            delete config.dynamic_values.status;
            delete config.dynamic_values.operation_status;
        }

        const formData = new FormData();
        formData.append('update_status', 'true');
        formData.append('id', String(configId));
        formData.append('index', String(index));
        formData.append('status', status);

        if (statusSelect) statusSelect.disabled = true;
        showModal('Processing', 'Updating status to "' + status + '"...');

        fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                closeModal();
                if (data.success) {
                    if (statusSelect) statusSelect.dataset.currentStatus = status;
                    showModal('Success', 'Status updated to "' + status + '"! Refreshing...');
                    setTimeout(() => window.location.reload(), 700);
                } else {
                    rollbackStatusChange();
                    showModal('Error', data.message || 'Failed to update status.');
                }
            })
            .catch(error => {
                rollbackStatusChange();
                closeModal();
                showModal('Error', 'Error: ' + error.message);
            });
    }
    
    // ===== ACCOUNT FUNCTIONS =====
    function showAddAccount() {
        document.getElementById('add_account_form').style.display = 'block';
    }
    
    function hideAddAccount() {
        document.getElementById('add_account_form').style.display = 'none';
        document.getElementById('new_account_name').value = '';
        document.getElementById('new_account_url').value = '';
    }
    
    function addAccount() {
        const name = document.getElementById('new_account_name').value.trim();
        const url = document.getElementById('new_account_url').value.trim();
        
        if (!name || !url) {
            showModal('Error', 'Please enter both account name and URL.');
            return;
        }
        
        if (!configData.accounts || typeof configData.accounts !== 'object' || Array.isArray(configData.accounts)) {
            configData.accounts = {};
        }
        
        if (configData.accounts[name]) {
            showModal('Error', 'Account already exists!');
            return;
        }
        
        configData.accounts[name] = { schedule: [url] };
        loadAccounts();
        loadCaptionAuthors();
        hideAddAccount();
        showModal('Success', 'Account added successfully!');
    }
    
    function deleteAccount(name) {
        showModal('Confirm Delete', 'Are you sure you want to delete account "' + name + '"?', function() {
            if (configData.accounts) {
                delete configData.accounts[name];
                loadAccounts();
                loadCaptionAuthors();
            }
        });
    }
    
    function loadAccounts() {
        const container = document.getElementById('accounts_list');
        container.innerHTML = '';
        
        const accounts = configData.accounts || {};
        
        if (Object.keys(accounts).length === 0) {
            container.innerHTML = '<p style="color: #999; text-align: center; padding: 20px;">No accounts added yet.</p>';
            return;
        }
        
        for (const [key, value] of Object.entries(accounts)) {
            const url = value.schedule && value.schedule[0] ? value.schedule[0] : '';
            const div = document.createElement('div');
            div.className = 'item-row';
            div.innerHTML = `
                <div class="item-info">
                    <span class="key">${key}</span>
                    <span class="value">${url}</span>
                </div>
                <button class="btn btn-danger btn-sm" onclick="deleteAccount('${key}')">Delete</button>
            `;
            container.appendChild(div);
        }
    }
    
    // ===== CAPTION FUNCTIONS =====
    function showAddCaption() {
        document.getElementById('add_caption_form').style.display = 'block';
        loadCaptionAuthors();
    }
    
    function hideAddCaption() {
        document.getElementById('add_caption_form').style.display = 'none';
        document.getElementById('new_caption_text').value = '';
        document.getElementById('caption_author_select').value = '';
    }
    
    function parseCaptionInput(input) {
        if (!input || typeof input !== 'string') {
            return [];
        }
        
        const trimmed = input.trim();
        
        try {
            const parsed = JSON.parse(trimmed);
            
            if (Array.isArray(parsed) && parsed.length > 0) {
                if (parsed[0].description !== undefined || parsed[0].caption !== undefined) {
                    const descriptionField = parsed[0].description !== undefined ? 'description' : 'caption';
                    return parsed
                        .filter(item => item[descriptionField] && item[descriptionField].trim())
                        .map((item, index) => ({
                            id: index + 1,
                            description: sanitizeCaption(item[descriptionField].trim())
                        }))
                        .filter(item => item.description);
                }
                
                if (parsed.length > 0 && typeof parsed[0] === 'string') {
                    return parsed
                        .filter(item => typeof item === 'string' && item.trim())
                        .map((item, index) => ({
                            id: index + 1,
                            description: sanitizeCaption(item.trim())
                        }))
                        .filter(item => item.description);
                }
            }
        } catch (e) {}
        
        const items = trimmed.split(/\n+/).filter(item => item.trim());
        
        if (items.length === 0) {
            return [];
        }
        
        const maybeObjects = items.map(item => {
            try {
                const obj = JSON.parse(item.trim());
                if (obj && typeof obj === 'object') {
                    const descField = obj.description !== undefined ? 'description' : 
                                    obj.caption !== undefined ? 'caption' : null;
                    if (descField && obj[descField]) {
                        return { 
                            id: obj.id || null, 
                            description: sanitizeCaption(obj[descField]) 
                        };
                    }
                }
            } catch (e) {}
            return null;
        });
        
        if (maybeObjects.every(item => item !== null)) {
            return maybeObjects
                .filter(item => item && item.description)
                .map((item, index) => ({
                    id: index + 1,
                    description: item.description
                }));
        }
        
        return items
            .filter(item => item.trim())
            .map((item, index) => ({
                id: index + 1,
                description: sanitizeCaption(item.trim())
            }))
            .filter(item => item.description);
    }
    
    function sanitizeCaption(text) {
        if (!text || typeof text !== 'string') {
            return text;
        }
        
        let sanitized = text.replace(/\*/g, '');
        sanitized = sanitized.replace(/^[\s]*\d+[\s]*[\.\)\-\:]?\s*/, '');
        return sanitized.trim();
    }
    
    function addCaption() {
        const author = document.getElementById('caption_author_select').value;
        const inputText = document.getElementById('new_caption_text').value;
        
        if (!author) {
            showModal('Error', 'Please select an author.');
            return;
        }
        
        if (!inputText || !inputText.trim()) {
            showModal('Error', 'Please enter caption text.');
            return;
        }
        
        let parsedCaptions = parseCaptionInput(inputText);
        
        if (parsedCaptions.length === 0) {
            try {
                const trimmed = inputText.trim();
                const parsed = JSON.parse(trimmed);
                if (Array.isArray(parsed) && parsed.length > 0) {
                    const descField = parsed[0].description !== undefined ? 'description' : 
                                    parsed[0].caption !== undefined ? 'caption' : null;
                    if (descField) {
                        parsedCaptions = parsed
                            .filter(item => item[descField] && item[descField].trim())
                            .map((item, index) => ({
                                id: index + 1,
                                description: sanitizeCaption(item[descField].trim())
                            }))
                            .filter(item => item.description);
                    }
                }
            } catch (e) {}
        }
        
        if (parsedCaptions.length === 0) {
            showModal('Error', 'No valid captions found. Please enter text, JSON array, or caption objects.');
            return;
        }
        
        if (!configData.captions || !Array.isArray(configData.captions)) {
            configData.captions = [];
        }
        
        let authorIndex = configData.captions.findIndex(item => item.name === author);
        
        if (authorIndex === -1) {
            configData.captions.push({
                name: author,
                id: configData.captions.length + 1,
                captions: []
            });
            authorIndex = configData.captions.length - 1;
        }
        
        const existingCount = configData.captions[authorIndex].captions.length;
        
        const newCaptions = parsedCaptions.map((caption, index) => ({
            id: existingCount + index + 1,
            description: caption.description
        }));
        
        configData.captions[authorIndex].captions = configData.captions[authorIndex].captions.concat(newCaptions);
        
        loadCaptions();
        hideAddCaption();
        showModal('Success', parsedCaptions.length + ' caption(s) added successfully for ' + author + '!');
    }
    
    function editCaptions(index) {
        const authorData = configData.captions[index];
        if (!authorData) return;
        
        let captionsText = '';
        if (authorData.captions && authorData.captions.length > 0) {
            captionsText = authorData.captions
                .map(cap => cap.description)
                .join('\n');
        }
        
        let html = `
            <h3 style="margin-bottom:10px; color:#333;">Edit ${authorData.name} - Captions</h3>
            <div style="margin-bottom:10px; color:#718096; font-size:13px;">
                <p>Enter captions in any format:</p>
                <ul style="margin-top:5px; padding-left:20px;">
                    <li><strong>Simple:</strong> One caption per line</li>
                    <li><strong>JSON Array:</strong> ["caption 1", "caption 2"]</li>
                    <li><strong>Object Array:</strong> [{"id":1,"description":"caption"}]</li>
                </ul>
            </div>
            <div class="form-group">
                <textarea id="edit_captions_textarea" rows="8" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; resize:vertical; font-family:monospace;">${captionsText}</textarea>
            </div>
            <div style="margin-top:15px; display:flex; gap:10px; flex-wrap:wrap;">
                <button class="btn btn-success" onclick="saveEditedCaptions(${index})">Save Changes</button>
                <button class="btn btn-danger" onclick="deleteCaptionFromAuthor(${index})">Delete All Captions</button>
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            </div>
        `;
        
        showModal('Edit Captions', '', null, html);
        window._editingCaptionIndex = index;
    }
    
    function saveEditedCaptions(index) {
        const authorData = configData.captions[index];
        if (!authorData) return;
        
        const textarea = document.getElementById('edit_captions_textarea');
        if (!textarea) return;
        
        const inputText = textarea.value;
        let parsedCaptions = parseCaptionInput(inputText);
        
        if (parsedCaptions.length === 0) {
            try {
                const trimmed = inputText.trim();
                const parsed = JSON.parse(trimmed);
                if (Array.isArray(parsed) && parsed.length > 0) {
                    const descField = parsed[0].description !== undefined ? 'description' : 
                                    parsed[0].caption !== undefined ? 'caption' : null;
                    if (descField) {
                        parsedCaptions = parsed
                            .filter(item => item[descField] && item[descField].trim())
                            .map((item, index) => ({
                                id: index + 1,
                                description: sanitizeCaption(item[descField].trim())
                            }))
                            .filter(item => item.description);
                    }
                }
            } catch (e) {}
        }
        
        if (parsedCaptions.length === 0) {
            showModal('Error', 'No valid captions found. Please enter text, JSON array, or caption objects.');
            return;
        }
        
        authorData.captions = parsedCaptions.map((caption, idx) => ({
            id: idx + 1,
            description: caption.description
        }));
        
        loadCaptions();
        closeModal();
        showModal('Success', authorData.captions.length + ' caption(s) updated successfully!');
    }
    
    function deleteCaptionFromAuthor(index) {
        showModal('Confirm Delete', 'Are you sure you want to delete all captions for this author?', function() {
            if (configData.captions && configData.captions[index]) {
                configData.captions[index].captions = [];
                loadCaptions();
                closeModal();
                showModal('Success', 'All captions deleted for this author.');
            }
        });
    }
    
    function deleteCaptionAuthor(index) {
        showModal('Confirm Delete', 'Are you sure you want to delete this author and all their captions?', function() {
            if (configData.captions) {
                configData.captions.splice(index, 1);
                loadCaptions();
            }
        });
    }
    
    function loadCaptions() {
        const container = document.getElementById('captions_list');
        container.innerHTML = '';
        
        const captions = configData.captions || [];
        
        if (captions.length === 0) {
            container.innerHTML = '<p style="color: #999; text-align: center; padding: 20px;">No captions added yet.</p>';
            return;
        }
        
        captions.forEach((authorData, index) => {
            const div = document.createElement('div');
            div.className = 'caption-item';
            
            let captionsDisplay = '';
            if (authorData.captions && authorData.captions.length > 0) {
                const captionLines = authorData.captions
                    .map(cap => {
                        if (cap && typeof cap === 'object' && cap.description) {
                            return cap.description;
                        }
                        if (cap && typeof cap === 'object' && cap.caption) {
                            return cap.caption;
                        }
                        if (typeof cap === 'string') {
                            return cap;
                        }
                        return JSON.stringify(cap);
                    })
                    .slice(0, 5);
                captionsDisplay = captionLines.join(' | ');
                if (authorData.captions.length > 5) {
                    captionsDisplay += ' ... (+' + (authorData.captions.length - 5) + ' more)';
                }
            } else {
                captionsDisplay = 'No captions';
            }
            
            div.innerHTML = `
                <div class="caption-content">
                    <span class="caption-author">${authorData.name || 'Unknown Author'}</span>
                    <span style="font-size:13px; color:#5a7a8a;">${authorData.captions ? authorData.captions.length : 0} caption(s)</span>
                    <div style="margin-top:5px; font-size:13px; color:#718096; word-break:break-word;">
                        ${captionsDisplay}
                    </div>
                </div>
                <div>
                    <button class="btn btn-warning btn-sm" onclick="editCaptions(${index})" style="margin-right:5px;">Edit</button>
                    <button class="btn btn-danger btn-sm" onclick="deleteCaptionAuthor(${index})">Delete</button>
                </div>
            `;
            container.appendChild(div);
        });
    }
    
    function loadCaptionAuthors() {
        const select = document.getElementById('caption_author_select');
        if (!select) return;
        select.innerHTML = '<option value="">Select Author</option>';
        
        const accounts = configData.accounts || {};
        for (const key of Object.keys(accounts)) {
            const option = document.createElement('option');
            option.value = key;
            option.textContent = key;
            select.appendChild(option);
        }
    }
    
    // ===== TIME ORDER FUNCTIONS =====
    function showAddTimeOrder() {
        document.getElementById('add_timeorder_form')?.remove();
        
        const container = document.getElementById('timeorders_list');
        const form = document.createElement('div');
        form.id = 'add_timeorder_form';
        form.style.cssText = 'background: #f8fafc; padding: 20px; border-radius: 8px; margin-bottom: 20px;';
        form.innerHTML = `
            <div class="form-group">
                <label>Time Order Name</label>
                <input type="text" id="new_timeorder_name" placeholder="Enter time order name..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div class="time-input-group">
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; width:100%; margin-top:10px;">
                    <span style="font-weight:500;">24h:</span>
                    <input type="number" id="new_time_hour_24" placeholder="HH" min="0" max="23" style="width:80px; padding:10px;">
                    <span>:</span>
                    <input type="number" id="new_time_minute_24" placeholder="MM" min="0" max="59" style="width:80px; padding:10px;">
                </div>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; width:100%;">
                    <span style="font-weight:500;">12h:</span>
                    <input type="number" id="new_time_hour_12" placeholder="HH" min="1" max="12" style="width:80px; padding:10px;">
                    <span>:</span>
                    <input type="number" id="new_time_minute_12" placeholder="MM" min="0" max="59" style="width:80px; padding:10px;">
                    <select id="new_time_ampm" style="width:100px; padding:10px;">
                        <option value="AM">AM</option>
                        <option value="PM">PM</option>
                    </select>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn btn-primary" onclick="addTimeToNewOrder()">Add to List</button>
                </div>
            </div>
            <div id="new_time_list" style="margin-top: 10px;"></div>
            <div style="margin-top: 15px;">
                <button class="btn btn-success" onclick="saveNewTimeOrder()">Save Time Order</button>
                <button class="btn btn-secondary" onclick="cancelNewTimeOrder()" style="margin-left: 10px;">Cancel</button>
            </div>
        `;
        container.prepend(form);
        _newTimeList = [];
        renderNewTimeList();
        setupNewTimeConversion();
    }
    
    function setupNewTimeConversion() {
        const hour12 = document.getElementById('new_time_hour_12');
        const minute12 = document.getElementById('new_time_minute_12');
        const ampm = document.getElementById('new_time_ampm');
        const hour24 = document.getElementById('new_time_hour_24');
        const minute24 = document.getElementById('new_time_minute_24');
        
        if (!hour12 || !minute12 || !ampm || !hour24 || !minute24) return;
        
        function convert12To24() {
            let h = parseInt(hour12.value);
            const m = parseInt(minute12.value);
            const a = ampm.value;
            
            if (isNaN(h) || isNaN(m) || h < 1 || h > 12 || m < 0 || m > 59) {
                return;
            }
            
            let h24 = h;
            if (a === 'PM' && h !== 12) h24 = h + 12;
            if (a === 'AM' && h === 12) h24 = 0;
            
            hour24.value = String(h24).padStart(2, '0');
            minute24.value = String(m).padStart(2, '0');
        }
        
        function convert24To12() {
            let h = parseInt(hour24.value);
            const m = parseInt(minute24.value);
            
            if (isNaN(h) || isNaN(m) || h < 0 || h > 23 || m < 0 || m > 59) {
                return;
            }
            
            let a = 'AM';
            let h12 = h;
            if (h >= 12) {
                a = 'PM';
                if (h > 12) h12 = h - 12;
            }
            if (h === 0) {
                h12 = 12;
                a = 'AM';
            }
            
            hour12.value = h12;
            ampm.value = a;
            minute12.value = String(m).padStart(2, '0');
        }
        
        hour12.addEventListener('input', convert12To24);
        minute12.addEventListener('input', convert12To24);
        ampm.addEventListener('change', convert12To24);
        hour24.addEventListener('input', convert24To12);
        minute24.addEventListener('input', convert24To12);
    }
    
    function cancelNewTimeOrder() {
        document.getElementById('add_timeorder_form')?.remove();
        _newTimeList = [];
    }
    
    function addTimeToNewOrder() {
        const hour12 = document.getElementById('new_time_hour_12').value;
        const minute12 = document.getElementById('new_time_minute_12').value;
        const ampm = document.getElementById('new_time_ampm').value;
        const hour24 = document.getElementById('new_time_hour_24').value;
        const minute24 = document.getElementById('new_time_minute_24').value;
        
        if (!hour12 || !minute12 || !hour24 || !minute24) {
            showModal('Error', 'Please enter valid time in both formats.');
            return;
        }
        
        const time12h = hour12 + ':' + String(minute12).padStart(2, '0') + ' ' + ampm;
        const time24h = String(hour24).padStart(2, '0') + ':' + String(minute24).padStart(2, '0');
        
        _newTimeList.push({ '12hours': time12h, '24hours': time24h });
        renderNewTimeList();
        
        document.getElementById('new_time_hour_12').value = '';
        document.getElementById('new_time_minute_12').value = '';
        document.getElementById('new_time_hour_24').value = '';
        document.getElementById('new_time_minute_24').value = '';
    }
    
    function renderNewTimeList() {
        const container = document.getElementById('new_time_list');
        if (!container) return;
        container.innerHTML = '';
        
        if (_newTimeList.length === 0) {
            container.innerHTML = '<p style="color: #999; font-size: 14px;">No times added yet.</p>';
            return;
        }
        
        const table = document.createElement('div');
        table.style.cssText = 'display: grid; grid-template-columns: 1fr 1fr; gap: 5px;';
        table.innerHTML = `
            <div class="time-badge header" style="background:#00695c; color:white; padding:8px; border-radius:6px; text-align:center; font-weight:600;">12 Hours</div>
            <div class="time-badge header" style="background:#00695c; color:white; padding:8px; border-radius:6px; text-align:center; font-weight:600;">24 Hours</div>
        `;
        
        _newTimeList.forEach((item, index) => {
            const div1 = document.createElement('div');
            div1.className = 'time-badge';
            div1.style.cssText = 'background:white; padding:8px; border-radius:6px; border:1px solid #ddd; text-align:center;';
            div1.textContent = item['12hours'];
            
            const div2 = document.createElement('div');
            div2.className = 'time-badge';
            div2.style.cssText = 'background:white; padding:8px; border-radius:6px; border:1px solid #ddd; text-align:center; display:flex; justify-content:space-between; align-items:center;';
            div2.innerHTML = item['24hours'] + ' <span style="color:#ef4444; cursor:pointer; margin-left:10px;" onclick="removeNewTime(' + index + ')">×</span>';
            
            table.appendChild(div1);
            table.appendChild(div2);
        });
        
        container.appendChild(table);
    }
    
    function removeNewTime(index) {
        _newTimeList.splice(index, 1);
        renderNewTimeList();
    }
    
    function saveNewTimeOrder() {
        const name = document.getElementById('new_timeorder_name').value.trim();
        
        if (!name) {
            showModal('Error', 'Please enter a time order name.');
            return;
        }
        
        if (!configData.timeorders || typeof configData.timeorders !== 'object' || Array.isArray(configData.timeorders)) {
            configData.timeorders = {};
        }
        
        if (configData.timeorders[name]) {
            showModal('Error', 'Time order already exists!');
            return;
        }
        
        if (_newTimeList.length === 0) {
            showModal('Error', 'Please add at least one time.');
            return;
        }
        
        configData.timeorders[name] = _newTimeList;
        loadTimeOrders();
        cancelNewTimeOrder();
        showModal('Success', 'Time order saved successfully!');
    }
    
    function addTimeToExistingOrder(key) {
        const container = document.getElementById('add_time_' + key);
        if (container) {
            container.style.display = container.style.display === 'none' ? 'block' : 'none';
        }
    }
    
    function cancelAddTime(key) {
        const container = document.getElementById('add_time_' + key);
        if (container) container.style.display = 'none';
        document.getElementById('time_hour_12_' + key).value = '';
        document.getElementById('time_minute_12_' + key).value = '';
        document.getElementById('time_hour_24_' + key).value = '';
        document.getElementById('time_minute_24_' + key).value = '';
    }
    
    function saveTimeToOrder(key) {
        const hour12 = document.getElementById('time_hour_12_' + key).value;
        const minute12 = document.getElementById('time_minute_12_' + key).value;
        const ampm = document.getElementById('time_ampm_' + key).value;
        const hour24 = document.getElementById('time_hour_24_' + key).value;
        const minute24 = document.getElementById('time_minute_24_' + key).value;
        
        if (!hour12 || !minute12 || !hour24 || !minute24) {
            showModal('Error', 'Please enter valid time in both formats.');
            return;
        }
        
        const time12h = hour12 + ':' + String(minute12).padStart(2, '0') + ' ' + ampm;
        const time24h = String(hour24).padStart(2, '0') + ':' + String(minute24).padStart(2, '0');
        
        if (configData.timeorders && configData.timeorders[key]) {
            configData.timeorders[key].push({ '12hours': time12h, '24hours': time24h });
            loadTimeOrders();
            showModal('Success', 'Time added successfully!');
        }
    }
    
    function deleteTimeOrder(name) {
        showModal('Confirm Delete', 'Are you sure you want to delete time order "' + name + '"?', function() {
            if (configData.timeorders) {
                delete configData.timeorders[name];
                loadTimeOrders();
            }
        });
    }
    
    function loadTimeOrders() {
        const container = document.getElementById('timeorders_list');
        container.innerHTML = '';
        
        const timeOrders = configData.timeorders || {};
        
        if (Object.keys(timeOrders).length === 0) {
            container.innerHTML = '<p style="color: #999; text-align: center; padding: 20px;">No time orders added yet.</p>';
            return;
        }
        
        for (const [key, value] of Object.entries(timeOrders)) {
            const div = document.createElement('div');
            div.className = 'timeorder-item';
            
            let timesHtml = '';
            if (Array.isArray(value) && value.length > 0) {
                timesHtml += '<div class="time-list">';
                value.forEach(t => {
                    const time12 = t['12hours'] || t.twelveHour || t.time || '';
                    const time24 = t['24hours'] || '';
                    timesHtml += '<div class="time-badge">' + time12 + '</div><div class="time-badge">' + time24 + '</div>';
                });
                timesHtml += '</div>';
            } else {
                timesHtml = '<span style="color:#999;">No times added</span>';
            }
            
            div.innerHTML = `
                <div class="timeorder-header">
                    <span class="key">${key}</span>
                    <button class="btn btn-danger btn-sm" onclick="deleteTimeOrder('${key}')">Delete</button>
                </div>
                ${timesHtml}
                <div class="add-time-area">
                    <button class="btn btn-primary btn-sm" onclick="addTimeToExistingOrder('${key}')">Add Time</button>
                    <div id="add_time_${key}" style="display:none; margin-top:10px;">
                        <div class="time-input-group">
                            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:10px;">
                                <span style="font-weight:500;">24h:</span>
                                <input type="number" id="time_hour_24_${key}" placeholder="HH" min="0" max="23" style="width:80px; padding:10px;">
                                <span>:</span>
                                <input type="number" id="time_minute_24_${key}" placeholder="MM" min="0" max="59" style="width:80px; padding:10px;">
                            </div>
                            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                <span style="font-weight:500;">12h:</span>
                                <input type="number" id="time_hour_12_${key}" placeholder="HH" min="1" max="12" style="width:80px; padding:10px;">
                                <span>:</span>
                                <input type="number" id="time_minute_12_${key}" placeholder="MM" min="0" max="59" style="width:80px; padding:10px;">
                                <select id="time_ampm_${key}" style="width:100px; padding:10px;">
                                    <option value="AM">AM</option>
                                    <option value="PM">PM</option>
                                </select>
                            </div>
                            <div style="margin-top:10px;">
                                <button class="btn btn-success btn-sm" onclick="saveTimeToOrder('${key}')">Add to List</button>
                                <button class="btn btn-secondary btn-sm" onclick="cancelAddTime('${key}')">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(div);
            setupTimeOrderConversion(key);
        }
    }
    
    function setupTimeOrderConversion(key) {
        const hour12 = document.getElementById('time_hour_12_' + key);
        const minute12 = document.getElementById('time_minute_12_' + key);
        const ampm = document.getElementById('time_ampm_' + key);
        const hour24 = document.getElementById('time_hour_24_' + key);
        const minute24 = document.getElementById('time_minute_24_' + key);
        
        if (!hour12 || !minute12 || !ampm || !hour24 || !minute24) return;
        
        function convert12To24() {
            let h = parseInt(hour12.value);
            const m = parseInt(minute12.value);
            const a = ampm.value;
            
            if (isNaN(h) || isNaN(m) || h < 1 || h > 12 || m < 0 || m > 59) {
                return;
            }
            
            let h24 = h;
            if (a === 'PM' && h !== 12) h24 = h + 12;
            if (a === 'AM' && h === 12) h24 = 0;
            
            hour24.value = String(h24).padStart(2, '0');
            minute24.value = String(m).padStart(2, '0');
        }
        
        function convert24To12() {
            let h = parseInt(hour24.value);
            const m = parseInt(minute24.value);
            
            if (isNaN(h) || isNaN(m) || h < 0 || h > 23 || m < 0 || m > 59) {
                return;
            }
            
            let a = 'AM';
            let h12 = h;
            if (h >= 12) {
                a = 'PM';
                if (h > 12) h12 = h - 12;
            }
            if (h === 0) {
                h12 = 12;
                a = 'AM';
            }
            
            hour12.value = h12;
            ampm.value = a;
            minute12.value = String(m).padStart(2, '0');
        }
        
        hour12.addEventListener('input', convert12To24);
        minute12.addEventListener('input', convert12To24);
        ampm.addEventListener('change', convert12To24);
        hour24.addEventListener('input', convert24To12);
        minute24.addEventListener('input', convert24To12);
    }
    
    // ===== FILTER FUNCTIONS =====
    function addFilter() {
        const input = document.getElementById('filter_input');
        const value = input.value.trim();
        if (value) {
            if (!configData.filters) configData.filters = [];
            if (!configData.filters.includes(value)) {
                configData.filters.push(value);
                loadFilters();
                input.value = '';
            }
        }
    }
    
    function removeFilter(index) {
        showModal('Confirm Delete', 'Are you sure you want to remove this filter?', function() {
            if (configData.filters) {
                configData.filters.splice(index, 1);
                loadFilters();
            }
        });
    }
    
    function loadFilters() {
        const container = document.getElementById('filters_list');
        container.innerHTML = '';
        
        const filters = configData.filters || [];
        if (filters.length === 0) {
            container.innerHTML = '<p style="color: #999; font-size: 14px;">No filters added.</p>';
            return;
        }
        
        filters.forEach((filter, index) => {
            const span = document.createElement('span');
            span.className = 'group-item';
            span.innerHTML = filter + ' <span class="remove-group" onclick="removeFilter(' + index + ')">×</span>';
            container.appendChild(span);
        });
    }
    
    // ===== DYNAMIC FIELD FUNCTIONS =====
    function loadDynamicFields() {
        const container = document.getElementById('dynamic_fields_list');
        if (!container) return;
        container.innerHTML = '';

        const fields = configData.dynamic_fields || {};
        const fieldKeys = Object.keys(fields);

        if (fieldKeys.length === 0) {
            container.innerHTML = '<p style="color: #999; text-align: center; padding: 20px;">No dynamic fields added yet.</p>';
            return;
        }

        fieldKeys.forEach((key, index) => {
            const field = fields[key] || {};
            const div = document.createElement('div');
            div.className = 'dynamic-field-item';

            const defaultValues = Array.isArray(field.default_values) ? field.default_values : [];
            const sourceType = field.source_type || 'none';
            const allowList = !!field.allow_list;
            const title = field.title || key;
            const submitAs = field.submit_as || 'key';

            let sourceInfo = '';
            if (sourceType === 'accounts') sourceInfo = ' | Source: Account URLs';
            else if (sourceType === 'captions') sourceInfo = ' | Source: Captions';
            else if (sourceType === 'timeorders') sourceInfo = ' | Source: Time Orders';
            else if (sourceType === 'filters') sourceInfo = ' | Source: Filters';

            let submitAsLabel = '';
            if (submitAs === 'key') submitAsLabel = 'Submit: Key Only';
            else if (submitAs === 'value') submitAsLabel = 'Submit: Value Only';
            else if (submitAs === 'both') submitAsLabel = 'Submit: Key & Value';

            div.innerHTML = `
                <div class="field-header">
                    <span class="field-name">${title} (${key})</span>
                    <span class="field-type-badge">${field.element_type || 'input'}${allowList ? ' + List' : ''}</span>
                </div>
                <div style="color: #6b7280; font-size: 13px;">
                    ${field.element_type === 'select' ? 'Options: ' + (defaultValues.length > 0 ? defaultValues.join(', ') : 'None') : ''}
                    ${sourceInfo}
                    ${allowList ? ' | Allows adding custom values' : ''}
                    ${submitAsLabel ? ' | ' + submitAsLabel : ''}
                </div>
                <div class="default-values">
                    ${defaultValues.map(val => `<span class="value-tag">${val}</span>`).join('')}
                </div>
                <div class="dynamic-field-action-row" style="margin-top:10px;display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-warning btn-sm dynamic-field-edit-button" style="margin-right:5px;">Edit</button>
                    <button type="button" class="btn btn-danger btn-sm dynamic-field-delete-button">Delete</button>
                </div>
            `;

            // SceneIQ-style position controls for the complete Dynamic Fields list.
            const header = div.querySelector('.field-header');
            const reorderControls = document.createElement('div');
            reorderControls.className = 'dynamic-field-reorder-controls';

            const position = document.createElement('span');
            position.className = 'dynamic-field-position';
            position.textContent = String(index + 1);
            position.setAttribute('aria-label', 'Position ' + (index + 1));

            const moveUp = document.createElement('button');
            moveUp.type = 'button';
            moveUp.className = 'dynamic-field-move-button';
            moveUp.textContent = '↑';
            moveUp.title = 'Move field up';
            moveUp.setAttribute('aria-label', 'Move ' + key + ' up');
            moveUp.disabled = index === 0;
            moveUp.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                moveDynamicField(key, 'up');
            });

            const moveDown = document.createElement('button');
            moveDown.type = 'button';
            moveDown.className = 'dynamic-field-move-button';
            moveDown.textContent = '↓';
            moveDown.title = 'Move field down';
            moveDown.setAttribute('aria-label', 'Move ' + key + ' down');
            moveDown.disabled = index === fieldKeys.length - 1;
            moveDown.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                moveDynamicField(key, 'down');
            });

            reorderControls.appendChild(position);
            reorderControls.appendChild(moveUp);
            reorderControls.appendChild(moveDown);
            header.appendChild(reorderControls);

            div.querySelector('.dynamic-field-edit-button').addEventListener('click', function() {
                editDynamicField(key);
            });
            div.querySelector('.dynamic-field-delete-button').addEventListener('click', function() {
                deleteDynamicField(key);
            });

            container.appendChild(div);
        });
    }

    // Rebuild dynamic_fields in the chosen key order. Object insertion order survives
    // JSON.stringify and PHP json_decode, so Setup and Add/Edit forms stay aligned.
    function moveDynamicField(fieldKey, direction) {
        const current = configData.dynamic_fields || {};
        const keys = Object.keys(current);
        const from = keys.indexOf(String(fieldKey));
        const to = from + (direction === 'up' ? -1 : 1);

        if (from < 0 || to < 0 || to >= keys.length) return;

        const reorderedKeys = keys.slice();
        const movedKey = reorderedKeys.splice(from, 1)[0];
        reorderedKeys.splice(to, 0, movedKey);

        const reorderedFields = {};
        reorderedKeys.forEach(function(key) {
            reorderedFields[key] = current[key];
        });

        const previousFields = current;
        configData.dynamic_fields = reorderedFields;

        const container = document.getElementById('dynamic_fields_list');
        if (container) container.classList.add('is-saving-order');

        loadDynamicFields();

        const formData = new FormData();
        formData.append('save_dynamic_fields_order', 'true');
        formData.append('field_order', JSON.stringify(reorderedKeys));

        fetch(window.location.href, { method: 'POST', body: formData })
            .then(function(response) {
                return response.json().then(function(result) {
                    if (!response.ok || !result || !result.success) {
                        throw new Error((result && result.message) || 'The new field order could not be saved.');
                    }
                    return result;
                });
            })
            .then(function() {
                loadDynamicFields();
            })
            .catch(function(error) {
                configData.dynamic_fields = previousFields;
                loadDynamicFields();
                showModal('Order Not Saved', error.message || 'Could not save the new dynamic-field order.');
            })
            .finally(function() {
                if (container) container.classList.remove('is-saving-order');
            });
    }

    function showAddDynamicFieldModal() {
        _newDefaultValues = [];
        editingDynamicFieldKey = null;
        
        const html = `
            <div class="form-group">
                <label>Display Title (shown in UI)</label>
                <input type="text" id="modal_field_title" placeholder="Enter display title..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                <small style="color:#999;">This is what users will see</small>
            </div>
            <div class="form-group">
                <label>Field Value (used in backend)</label>
                <input type="text" id="modal_field_name" placeholder="Enter field value (no spaces)..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                <small style="color:#999;">Spaces will be replaced with underscores. Used for storage.</small>
            </div>
            <div class="form-group">
                <label>Element Type</label>
                <select id="modal_element_type" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="select">Select</option>
                    <option value="input">Input</option>
                    <option value="textarea">Textarea</option>
                    <option value="date-time-input">Date & Time</option>
                </select>
            </div>
            <div class="form-group" id="modal_allow_list_container" style="display:none;">
                <label>Allow Adding Custom Values (List Input)</label>
                <select id="modal_allow_list" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="no">No - Single value only</option>
                    <option value="yes">Yes - Allow multiple values (add/remove)</option>
                </select>
                <small style="color:#999;">If Yes, users can add multiple values to this field</small>
            </div>
            <div class="form-group" id="modal_default_values_container">
                <label>Default Values</label>
                <div style="display:flex; gap:10px; margin-bottom:10px;">
                    <input type="text" id="modal_default_value_input" placeholder="Enter value..." style="flex:1; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <button class="btn btn-primary" onclick="addModalDefaultValue()">Add</button>
                </div>
                <div id="modal_default_values_list"></div>
            </div>
            <div class="form-group" id="modal_source_container">
                <label>Source Options From (for Select type)</label>
                <select id="modal_source_type" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="none">None - Use default values only</option>
                    <option value="accounts">Account URLs</option>
                    <option value="captions">Captions</option>
                    <option value="timeorders">Time Orders</option>
                    <option value="filters">Filters</option>
                </select>
                <small style="color:#999;">Select which data source to populate the dropdown with</small>
            </div>
            <div class="form-group" id="modal_submit_as_container">
                <label>Submit As</label>
                <select id="modal_submit_as" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="key">Submit Key Only</option>
                    <option value="value">Submit Value Only</option>
                    <option value="both">Submit Key & Value</option>
                </select>
                <small style="color:#999;">Choose what data to submit to the backend</small>
                <div id="modal_submit_preview" class="submit-as-preview" style="margin-top:5px; padding:6px 12px; background:#f8fafc; border-radius:6px; border:1px dashed #c8e0d8; font-size:12px; color:#6b7280; font-family:monospace;">
                    Example: "key" → "value"
                </div>
            </div>
            <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: flex-end;">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-success" onclick="saveDynamicFieldFromModal()">Save Field</button>
            </div>
        `;
        
        showModal('Add Dynamic Field', '', null, html);
        renderModalDefaultValues();
        
        document.getElementById('modal_element_type').addEventListener('change', function() {
            const isSelect = this.value === 'select';
            const isInput = this.value === 'input';
            
            document.getElementById('modal_default_values_container').style.display = (isSelect || isInput) ? 'block' : 'none';
            document.getElementById('modal_source_container').style.display = isSelect ? 'block' : 'none';
            document.getElementById('modal_allow_list_container').style.display = isInput ? 'block' : 'none';
            document.getElementById('modal_submit_as_container').style.display = isSelect ? 'block' : 'none';
        });
        
        document.getElementById('modal_field_title').addEventListener('input', function() {
            const title = this.value;
            const nameInput = document.getElementById('modal_field_name');
            if (!nameInput.dataset.manual) {
                nameInput.value = title.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
            }
        });
        
        document.getElementById('modal_field_name').addEventListener('input', function() {
            this.dataset.manual = 'true';
        });
        
        document.getElementById('modal_submit_as').addEventListener('change', function() {
            updateSubmitPreview(this.value);
        });
    }
    
    function updateSubmitPreview(value) {
        const preview = document.getElementById('modal_submit_preview');
        if (!preview) return;
        
        if (value === 'key') {
            preview.innerHTML = 'Example: "key"';
        } else if (value === 'value') {
            preview.innerHTML = 'Example: "value"';
        } else if (value === 'both') {
            preview.innerHTML = 'Example: {"key": "value"}';
        }
    }
    
    function editDynamicField(key) {
        editingDynamicFieldKey = key;
        const field = configData.dynamic_fields[key];
        if (!field) return;
        
        _newDefaultValues = field.default_values || [];
        _newListValues = field.list_values || [];
        
        const html = `
            <div class="form-group">
                <label>Display Title (shown in UI)</label>
                <input type="text" id="modal_field_title" value="${field.title || key}" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                <small style="color:#999;">This is what users will see</small>
            </div>
            <div class="form-group">
                <label>Field Value (used in backend)</label>
                <input type="text" id="modal_field_name" value="${key}" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;" readonly>
                <small style="color:#999;">Field value cannot be changed</small>
            </div>
            <div class="form-group">
                <label>Element Type</label>
                <select id="modal_element_type" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="select" ${field.element_type === 'select' ? 'selected' : ''}>Select</option>
                    <option value="input" ${field.element_type === 'input' ? 'selected' : ''}>Input</option>
                    <option value="textarea" ${field.element_type === 'textarea' ? 'selected' : ''}>Textarea</option>
                    <option value="date-time-input" ${field.element_type === 'date-time-input' ? 'selected' : ''}>Date & Time</option>
                </select>
            </div>
            <div class="form-group" id="modal_allow_list_container" style="${field.element_type === 'input' ? 'display:block' : 'display:none'}">
                <label>Allow Adding Custom Values (List Input)</label>
                <select id="modal_allow_list" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="no" ${field.allow_list ? '' : 'selected'}>No - Single value only</option>
                    <option value="yes" ${field.allow_list ? 'selected' : ''}>Yes - Allow multiple values (add/remove)</option>
                </select>
                <small style="color:#999;">If Yes, users can add multiple values to this field</small>
            </div>
            <div class="form-group" id="modal_default_values_container" style="${field.element_type === 'select' || field.element_type === 'input' ? 'display:block' : 'display:none'}">
                <label>Default Values ${field.element_type === 'input' ? '(Pre-filled values)' : ''}</label>
                <div style="display:flex; gap:10px; margin-bottom:10px;">
                    <input type="text" id="modal_default_value_input" placeholder="Enter value..." style="flex:1; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <button class="btn btn-primary" onclick="addModalDefaultValue()">Add</button>
                </div>
                <div id="modal_default_values_list"></div>
            </div>
            <div class="form-group" id="modal_source_container" style="${field.element_type === 'select' ? 'display:block' : 'display:none'}">
                <label>Source Options From (for Select type)</label>
                <select id="modal_source_type" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="none" ${field.source_type === 'none' || !field.source_type ? 'selected' : ''}>None - Use default values only</option>
                    <option value="accounts" ${field.source_type === 'accounts' ? 'selected' : ''}>Account URLs</option>
                    <option value="captions" ${field.source_type === 'captions' ? 'selected' : ''}>Captions</option>
                    <option value="timeorders" ${field.source_type === 'timeorders' ? 'selected' : ''}>Time Orders</option>
                    <option value="filters" ${field.source_type === 'filters' ? 'selected' : ''}>Filters</option>
                </select>
                <small style="color:#999;">Select which data source to populate the dropdown with</small>
            </div>
            <div class="form-group" id="modal_submit_as_container" style="${field.element_type === 'select' ? 'display:block' : 'display:none'}">
                <label>Submit As</label>
                <select id="modal_submit_as" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="key" ${field.submit_as === 'key' || !field.submit_as ? 'selected' : ''}>Submit Key Only</option>
                    <option value="value" ${field.submit_as === 'value' ? 'selected' : ''}>Submit Value Only</option>
                    <option value="both" ${field.submit_as === 'both' ? 'selected' : ''}>Submit Key & Value</option>
                </select>
                <small style="color:#999;">Choose what data to submit to the backend</small>
                <div id="modal_submit_preview" class="submit-as-preview" style="margin-top:5px; padding:6px 12px; background:#f8fafc; border-radius:6px; border:1px dashed #c8e0d8; font-size:12px; color:#6b7280; font-family:monospace;">
                    ${field.submit_as === 'key' || !field.submit_as ? 'Example: "key"' : field.submit_as === 'value' ? 'Example: "value"' : 'Example: {"key": "value"}'}
                </div>
            </div>
            <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: flex-end;">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-success" onclick="saveDynamicFieldFromModal()">Update Field</button>
            </div>
        `;
        
        showModal('Edit Dynamic Field', '', null, html);
        renderModalDefaultValues();
        
        document.getElementById('modal_element_type').addEventListener('change', function() {
            const isSelect = this.value === 'select';
            const isInput = this.value === 'input';
            
            document.getElementById('modal_default_values_container').style.display = (isSelect || isInput) ? 'block' : 'none';
            document.getElementById('modal_source_container').style.display = isSelect ? 'block' : 'none';
            document.getElementById('modal_allow_list_container').style.display = isInput ? 'block' : 'none';
            document.getElementById('modal_submit_as_container').style.display = isSelect ? 'block' : 'none';
        });
        
        document.getElementById('modal_submit_as').addEventListener('change', function() {
            updateSubmitPreview(this.value);
        });
    }
    
    function addModalDefaultValue() {
        const input = document.getElementById('modal_default_value_input');
        const value = input.value.trim();
        if (value) {
            if (!_newDefaultValues.includes(value)) {
                _newDefaultValues.push(value);
                renderModalDefaultValues();
                input.value = '';
            }
        }
    }
    
    function removeModalDefaultValue(index) {
        _newDefaultValues.splice(index, 1);
        renderModalDefaultValues();
    }
    
    function renderModalDefaultValues() {
        const container = document.getElementById('modal_default_values_list');
        if (!container) return;
        container.innerHTML = '';
        
        if (_newDefaultValues.length === 0) {
            container.innerHTML = '<p style="color: #999; font-size: 14px;">No values added.</p>';
            return;
        }
        
        _newDefaultValues.forEach((val, index) => {
            const span = document.createElement('span');
            span.className = 'group-item';
            span.innerHTML = val + ' <span class="remove-group" onclick="removeModalDefaultValue(' + index + ')">×</span>';
            container.appendChild(span);
        });
    }
    
    function saveDynamicFieldFromModal() {
        const title = document.getElementById('modal_field_title').value.trim();
        let fieldName = document.getElementById('modal_field_name').value.trim();
        const elementType = document.getElementById('modal_element_type').value;
        const sourceType = document.getElementById('modal_source_type').value;
        const allowList = document.getElementById('modal_allow_list').value === 'yes';
        const submitAs = document.getElementById('modal_submit_as') ? document.getElementById('modal_submit_as').value : 'key';
        
        if (!title) {
            showModal('Error', 'Please enter a display title.');
            return;
        }
        
        if (!fieldName || !document.getElementById('modal_field_name').dataset.manual) {
            fieldName = title.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
        }
        
        if (!fieldName) {
            showModal('Error', 'Please enter a field value.');
            return;
        }
        
        if (!editingDynamicFieldKey && configData.dynamic_fields[fieldName]) {
            showModal('Error', 'A field with this value already exists.');
            return;
        }
        
        const fieldData = {
            title: title,
            element_type: elementType,
            default_values: (elementType === 'select' || elementType === 'input') ? _newDefaultValues : [],
            source_type: (elementType === 'select' && sourceType !== 'none') ? sourceType : 'none',
            allow_list: elementType === 'input' ? allowList : false,
            submit_as: (elementType === 'select' && sourceType !== 'none') ? submitAs : 'key'
        };
        
        if (editingDynamicFieldKey) {
            if (editingDynamicFieldKey !== fieldName) {
                const currentFields = configData.dynamic_fields || {};
                const renamedFields = {};
                Object.keys(currentFields).forEach(function(existingKey) {
                    if (existingKey === editingDynamicFieldKey) {
                        renamedFields[fieldName] = fieldData;
                    } else {
                        renamedFields[existingKey] = currentFields[existingKey];
                    }
                });
                configData.dynamic_fields = renamedFields;
            } else {
                configData.dynamic_fields[fieldName] = fieldData;
            }
        } else {
            configData.dynamic_fields[fieldName] = fieldData;
        }
        
        loadDynamicFields();
        renderDynamicFields();
        closeModal();
        showModal('Success', 'Dynamic field saved successfully!');
    }
    
    function deleteDynamicField(key) {
        showModal('Confirm Delete', 'Are you sure you want to delete dynamic field "' + key + '"?', function() {
            if (configData.dynamic_fields) {
                delete configData.dynamic_fields[key];
                loadDynamicFields();
                renderDynamicFields();
                showModal('Success', 'Dynamic field deleted successfully!');
            }
        });
    }
    
    // ===== SAVE FUNCTIONS =====
    function saveConfig() {
        // Get dynamic values from rendered fields
        const dynamicValues = {};
        const fieldKeys = Object.keys(configData.dynamic_fields || {});
        
        for (const key of fieldKeys) {
            const field = configData.dynamic_fields[key];
            const elementType = field.element_type || 'input';
            const allowList = field.allow_list || false;
            
            if (elementType === 'date-time-input') {
                const dateInput = document.getElementById('dynamic_field_' + key + '_date');
                const formatSelect = document.getElementById('dynamic_field_' + key + '_format');
                
                if (dateInput && formatSelect) {
                    const date = dateInput.value;
                    const format = formatSelect.value;
                    
                    let timeStr = '';
                    if (format === '12h') {
                        const hour12 = document.getElementById('dynamic_field_' + key + '_hour_12');
                        const minute12 = document.getElementById('dynamic_field_' + key + '_minute_12');
                        const ampm = document.getElementById('dynamic_field_' + key + '_ampm');
                        if (hour12 && minute12 && ampm) {
                            const h = hour12.value;
                            const m = minute12.value;
                            const a = ampm.value;
                            if (h && m) {
                                timeStr = h + ':' + String(m).padStart(2, '0') + ' ' + a;
                            }
                        }
                    } else {
                        const hour24 = document.getElementById('dynamic_field_' + key + '_hour_24');
                        const minute24 = document.getElementById('dynamic_field_' + key + '_minute_24');
                        if (hour24 && minute24) {
                            const h = hour24.value;
                            const m = minute24.value;
                            if (h && m) {
                                timeStr = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
                            }
                        }
                    }
                    
                    if (date || timeStr) {
                        let formattedDate = '';
                        if (date) {
                            const parts = date.split('-');
                            if (parts.length === 3) {
                                formattedDate = parts[2] + '/' + parts[1] + '/' + parts[0];
                            }
                        }
                        dynamicValues[key] = formattedDate + (timeStr ? ' ' + timeStr : '');
                    }
                }
            } else if (elementType === 'input' && allowList) {
                const listContainer = document.getElementById('dynamic_field_' + key + '_list');
                if (listContainer) {
                    const values = [];
                    const tags = listContainer.querySelectorAll('.value-tag');
                    tags.forEach(tag => {
                        const text = tag.textContent.replace('×', '').trim();
                        if (text) values.push(text);
                    });
                    dynamicValues[key] = values.join(', ');
                }
            } else if (elementType === 'select') {
                const select = document.getElementById('dynamic_field_' + key);
                if (select) {
                    const selectedOption = select.options[select.selectedIndex];
                    if (selectedOption) {
                        dynamicValues[key] = selectedOption.value;
                    }
                }
            } else {
                const input = document.getElementById('dynamic_field_' + key);
                if (input) {
                    dynamicValues[key] = input.value;
                }
            }
        }
        
        const firstKey = Object.keys(dynamicValues)[0] || 'default';
        const author = dynamicValues[firstKey] || 'default';
        
        let existingSettings = Array.isArray(configData.settings) ? configData.settings : [];
        let settingsObj;
        if (isEditMode && Number.isInteger(editingIndex) && editingIndex >= 0 && editingIndex < existingSettings.length) {
            const oldConfig = existingSettings[editingIndex] && typeof existingSettings[editingIndex] === 'object'
                ? existingSettings[editingIndex] : {};
            settingsObj = Object.assign({}, oldConfig, {
                author: author,
                dynamic_values: dynamicValues,
                status: oldConfig.status || 'pending'
            });
            if (Object.prototype.hasOwnProperty.call(oldConfig, 'operation_status')) {
                settingsObj.operation_status = oldConfig.operation_status;
            }
            existingSettings[editingIndex] = settingsObj;
        } else {
            // A new form submission is always a new independent entry. Never
            // replace an existing entry merely because its author is the same.
            settingsObj = { author: author, dynamic_values: dynamicValues, status: 'pending' };
            existingSettings.push(settingsObj);
        }
        
        configData.settings = existingSettings;
        
        const postData = {
            settings: configData.settings,
            accounts: configData.accounts || {},
            captions: configData.captions || [],
            timeorders: configData.timeorders || {},
            filters: configData.filters || [],
            dynamic_fields: configData.dynamic_fields || {}
        };
        
        const saveBtn = document.getElementById('saveConfigBtn');
        const originalText = saveBtn.textContent;
        saveBtn.textContent = 'Saving...';
        saveBtn.disabled = true;
        
        const formData = new FormData();
        formData.append('save_all', 'true');
        formData.append('settings', JSON.stringify(postData.settings));
        formData.append('accounts', JSON.stringify(postData.accounts));
        formData.append('captions', JSON.stringify(postData.captions));
        formData.append('timeorders', JSON.stringify(postData.timeorders));
        formData.append('filters', JSON.stringify(postData.filters));
        formData.append('dynamic_fields', JSON.stringify(postData.dynamic_fields));
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showModal('Success', data.message + ' Refreshing...');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showModal('Error', data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showModal('Error', 'Error saving data: ' + error.message);
        })
        .finally(() => {
            saveBtn.textContent = originalText;
            saveBtn.disabled = false;
        });
    }
    
    function saveSetup() {
        const postData = {
            accounts: configData.accounts || {},
            captions: configData.captions || [],
            timeorders: configData.timeorders || {},
            filters: configData.filters || [],
            dynamic_fields: configData.dynamic_fields || {}
        };
        
        const saveBtn = document.getElementById('saveSetupBtn');
        const originalText = saveBtn.textContent;
        saveBtn.textContent = 'Saving...';
        saveBtn.disabled = true;
        
        const formData = new FormData();
        formData.append('save_all', 'true');
        formData.append('accounts', JSON.stringify(postData.accounts));
        formData.append('captions', JSON.stringify(postData.captions));
        formData.append('timeorders', JSON.stringify(postData.timeorders));
        formData.append('filters', JSON.stringify(postData.filters));
        formData.append('dynamic_fields', JSON.stringify(postData.dynamic_fields));
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showModal('Success', data.message + ' Refreshing...');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showModal('Error', data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showModal('Error', 'Error saving data: ' + error.message);
        })
        .finally(() => {
            saveBtn.textContent = originalText;
            saveBtn.disabled = false;
        });
    }
    
    // ===== AUTH =====
    function logout() {
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=logout'
        })
        .then(() => {
            window.location.href = 'index.php';
        })
        .catch(() => {
            window.location.href = 'index.php';
        });
    }
    
    function checkAuth() {
        fetch('?action=check_auth')
            .then(r => r.json())
            .then(data => {
                if (!data.authenticated) {
                    window.location.href = 'login.php';
                }
            })
            .catch(() => {
                window.location.href = 'login.php';
            });
    }
    
    let activityTimer;
    function resetActivityTimer() {
        if (activityTimer) clearTimeout(activityTimer);
        activityTimer = setTimeout(() => {
            logout();
        }, 30 * 60 * 1000);
    }
    
    ['click', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
        document.addEventListener(event, resetActivityTimer);
    });
    
    resetActivityTimer();
    setInterval(checkAuth, 60000);
    
    // ===== INIT =====
    document.addEventListener('DOMContentLoaded', function() {
        // Show pending tab by default
        switchMainTab('pending');
        
        document.querySelectorAll('input, select, textarea').forEach(el => {
            el.addEventListener('focus', function(e) {
                this.style.fontSize = '16px';
            });
        });
    });
</script>
</body>
</html>

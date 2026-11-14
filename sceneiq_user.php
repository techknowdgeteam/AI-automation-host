<?php
/**
 * sceneiq_user.php
 * SceneIQ user dashboard.
 *
 * Users can sign in only; account creation and configuration editors are intentionally
 * excluded. Dynamic fields/tabs are read from sceneiq_config and treated as management-owned.
 */
declare(strict_types=1);
session_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');

$host = 'sql201.infinityfree.com';
$dbname = 'if0_40367004_automation_tree';
$username = 'if0_40367004';
$password = 'NkwFAH15FRIlvCf';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (Throwable $e) {
    error_log('SceneIQ database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('SceneIQ is temporarily unavailable. Please try again later.');
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function decodeArray($value): array {
    if (is_array($value)) return $value;
    if (!is_string($value) || $value === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}
function repairSettings($settings): array {
    if (!is_array($settings)) return [];
    if (isset($settings[0]) && is_array($settings[0])) {
        return array_values(array_filter($settings, 'is_array'));
    }
    if (isset($settings['status']) || isset($settings['dynamic_values'])) return [$settings];
    $result = [];
    foreach ($settings as $value) {
        if (is_array($value) && (isset($value['status']) || isset($value['dynamic_values']))) {
            $result[] = $value;
        }
    }
    return $result ?: $settings;
}
function getUserConfig(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare(
        'SELECT id, user_id, settings, dynamic_fields, dynamic_tabs
         FROM sceneiq_config WHERE user_id = :uid ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}
function saveUserSettings(PDO $pdo, int $userId, array $settings, array $config): void {
    $stmt = $pdo->prepare('UPDATE sceneiq_config SET settings = :settings WHERE id = :id AND user_id = :uid');
    $stmt->execute([
        ':settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':id' => (int)$config['id'],
        ':uid' => $userId
    ]);
}
function fieldTitle(string $key, array $definition): string {
    return (string)($definition['field-title'] ?? $definition['title'] ?? $key);
}
function fieldType(array $definition): string {
    return strtolower((string)($definition['element_type'] ?? $definition['element-type'] ?? $definition['type'] ?? 'input'));
}
function fieldOptions(array $definition): array {
    $values = $definition['default_values'] ?? $definition['default-values'] ?? $definition['options'] ?? [];
    if (is_string($values)) {
        $decoded = json_decode($values, true);
        if (is_array($decoded)) $values = $decoded;
        else $values = preg_split('/\r\n|\r|\n|,/', $values) ?: [];
    }
    return is_array($values) ? $values : [];
}
function renderField(string $key, array $definition, string $value = ''): void {
    $title = fieldTitle($key, $definition);
    $type = fieldType($definition);
    $options = fieldOptions($definition);
    $required = !empty($definition['required']) ? ' required' : '';
    $name = 'entry[' . h($key) . ']';
    echo '<div class="form-group"><label for="field_' . h($key) . '">' . h($title) . '</label>';
    if (($definition['field_type'] ?? '') === 'objects') {
        $subfields = $definition['fieldkeyvalue'] ?? [];
        echo '<div class="object-fields">';
        foreach ($subfields as $subKey => $subDefinition) {
            if (!is_array($subDefinition)) $subDefinition = ['field-title' => (string)$subKey, 'default_value' => (string)$subDefinition];
            $subTitle = fieldTitle((string)$subKey, $subDefinition);
            $subType = fieldType($subDefinition);
            $subValue = (string)($subDefinition['default_value'] ?? '');
            echo '<label class="sub-label">' . h($subTitle) . '</label>';
            echo '<input type="text" name="' . $name . '[' . h((string)$subKey) . ']" value="' . h($subValue) . '"' . $required . '>';
        }
        echo '</div>';
    } elseif (in_array($type, ['textarea', 'text_area'], true)) {
        echo '<textarea id="field_' . h($key) . '" name="' . $name . '" rows="4"' . $required . '>' . h($value !== '' ? $value : ($definition['default_value'] ?? '')) . '</textarea>';
    } elseif (in_array($type, ['select', 'dropdown', 'radio'], true)) {
        echo '<select id="field_' . h($key) . '" name="' . $name . '"' . $required . '><option value="">Select ' . h($title) . '</option>';
        foreach ($options as $optionKey => $option) {
            $optionValue = is_array($option) ? (string)($option['value'] ?? $option['label'] ?? $optionKey) : (string)$option;
            $optionLabel = is_array($option) ? (string)($option['label'] ?? $optionValue) : (string)$option;
            echo '<option value="' . h($optionValue) . '">' . h($optionLabel) . '</option>';
        }
        echo '</select>';
    } elseif (in_array($type, ['checkbox', 'boolean', 'toggle'], true)) {
        echo '<select id="field_' . h($key) . '" name="' . $name . '"><option value="No">No</option><option value="Yes">Yes</option></select>';
    } elseif (in_array($type, ['number', 'date', 'time', 'email', 'url'], true)) {
        echo '<input id="field_' . h($key) . '" type="' . h($type) . '" name="' . $name . '" value="' . h($value !== '' ? $value : ($definition['default_value'] ?? '')) . '"' . $required . '>';
    } else {
        echo '<input id="field_' . h($key) . '" type="text" name="' . $name . '" value="' . h($value !== '' ? $value : ($definition['default_value'] ?? '')) . '"' . $required . '>';
    }
    if (!empty($definition['description'])) echo '<small class="help-text">' . h($definition['description']) . '</small>';
    echo '</div>';
}
function visibleTabFields(array $tab, array $fields): array {
    $keys = $tab['dynamic_fields_display'] ?? $tab['fields'] ?? array_keys($fields);
    if (!is_array($keys)) $keys = array_keys($fields);
    $result = [];
    foreach ($keys as $key) {
        if (isset($fields[$key]) && is_array($fields[$key])) $result[$key] = $fields[$key];
    }
    return $result;
}
function renderEntryForm(array $fields, array $tabs): void {
    $visibleTabs = [];
    foreach ($tabs as $index => $tab) {
        if (!is_array($tab) || (array_key_exists('display_tab', $tab) && empty($tab['display_tab']))) continue;
        $visibleTabs[] = ['index' => $index, 'tab' => $tab, 'fields' => visibleTabFields($tab, $fields)];
    }
    if (!$visibleTabs) {
        echo '<div class="empty-state"><strong>No project form has been configured yet.</strong><span>Please contact your management team.</span></div>';
        return;
    }
    echo '<form method="post" class="project-form" id="newProjectForm"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"><input type="hidden" name="create_project" value="1">';
    echo '<div class="form-intro"><span class="eyebrow">PROJECT WORKSPACE</span><h2>New Project</h2><p>Complete the fields configured by your management team.</p></div>';
    if (count($visibleTabs) > 1) {
        echo '<div class="section-picker" role="navigation" aria-label="Project sections">';
        foreach ($visibleTabs as $i => $item) {
            $label = (string)($item['tab']['tab_name'] ?? $item['tab']['name'] ?? ('Section ' . ($i + 1)));
            echo '<button type="button" class="section-button' . ($i === 0 ? ' active' : '') . '" data-section="section_' . $i . '">' . h($label) . '</button>';
        }
        echo '</div>';
    }
    foreach ($visibleTabs as $i => $item) {
        $tab = $item['tab'];
        $label = (string)($tab['tab_name'] ?? $tab['name'] ?? ('Section ' . ($i + 1)));
        echo '<section class="configured-section' . ($i === 0 ? ' active' : '') . '" id="section_' . $i . '">';
        if (!empty($tab['header'])) echo '<h3>' . h($tab['header']) . '</h3>';
        elseif (count($visibleTabs) > 1) echo '<h3>' . h($label) . '</h3>';
        if (!empty($tab['description'])) echo '<p class="section-description">' . h($tab['description']) . '</p>';
        if (!$item['fields']) {
            echo '<p class="muted">No fields are assigned to this section.</p>';
        } else {
            foreach ($item['fields'] as $key => $definition) renderField((string)$key, $definition);
        }
        echo '</section>';
    }
    // Hidden management-configured fields not assigned to a visible section are not user-editable or submitted.
    echo '<div class="form-actions"><button class="btn btn-primary" type="submit">Submit Project <span aria-hidden="true">→</span></button></div></form>';
}
function entryStatus(array $entry): string {
    $status = strtolower((string)($entry['status'] ?? 'pending'));
    return in_array($status, ['pending', 'completed', 'aborted'], true) ? $status : 'pending';
}
function entrySummary(array $entry, array $fields): string {
    foreach ($fields as $key => $definition) {
        if (isset($entry[$key]) && is_scalar($entry[$key]) && trim((string)$entry[$key]) !== '') {
            return (string)$entry[$key];
        }
    }
    foreach ($entry as $key => $value) {
        if (!in_array($key, ['status', 'operation_status', 'dynamic_values'], true) && is_scalar($value) && trim((string)$value) !== '') {
            return (string)$value;
        }
    }
    return 'Project';
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$loginError = '';
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $csrf = (string)($_POST['csrf'] ?? '');
    $identity = trim((string)($_POST['identity'] ?? ''));
    $plainPassword = (string)($_POST['password'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf'], $csrf)) {
        $loginError = 'Unable to verify this request. Refresh the page and try again.';
    } elseif ($identity === '' || $plainPassword === '') {
        $loginError = 'Incorrect login details. Please contact your management team.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id, email, username, password FROM users WHERE email = :email OR username = :username LIMIT 1');
            $stmt->execute([':email' => $identity, ':username' => $identity]);
            $user = $stmt->fetch();
            if ($user && isset($user['password']) && password_verify($plainPassword, (string)$user['password'])) {
                session_regenerate_id(true);
                $_SESSION['sceneiq_user_id'] = (int)$user['id'];
                $_SESSION['sceneiq_user_name'] = (string)($user['username'] ?: $user['email']);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            }
            $loginError = 'Incorrect login details. Please contact your management team.';
        } catch (Throwable $e) {
            error_log('SceneIQ login error: ' . $e->getMessage());
            $loginError = 'Unable to sign in right now. Please contact your management team.';
        }
    }
}

$userId = (int)($_SESSION['sceneiq_user_id'] ?? 0);
if ($userId > 0) {
    $stmt = $pdo->prepare('SELECT id, email, username FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $signedInUser = $stmt->fetch();
    if (!$signedInUser) {
        unset($_SESSION['sceneiq_user_id'], $_SESSION['sceneiq_user_name']);
        $userId = 0;
    }
}

$config = null;
$fields = [];
$tabs = [];
$entries = [];
$grouped = ['pending' => [], 'completed' => [], 'aborted' => []];
if ($userId > 0) {
    $config = getUserConfig($pdo, $userId);
    if ($config) {
        $fields = decodeArray($config['dynamic_fields']);
        $tabs = decodeArray($config['dynamic_tabs']);
        $entries = repairSettings(decodeArray($config['settings']));
    }
    foreach ($entries as $index => $entry) {
        if (!is_array($entry)) continue;
        $status = entryStatus($entry);
        $entry['_index'] = $index;
        $grouped[$status][] = $entry;
    }
}

if ($userId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $_SESSION['flash_error'] = 'Your session expired. Please refresh and try again.';
    } elseif (!$config) {
        $_SESSION['flash_error'] = 'No project configuration is available. Please contact your management team.';
    } else {
        $posted = $_POST['entry'] ?? [];
        if (!is_array($posted)) $posted = [];
        $allowed = [];
        foreach ($tabs as $tab) {
            if (!is_array($tab) || (array_key_exists('display_tab', $tab) && empty($tab['display_tab']))) continue;
            // Respect the management's submit_tab setting; users cannot change it.
            if (array_key_exists('submit_tab', $tab) && empty($tab['submit_tab'])) continue;
            foreach (visibleTabFields($tab, $fields) as $key => $definition) $allowed[(string)$key] = $definition;
        }
        $newEntry = [];
        foreach ($allowed as $key => $definition) {
            if (!array_key_exists($key, $posted)) continue;
            $value = $posted[$key];
            if (is_array($value)) {
                $clean = [];
                foreach ($value as $subKey => $subValue) {
                    if (is_scalar($subValue)) $clean[(string)$subKey] = trim((string)$subValue);
                }
                $newEntry[$key] = $clean;
            } elseif (is_scalar($value)) {
                $newEntry[$key] = trim((string)$value);
            }
        }
        if (!$newEntry) {
            $_SESSION['flash_error'] = 'Please complete the configured project fields before submitting.';
        } else {
            $newEntry['status'] = 'pending';
            $newEntry['operation_status'] = 'entry_created: New Project submitted by user';
            $entries[] = $newEntry;
            saveUserSettings($pdo, $userId, $entries, $config);
            $_SESSION['flash_success'] = 'Your project was submitted successfully.';
        }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?view=new');
    exit;
}

$view = (string)($_GET['view'] ?? 'home');
if (!in_array($view, ['home', 'new', 'pending', 'completed', 'aborted'], true)) $view = 'home';
$counts = [
    'new' => 0,
    'pending' => count($grouped['pending']),
    'completed' => count($grouped['completed']),
    'aborted' => count($grouped['aborted'])
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SceneIQ — User Dashboard</title>
<style>
:root{--teal:#00695c;--teal2:#00897b;--mint:#26a69a;--ink:#172033;--muted:#687386;--line:#e4eaf0;--surface:#fff;--bg:#f5f8fa;--shadow:0 8px 26px rgba(18,42,56,.07)}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;color:var(--ink);background:var(--bg)}button,input,textarea,select{font:inherit}button{cursor:pointer}
.header{min-height:68px;padding:13px 24px;background:linear-gradient(135deg,#00695c 0%,#00897b 50%,#26a69a 100%);color:#fff;display:flex;align-items:center;justify-content:space-between;gap:15px;box-shadow:0 4px 20px rgba(0,105,92,.22)}
.brand{font-size:19px;font-weight:750;letter-spacing:.2px}.brand small{display:block;font-size:11px;font-weight:500;letter-spacing:1.8px;opacity:.75;margin-top:2px}.header-right{display:flex;align-items:center;gap:14px;font-size:13px}.logout{color:#fff;text-decoration:none;border:1px solid #ffffff66;border-radius:9px;padding:8px 12px;background:#ffffff12}.logout:hover{background:#ffffff25}
.wrap{max-width:1320px;margin:0 auto;padding:28px 22px 46px}.welcome{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:24px}.welcome h1{margin:0 0 7px;font-size:27px;letter-spacing:-.5px}.welcome p{margin:0;color:var(--muted);font-size:14px;line-height:1.5}.eyebrow{font-size:10px;letter-spacing:1.7px;font-weight:800;color:var(--teal);display:block;margin-bottom:8px}
.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:25px}.stat-card{background:#fff;border:1px solid var(--line);border-radius:15px;padding:19px 20px;box-shadow:var(--shadow);text-decoration:none;color:inherit;display:block;transition:transform .16s,border-color .16s}.stat-card:hover{transform:translateY(-2px);border-color:#a8d8d1}.stat-top{display:flex;align-items:center;justify-content:space-between;gap:10px}.stat-label{font-size:13px;font-weight:650;color:#647084}.stat-icon{width:36px;height:36px;border-radius:11px;display:grid;place-items:center;font-size:18px}.stat-number{font-size:31px;font-weight:780;letter-spacing:-.8px;margin-top:15px}.stat-note{font-size:12px;color:#8a94a4;margin-top:3px}.icon-new{background:#e5f7f3;color:#087f75}.icon-pending{background:#fff4d9;color:#b77b0b}.icon-completed{background:#e5f8ee;color:#16865f}.icon-aborted{background:#fff0f0;color:#d94b4b}
.panel{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);overflow:hidden}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:19px 22px;border-bottom:1px solid var(--line)}.panel-head h2{margin:0;font-size:17px}.panel-head p{margin:5px 0 0;color:var(--muted);font-size:12px}.panel-body{padding:22px}.quick-links{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.quick-link{display:flex;align-items:center;gap:12px;text-decoration:none;color:var(--ink);padding:15px;border:1px solid var(--line);border-radius:12px;background:#fff}.quick-link:hover{border-color:#91cfc6;background:#fafffe}.quick-icon{width:38px;height:38px;border-radius:10px;background:#e5f7f3;color:var(--teal);display:grid;place-items:center;font-size:17px;flex-shrink:0}.quick-link strong{display:block;font-size:13px}.quick-link small{display:block;color:var(--muted);font-size:11px;margin-top:4px}
.btn{border:0;border-radius:9px;padding:11px 16px;font-weight:700;font-size:13px;display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none}.btn-primary{color:#fff;background:linear-gradient(135deg,#00695c,#00897b);box-shadow:0 4px 10px #00695c24}.btn-primary:hover{filter:brightness(1.05)}.btn-light{color:#425064;background:#f2f5f8;border:1px solid var(--line)}
.form-intro{margin-bottom:22px}.form-intro h2{font-size:22px;margin:0 0 6px}.form-intro p{margin:0;color:var(--muted);font-size:13px}.project-form{max-width:100%}.section-picker{display:flex;flex-wrap:wrap;gap:8px;padding:10px;margin:0 0 18px;border:1px solid #dce5ed;border-radius:12px;background:#f7f9fc}.section-button{padding:9px 14px;border:1px solid #d9e2eb;border-radius:9px;background:#fff;color:#46566b;font-size:13px;font-weight:650}.section-button.active{border-color:#087f75;background:#e5f7f3;color:#075e57}.configured-section{display:none;padding:19px;border:1px solid #dce5ed;border-radius:13px;background:#fff}.configured-section.active{display:block}.configured-section h3{margin:0 0 16px;font-size:16px}.section-description{font-size:13px;color:var(--muted);margin:-8px 0 18px;line-height:1.5}.form-group{margin-bottom:17px}.form-group:last-child{margin-bottom:0}.form-group>label{display:block;margin-bottom:7px;font-size:13px;font-weight:650;color:#334155}.form-group input,.form-group select,.form-group textarea{width:100%;min-width:0;padding:11px 12px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#172033;font-size:14px;outline:none}.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:#0f8b80;box-shadow:0 0 0 3px #0f8b8017}.form-group textarea{resize:vertical}.object-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:13px;background:#f8fafc;border:1px solid var(--line);border-radius:10px}.sub-label{display:block;font-size:12px;color:#536176;margin-bottom:5px}.help-text{display:block;margin-top:6px;color:var(--muted);font-size:11px}.form-actions{display:flex;justify-content:flex-end;margin-top:20px}.empty-state{padding:35px 18px;text-align:center;border:1px dashed #cbd5e1;border-radius:12px;color:var(--muted);display:flex;flex-direction:column;gap:7px}.empty-state strong{color:#334155}.muted{color:var(--muted);font-size:13px}
.alert{border-radius:10px;padding:12px 15px;margin-bottom:18px;font-size:13px}.alert-success{background:#e9f9ef;border:1px solid #bce8ca;color:#17633a}.alert-error{background:#fff0f0;border:1px solid #f2c3c3;color:#a82f2f}
.project-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.project-card{border:1px solid var(--line);border-radius:12px;padding:17px;background:#fff;min-width:0}.project-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.project-title{font-size:14px;font-weight:750;overflow-wrap:anywhere}.project-status{display:inline-flex;padding:5px 9px;border-radius:99px;font-size:10px;font-weight:800;text-transform:capitalize;white-space:nowrap}.status-pending{color:#9a6808;background:#fff4d8}.status-completed{color:#14764f;background:#e5f8ee}.status-aborted{color:#b83b3b;background:#fff0f0}.project-preview{font-size:12px;color:#687386;line-height:1.55;margin:11px 0;overflow-wrap:anywhere}.project-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:13px}.copy-btn{border:1px solid #d8e1e9;background:#f8fafc;color:#425064;border-radius:8px;padding:8px 11px;font-size:12px;font-weight:700}.copy-btn:hover{background:#e5f7f3;border-color:#9bd6cd;color:#075e57}.copy-btn.copied{background:#dcfce7;border-color:#16a34a;color:#166534}.details{display:none;margin-top:13px;border-top:1px solid var(--line);padding-top:12px}.details.open{display:block}.detail-row{padding:7px 0;border-bottom:1px solid #f0f3f6;font-size:12px;display:grid;grid-template-columns:minmax(90px, .7fr) minmax(0,1.3fr);gap:10px}.detail-row:last-child{border-bottom:0}.detail-key{font-weight:700;color:#536176;overflow-wrap:anywhere}.detail-value{white-space:pre-wrap;overflow-wrap:anywhere;color:#253247}.empty-list{text-align:center;padding:35px 15px;color:var(--muted);font-size:13px}
.login-page{min-height:100vh;display:grid;grid-template-columns:minmax(0,1.05fr) minmax(360px,.95fr);background:#fff}.login-brand-panel{background:linear-gradient(145deg,#00594f,#00796b 58%,#26a69a);color:#fff;padding:clamp(35px,7vw,100px);display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden}.login-brand-panel:after{content:"";position:absolute;width:460px;height:460px;border:1px solid #ffffff20;border-radius:50%;right:-160px;bottom:-190px;box-shadow:0 0 0 55px #ffffff08,0 0 0 110px #ffffff05}.login-brand-panel h1{font-size:clamp(32px,4vw,54px);line-height:1.08;letter-spacing:-1.7px;margin:55px 0 17px;max-width:500px;position:relative;z-index:1}.login-brand-panel p{max-width:430px;line-height:1.8;color:#e0f2ef;font-size:15px;position:relative;z-index:1}.login-brand-panel .brand{font-size:22px}.login-foot{font-size:11px;letter-spacing:1px;color:#c5e5e0;position:relative;z-index:1}.login-form-side{display:flex;align-items:center;justify-content:center;padding:35px}.login-box{width:100%;max-width:410px}.login-box .eyebrow{margin-bottom:12px}.login-box h2{font-size:29px;letter-spacing:-.7px;margin:0 0 9px}.login-subtitle{font-size:13px;line-height:1.6;color:var(--muted);margin:0 0 28px}.login-box .form-group{margin-bottom:18px}.login-box .form-group input{padding:13px}.login-box .btn{width:100%;padding:13px;margin-top:7px}.login-help{margin-top:20px;text-align:center;color:#788496;font-size:12px;line-height:1.6}
@media(max-width:900px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.login-page{grid-template-columns:1fr}.login-brand-panel{min-height:250px;padding:28px 25px}.login-brand-panel h1{font-size:34px;margin:28px 0 10px}.login-brand-panel p{margin:0}.login-foot{margin-top:30px}.login-form-side{padding:35px 22px 50px}}
@media(max-width:650px){.header{padding:12px 15px}.brand{font-size:17px}.header-right{font-size:11px;gap:8px}.wrap{padding:22px 13px 35px}.welcome{align-items:flex-start;flex-direction:column}.welcome h1{font-size:24px}.cards{gap:10px}.stat-card{padding:14px}.stat-number{font-size:27px}.stat-icon{width:31px;height:31px}.quick-links,.project-list{grid-template-columns:1fr}.panel-head{padding:16px}.panel-body{padding:15px}.configured-section{padding:14px}.object-fields{grid-template-columns:1fr}.detail-row{grid-template-columns:1fr;gap:4px}.section-picker{padding:8px}.section-button{flex:1 1 auto}}
</style>
</head>
<body>
<?php if ($userId <= 0): ?>
<div class="login-page">
    <section class="login-brand-panel">
        <div class="brand">SceneIQ<small>PROJECT WORKSPACE</small></div>
        <div>
            <span class="eyebrow" style="color:#c9eee8">YOUR WORKSPACE, IN ONE PLACE</span>
            <h1>Welcome back to your projects.</h1>
            <p>Sign in to access your assigned projects, submit new work, and track progress from one simple workspace.</p>
        </div>
        <div class="login-foot">SECURE USER ACCESS · SCENEIQ</div>
    </section>
    <section class="login-form-side">
        <div class="login-box">
            <span class="eyebrow">USER SIGN IN</span>
            <h2>Log in to SceneIQ</h2>
            <p class="login-subtitle">Use the login details provided by your management team.</p>
            <?php if ($loginError): ?><div class="alert alert-error"><?=h($loginError)?></div><?php endif; ?>
            <form method="post" autocomplete="on">
                <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                <input type="hidden" name="login" value="1">
                <div class="form-group">
                    <label for="identity">Email or username</label>
                    <input id="identity" name="identity" type="text" autocomplete="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <button class="btn btn-primary" type="submit">Log In <span aria-hidden="true">→</span></button>
            </form>
            <p class="login-help">Having trouble signing in?<br>Contact your management team. Account creation is managed by your organisation.</p>
        </div>
    </section>
</div>
<?php else: ?>
<header class="header">
    <div class="brand">SceneIQ<small>USER WORKSPACE</small></div>
    <div class="header-right"><span><?=h($signedInUser['username'] ?: $signedInUser['email'])?></span><a class="logout" href="?logout=1">Log out ↗</a></div>
</header>
<main class="wrap">
    <?php if ($flashSuccess): ?><div class="alert alert-success"><?=h($flashSuccess)?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-error"><?=h($flashError)?></div><?php endif; ?>
    <div class="welcome">
        <div><span class="eyebrow">YOUR WORKSPACE</span><h1><?= $view === 'home' ? 'Project dashboard' : h(['new'=>'New Project','pending'=>'Pending Projects','completed'=>'Completed Projects','aborted'=>'Aborted Projects'][$view] ?? 'Project dashboard') ?></h1><p>Manage your project submissions and check their current status.</p></div>
        <?php if ($view !== 'home'): ?><a class="btn btn-light" href="?">← Dashboard</a><?php endif; ?>
    </div>
    <div class="cards">
        <a class="stat-card" href="?view=new"><div class="stat-top"><span class="stat-label">New Project</span><span class="stat-icon icon-new">✦</span></div><div class="stat-number">＋</div><div class="stat-note">Start a new submission</div></a>
        <a class="stat-card" href="?view=pending"><div class="stat-top"><span class="stat-label">Pending</span><span class="stat-icon icon-pending">◷</span></div><div class="stat-number"><?=count($grouped['pending'])?></div><div class="stat-note">Awaiting action</div></a>
        <a class="stat-card" href="?view=completed"><div class="stat-top"><span class="stat-label">Completed</span><span class="stat-icon icon-completed">✓</span></div><div class="stat-number"><?=count($grouped['completed'])?></div><div class="stat-note">Finished projects</div></a>
        <a class="stat-card" href="?view=aborted"><div class="stat-top"><span class="stat-label">Aborted</span><span class="stat-icon icon-aborted">×</span></div><div class="stat-number"><?=count($grouped['aborted'])?></div><div class="stat-note">Stopped or cancelled</div></a>
    </div>
    <?php if ($view === 'home'): ?>
    <section class="panel"><div class="panel-head"><div><h2>Quick navigation</h2><p>Open a workspace section to continue.</p></div></div><div class="panel-body"><div class="quick-links">
        <a class="quick-link" href="?view=new"><span class="quick-icon">✦</span><span><strong>New Project</strong><small>Submit a project using the configured form</small></span></a>
        <a class="quick-link" href="?view=pending"><span class="quick-icon" style="background:#fff4d9;color:#b77b0b">◷</span><span><strong>Pending</strong><small>Review your pending submissions</small></span></a>
        <a class="quick-link" href="?view=completed"><span class="quick-icon" style="background:#e5f8ee;color:#16865f">✓</span><span><strong>Completed</strong><small>View completed projects and copy details</small></span></a>
        <a class="quick-link" href="?view=aborted"><span class="quick-icon" style="background:#fff0f0;color:#d94b4b">×</span><span><strong>Aborted</strong><small>View aborted projects</small></span></a>
    </div></div></section>
    <?php elseif ($view === 'new'): ?>
    <section class="panel"><div class="panel-head"><div><h2>New Project</h2><p>The form and sections below are configured by management.</p></div></div><div class="panel-body"><?php renderEntryForm($fields, $tabs); ?></div></section>
    <?php else: $list = $grouped[$view] ?? []; ?>
    <section class="panel"><div class="panel-head"><div><h2><?=h(ucfirst($view))?> projects</h2><p><?=count($list)?> project(s) in this section. You can copy project details for your workflow.</p></div></div><div class="panel-body">
    <?php if (!$list): ?><div class="empty-list">No <?=h($view)?> projects found.</div><?php else: ?><div class="project-list">
        <?php foreach ($list as $entry): $index=(int)$entry['_index']; $summary=entrySummary($entry,$fields); $copyData=[]; foreach($entry as $key=>$value){if(in_array($key,['status','operation_status','_index','_user','_user_id'],true))continue;$copyData[$key]=$value;} $copyText=''; foreach($copyData as $key=>$value){$label=fieldTitle((string)$key,$fields[$key]??[]);$copyText.=$label.': '.(is_array($value)?json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):(string)$value)."\n";} ?>
        <article class="project-card">
            <div class="project-card-top"><div class="project-title"><?=h($summary)?></div><span class="project-status status-<?=h($view)?>"><?=h($view)?></span></div>
            <div class="project-preview"><?php $preview=trim($copyText); echo h(function_exists('mb_strimwidth') ? mb_strimwidth($preview,0,190,'…','UTF-8') : (strlen($preview) > 190 ? substr($preview,0,187).'...' : $preview)); ?></div>
            <div class="project-actions"><button class="copy-btn" type="button" data-copy="<?=h($copyText)?>">Copy details</button><button class="copy-btn" type="button" onclick="document.getElementById('details-<?= $index ?>').classList.toggle('open')">View details</button></div>
            <div class="details" id="details-<?= $index ?>"><?php foreach($copyData as $key=>$value): ?><div class="detail-row"><div class="detail-key"><?=h(fieldTitle((string)$key,$fields[$key]??[]))?></div><div class="detail-value"><?=h(is_array($value)?json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):(string)$value)?></div></div><?php endforeach; ?></div>
        </article>
        <?php endforeach; ?>
    </div><?php endif; ?>
    </div></section>
    <?php endif; ?>
    <div style="text-align:center;color:#98a2b1;font-size:11px;margin-top:24px">SceneIQ · User workspace</div>
</main>
<?php endif; ?>
<script>
document.querySelectorAll('.section-button').forEach(function(button){
  button.addEventListener('click',function(){
    document.querySelectorAll('.section-button').forEach(function(b){b.classList.toggle('active',b===button)});
    document.querySelectorAll('.configured-section').forEach(function(section){section.classList.toggle('active',section.id===button.dataset.section)});
  });
});
async function sceneIQCopy(text,button){
  try{
    if(navigator.clipboard && window.isSecureContext){await navigator.clipboard.writeText(text);}
    else{
      const area=document.createElement('textarea');area.value=text;area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();
      const ok=document.execCommand('copy');area.remove();if(!ok)throw new Error('Copy failed');
    }
    const old=button.textContent;button.textContent='Copied ✓';button.classList.add('copied');
    setTimeout(function(){button.textContent=old;button.classList.remove('copied')},1600);
  }catch(e){window.prompt('Copy the project details below:',text);}
}
document.querySelectorAll('[data-copy]').forEach(function(button){button.addEventListener('click',function(){sceneIQCopy(button.dataset.copy,button)})});
</script>
</body>
</html>

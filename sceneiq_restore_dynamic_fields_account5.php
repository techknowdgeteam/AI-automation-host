<?php
/**
 * SceneIQ one-time Dynamic Content recovery for account ID 5 / config ID 15.
 *
 * SAFE BEHAVIOUR:
 * - GET only inspects and validates the stored envelope. It never writes.
 * - Recovery is enabled only if Base64 decoding, decompression, original byte
 *   count, SHA-256 checksum and JSON parsing all succeed.
 * - Before updating dynamic_fields, the exact old envelope is copied to a
 *   dedicated backup table in the same database transaction.
 * - Only sceneiq_config.dynamic_fields for user_id=5/config_id=15 is changed.
 * - settings, dynamic_tabs and all other accounts are untouched.
 *
 * Remove this file from the web directory immediately after recovery.
 */

session_start();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

error_reporting(E_ALL);
ini_set('display_errors', '0');

// These connection details are taken from the supplied SceneIQ server script.
// Rotate the database password after completing recovery.
$host = 'sql201.infinityfree.com';
$dbname = 'if0_40367004_automation_tree';
$username = 'if0_40367004';
$password = 'NkwFAH15FRIlvCf';

$targetUserId = 5;
$targetConfigId = 15;
$targetEmail = 'avatarcharacter@gmail.com';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sceneiqDecodeStoragePayload(string $payload): array {
    $binary = base64_decode($payload, true);
    if ($binary === false) {
        throw new RuntimeException('The payload is not valid Base64. Nothing was changed.');
    }

    $plain = false;
    $method = '';

    // The supplied payload preview decodes to 78 9C..., which is a zlib
    // wrapper (normally produced by gzcompress), not a GZIP 1F 8B header.
    if (function_exists('gzuncompress')) {
        $candidate = @gzuncompress($binary);
        if ($candidate !== false) {
            $plain = $candidate;
            $method = 'zlib/gzuncompress';
        }
    }

    // Fallbacks are tried only if the primary zlib decoder does not work.
    if ($plain === false && function_exists('gzdecode')) {
        $candidate = @gzdecode($binary);
        if ($candidate !== false) {
            $plain = $candidate;
            $method = 'gzip/gzdecode';
        }
    }

    if ($plain === false && function_exists('gzinflate')) {
        $candidate = @gzinflate($binary);
        if ($candidate !== false) {
            $plain = $candidate;
            $method = 'raw-deflate/gzinflate';
        }
    }

    // Last fallback: strip a zlib header and Adler-32 trailer, then inflate.
    if ($plain === false && strlen($binary) > 6 && function_exists('gzinflate')) {
        $candidate = @gzinflate(substr($binary, 2, -4));
        if ($candidate !== false) {
            $plain = $candidate;
            $method = 'zlib-body/gzinflate';
        }
    }

    if ($plain === false) {
        throw new RuntimeException('Base64 decoded, but no supported decompressor could unpack the payload. Nothing was changed.');
    }

    return ['plain' => $plain, 'method' => $method, 'compressed_bytes' => strlen($binary)];
}

function inspectSceneIQEnvelope(array $row): array {
    $raw = (string)($row['dynamic_fields'] ?? '');
    $envelope = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($envelope)) {
        throw new RuntimeException('dynamic_fields is not a valid JSON object. Nothing was changed.');
    }

    if (($envelope['__sceneiq_storage'] ?? null) !== 'gzip-json-v1') {
        throw new RuntimeException('The expected gzip-json-v1 storage marker was not found. Nothing was changed.');
    }

    $expectedBytes = filter_var($envelope['original_bytes'] ?? null, FILTER_VALIDATE_INT);
    $expectedHash = strtolower((string)($envelope['sha256'] ?? ''));
    $payload = $envelope['payload'] ?? null;

    if ($expectedBytes === false || $expectedBytes <= 0) {
        throw new RuntimeException('The original_bytes metadata is missing or invalid. Nothing was changed.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
        throw new RuntimeException('The SHA-256 metadata is missing or invalid. Nothing was changed.');
    }
    if (!is_string($payload) || $payload === '') {
        throw new RuntimeException('The storage payload is missing or is not a string. Nothing was changed.');
    }

    $decoded = sceneiqDecodeStoragePayload($payload);
    $plain = $decoded['plain'];
    $actualBytes = strlen($plain);
    $actualHash = hash('sha256', $plain);

    if ($actualBytes !== (int)$expectedBytes) {
        throw new RuntimeException(
            'Decompression worked, but the recovered byte count (' . $actualBytes .
            ') does not match original_bytes (' . $expectedBytes . '). Nothing was changed.'
        );
    }

    if (!hash_equals($expectedHash, $actualHash)) {
        throw new RuntimeException('Decompression worked, but SHA-256 does not match the stored checksum. Nothing was changed.');
    }

    try {
        $recovered = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('The checksum is valid, but the recovered payload is not valid JSON: ' . $e->getMessage() . '. Nothing was changed.');
    }

    if (!is_array($recovered)) {
        throw new RuntimeException('Recovered JSON is not an array/object of Dynamic Content definitions. Nothing was changed.');
    }
    if (isset($recovered['__sceneiq_storage'])) {
        throw new RuntimeException('The payload still contains a storage wrapper instead of the original definitions. Nothing was changed.');
    }
    if (count($recovered) === 0) {
        throw new RuntimeException('Recovered JSON is empty. Nothing was changed.');
    }

    return [
        'raw_envelope' => $raw,
        'plain_json' => $plain,
        'definitions' => $recovered,
        'method' => $decoded['method'],
        'compressed_bytes' => $decoded['compressed_bytes'],
        'original_bytes' => $actualBytes,
        'sha256' => $actualHash,
        'field_count' => count($recovered),
        'field_names' => array_map('strval', array_keys($recovered)),
    ];
}

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (Throwable $e) {
    http_response_code(500);
    exit('<h2>Database connection failed</h2><p>' . h($e->getMessage()) . '</p>');
}

// Require the same browser session to have account 5 selected in SceneIQ.
if ((int)($_SESSION['selected_user_id'] ?? 0) !== $targetUserId) {
    http_response_code(403);
    exit('<h2>Recovery not authorized</h2><p>Open SceneIQ in this same browser, select avatarcharacter@gmail.com, then open this recovery page in another tab.</p>');
}

if (empty($_SESSION['sceneiq_recovery_csrf'])) {
    $_SESSION['sceneiq_recovery_csrf'] = bin2hex(random_bytes(32));
}

try {
    $select = $pdo->prepare(
        'SELECT c.id, c.user_id, c.dynamic_fields, u.email
         FROM sceneiq_config c
         INNER JOIN users u ON u.id = c.user_id
         WHERE c.id = :config_id AND c.user_id = :user_id
         LIMIT 1'
    );
    $select->execute([':config_id' => $targetConfigId, ':user_id' => $targetUserId]);
    $row = $select->fetch(PDO::FETCH_ASSOC);

    if (!$row || strtolower((string)$row['email']) !== strtolower($targetEmail)) {
        throw new RuntimeException('The expected user/configuration record could not be verified. No data was changed.');
    }

    $inspection = inspectSceneIQEnvelope($row);
} catch (Throwable $e) {
    $inspection = null;
    $inspectionError = $e->getMessage();
}

$completed = false;
$completionMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recover_dynamic_fields'])) {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['sceneiq_recovery_csrf'], $postedToken)) {
        $inspectionError = 'Security token expired or invalid. Refresh this page and inspect again.';
        $inspection = null;
    } elseif (!$inspection) {
        $inspectionError = $inspectionError ?? 'Pre-recovery validation failed. Nothing was changed.';
    } else {
        try {
            // Create the backup table before opening the data transaction. MySQL/MariaDB
            // can implicitly commit when executing CREATE TABLE, so DDL must stay outside
            // the transaction that protects the backup insert and configuration update.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS `sceneiq_config_storage_backups` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `config_id` BIGINT NOT NULL,
                    `user_id` BIGINT NOT NULL,
                    `column_name` VARCHAR(64) NOT NULL,
                    `original_value` LONGTEXT NOT NULL,
                    `original_sha256` CHAR(64) NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_sceneiq_storage_backup` (`config_id`, `user_id`, `column_name`, `original_sha256`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            // Re-read and lock the exact row before making any change.
            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                'SELECT c.id, c.user_id, c.dynamic_fields, u.email
                 FROM sceneiq_config c
                 INNER JOIN users u ON u.id = c.user_id
                 WHERE c.id = :config_id AND c.user_id = :user_id
                 LIMIT 1 FOR UPDATE'
            );
            $lock->execute([':config_id' => $targetConfigId, ':user_id' => $targetUserId]);
            $lockedRow = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$lockedRow || strtolower((string)$lockedRow['email']) !== strtolower($targetEmail)) {
                throw new RuntimeException('The target account/configuration changed. No data was changed.');
            }

            $lockedInspection = inspectSceneIQEnvelope($lockedRow);

            // The exact original JSON envelope is now backed up inside this
            // transaction; if backup insertion or verification fails, nothing changes.
            $backupHash = hash('sha256', $lockedInspection['raw_envelope']);
            $backup = $pdo->prepare(
                'INSERT IGNORE INTO `sceneiq_config_storage_backups`
                    (`config_id`, `user_id`, `column_name`, `original_value`, `original_sha256`)
                 VALUES (:config_id, :user_id, :column_name, :original_value, :original_sha256)'
            );
            $backup->execute([
                ':config_id' => $targetConfigId,
                ':user_id' => $targetUserId,
                ':column_name' => 'dynamic_fields',
                ':original_value' => $lockedInspection['raw_envelope'],
                ':original_sha256' => $backupHash,
            ]);

            $verifyBackup = $pdo->prepare(
                'SELECT `original_value`, `original_sha256`
                 FROM `sceneiq_config_storage_backups`
                 WHERE `config_id` = :config_id AND `user_id` = :user_id
                   AND `column_name` = :column_name AND `original_sha256` = :original_sha256
                 LIMIT 1'
            );
            $verifyBackup->execute([
                ':config_id' => $targetConfigId,
                ':user_id' => $targetUserId,
                ':column_name' => 'dynamic_fields',
                ':original_sha256' => $backupHash,
            ]);
            $backupRow = $verifyBackup->fetch(PDO::FETCH_ASSOC);
            if (!$backupRow || !hash_equals($backupHash, hash('sha256', (string)$backupRow['original_value']))) {
                throw new RuntimeException('The original storage envelope could not be backed up and verified. Recovery aborted.');
            }

            // Store the exact verified original JSON bytes, not a normalized or
            // re-created structure. settings and dynamic_tabs are not touched.
            $update = $pdo->prepare(
                'UPDATE `sceneiq_config`
                 SET `dynamic_fields` = :dynamic_fields
                 WHERE `id` = :config_id AND `user_id` = :user_id'
            );
            $update->bindValue(':dynamic_fields', $lockedInspection['plain_json'], PDO::PARAM_STR);
            $update->bindValue(':config_id', $targetConfigId, PDO::PARAM_INT);
            $update->bindValue(':user_id', $targetUserId, PDO::PARAM_INT);
            $update->execute();

            $verify = $pdo->prepare(
                'SELECT `dynamic_fields` FROM `sceneiq_config`
                 WHERE `id` = :config_id AND `user_id` = :user_id LIMIT 1'
            );
            $verify->execute([':config_id' => $targetConfigId, ':user_id' => $targetUserId]);
            $savedJson = (string)$verify->fetchColumn();
            if (!hash_equals($lockedInspection['sha256'], hash('sha256', $savedJson))) {
                throw new RuntimeException('Post-save verification failed. The transaction will be rolled back.');
            }

            $pdo->commit();
            $completed = true;
            $completionMessage = 'Recovery completed. The original Dynamic Content JSON has been restored and verified. The old compressed envelope is preserved in sceneiq_config_storage_backups.';
            $_SESSION['sceneiq_recovery_csrf'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $inspectionError = 'Recovery aborted safely: ' . $e->getMessage();
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SceneIQ Dynamic Content Recovery</title>
<style>
body{font-family:Segoe UI,Arial,sans-serif;background:#f3f6f8;color:#17212b;margin:0;padding:24px}
main{max-width:820px;margin:0 auto;background:#fff;border:1px solid #dbe3e8;border-radius:14px;padding:24px;box-shadow:0 8px 28px rgba(0,0,0,.06)}
h1{font-size:22px;margin:0 0 8px}p{line-height:1.55}.muted{color:#536471}.ok{background:#ecfdf5;border:1px solid #86efac;padding:14px;border-radius:9px;color:#166534}.bad{background:#fff1f2;border:1px solid #fda4af;padding:14px;border-radius:9px;color:#9f1239}.warn{background:#fffbeb;border:1px solid #fcd34d;padding:14px;border-radius:9px;color:#92400e}table{width:100%;border-collapse:collapse;margin:16px 0}td{padding:9px;border-bottom:1px solid #e5e7eb;vertical-align:top}td:first-child{font-weight:600;width:220px}code{overflow-wrap:anywhere}button{background:#087f5b;color:white;border:0;padding:12px 18px;border-radius:8px;font-weight:700;cursor:pointer}button:hover{background:#066b4d}.danger-note{font-size:13px;color:#596773;margin-top:18px}
</style>
</head>
<body>
<main>
<h1>SceneIQ Dynamic Content Recovery</h1>
<p class="muted">Target: avatarcharacter@gmail.com · user ID 5 · configuration ID 15</p>
<div class="warn"><strong>Do not close this page during recovery.</strong> The tool only changes <code>sceneiq_config.dynamic_fields</code> for this account. It does not change project settings, Dynamic Tabs, or other accounts.</div>

<?php if ($completed): ?>
    <div class="ok"><strong>Success:</strong> <?= h($completionMessage) ?></div>
    <p>Recovered <?= (int)$inspection['field_count'] ?> top-level Dynamic Content definition(s).</p>
    <p><strong>Recovered keys:</strong> <?= h(implode(', ', $inspection['field_names'])) ?></p>
    <p><strong>Next:</strong> Remove this recovery PHP file from your hosting directory, then reload SceneIQ and open Dynamic Content for this account.</p>
<?php elseif (isset($inspectionError)): ?>
    <div class="bad"><strong>Recovery not performed:</strong> <?= h($inspectionError) ?></div>
<?php elseif ($inspection): ?>
    <div class="ok"><strong>Pre-recovery validation passed.</strong> The original payload decompressed successfully, its byte count and SHA-256 checksum match the stored metadata, and the recovered content is valid JSON.</div>
    <table>
      <tr><td>Decompression method</td><td><?= h($inspection['method']) ?></td></tr>
      <tr><td>Compressed binary bytes</td><td><?= (int)$inspection['compressed_bytes'] ?></td></tr>
      <tr><td>Recovered original bytes</td><td><?= (int)$inspection['original_bytes'] ?></td></tr>
      <tr><td>Verified SHA-256</td><td><code><?= h($inspection['sha256']) ?></code></td></tr>
      <tr><td>Top-level definitions</td><td><?= (int)$inspection['field_count'] ?></td></tr>
      <tr><td>Recovered keys</td><td><?= h(implode(', ', $inspection['field_names'])) ?></td></tr>
    </table>
    <form method="post" onsubmit="return confirm('Restore the verified original Dynamic Content data for avatarcharacter@gmail.com? The original compressed envelope will first be backed up in the database.');">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['sceneiq_recovery_csrf']) ?>">
      <button type="submit" name="recover_dynamic_fields" value="1">Back up and restore original Dynamic Content</button>
    </form>
<?php else: ?>
    <div class="bad">No recovery inspection result is available. Refresh the page after selecting the target account in SceneIQ.</div>
<?php endif; ?>

<p class="danger-note">This is a one-time recovery tool. Delete <code>sceneiq_restore_dynamic_fields_account5.php</code> from the server immediately after use. Rotate the database password after recovery because it was present in the supplied source file.</p>
</main>
</body>
</html>

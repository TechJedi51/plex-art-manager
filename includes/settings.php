<?php
declare(strict_types=1);

function get_all_settings(): array
{
    $rows = get_db()->query('SELECT key, value FROM settings')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[$row['key']] = $row['value'];
    }
    return $out;
}

function get_setting(string $key, ?string $default = null): ?string
{
    $stmt = get_db()->prepare('SELECT value FROM settings WHERE key = :k');
    $stmt->execute(['k' => $key]);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : $val;
}

function set_setting(string $key, string $value): void
{
    $stmt = get_db()->prepare('
        INSERT INTO settings (key, value) VALUES (:k, :v)
        ON CONFLICT(key) DO UPDATE SET value = :v
    ');
    $stmt->execute(['k' => $key, 'v' => $value]);
}

function set_settings(array $pairs): void
{
    $db = get_db();
    $db->beginTransaction();
    try {
        foreach ($pairs as $k => $v) {
            set_setting((string) $k, (string) $v);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** Settings keys that should never be echoed back in full to the browser. */
const SECRET_SETTINGS = ['plex_token', 'fanart_api_key', 'tmdb_api_key'];

/** Mask secrets for display: keep the last 4 characters so the user can confirm which key is saved. */
function mask_secret(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $len = strlen($value);
    if ($len <= 4) {
        return str_repeat('•', $len);
    }
    return str_repeat('•', $len - 4) . substr($value, -4);
}

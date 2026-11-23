<?php
/** Запись в Google-таблицы: маркетинговая «выгрузка расходов» и журнал выплат. */
declare(strict_types=1);

function g_token(): string
{
    $cached = kv_get('g_token', 3000);
    if ($cached) return $cached;
    $file = __DIR__ . '/google-key.json';
    if (!is_file($file)) throw new RuntimeException('Нет ключа сервисного аккаунта Google');
    $key = json_decode(file_get_contents($file), true);
    $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud'   => $key['token_uri'],
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    openssl_sign($unsigned, $sig, $key['private_key'], 'sha256WithRSAEncryption');
    $r = http_json('POST', $key['token_uri'], [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $unsigned . '.' . $b64($sig),
    ], null, true);
    kv_set('g_token', $r['access_token']);
    return $r['access_token'];
}

function sheet_append(string $spreadsheetId, string $range, array $rows): void
{
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . $spreadsheetId . '/values/'
         . rawurlencode($range) . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';
    http_json('POST', $url, ['values' => $rows], g_token());
}

/** Журнал: каждая заявка на выплату с выбранным способом — для статистики маркетинга */
function sheet_log_payout(array $row): void
{
    $cfg = cfg('marketing');
    if (empty($cfg['log_sheet_id'])) return;
    try {
        sheet_append($cfg['log_sheet_id'], $cfg['log_range'], [$row]);
    } catch (Throwable $e) {
        error_log('[sheets] ' . $e->getMessage());
    }
}

<?php
/**
 * Доставка уведомлений через чат-боты LeadTeh (телеграм и MAX).
 * Push доходит только до тех, кто его разрешил, а в боте человек уже подписан —
 * поэтому бот остаётся каналом доставки, а портал кабинетом.
 *
 * Лимиты LeadTeh: 2 запроса в секунду на API, телеграм — 10 сообщений за 10 секунд.
 */
declare(strict_types=1);

function lt_api(string $method, array $params = [], bool $post = false): array
{
    $cfg = cfg('leadteh');
    if (($cfg['token'] ?? '') === '') throw new RuntimeException('Не настроен ключ LeadTeh');
    $url = rtrim($cfg['base'], '/') . '/' . $method . '?api_token=' . urlencode($cfg['token']);
    if (!$post && $params) $url .= '&' . http_build_query($params);

    $headers = ['X-Requested-With: XMLHttpRequest'];
    $ch = curl_init($url);
    if ($post) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('LeadTeh недоступен: ' . $err);
    if ($code < 200 || $code >= 300) throw new RuntimeException('LeadTeh ' . $code . ': ' . substr((string)$body, 0, 200));
    return json_decode((string)$body, true) ?: [];
}

/** Подтягиваем контакты ботов: нам нужны телефон, id контакта и мессенджер */
function lt_sync_contacts(?int $sinceTs = null): int
{
    $n = 0;
    $page = 1;
    $last = 1;
    do {
        $params = ['count' => 500, 'page' => $page];
        if ($sinceTs) $params['date_from'] = $sinceTs;
        $r = lt_api('getContacts', $params);
        $rows = $r['data'] ?? [];
        $last = (int)($r['meta']['last_page'] ?? $last);
        foreach ($rows as $c) {
            $phone = norm_phone((string)($c['phone'] ?? ''));
            if (strlen($phone) !== 11) continue;
            q('INSERT INTO leadteh_contacts (contact_id, phone, bot_id, messenger, name, created_at)
               VALUES (:id, :p, :b, :m, :n, :c)
               ON CONFLICT(contact_id) DO UPDATE SET phone = :p, bot_id = :b, messenger = :m, name = :n', [
                'id' => (int)$c['id'], 'p' => $phone, 'b' => (int)($c['bot_id'] ?? 0),
                'm' => (string)($c['messenger'] ?? ''), 'n' => (string)($c['name'] ?? ''),
                'c' => (string)($c['created_at'] ?? ''),
            ]);
            $n++;
        }
        $page++;
        usleep(600000);   // не больше двух запросов в секунду
    } while ($page <= $last && $page <= 60);
    kv_set('leadteh_sync', ['at' => date('c'), 'rows' => $n]);
    return $n;
}

/** Куда писать человеку: телеграм важнее MAX, из нескольких берём свежий */
function lt_contact(string $phone): ?array
{
    // сначала бот реферальной программы, потом остальные: человек должен получать
    // сообщения оттуда же, откуда получал раньше, а не из случайного бота автошколы
    $order = '';
    foreach (array_values(cfg('leadteh')['bots'] ?? []) as $i => $bot) {
        $order .= ' WHEN ' . (int)$bot . ' THEN ' . $i;
    }
    $byBot = $order !== '' ? 'CASE bot_id' . $order . ' ELSE 99 END, ' : '';
    $rows = q("SELECT contact_id, bot_id, messenger, created_at FROM leadteh_contacts
               WHERE phone = ? AND dead = 0
               ORDER BY " . $byBot . "CASE messenger WHEN 'telegram' THEN 0 WHEN 'max' THEN 1 ELSE 2 END,
               created_at DESC",
        [$phone])->fetchAll();
    return $rows[0] ?? null;
}

/** Отправка сообщения в бот. true — ушло */
function lt_send(string $phone, string $text): bool
{
    $cfg = cfg('leadteh');
    if (empty($cfg['enabled']) || ($cfg['token'] ?? '') === '') return false;
    $c = lt_contact($phone);
    if (!$c) return false;
    try {
        $r = lt_api('sendMessage', ['contact_id' => (int)$c['contact_id'], 'text' => $text], true);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        error_log('[leadteh] ' . $msg);
        // 403 или 422 — контакт больше не доступен, второй раз не пробуем
        if (str_contains($msg, 'LeadTeh 403') || str_contains($msg, 'LeadTeh 422')) {
            q('UPDATE leadteh_contacts SET dead = 1 WHERE contact_id = ?', [(int)$c['contact_id']]);
        }
        return false;
    }
    if (empty($r['success'])) return false;
    q('UPDATE leadteh_contacts SET last_sent = ? WHERE contact_id = ?', [date('c'), (int)$c['contact_id']]);
    return true;
}

/** Сколько участников портала мы можем достать через боты */
function lt_coverage(): array
{
    $total = (int)q('SELECT COUNT(*) FROM users')->fetchColumn();
    $reach = (int)q('SELECT COUNT(*) FROM users u WHERE EXISTS
                     (SELECT 1 FROM leadteh_contacts c WHERE c.phone = u.phone AND c.dead = 0)')->fetchColumn();
    return ['users' => $total, 'reachable' => $reach];
}

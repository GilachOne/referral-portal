<?php
/**
 * Push-уведомления в приложении (Web Push, VAPID).
 * Шлём пустой push, а service worker сам забирает текст — так не нужно шифровать тело
 * и уведомление всегда показывает свежие данные.
 */
declare(strict_types=1);

/** Какие события шлём и что написано в настройках у человека */
function notify_events(): array
{
    return [
        'ref.clicked' => ['Переход по ссылке', 'друг открыл вашу ссылку', false],
        'ref.lead'    => ['Друг оставил заявку', 'друг записался в автошколу', true],
        'ref.paid'    => ['Друг оплатил обучение', 'осталось дождаться ГИБДД', true],
        'ref.gibdd'   => ['Можно получить деньги', 'друг зарегистрирован в ГИБДД', true],
        'claim.linked'   => ['Друг привязан к вам', 'тот, кто записался без ссылки', true],
        'claim.rejected' => ['Друга не привязали', 'если номер уже за кем-то', true],
        'payout.sent' => ['Заявка на выплату принята', 'подтверждение отправки', true],
        'payout.paid' => ['Выплата прошла', 'деньги отправлены', true],
        'payout.rejected' => ['Заявка отклонена', 'если по выплате что-то не так', true],
    ];
}

function notify_settings(string $phone): array
{
    $raw = q('SELECT notify_json FROM users WHERE phone = ?', [$phone])->fetchColumn();
    $saved = json_decode((string)$raw, true) ?: [];
    $out = [];
    foreach (notify_events() as $k => [$title, $hint, $default]) {
        $out[$k] = array_key_exists($k, $saved) ? (bool)$saved[$k] : $default;
    }
    return $out;
}

// ---------- VAPID ----------

function vapid_key(): ?OpenSSLAsymmetricKey
{
    static $key = false;
    if ($key !== false) return $key;
    $pem = base64_decode((string)cfg('push')['private_pem']);
    $key = $pem ? (openssl_pkey_get_private($pem) ?: null) : null;
    return $key;
}

/**
 * Подпись ECDSA приходит в DER: 30 len 02 lenR R 02 lenS S.
 * Для VAPID нужны «сырые» 64 байта — R и S по 32, без ведущих нулей DER.
 */
function der_to_raw(string $der): string
{
    $lr = ord($der[3]);
    $r  = ltrim(substr($der, 4, $lr), chr(0));
    $ls = ord($der[4 + $lr + 1]);        // длина S идёт сразу после её тега 0x02
    $s  = ltrim(substr($der, 4 + $lr + 2, $ls), chr(0));
    return str_pad($r, 32, chr(0), STR_PAD_LEFT) . str_pad($s, 32, chr(0), STR_PAD_LEFT);
}

function b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function vapid_auth(string $endpoint): ?array
{
    $cfg = cfg('push');
    $key = vapid_key();
    if (!$key || $cfg['public'] === '') return null;
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'];
    $jwt = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' .
           b64u(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => $cfg['subject']]));
    if (!openssl_sign($jwt, $der, $key, OPENSSL_ALGO_SHA256)) return null;
    return [
        'Authorization: vapid t=' . $jwt . '.' . b64u(der_to_raw($der)) . ', k=' . $cfg['public'],
        'TTL: 86400',
        'Content-Length: 0',
        'Urgency: normal',
    ];
}

/** Толкаем пустой push на все устройства человека. Протухшие подписки удаляем. */
function push_to(string $phone): int
{
    $subs = q('SELECT id, endpoint FROM push_subs WHERE phone = ?', [$phone])->fetchAll();
    $sent = 0;
    foreach ($subs as $s) {
        $headers = vapid_auth($s['endpoint']);
        if (!$headers) return 0;
        $ch = curl_init($s['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $answer = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (in_array($code, [403, 404, 410], true)) {
            // 403 — подписка сделана другим ключом, 404/410 — устройство отписалось
            error_log('[push] ' . $code . ' снимаем подписку ' . $s['id'] . ' ' . substr($s['endpoint'], 0, 60)
                . ' | ответ: ' . substr($answer, 0, 200));
            q('DELETE FROM push_subs WHERE id = ?', [$s['id']]);
        } elseif ($code >= 200 && $code < 300) {
            $sent++;
            q('UPDATE push_subs SET last_ok = ? WHERE id = ?', [date('c'), $s['id']]);
        } else {
            error_log('[push] ' . $code . ' ' . $s['endpoint'] . ' | ответ: ' . substr($answer, 0, 200));
        }
    }
    return $sent;
}

/** Создать уведомление и доставить его, если человек не отключил этот тип */
function notify(string $phone, string $event, string $title, string $body, string $url = '/'): void
{
    if (empty(notify_settings($phone)[$event])) return;
    q('INSERT INTO notifications (phone, event, title, body, url, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$phone, $event, $title, $body, $url, date('c')]);
    $sent = push_to($phone);
    // кто не включил push, получит то же самое в чат-боте, где он уже подписан
    $bot = false;
    if (function_exists('lt_send')) {
        $link = rtrim(cfg('base_url'), '/') . ($url !== '/' ? $url : '');
        $bot = lt_send($phone, $title . "\n" . $body . "\n\n" . $link);
    }
    if (function_exists('ev')) {
        ev('notify.sent', ['actor' => $phone, 'entity' => ['user', $phone],
            'payload' => ['event' => $event, 'devices' => $sent, 'bot' => $bot, 'title' => $title]]);
    }
}

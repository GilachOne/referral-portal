<?php
/**
 * Журнал событий в PostgreSQL: кто что сделал, что пришло извне, чем закончилось.
 * Логирование не должно ронять приложение: при любой ошибке пишем в файл и идём дальше.
 */
declare(strict_types=1);

function ev_db(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    $c = cfg('events');
    if (empty($c['dsn'])) return $pdo = null;
    try {
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 3,
        ]);
    } catch (Throwable $e) {
        error_log('[events] нет связи с базой журнала: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

/** Один идентификатор на весь запрос — так видно всю цепочку действий */
function ev_request_id(): string
{
    static $id;
    return $id ??= substr(bin2hex(random_bytes(6)), 0, 12);
}

/**
 * Записать событие.
 *   ev('ref.status_changed', ['entity' => ['ref', $dealId], 'actor' => $phone, 'payload' => [...]])
 */
function ev(string $event, array $o = []): void
{
    $row = [
        'app'         => cfg('events')['app'] ?? 'referral',
        'event'       => $event,
        'actor_type'  => $o['actor_type'] ?? (isset($o['actor']) ? 'user' : 'system'),
        'actor_id'    => isset($o['actor']) ? (string)$o['actor'] : null,
        'entity_type' => $o['entity'][0] ?? null,
        'entity_id'   => isset($o['entity'][1]) ? (string)$o['entity'][1] : null,
        'status'      => $o['status'] ?? 'ok',
        'message'     => isset($o['message']) ? mb_substr((string)$o['message'], 0, 500) : null,
        'payload'     => json_encode($o['payload'] ?? [], JSON_UNESCAPED_UNICODE),
        'request_id'  => ev_request_id(),
        'ip'          => $o['ip'] ?? (filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: null),
        'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 300) : null,
    ];

    $db = ev_db();
    if ($db) {
        try {
            $st = $db->prepare('INSERT INTO events
                (app, event, actor_type, actor_id, entity_type, entity_id, status, message, payload, request_id, ip, user_agent)
                VALUES (:app, :event, :actor_type, :actor_id, :entity_type, :entity_id, :status, :message,
                        CAST(:payload AS JSONB), :request_id, CAST(:ip AS INET), :user_agent)');
            $st->execute($row);
            return;
        } catch (Throwable $e) {
            error_log('[events] ' . $e->getMessage());
        }
    }
    // база недоступна — складываем на диск, потом доливаем командой events-flush
    $file = dirname(cfg('db')) . '/events-queue.jsonl';
    @file_put_contents($file, json_encode($row + ['ts' => date('c')], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

/** Отложенные события из файла — в базу (вызывается кроном) */
function ev_flush(): int
{
    $file = dirname(cfg('db')) . '/events-queue.jsonl';
    if (!is_file($file) || !ev_db()) return 0;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $done = 0;
    foreach ($lines as $line) {
        $r = json_decode($line, true);
        if (!$r) continue;
        try {
            $st = ev_db()->prepare('INSERT INTO events
                (ts, app, event, actor_type, actor_id, entity_type, entity_id, status, message, payload, request_id, ip, user_agent)
                VALUES (:ts, :app, :event, :actor_type, :actor_id, :entity_type, :entity_id, :status, :message,
                        CAST(:payload AS JSONB), :request_id, CAST(:ip AS INET), :user_agent)');
            $st->execute($r);
            $done++;
        } catch (Throwable $e) {
            error_log('[events flush] ' . $e->getMessage());
            return $done;   // база снова недоступна — файл не трогаем
        }
    }
    @unlink($file);
    return $done;
}

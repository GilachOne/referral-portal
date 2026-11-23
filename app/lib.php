<?php
declare(strict_types=1);

$CFG = require __DIR__ . '/config.php';
date_default_timezone_set($CFG['tz']);

function cfg(string $k)
{
    global $CFG;
    return $CFG[$k];
}

// ---------- База ----------

function db(): PDO
{
    static $pdo;
    if ($pdo) return $pdo;
    $path = cfg('db');
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            phone TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            city TEXT NOT NULL DEFAULT 'kazan',
            ref_code TEXT UNIQUE,
            source TEXT NOT NULL DEFAULT 'self',   -- team | portal | self
            b24_contact_id INTEGER,
            b24_deal_id INTEGER,          -- сделка в воронке «Рефералка»
            created_at TEXT, last_login TEXT
        );
        CREATE TABLE IF NOT EXISTS sessions (
            token_hash TEXT PRIMARY KEY,
            phone TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            user_agent TEXT
        );
        CREATE TABLE IF NOT EXISTS phone_codes (
            phone TEXT PRIMARY KEY, code_hash TEXT, created_at INTEGER,
            attempts INTEGER DEFAULT 0, verified_at INTEGER, sent_today INTEGER DEFAULT 0, day TEXT
        );
        -- приглашённые: по одной строке на сделку приглашённого в Битриксе
        CREATE TABLE IF NOT EXISTS refs (
            deal_id INTEGER PRIMARY KEY,
            owner_phone TEXT NOT NULL,
            name TEXT, phone TEXT,
            category INTEGER, stage TEXT,
            status TEXT NOT NULL,          -- lead | paid | gibdd | payout | done | lost
            amount REAL,
            created_at TEXT, updated_at TEXT, seen INTEGER DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS refs_owner ON refs(owner_phone);
        -- заявки на выплату
        CREATE TABLE IF NOT EXISTS payouts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_phone TEXT NOT NULL,
            ref_deal_id INTEGER,
            method TEXT NOT NULL,          -- money | debt
            amount REAL NOT NULL,
            fio TEXT, requisites TEXT,
            created_at TEXT, b24_sent INTEGER DEFAULT 0,
            paid_at TEXT, exported_at TEXT
        );
        -- устройства, на которые шлём push
        CREATE TABLE IF NOT EXISTS push_subs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone TEXT NOT NULL,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT, auth TEXT,
            user_agent TEXT, created_at TEXT, last_ok TEXT
        );
        CREATE INDEX IF NOT EXISTS push_phone ON push_subs(phone);
        -- что показать человеку: service worker забирает отсюда
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone TEXT NOT NULL,
            event TEXT NOT NULL,
            title TEXT NOT NULL, body TEXT, url TEXT,
            created_at TEXT NOT NULL, shown_at TEXT, read_at TEXT
        );
        CREATE INDEX IF NOT EXISTS notif_phone ON notifications(phone, created_at DESC);
        -- заявки на выплату из воронки «Рефералка»: и те, что завёл чат-бот, и наши
        CREATE TABLE IF NOT EXISTS b24_payouts (
            deal_id INTEGER PRIMARY KEY,
            owner_phone TEXT NOT NULL,
            friend_phone TEXT,
            status TEXT NOT NULL,          -- request | paid | rejected
            method TEXT, amount REAL,
            task_id INTEGER, task_status INTEGER,   -- задача «Выплата»: 5 — закрыта, 7 — отклонена
            paid_at TEXT, stale INTEGER DEFAULT 0, is_test INTEGER DEFAULT 0,
            created_at TEXT, updated_at TEXT
        );
        CREATE INDEX IF NOT EXISTS b24_payouts_owner ON b24_payouts(owner_phone);
        -- начисления: весь путь денег по паре «участник + друг»
        CREATE TABLE IF NOT EXISTS accruals (
            owner_phone TEXT NOT NULL,
            friend_key TEXT NOT NULL,      -- телефон друга, а если его нет — d<id сделки>
            friend_name TEXT, friend_phone TEXT, friend_deal INTEGER,
            status TEXT NOT NULL,          -- accrued | requested | paid | rejected
            amount REAL, method TEXT,
            gibdd_at TEXT, requested_at TEXT, paid_at TEXT, rejected_at TEXT,
            payout_deal INTEGER, task_id INTEGER,
            is_test INTEGER DEFAULT 0, updated_at TEXT,
            PRIMARY KEY (owner_phone, friend_key)
        );
        CREATE INDEX IF NOT EXISTS accruals_status ON accruals(status);
        -- друг записался без ссылки: участник просит привязать его к себе
        CREATE TABLE IF NOT EXISTS claims (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_phone TEXT NOT NULL,
            friend_phone TEXT NOT NULL,
            friend_name TEXT,
            status TEXT NOT NULL,          -- wait | linked | rejected
            reason TEXT,                   -- почему отказали
            deal_id INTEGER,               -- к чему привязали: сделка (+) или лид (-)
            created_at TEXT, decided_at TEXT
        );
        CREATE INDEX IF NOT EXISTS claims_owner ON claims(owner_phone);
        CREATE UNIQUE INDEX IF NOT EXISTS claims_pair ON claims(owner_phone, friend_phone);
        -- партнёрские каналы: QR на сертификатах, листовки, совместные акции
        CREATE TABLE IF NOT EXISTS campaigns (
            code TEXT PRIMARY KEY,
            title TEXT, partner TEXT,
            city TEXT NOT NULL DEFAULT 'kazan',
            landing TEXT DEFAULT '',
            utm_source TEXT DEFAULT 'partner', utm_medium TEXT DEFAULT 'qr', utm_content TEXT DEFAULT '',
            target TEXT NOT NULL DEFAULT 'landing',   -- portal: зовём в рефералку, landing: сразу учиться
            clicks INTEGER DEFAULT 0, last_click TEXT, created_at TEXT
        );
        -- контакты чат-ботов LeadTeh: через них доставляем уведомления тем, кто не включил push
        CREATE TABLE IF NOT EXISTS leadteh_contacts (
            contact_id INTEGER PRIMARY KEY,
            phone TEXT NOT NULL,
            bot_id INTEGER, messenger TEXT, name TEXT,
            created_at TEXT, last_sent TEXT, dead INTEGER DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS leadteh_phone ON leadteh_contacts(phone);
        CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT, t INTEGER);
        CREATE TABLE IF NOT EXISTS attempts (k TEXT PRIMARY KEY, n INTEGER, t INTEGER);
    ");
    // откуда пришёл участник: сотрудник, курсант или сам с улицы
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('source', $cols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN source TEXT NOT NULL DEFAULT 'self'");
    // поля заявки отдельно от текста для Битрикса — чтобы подставлять их при редактировании
    $pcols = array_column($pdo->query('PRAGMA table_info(payouts)')->fetchAll(), 'name');
    if (!in_array('notify_json', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN notify_json TEXT');
    // ответственный сотрудник: его ID в Битриксе, приходит из персональной ссылки менеджера
    if (!in_array('manager_id', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN manager_id TEXT');
    // метка канала из короткого адреса (referral.example.com/drive016) — уезжает в utm_medium
    if (!in_array('medium', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN medium TEXT');
    // канал, из которого пришёл сам участник (QR в кофейне и т.п.) — он наследуется его друзьям
    if (!in_array('campaign', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN campaign TEXT');
    if (!in_array('campaign_src', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN campaign_src TEXT');
    $ccols = array_column($pdo->query('PRAGMA table_info(campaigns)')->fetchAll(), 'name');
    if ($ccols && !in_array('target', $ccols, true)) $pdo->exec("ALTER TABLE campaigns ADD COLUMN target TEXT NOT NULL DEFAULT 'landing'");
    // заявки из воронки: задача, факт выплаты и пометка теста
    $bcols = array_column($pdo->query('PRAGMA table_info(b24_payouts)')->fetchAll(), 'name');
    // почему друг не засчитан: self — это сам участник, old_client — уже учился, taken — чужой реферал
    $rcols = array_column($pdo->query('PRAGMA table_info(refs)')->fetchAll(), 'name');
    if (!in_array('reject', $rcols, true)) $pdo->exec('ALTER TABLE refs ADD COLUMN reject TEXT');
    if (!in_array('reject_at', $rcols, true)) $pdo->exec('ALTER TABLE refs ADD COLUMN reject_at TEXT');
    if (!in_array('b24_deal_id', $pcols, true)) $pdo->exec('ALTER TABLE payouts ADD COLUMN b24_deal_id INTEGER');
    foreach (['task_id INTEGER', 'task_status INTEGER', 'paid_at TEXT', 'stale INTEGER DEFAULT 0',
              'is_test INTEGER DEFAULT 0', 'hold TEXT', 'task_title TEXT'] as $col) {
        $name = strtok($col, ' ');
        if ($bcols && !in_array($name, $bcols, true)) $pdo->exec('ALTER TABLE b24_payouts ADD COLUMN ' . $col);
    }
    if (!in_array('data_json', $pcols, true)) $pdo->exec('ALTER TABLE payouts ADD COLUMN data_json TEXT');
    if (!in_array('updated_at', $pcols, true)) $pdo->exec('ALTER TABLE payouts ADD COLUMN updated_at TEXT');
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function kv_get(string $k, ?int $ttl)
{
    $r = q('SELECT v, t FROM kv WHERE k = ?', [$k])->fetch();
    if (!$r || ($ttl !== null && $r['t'] < time() - $ttl)) return null;
    return json_decode($r['v'], true);
}

function kv_set(string $k, $v): void
{
    q('INSERT OR REPLACE INTO kv (k, v, t) VALUES (?, ?, ?)', [$k, json_encode($v, JSON_UNESCAPED_UNICODE), time()]);
}

// ---------- HTTP ----------

function http_json(string $method, string $url, $body = null, ?string $token = null, bool $form = false): array
{
    $ch = curl_init($url);
    $headers = [];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) {
        if ($form) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        } else {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 25,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Сервис недоступен: ' . $err);
    $data = json_decode($raw, true) ?? [];
    if ($code >= 300) throw new RuntimeException('HTTP ' . $code . ': ' . substr($raw, 0, 300));
    return $data;
}

// ---------- Битрикс24 ----------

function b24(string $method, array $params = []): array
{
    $cfg = cfg('bitrix');
    if ($cfg['webhook'] === '') throw new RuntimeException('Не настроен вебхук Битрикса');
    $r = http_json('POST', $cfg['webhook'] . '/' . $method . '.json', $params);
    if (isset($r['error'])) throw new RuntimeException('Битрикс: ' . ($r['error_description'] ?? $r['error']));
    return $r;
}

/**
 * Пакетный вызов Битрикса: до 50 команд за один запрос вместо пятидесяти отдельных.
 * $cmd — ['ключ' => ['crm.deal.get', ['id' => 1]], …], возвращает ['ключ' => результат].
 */
function b24_batch(array $cmd): array
{
    $out = [];
    foreach (array_chunk($cmd, 50, true) as $chunk) {
        $calls = [];
        foreach ($chunk as $key => $c) $calls[$key] = $c[0] . '?' . http_build_query($c[1] ?? []);
        try {
            $r = b24('batch', ['halt' => 0, 'cmd' => $calls]);
        } catch (Throwable $e) {
            error_log('[batch] ' . $e->getMessage());
            continue;
        }
        foreach ($r['result']['result'] ?? [] as $k => $v) $out[$k] = $v;
        foreach ($r['result']['result_error'] ?? [] as $k => $err) {
            error_log('[batch] ' . $k . ': ' . json_encode($err, JSON_UNESCAPED_UNICODE));
        }
    }
    return $out;
}

/**
 * Добор друзей сразу по списку участников: на человека уходит две команды,
 * то есть 25 человек за один запрос вместо пятидесяти. Нужен для массовых прогонов.
 */
function ref_backfill_many(array $phones): array
{
    $cfg = cfg('bitrix');
    $sel = ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', 'UTM_CAMPAIGN', 'CONTACT_ID',
            'DATE_CREATE', 'DATE_MODIFY', 'OPPORTUNITY', $cfg['uf_gibdd']];
    $selLead = ['ID', 'TITLE', 'NAME', 'LAST_NAME', 'STATUS_ID', 'UTM_CAMPAIGN', 'PHONE', 'DATE_CREATE', 'DATE_MODIFY'];
    $cmd = [];
    foreach ($phones as $p) {
        $camp = array_values(array_unique([$p, '+' . $p, '8' . substr($p, 1)]));
        $cmd['d_' . $p] = ['crm.deal.list', ['filter' => ['UTM_CAMPAIGN' => $camp, 'CATEGORY_ID' => $cfg['friend_categories']],
                                              'select' => $sel, 'order' => ['ID' => 'ASC']]];
        $cmd['l_' . $p] = ['crm.lead.list', ['filter' => ['UTM_CAMPAIGN' => $camp, '!STATUS_ID' => 'CONVERTED'],
                                              'select' => $selLead, 'order' => ['ID' => 'ASC']]];
    }
    $res = b24_batch($cmd);

    $stat = ['people' => count($phones), 'saved' => 0, 'partial' => []];
    ref_silent(true);
    foreach ($phones as $p) {
        foreach ((array)($res['d_' . $p] ?? []) as $d) if (ref_save_deal($d)) $stat['saved']++;
        foreach ((array)($res['l_' . $p] ?? []) as $l) if (ref_save_lead($l)) $stat['saved']++;
        // в пакете возвращается первая полусотня: у кого больше — дочитываем обычным способом
        $full = count((array)($res['d_' . $p] ?? [])) < 50 && count((array)($res['l_' . $p] ?? [])) < 50;
        if (!$full) {
            $stat['partial'][] = $p;
            ref_backfill($p, true);
        } else {
            // список пришёл целиком — значит строки, которых в нём нет, в Битриксе удалили
            $live = array_map('intval', array_column((array)($res['d_' . $p] ?? []), 'ID'));
            $sql = 'DELETE FROM refs WHERE owner_phone = ? AND deal_id > 0'
                . ($live ? ' AND deal_id NOT IN (' . implode(',', $live) . ')' : '');
            $gone = q($sql, [$p])->rowCount();
            if ($gone) $stat['removed'] = ($stat['removed'] ?? 0) + $gone;
        }
        kv_set('backfill:' . $p, ['at' => date('c'), 'batch' => true]);
    }
    ref_silent(false);
    return $stat;
}

/**
 * Откуда пришёл сам участник. Сначала верим куке партнёрской ссылки,
 * иначе смотрим его собственную сделку в CRM: по её меткам видно канал.
 * Результат запоминаем — это корень всей его ветки приглашённых.
 */
function user_campaign(array $user): string
{
    $known = (string)($user['campaign'] ?? '');
    if ($known !== '') return $known;

    $codes = array_column(q('SELECT code FROM campaigns')->fetchAll(), 'code');
    $set = function (string $code, string $src) use ($user) {
        q('UPDATE users SET campaign = ?, campaign_src = ? WHERE phone = ?', [$code, $src, $user['phone']]);
        ev('user.campaign', ['actor' => $user['phone'], 'entity' => ['user', $user['phone']],
            'payload' => ['campaign' => $code, 'from' => $src]]);
        return $code;
    };

    $cookie = mb_strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_COOKIE['dp_campaign'] ?? '')));
    if ($cookie !== '' && in_array($cookie, $codes, true)) return $set($cookie, 'cookie');
    if (!$codes) return '';

    // его собственная заявка в автошколу: там стоит метка канала, по которому он пришёл
    try {
        $cfg = cfg('bitrix');
        $dup = b24('crm.duplicate.findbycomm', ['entity_type' => 'CONTACT', 'type' => 'PHONE', 'values' => ['+' . $user['phone']]]);
        $ids = array_map('intval', $dup['result']['CONTACT'] ?? []);
        if (!$ids) return '';
        // 1) пришёл прямо по партнёрской ссылке: код канала стоит в UTM_CAMPAIGN
        $r = b24('crm.deal.list', [
            'filter' => ['CONTACT_ID' => $ids, 'CATEGORY_ID' => $cfg['friend_categories'], 'UTM_CAMPAIGN' => $codes],
            'select' => ['ID', 'UTM_CAMPAIGN', 'DATE_CREATE'], 'order' => ['ID' => 'ASC'],
        ]);
        $code = mb_strtolower((string)($r['result'][0]['UTM_CAMPAIGN'] ?? ''));
        if ($code !== '' && in_array($code, $codes, true)) return $set($code, 'crm');

        // 2) его самого привёл участник из канала: тогда код канала едет в UTM_MEDIUM.
        // так метка наследуется на любую глубину цепочки приглашений
        $r2 = b24('crm.deal.list', [
            'filter' => ['CONTACT_ID' => $ids, 'CATEGORY_ID' => $cfg['friend_categories'], 'UTM_MEDIUM' => $codes],
            'select' => ['ID', 'UTM_MEDIUM', 'DATE_CREATE'], 'order' => ['ID' => 'ASC'],
        ]);
        $code2 = mb_strtolower((string)($r2['result'][0]['UTM_MEDIUM'] ?? ''));
        if ($code2 !== '' && in_array($code2, $codes, true)) return $set($code2, 'chain');
    } catch (Throwable $e) {
        error_log('[user campaign] ' . $e->getMessage());
    }
    return '';
}

/** Контакт и сделка в воронке «Рефералка» — как делает чат-бот при выдаче ссылки */
function ensure_referrer_deal(array $user): array
{
    if (!empty($user['b24_deal_id'])) return $user;
    $cfg = cfg('bitrix');
    $manager = preg_replace('/\D+/', '', (string)($user['manager_id'] ?? ''));   // кто привёл участника
    $parts = preg_split('/\s+/u', trim($user['name']), 2);
    // источник: пришёл с рассылки, по QR, из портала сотрудников или сам
    $src = (string)($user['source'] ?? 'self');
    $map = $cfg['source_map'][$src] ?? $cfg['source_map']['self'];

    $contactFields = [
        'NAME'       => $parts[0] ?? $user['name'],
        'LAST_NAME'  => $parts[1] ?? '',
        'PHONE'      => [['VALUE' => '+' . $user['phone'], 'VALUE_TYPE' => 'WORK']],
        'SOURCE_ID'  => $map['SOURCE_ID'],
        'UTM_SOURCE' => 'referral',
        'UTM_MEDIUM' => $src === 'sms' ? 'sms' : 'portal',
    ];
    if ($manager !== '') {
        $contactFields['ASSIGNED_BY_ID'] = $manager;
        $contactFields['UTM_TERM'] = $manager;
    }
    // сначала ищем человека в CRM по телефону — у сотрудников и бывших клиентов контакт уже есть
    $contactId = 0;
    try {
        $dup = b24('crm.duplicate.findbycomm', [
            'entity_type' => 'CONTACT', 'type' => 'PHONE', 'values' => ['+' . $user['phone']],
        ]);
        $contactId = (int)($dup['result']['CONTACT'][0] ?? 0);
    } catch (Throwable $e) {
        error_log('[b24 dup] ' . $e->getMessage());
    }
    if ($contactId > 0) {
        ev('bitrix.contact_found', ['actor' => $user['phone'], 'entity' => ['contact', $contactId]]);
    } else {
        $contact = b24('crm.contact.add', ['fields' => $contactFields]);
        $contactId = (int)($contact['result'] ?? 0);
        ev('bitrix.contact_created', ['actor' => $user['phone'], 'entity' => ['contact', $contactId]]);
    }

    // и проверяем, нет ли уже его сделки в воронке «Рефералка» — например, из чат-бота
    try {
        $exist = b24('crm.deal.list', [
            'filter' => ['CATEGORY_ID' => $cfg['category'], 'UTM_CAMPAIGN' => $user['phone']],
            'select' => ['ID'], 'order' => ['ID' => 'DESC'],
        ])['result'][0]['ID'] ?? null;
        if ($exist) {
            q('UPDATE users SET b24_contact_id = ?, b24_deal_id = ? WHERE phone = ?', [$contactId, (int)$exist, $user['phone']]);
            $user['b24_contact_id'] = $contactId;
            $user['b24_deal_id'] = (int)$exist;
            ev('bitrix.deal_found', ['actor' => $user['phone'], 'entity' => ['deal', (int)$exist]]);
            return $user;
        }
    } catch (Throwable $e) {
        error_log('[b24 deal search] ' . $e->getMessage());
    }
    $dealFields = [
        'TITLE'          => 'Реффер (' . $user['phone'] . ')',
        'CATEGORY_ID'    => $cfg['category'],
        'STAGE_ID'       => $cfg['stage_new'],
        'SOURCE_ID'      => $map['SOURCE_ID'],
        $cfg['uf_source'] => $map['uf'],
        'CONTACT_IDS'    => [$contactId],
        'UTM_SOURCE'     => 'referral',
        'UTM_MEDIUM'     => (string)($user['campaign'] ?? '') ?: ($src === 'sms' ? 'sms' : 'portal'),
        'UTM_CAMPAIGN'   => $user['phone'],
    ];
    if ($manager !== '') {
        $dealFields['ASSIGNED_BY_ID'] = $manager;
        $dealFields['UTM_TERM'] = $manager;
    }
    $deal = b24('crm.deal.add', ['fields' => $dealFields]);
    $dealId = (int)($deal['result'] ?? 0);
    q('UPDATE users SET b24_contact_id = ?, b24_deal_id = ? WHERE phone = ?', [$contactId, $dealId, $user['phone']]);
    $user['b24_contact_id'] = $contactId;
    $user['b24_deal_id'] = $dealId;
    return $user;
}

// ---------- Реферальная ссылка ----------

function ref_code(string $phone): string
{
    $row = q('SELECT ref_code FROM users WHERE phone = ?', [$phone])->fetch();
    if ($row && $row['ref_code']) return $row['ref_code'];
    do {
        $code = strtoupper(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(6))), 0, 6));
    } while (q('SELECT 1 FROM users WHERE ref_code = ?', [$code])->fetch());
    q('UPDATE users SET ref_code = ? WHERE phone = ?', [$code, $phone]);
    return $code;
}

/** Куда ведёт короткая ссылка: лендинг с метками, как в чат-боте */
function landing_url(array $user): string
{
    $r = cfg('referral');
    $base = $user['city'] === 'cherepovets' ? $r['landing_cherepovets'] : $r['landing'];
    // канал участника наследуется его друзьям: по utm_medium видно, что человек пришёл,
    // например, из Coffee Like — даже если он уже третий в цепочке
    $channel = (string)($user['campaign'] ?? '');
    $q = [
        'utm_source'   => 'referral',
        'utm_campaign' => $user['phone'],
        'utm_medium'   => $channel ?: (($user['medium'] ?? '') ?: 'portal'),
        'utm_content'  => $user['ref_code'] ?? '',
    ];
    if ($channel !== '') $q['ref'] = $channel;
    // ответственный сотрудник едет дальше, как в ссылках из чат-бота
    if (!empty($user['manager_id'])) $q['utm_term'] = preg_replace('/\D+/', '', (string)$user['manager_id']);
    if ($user['city'] === 'cherepovets') $q = ['utm' => 'chp'] + $q;
    return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($q);
}

function short_link(array $user): string
{
    return rtrim(cfg('base_url'), '/') . '/r/' . ($user['ref_code'] ?? ref_code($user['phone']));
}

// ---------- Приглашённые: разбор сделок и лидов Битрикса ----------

/** Телефон и имя контакта по его id (с коротким кэшем) */
function b24_contact(int $id): array
{
    if ($id <= 0) return ['name' => '', 'phone' => ''];
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    try {
        $r = b24('crm.contact.get', ['id' => $id]);
    } catch (Throwable $e) {
        return ['name' => '', 'phone' => ''];
    }
    $c = $r['result'] ?? [];
    $phone = '';
    foreach ($c['PHONE'] ?? [] as $p) {
        $phone = norm_phone((string)($p['VALUE'] ?? ''));
        if ($phone !== '') break;
    }
    return $cache[$id] = ['name' => trim(($c['NAME'] ?? '') . ' ' . ($c['LAST_NAME'] ?? '')), 'phone' => $phone];
}

/** Добор старых друзей идёт молча: без push о каждом, кто пришёл год назад */
function ref_silent(?bool $on = null): bool
{
    static $silent = false;
    if ($on !== null) $silent = $on;
    return $silent;
}

/** Телефоны действующих сотрудников — список держим сутки */
function staff_phones(bool $fresh = false): array
{
    if (!$fresh && ($c = kv_get('staff_phones', 86400)) !== null) return $c;
    $out = [];
    try {
        $start = 0;
        do {
            $r = b24('user.get', ['FILTER' => ['ACTIVE' => true], 'start' => $start]);
            foreach ($r['result'] ?? [] as $u) {
                foreach (['PERSONAL_MOBILE', 'WORK_PHONE', 'PERSONAL_PHONE'] as $k) {
                    $p = norm_phone((string)($u[$k] ?? ''));
                    if (strlen($p) === 11) $out[] = $p;
                }
            }
            $start = $r['next'] ?? null;
        } while ($start !== null);
    } catch (Throwable $e) {
        error_log('[staff] ' . $e->getMessage());
        return $c ?? [];
    }
    $out = array_values(array_unique($out));
    kv_set('staff_phones', $out);
    return $out;
}

/**
 * Наш ли это курсант: учится сейчас или учился раньше.
 * Считаем курсантом, если у человека есть сделка в воронке обучения
 * или оплаченная сделка в «Автошколе»/«Череповце».
 * Ответ держим неделю: статус меняется редко, а запросов экономит много.
 */
function ref_is_student(string $phone, bool $fresh = false): array
{
    $cfg = cfg('bitrix');
    $key = 'student:' . $phone;
    if (!$fresh && ($c = kv_get($key, 7 * 86400)) !== null) return $c;

    $out = ['student' => false, 'why' => '', 'deal' => 0];
    // сотрудники автошколы тоже участвуют в программе, хотя сами не учились
    if (in_array($phone, staff_phones(), true)) {
        $out = ['student' => true, 'why' => 'сотрудник автошколы', 'deal' => 0];
        kv_set($key, $out);
        return $out;
    }
    try {
        $dup = b24('crm.duplicate.findbycomm', ['entity_type' => 'CONTACT', 'type' => 'PHONE', 'values' => ['+' . $phone]]);
        $ids = array_map('intval', $dup['result']['CONTACT'] ?? []);
        if ($ids) {
            $deals = b24('crm.deal.list', ['filter' => ['CONTACT_ID' => $ids],
                'select' => ['ID', 'CATEGORY_ID', 'STAGE_ID', 'DATE_CREATE'], 'order' => ['ID' => 'ASC']])['result'] ?? [];
            foreach ($deals as $d) {
                $cat = (int)$d['CATEGORY_ID'];
                $stage = (string)$d['STAGE_ID'];
                if (in_array($cat, $cfg['student_categories'], true)) {
                    $out = ['student' => true, 'why' => 'учится у нас', 'deal' => (int)$d['ID']];
                    break;
                }
                if (in_array($cat, $cfg['friend_categories'], true) && str_ends_with($stage, 'WON')) {
                    $out = ['student' => true, 'why' => 'оплатил обучение', 'deal' => (int)$d['ID']];
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[student] ' . $e->getMessage());
        return $out + ['unknown' => true];   // не смогли проверить — не кэшируем
    }
    kv_set($key, $out);
    return $out;
}

/** Человеческие формулировки для отказа */
function ref_reject_text(string $code): string
{
    return [
        'self'       => 'Это номер самого участника — вознаграждение не начисляется',
        'old_client' => 'Этот человек обращался в автошколу раньше, чем перешёл по ссылке',
        'taken'      => 'Этот человек закреплён за другим участником программы',
    ][$code] ?? '';
}

/** Учился ли человек раньше: ищем его сделки, созданные до реферальной */
function ref_old_client(int $contactId, string $dealDate): bool
{
    $cfg = cfg('bitrix');
    if ($contactId <= 0 || $dealDate === '') return false;
    $key = 'oldclient:' . $contactId;
    $cached = kv_get($key, 7 * 86400);
    $first = $cached['first'] ?? null;
    if ($first === null) {
        try {
            // ищем только НЕреферальные сделки: иначе копии той же продажи
            // в соседних воронках блокировали бы сами себя
            $r = b24('crm.deal.list', [
                'filter' => ['CONTACT_ID' => $contactId, 'CATEGORY_ID' => $cfg['friend_categories'],
                             '!UTM_SOURCE' => 'referral'],
                'select' => ['ID', 'DATE_CREATE'], 'order' => ['ID' => 'ASC'],
            ]);
            $first = (string)($r['result'][0]['DATE_CREATE'] ?? '');
        } catch (Throwable $e) {
            error_log('[old client] ' . $e->getMessage());
            return false;
        }
        kv_set($key, ['first' => $first]);
    }
    if ($first === '') return false;
    // сделка старше реферальной больше чем на сутки — человек пришёл не по ссылке
    return strtotime($first) < strtotime($dealDate) - 86400;
}

/**
 * Защита от накруток. Возвращает код отказа или пустую строку:
 *   self       — участник записал сам себя
 *   old_client — человек уже учился до перехода по ссылке
 *   taken      — его раньше привёл другой участник
 */
function ref_fraud_check(string $owner, string $friendPhone, int $contactId, string $dealDate): string
{
    $cfg = cfg('referral')['fraud'] ?? [];
    $me = q('SELECT b24_contact_id FROM users WHERE phone = ?', [$owner])->fetch();

    if (!empty($cfg['self'])) {
        if ($friendPhone !== '' && $friendPhone === $owner) return 'self';
        if ($contactId > 0 && $contactId === (int)($me['b24_contact_id'] ?? 0)) return 'self';
    }
    if (!empty($cfg['taken']) && $friendPhone !== '') {
        // чужим считаем только того, кто привёл этого человека РАНЬШЕ нас
        $other = q("SELECT owner_phone FROM refs WHERE phone = ? AND owner_phone != ?
                     AND (reject IS NULL OR reject = '') AND created_at < ? ORDER BY created_at LIMIT 1",
            [$friendPhone, $owner, $dealDate !== '' ? $dealDate : date('c')])->fetchColumn();
        if ($other) return 'taken';
    }
    if (!empty($cfg['old_client']) && ref_old_client($contactId, $dealDate)) return 'old_client';
    return '';
}

/** На каком этапе друг: заявка, оплатил, прошёл ГИБДД или отвалился */
function ref_status(array $deal): string
{
    $cfg = cfg('bitrix');
    $stage = (string)($deal['STAGE_ID'] ?? '');
    $cat = (int)($deal['CATEGORY_ID'] ?? 0);
    if (str_ends_with($stage, ':LOSE') || $stage === 'LOSE') return 'lost';
    if ((string)($deal[$cfg['uf_gibdd']] ?? '') === $cfg['gibdd_yes']) return 'gibdd';
    if ($cat === $cfg['study_category']) return 'paid';
    if (str_ends_with($stage, ':WON') || $stage === 'WON') return 'paid';
    return 'lead';
}

/** Пишем сделку друга в наш список. Возвращает true, если это реферал нашего участника */
function ref_save_deal(array $d, array $contact = null): bool
{
    $cfg = cfg('bitrix');
    $owner = norm_phone((string)($d['UTM_CAMPAIGN'] ?? ''));
    // следим только за продажами: «Автошкола» и «Череповец». Остальные воронки — служебные
    // копии того же клиента, из-за них друг двоился в списке
    if ($owner === '' || !in_array((int)($d['CATEGORY_ID'] ?? 0), $cfg['friend_categories'], true)) return false;
    if (!q('SELECT 1 FROM users WHERE phone = ?', [$owner])->fetch()) return false;

    $c = $contact ?? b24_contact((int)($d['CONTACT_ID'] ?? 0));
    $name = $c['name'] !== '' ? $c['name'] : trim(preg_replace('/^Рефералка,?\s*/u', '', (string)($d['TITLE'] ?? '')), " ()");
    $status = ref_status($d);

    // в журнал пишем только появление друга и смену этапа, а не каждую перезапись
    $row = q('SELECT status, reject FROM refs WHERE deal_id = ?', [(int)$d['ID']])->fetch();
    $was = $row ? (string)$row['status'] : false;
    // проверяем на накрутку один раз — при первом появлении друга
    $reject = $row ? (string)($row['reject'] ?? '') : ref_fraud_check($owner, $c['phone'], (int)($d['CONTACT_ID'] ?? 0), (string)($d['DATE_CREATE'] ?? ''));
    if ($reject !== '' && !$row) {
        ev('ref.rejected', ['actor' => $owner, 'entity' => ['ref', $d['ID']],
            'payload' => ['friend' => $name, 'reason' => $reject]]);
    }
    if ($was === false) {
        ev('ref.appeared', ['actor' => $owner, 'entity' => ['ref', $d['ID']],
            'payload' => ['friend' => $name, 'status' => $status, 'category' => (int)$d['CATEGORY_ID']]]);
    } elseif ($was !== $status) {
        ev('ref.status_changed', ['actor' => $owner, 'entity' => ['ref', $d['ID']],
            'payload' => ['friend' => $name, 'from' => $was, 'to' => $status, 'stage' => (string)$d['STAGE_ID']]]);
        // о важных шагах сообщаем пригласившему
        if ($reject !== '') {
            // не засчитан — молчим, чтобы не обнадёживать
        } elseif ($status === 'paid' && !ref_silent()) {
            notify($owner, 'ref.paid', 'Друг оплатил обучение',
                $name . ' оплатил. Вознаграждение придёт после регистрации в ГИБДД.');
        } elseif ($status === 'gibdd' && !ref_silent()) {
            notify($owner, 'ref.gibdd', 'Можно получить ' . cfg('referral')['reward'] . ' ₽',
                $name . ' зарегистрирован в ГИБДД. Откройте приложение и выберите способ выплаты.');
        }
    }
    if ($was === false && $status === 'lead' && $reject === '' && !ref_silent()) {
        notify($owner, 'ref.lead', 'Друг оставил заявку', $name . ' записался в автошколу по вашей ссылке.');
    }

    q('INSERT INTO refs (deal_id, owner_phone, name, phone, category, stage, status, amount, created_at, updated_at, reject, reject_at)
       VALUES (:id, :owner, :name, :phone, :cat, :stage, :status, :amount, :created, :updated, :reject, :rejat)
       ON CONFLICT(deal_id) DO UPDATE SET
         stage = :stage, category = :cat, status = :status, amount = :amount,
         reject = :reject, reject_at = :rejat,
         name = CASE WHEN :name != \'\' THEN :name ELSE name END,
         phone = CASE WHEN :phone != \'\' THEN :phone ELSE phone END,
         updated_at = :updated,
         seen = CASE WHEN refs.status != :status THEN 0 ELSE refs.seen END', [
        'id' => (int)$d['ID'], 'owner' => $owner, 'name' => $name, 'phone' => $c['phone'],
        'cat' => (int)$d['CATEGORY_ID'], 'stage' => (string)$d['STAGE_ID'], 'status' => $status,
        'amount' => (float)($d['OPPORTUNITY'] ?? 0),
        'created' => $d['DATE_CREATE'] ?? date('c'), 'updated' => $d['DATE_MODIFY'] ?? date('c'),
        'reject' => $reject, 'rejat' => $reject !== '' ? date('c') : null,
    ]);
    // лид по этому же человеку больше не нужен
    if ($c['phone'] !== '') q('DELETE FROM refs WHERE deal_id < 0 AND phone = ?', [$c['phone']]);
    return true;
}

function ref_save_lead(array $l): bool
{
    $owner = norm_phone((string)($l['UTM_CAMPAIGN'] ?? ''));
    if ($owner === '' || !q('SELECT 1 FROM users WHERE phone = ?', [$owner])->fetch()) return false;

    $phone = '';
    foreach ($l['PHONE'] ?? [] as $p) {
        $phone = norm_phone((string)($p['VALUE'] ?? ''));
        if ($phone !== '') break;
    }
    if ((string)($l['STATUS_ID'] ?? '') === 'CONVERTED') return false;   // стал сделкой, её и покажем
    if ($phone !== '' && q('SELECT 1 FROM refs WHERE owner_phone = ? AND phone = ? AND deal_id > 0', [$owner, $phone])->fetch()) return false;

    $name = trim((string)($l['NAME'] ?? '') . ' ' . (string)($l['LAST_NAME'] ?? ''));
    if ($name === '') $name = trim(preg_replace('/^.*?,\s*\(([^)]*)\).*$/u', '$1', (string)($l['TITLE'] ?? '')));

    $known = q('SELECT reject FROM refs WHERE deal_id = ?', [-(int)$l['ID']])->fetchColumn();
    $reject = $known !== false ? (string)$known
        : ref_fraud_check($owner, $phone, 0, (string)($l['DATE_CREATE'] ?? ''));

    q('INSERT INTO refs (deal_id, owner_phone, name, phone, category, stage, status, amount, created_at, updated_at, reject, reject_at)
       VALUES (:id, :owner, :name, :phone, -1, :stage, :status, 0, :created, :updated, :reject, :rejat)
       ON CONFLICT(deal_id) DO UPDATE SET stage = :stage, status = :status, name = :name, phone = :phone,
         updated_at = :updated, reject = :reject, reject_at = :rejat', [
        'id' => -(int)$l['ID'], 'owner' => $owner, 'name' => $name, 'phone' => $phone,
        'stage' => (string)($l['STATUS_ID'] ?? ''), 'status' => ($l['STATUS_ID'] ?? '') === 'JUNK' ? 'lost' : 'lead',
        'created' => $l['DATE_CREATE'] ?? date('c'), 'updated' => $l['DATE_MODIFY'] ?? date('c'),
        'reject' => $reject, 'rejat' => $reject !== '' ? date('c') : null,
    ]);
    return true;
}

/**
 * Человек пришёл из чат-бота и впервые зашёл в портал: подтягиваем всех его друзей
 * за всё время. Обычная синхронизация смотрит только на свежие изменения,
 * поэтому без этого старый участник увидел бы пустой список.
 */
function ref_backfill(string $phone, bool $force = false): int
{
    if (!$force && kv_get('backfill:' . $phone, null)) return 0;
    $cfg = cfg('bitrix');
    // бот и портал писали номер по-разному — ищем во всех написаниях
    $camp = array_values(array_unique([$phone, '+' . $phone, '8' . substr($phone, 1)]));
    $n = 0;
    ref_silent(true);
    try {
        $start = 0;
        do {
            $r = b24('crm.deal.list', [
                'filter' => ['UTM_CAMPAIGN' => $camp, 'CATEGORY_ID' => $cfg['friend_categories']],
                'select' => ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', 'UTM_CAMPAIGN', 'CONTACT_ID',
                             'DATE_CREATE', 'DATE_MODIFY', 'OPPORTUNITY', $cfg['uf_gibdd']],
                'order'  => ['ID' => 'ASC'], 'start' => $start,
            ]);
            foreach ($r['result'] ?? [] as $d) if (ref_save_deal($d)) $n++;
            $start = $r['next'] ?? null;
        } while ($start !== null);

        $start = 0;
        do {
            $r = b24('crm.lead.list', [
                'filter' => ['UTM_CAMPAIGN' => $camp, '!STATUS_ID' => 'CONVERTED'],
                'select' => ['ID', 'TITLE', 'NAME', 'LAST_NAME', 'STATUS_ID', 'UTM_CAMPAIGN', 'PHONE',
                             'DATE_CREATE', 'DATE_MODIFY'],
                'order'  => ['ID' => 'ASC'], 'start' => $start,
            ]);
            foreach ($r['result'] ?? [] as $l) if (ref_save_lead($l)) $n++;
            $start = $r['next'] ?? null;
        } while ($start !== null);
    } catch (Throwable $e) {
        ref_silent(false);
        error_log('[backfill] ' . $e->getMessage());
        return $n;
    }
    ref_silent(false);
    kv_set('backfill:' . $phone, ['at' => date('c'), 'found' => $n]);
    if ($n > 0) ev('ref.backfilled', ['actor' => $phone, 'entity' => ['user', $phone], 'payload' => ['found' => $n]]);
    return $n;
}

/** Хук из Битрикса прислал id — забираем карточку и обновляем статус сразу */
function ref_refresh(string $kind, int $id): bool
{
    $cfg = cfg('bitrix');
    if ($kind === 'lead') {
        $r = b24('crm.lead.get', ['id' => $id]);
        $l = $r['result'] ?? [];
        if (!$l) return false;
        if ((string)($l['STATUS_ID'] ?? '') === 'CONVERTED') {   // лид превратился в сделку — подтянем её
            $d = b24('crm.deal.list', ['filter' => ['LEAD_ID' => $id],
                'select' => ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', 'UTM_CAMPAIGN', 'CONTACT_ID',
                             'DATE_CREATE', 'DATE_MODIFY', 'OPPORTUNITY', $cfg['uf_gibdd']]]);
            $ok = false;
            foreach ($d['result'] ?? [] as $deal) $ok = ref_save_deal($deal) || $ok;
            q('DELETE FROM refs WHERE deal_id = ?', [-$id]);
            return $ok;
        }
        return ref_save_lead($l);
    }
    $r = b24('crm.deal.get', ['id' => $id]);
    $d = $r['result'] ?? [];
    if (!$d) return false;
    return ref_save_deal($d);
}

// ---------- Заявка на выплату ----------

/** Поля, которые нужны для перевода на карту. Ключ => как показываем человеку */
function payout_fields(): array
{
    return [
        'fio'         => 'ФИО как в паспорте',
        'birth'       => 'Дата рождения',
        'pass_serie'  => 'Серия паспорта',
        'pass_num'    => 'Номер паспорта',
        'pass_by'     => 'Кем выдан',
        'pass_date'   => 'Дата выдачи',
        'pass_code'   => 'Код подразделения',
        'reg_addr'    => 'Адрес прописки',
        'citizenship' => 'Гражданство',
        'inn'         => 'ИНН',
        'snils'       => 'СНИЛС',
        'bank'        => 'Банк',
        'rs'          => 'Номер счёта',
        'ks'          => 'Корреспондентский счёт',
        'bik'         => 'БИК',
    ];
}

/** «01.02.2020» → «2020-02-01», как ждёт Битрикс */
function ru_date_to_iso(string $v): ?string
{
    return preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim($v), $m) ? "$m[3]-$m[2]-$m[1]" : null;
}

/** При списании долга реквизиты не нужны, но ФИО и дату рождения спрашиваем: по ним ищем реферера в АШК */
function payout_check_person(array $in): array
{
    $fio = trim(preg_replace('/\s+/u', ' ', (string)($in['fio'] ?? '')));
    $birth = trim((string)($in['birth'] ?? ''));
    if (mb_strlen($fio) < 5 || !str_contains($fio, ' ')) fail('ФИО — полностью, как в паспорте');
    if (!ru_date_to_iso($birth)) fail('Дата рождения — в виде 01.02.2000');
    if (empty($in['agree'])) fail('Нужно согласие на обработку персональных данных');
    return [['fio' => $fio, 'birth' => $birth], 'ФИО как в паспорте: ' . $fio . "
Дата рождения: " . $birth];
}

/** Проверяем присланные реквизиты, возвращаем [данные, текст для Битрикса] */
function payout_check(array $in): array
{
    $data = [];
    foreach (payout_fields() as $k => $label) {
        $v = trim(preg_replace('/\s+/u', ' ', (string)($in[$k] ?? '')));
        if ($v === '') fail('Заполните поле: ' . mb_strtolower($label));
        $data[$k] = $v;
    }
    $digits = fn(string $k) => preg_replace('/\D+/', '', $data[$k]);
    if (!preg_match('/^\d{20}$/', $digits('rs')))    fail('Номер счёта — это 20 цифр');
    if (!preg_match('/^\d{20}$/', $digits('ks')))    fail('Корреспондентский счёт — это 20 цифр');
    if (!preg_match('/^\d{9}$/',  $digits('bik')))   fail('БИК — это 9 цифр');
    if (!preg_match('/^\d{10,12}$/', $digits('inn'))) fail('ИНН — 10 или 12 цифр');
    if (!preg_match('/^\d{11}$/', $digits('snils'))) fail('СНИЛС — 11 цифр');
    if (!preg_match('/^\d{4}$/', $digits('pass_serie'))) fail('Серия паспорта — 4 цифры');
    if (!preg_match('/^\d{6}$/', $digits('pass_num')))   fail('Номер паспорта — 6 цифр');
    if (!preg_match('/^\d{6}$/', $digits('pass_code')))  fail('Код подразделения — 6 цифр, например 160-001');
    if (!ru_date_to_iso($data['pass_date'])) fail('Дата выдачи паспорта — в виде 01.02.2020');
    if (!ru_date_to_iso($data['birth']))     fail('Дата рождения — в виде 01.02.2000');
    if (mb_strlen($data['reg_addr']) < 10)   fail('Укажите адрес прописки полностью');
    if (mb_strlen($data['fio']) < 5 || !str_contains($data['fio'], ' ')) fail('ФИО — полностью, как в паспорте');
    if (empty($in['agree'])) fail('Нужно согласие на обработку персональных данных');
    $data['pass_code'] = preg_replace('/^(\d{3})-?(\d{3})$/', '$1-$2', $digits('pass_code'));

    $text = [];
    foreach (payout_fields() as $k => $label) $text[] = $label . ': ' . $data[$k];
    return [$data, implode("\n", $text)];
}

/**
 * Заявка на выплату уезжает в Битрикс ОТДЕЛЬНОЙ сделкой воронки «Рефералка» — так же,
 * как делал чат-бот. Карточка «Реффер» остаётся профилем участника и не трогается:
 * иначе вторая заявка не сменила бы стадию, робот не поставил бы задачу на бухгалтерию
 * и выплата потерялась бы.
 *
 * Возвращает id сделки-заявки.
 */
function payout_to_b24(array $me, array $ref, array $payout, ?int $dealId = null): int
{
    $b = cfg('bitrix');
    $r = cfg('referral');
    $me = ensure_referrer_deal($me);   // контакт участника и его карточка-профиль
    $friend = (string)($ref['phone'] ?? '');

    $text = ($dealId ? 'Участник обновил заявку на выплату' : 'Заявка на выплату из портала')
        . "\nДруг: " . $ref['name'] . ' (' . $friend . '), сделка ' . $ref['deal_id']
        . "\nТелефон реферала: " . $friend          // тот же формат, что у чат-бота: по нему связываем выплату с другом
        . "\nСпособ получения: " . ($payout['method'] === 'debt'
            ? 'Списание в счёт долга ' . $r['reward'] . ' р.'
            : 'Выплата на банковскую карту ' . $r['reward_card'] . ' р. (НДФЛ ' . $r['ndfl'] . ' р.)')
        . ($payout['requisites'] !== '' ? "\n" . $payout['requisites'] : '')
        . (!empty($payout['ashk']) ? "\n\nПроверка реферера. " . ashk_text($payout['ashk']) : '');

    $fields = [
        // ФИО получателя видно прямо в списке, без захода в сделку. Телефон в скобках — первым:
        // по нему ref_deal_phone() узнаёт участника. ФИО из реквизитов точнее имени в профиле
        'TITLE'          => 'Выплата (' . $me['phone'] . ') '
            . (trim((string)($payout['fio'] ?? '')) ?: trim((string)$me['name']))
            . ' — за ' . $ref['name'] . ashk_title($payout['ashk'] ?? null),
        'CATEGORY_ID'    => $b['category'],
        'STAGE_ID'       => $b['stage_pay'],
        $b['uf_payout']  => $payout['method'] === 'debt' ? $b['payout_debt'] : $b['payout_money'],
        'COMMENTS'       => $text,
        'OPPORTUNITY'    => $payout['amount'],
        'UTM_SOURCE'     => 'referral',
        'UTM_CAMPAIGN'   => $me['phone'],
        'UTM_MEDIUM'     => 'portal',
        'UTM_CONTENT'    => (string)($me['ref_code'] ?? ''),
    ];
    if (!empty($me['b24_contact_id'])) $fields['CONTACT_IDS'] = [(int)$me['b24_contact_id']];
    $manager = preg_replace('/\D+/', '', (string)($me['manager_id'] ?? ''));
    if ($manager !== '') { $fields['ASSIGNED_BY_ID'] = $manager; $fields['UTM_TERM'] = $manager; }

    if ($dealId) {
        b24('crm.deal.update', ['id' => $dealId, 'fields' => $fields]);
    } else {
        $dealId = (int)(b24('crm.deal.add', ['fields' => $fields])['result'] ?? 0);
        if (!$dealId) return 0;
    }
    // отдельной записью в ленте — чтобы менеджер увидел именно изменение
    try {
        b24('crm.timeline.comment.add', ['fields' => [
            'ENTITY_ID'   => $dealId,
            'ENTITY_TYPE' => 'deal',
            'COMMENT'     => $text,
        ]]);
    } catch (Throwable $e) {
        error_log('[b24 timeline] ' . $e->getMessage());
    }
    ev('bitrix.payout_deal', ['actor' => $me['phone'], 'entity' => ['deal', $dealId],
        'payload' => ['method' => $payout['method'], 'amount' => $payout['amount'],
                      'friend' => $friend, 'friend_deal' => $ref['deal_id']]]);
    return $dealId;
}

// ---------- Подтверждение номера звонком ----------

function zvonok_call(string $phone): ?string
{
    $cfg = cfg('zvonok');
    $url = 'https://zvonok.com/manager/cabapi_external/api/v1/phones/flashcall/?' . http_build_query([
        'campaign_id' => $cfg['campaign_id'],
        'phone'       => '+' . $phone,
        'public_key'  => $cfg['public_key'],
    ]);
    try {
        $r = http_json('GET', $url);
    } catch (Throwable $e) {
        error_log('[zvonok] ' . $e->getMessage());
        return null;
    }
    if (($r['status'] ?? '') !== 'ok' || empty($r['data']['pincode'])) {
        error_log('[zvonok] ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        return null;
    }
    return (string)$r['data']['pincode'];
}

function request_call_code(string $phone): array
{
    $cfg = cfg('zvonok');
    if (!$cfg['enabled']) fail('Подтверждение по звонку временно отключено');
    $row = q('SELECT created_at, sent_today, day FROM phone_codes WHERE phone = ?', [$phone])->fetch();
    $today = date('Y-m-d');
    $sentToday = ($row && $row['day'] === $today) ? (int)$row['sent_today'] : 0;
    if ($row && time() - (int)$row['created_at'] < $cfg['resend_wait']) {
        fail('Звонок уже отправлен. Подождите ' . ($cfg['resend_wait'] - (time() - (int)$row['created_at'])) . ' секунд.');
    }
    if ($sentToday >= $cfg['per_day']) fail('Слишком много попыток за сегодня. Попробуйте завтра.');
    $code = zvonok_call($phone);
    if ($code === null) fail('Не получилось позвонить. Попробуйте ещё раз через минуту.');
    q('INSERT OR REPLACE INTO phone_codes (phone, code_hash, created_at, attempts, verified_at, sent_today, day)
       VALUES (?, ?, ?, 0, NULL, ?, ?)',
        [$phone, password_hash($code, PASSWORD_DEFAULT), time(), $sentToday + 1, $today]);
    return ['sent' => true, 'wait' => $cfg['resend_wait']];
}

/** Второй способ: человек сам звонит на наш номер. Возвращает номера для звонка */
function confirm_start(string $phone): array
{
    $cfg = cfg('zvonok');
    $url = 'https://zvonok.com/manager/cabapi_external/api/v1/phones/confirm/?' . http_build_query([
        'campaign_id' => $cfg['confirm_campaign_id'],
        'phone'       => '+' . $phone,
        'public_key'  => $cfg['public_key'],
    ]);
    try {
        $r = http_json('GET', $url);
    } catch (Throwable $e) {
        error_log('[zvonok confirm] ' . $e->getMessage());
        fail('Сервис проверки недоступен, попробуйте позже', 502);
    }
    $numbers = $r['data']['allowed_phones_for_call'] ?? [];
    if (($r['status'] ?? '') !== 'ok' || !$numbers) fail('Не удалось начать проверку, попробуйте позже', 502);
    kv_set('confirm:' . $phone, ['call_id' => $r['data']['call_id'] ?? null, 'at' => time()]);
    return ['numbers' => array_values($numbers)];
}

/** Дозвонился ли человек на проверочный номер */
function confirm_done(string $phone): bool
{
    $cfg = cfg('zvonok');
    $started = kv_get('confirm:' . $phone, 1800);
    if (!$started) return false;
    $url = 'https://zvonok.com/manager/cabapi_external/api/v1/phones/calls_by_phone/?' . http_build_query([
        'campaign_id' => $cfg['confirm_campaign_id'],
        'phone'       => '+' . $phone,
        'public_key'  => $cfg['public_key'],
    ]);
    try {
        $calls = http_json('GET', $url);
    } catch (Throwable $e) {
        return false;
    }
    foreach ($calls as $c) {
        if (($c['call_id'] ?? null) != $started['call_id']) continue;
        if (!in_array($c['status'] ?? '', ['compl_finished', 'pincode_ok'], true)) return false;
        // номер подтверждён — отмечаем так же, как после кода
        q('INSERT OR REPLACE INTO phone_codes (phone, code_hash, created_at, attempts, verified_at, sent_today, day)
           VALUES (?, ?, ?, 0, ?, 0, ?)', [$phone, '-', time(), time(), date('Y-m-d')]);
        return true;
    }
    return false;
}

/** Номер подтверждён в последние 15 минут */
function phone_verified(string $phone): bool
{
    $t = q('SELECT verified_at FROM phone_codes WHERE phone = ?', [$phone])->fetchColumn();
    return $t && time() - (int)$t < 900;
}

function check_call_code(string $phone, string $code): void
{
    $cfg = cfg('zvonok');
    $row = q('SELECT code_hash, created_at, attempts FROM phone_codes WHERE phone = ?', [$phone])->fetch();
    if (!$row || time() - (int)$row['created_at'] > $cfg['code_ttl']) fail('Код устарел, закажите звонок заново');
    if ((int)$row['attempts'] >= 5) fail('Слишком много неверных попыток, закажите звонок заново');
    if (!password_verify($code, $row['code_hash'])) {
        q('UPDATE phone_codes SET attempts = attempts + 1 WHERE phone = ?', [$phone]);
        fail('Неверный код. Это последние 4 цифры номера, с которого звонили, или цифры, которые продиктовал робот.');
    }
    q('UPDATE phone_codes SET verified_at = ? WHERE phone = ?', [time(), $phone]);
}

/**
 * Вход из другого нашего портала: там номер уже подтверждён звонком.
 * Ссылка живёт 2 минуты и подписана общим секретом.
 */
function sso_login(array $p): void
{
    $secret = cfg('sso_secret');
    if ($secret === '') fail('Сквозной вход не настроен', 503);
    $phone = norm_phone((string)($p['p'] ?? ''));
    $name  = trim((string)($p['n'] ?? ''));
    $from  = preg_replace('/[^a-z]/', '', (string)($p['r'] ?? 'team'));
    $exp   = (int)($p['e'] ?? 0);
    $sig   = (string)($p['s'] ?? '');
    $calc  = hash_hmac('sha256', $phone . '|' . $name . '|' . $from . '|' . $exp, $secret);
    if (!hash_equals($calc, $sig)) fail('Ссылка повреждена', 403);
    if ($exp < time()) fail('Ссылка устарела, откройте раздел заново', 403);
    if (strlen($phone) !== 11) fail('Некорректный номер', 400);

    $u = q('SELECT phone FROM users WHERE phone = ?', [$phone])->fetch();
    if (!$u) {
        q('INSERT INTO users (phone, name, city, source, created_at) VALUES (?, ?, ?, ?, ?)',
            [$phone, $name !== '' ? $name : 'Участник', 'kazan', $from, date('c')]);
        ev('user.registered', ['actor' => $phone, 'entity' => ['user', $phone], 'payload' => ['name' => $name, 'source' => $from]]);
    } elseif ($name !== '') {
        q('UPDATE users SET name = ? WHERE phone = ? AND (name = ? OR name = ?)', [$name, $phone, 'Участник', '']);
    }
    ref_code($phone);
    start_session($phone);
    ev('user.login', ['actor' => $phone, 'entity' => ['user', $phone], 'payload' => ['way' => 'sso', 'from' => $from]]);
    // из портала сотрудников можно прийти сразу на нужный экран: ?to=admin
    $to = preg_replace('/[^a-z]/', '', (string)($p['to'] ?? ''));
    header('Location: ' . rtrim(cfg('base_url'), '/') . '/' . ($to !== '' ? '#' . $to : ''), true, 302);
    exit;
}

/** Номер подтверждён — заводим человека, если он у нас впервые, и открываем сессию */
function finish_login(string $phone, array $in): void
{
    $u = q('SELECT * FROM users WHERE phone = ?', [$phone])->fetch();
    if (!$u) {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
        if (mb_strlen($name) < 2) out(['need_name' => true]);
        $city = ($in['city'] ?? '') === 'cherepovets' ? 'cherepovets' : 'kazan';
        $mgr = preg_replace('/\D+/', '', (string)($_COOKIE['dp_mgr'] ?? ''));   // пришёл по ссылке сотрудника
        $src = preg_replace('/[^a-z]/', '', (string)($_COOKIE['dp_src'] ?? '')) ?: 'self';   // откуда узнал: sms, пост, QR
        $medium = preg_replace('/[^a-z0-9_-]/', '', mb_strtolower((string)($_COOKIE['dp_medium'] ?? '')));   // метка канала из короткого адреса
        q('INSERT INTO users (phone, name, city, manager_id, source, medium, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$phone, $name, $city, $mgr ?: null, $src, $medium ?: null, date('c')]);
        ev('user.registered', ['actor' => $phone, 'entity' => ['user', $phone],
            'payload' => ['name' => $name, 'city' => $city, 'source' => $src, 'medium' => $medium, 'manager' => $mgr]]);
    }
    // пришёл по ссылке с меткой, а метки ещё не было — проставим
    $m = preg_replace('/[^a-z0-9_-]/', '', mb_strtolower((string)($_COOKIE['dp_medium'] ?? '')));
    if ($m !== '' && empty($u['medium'])) q('UPDATE users SET medium = ? WHERE phone = ? AND (medium IS NULL OR medium = "")', [$m, $phone]);
    ev('user.login', ['actor' => $phone, 'entity' => ['user', $phone]]);
    ref_code($phone);
    start_session($phone);
    out(['ok' => true]);
}

// ---------- Сессии ----------

const COOKIE = 'dp_session';

function start_session(string $phone): void
{
    $token = bin2hex(random_bytes(32));
    $exp = time() + cfg('session_days') * 86400;
    q('INSERT INTO sessions (token_hash, phone, expires_at, user_agent) VALUES (?, ?, ?, ?)',
        [hash('sha256', $token), $phone, $exp, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)]);
    q('UPDATE users SET last_login = ? WHERE phone = ?', [date('c'), $phone]);
    set_session_cookie($token, $exp);
}

function set_session_cookie(string $token, int $exp): void
{
    setcookie(COOKIE, $token, [
        'expires'  => $exp,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function current_user(): ?array
{
    $token = $_COOKIE[COOKIE] ?? '';
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    $s = q('SELECT phone, expires_at FROM sessions WHERE token_hash = ?', [$hash])->fetch();
    if (!$s || $s['expires_at'] < time()) return null;
    $u = q('SELECT * FROM users WHERE phone = ?', [$s['phone']])->fetch();
    if (!$u) return null;
    $full = time() + cfg('session_days') * 86400;
    if ($full - $s['expires_at'] > 86400) {
        q('UPDATE sessions SET expires_at = ? WHERE token_hash = ?', [$full, $hash]);
        set_session_cookie($token, $full);
    }
    return $u;
}

function end_session(): void
{
    if (!empty($_COOKIE[COOKIE])) q('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $_COOKIE[COOKIE])]);
    set_session_cookie('', 1);
}

function throttle(string $key, int $max = 10): void
{
    $r = q('SELECT n, t FROM attempts WHERE k = ?', [$key])->fetch();
    if ($r && $r['t'] > time() - 900) {
        if ($r['n'] >= $max) fail('Слишком много попыток. Подождите 15 минут.', 429);
        q('UPDATE attempts SET n = n + 1 WHERE k = ?', [$key]);
    } else {
        q('INSERT OR REPLACE INTO attempts (k, n, t) VALUES (?, 1, ?)', [$key, time()]);
    }
}

/**
 * Переход сделал робот, а не человек: сканер, сборщик, превью ссылки в мессенджере
 * или один адрес, с которого за сутки больше 10 переходов. Таких не считаем,
 * но переадресацию всё равно отдаём — для робота ничего не меняется.
 */
function is_bot_visit(): bool
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|scan|preview|headless|python|curl|wget|scrapy|httpclient|okhttp|'
        . 'go-http|java\/|axios|node-fetch|libwww|checker|triage|palo alto|security|whatsapp|facebookexternalhit|vkshare|'
        . 'yandex(bot|images|metrika)|google-|lighthouse|monitor|uptime/i', $ua)) return true;
    // браузер всегда сообщает язык, в том числе встроенный в Телеграм и WhatsApp; сканеры — почти никогда
    if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) return true;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') return false;
    $key = 'visit:' . date('Y-m-d') . ':' . $ip;
    $r = q('SELECT n FROM attempts WHERE k = ?', [$key])->fetch();
    if ($r) q('UPDATE attempts SET n = n + 1 WHERE k = ?', [$key]);
    else {
        q("DELETE FROM attempts WHERE k LIKE 'visit:%' AND t < ?", [time() - 2 * 86400]);
        q('INSERT INTO attempts (k, n, t) VALUES (?, 1, ?)', [$key, time()]);
    }
    // порог с запасом: у мобильных операторов десятки абонентов выходят в сеть с одного адреса
    return $r && (int)$r['n'] >= 10;
}

function norm_phone(string $s): string
{
    $d = preg_replace('/\D+/', '', $s);
    if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);
    if (strlen($d) === 10) $d = '7' . $d;
    return $d;
}

// ---------- Ответы API ----------

function out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): void
{
    out(['error' => $msg], $code);
}

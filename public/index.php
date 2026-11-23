<?php
declare(strict_types=1);
require __DIR__ . '/../app/lib.php';
require __DIR__ . '/../app/sheets.php';
require __DIR__ . '/../app/events.php';
require __DIR__ . '/../app/push.php';
require __DIR__ . '/../app/stats.php';
require __DIR__ . '/../app/claims.php';
require __DIR__ . '/../app/admin.php';
require __DIR__ . '/../app/payouts.php';
require __DIR__ . '/../app/accruals.php';
require __DIR__ . '/../app/leadteh.php';
require __DIR__ . '/../app/campaigns.php';
require __DIR__ . '/../app/ashk.php';

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// короткая ссылка: /r/КОД — считаем переход и отправляем на лендинг с метками
if (preg_match('#^/r/([A-Z0-9]{4,12})$#', $path, $m)) {
    $u = q('SELECT * FROM users WHERE ref_code = ?', [$m[1]])->fetch();
    if (!$u) { http_response_code(404); exit('Ссылка не найдена'); }
    if (!is_bot_visit()) {
        $clicks = (int)(kv_get('clicks:' . $u['phone'], null) ?? 0);
        kv_set('clicks:' . $u['phone'], $clicks + 1);
        ev('link.clicked', ['actor_type' => 'visitor', 'entity' => ['user', $u['phone']],
            'payload' => ['code' => $m[1], 'clicks' => $clicks + 1, 'ref' => $_SERVER['HTTP_REFERER'] ?? null]]);
    }
    header('Location: ' . landing_url($u), true, 302);
    exit;
}

/**
 * Персональная ссылка сотрудника: /m/60012 или ?m=60012.
 * Запоминаем, кто привёл человека, на 60 дней — потом проставим ответственным в Битриксе.
 */
$mgr = '';
if (preg_match('#^/m/(\d{1,9})$#', $path, $mm)) $mgr = $mm[1];
elseif (!empty($_GET['m']) && preg_match('/^\d{1,9}$/', (string)$_GET['m'])) $mgr = (string)$_GET['m'];
if ($mgr !== '') {
    setcookie('dp_mgr', $mgr, [
        'expires' => time() + 60 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    $_COOKIE['dp_mgr'] = $mgr;
    if (strpos($path, '/m/') === 0) {
        if (!is_bot_visit()) ev('manager.link', ['actor_type' => 'visitor', 'entity' => ['manager', $mgr]]);
        header('Location: /', true, 302);
        exit;
    }
}

// переход в кабинет с меткой канала: /?from=coffeelike
if (!empty($_GET['from'])) {
    $fromCode = mb_strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['from']));
    if ($fromCode !== '' && campaign_get($fromCode)) {
        setcookie('dp_campaign', $fromCode, [
            'expires' => time() + 60 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        ev('campaign.landed', ['actor_type' => 'visitor', 'payload' => ['code' => $fromCode]]);
    }
}

// партнёрская ссылка: /go/coffeelike — ведёт на лендинг с метками канала
if (preg_match('#^/go/([a-zA-Z0-9_-]{2,32})$#', $path, $gm)) {
    $camp = campaign_get($gm[1]);
    if (!$camp) { http_response_code(404); exit('Ссылка не найдена'); }
    // запоминаем канал на 60 дней: если человек потом станет участником, метка прилипнет к нему
    setcookie('dp_campaign', (string)$camp['code'], [
        'expires' => time() + 60 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!is_bot_visit()) {
        campaign_click($camp);
        ev('campaign.clicked', ['actor_type' => 'visitor', 'payload' => [
            'code' => $camp['code'], 'ref' => $_SERVER['HTTP_REFERER'] ?? null]]);
    }
    header('Location: ' . campaign_destination($camp, (string)($_GET['p'] ?? '')), true, 302);
    exit;
}

// короткая ссылка для рассылок: /s — SMS, /p — пост, /q — QR.
// Короткий домен r.example.com присылает сюда же с ?src=sms
if (preg_match('#^/(s|p|q)$#', $path, $sm) || !empty($_GET['src'])) {
    $src = isset($sm[1])
        ? ['s' => 'sms', 'p' => 'post', 'q' => 'qr'][$sm[1]]
        : preg_replace('/[^a-z]/', '', (string)$_GET['src']);
    // только наши метки: сканеры подставляют сюда пути вроде /wp-includes/…
    if (in_array($src, ['sms', 'post', 'qr'], true)) {
        setcookie('dp_src', $src, [
            'expires' => time() + 60 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        if (!is_bot_visit()) ev('link.campaign', ['actor_type' => 'visitor', 'payload' => ['src' => $src]]);
    }
    header('Location: /', true, 302);
    exit;
}

// презентация программы — открыта всем, вход не нужен
if ($path === '/present' || $path === '/presentation') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/present.html');
    exit;
}

// сквозной вход из портала сотрудников или курсантов
if ($path === '/sso') {
    sso_login($_GET);
}

/**
 * Хук из Битрикса: исходящий вебхук на событие сделки/лида или робот в воронке.
 * Принимаем и форму (как шлёт Битрикс), и JSON. Проверяем токен.
 */
if ($path === '/hook/bitrix') {
    $cfgB = cfg('bitrix');
    $raw = file_get_contents('php://input') ?: '';
    $data = $_POST ?: (json_decode($raw, true) ?: []);
    $data = array_merge($_GET, $data);

    $token = (string)($data['token'] ?? $data['auth']['application_token'] ?? '');
    if ($cfgB['hook_token'] === '' || !hash_equals($cfgB['hook_token'], $token)) {
        error_log('[hook] неверный токен');
        http_response_code(403);
        exit('forbidden');
    }

    $event = strtoupper((string)($data['event'] ?? ''));
    $kind = str_contains($event, 'LEAD') ? 'lead' : 'deal';
    if (!empty($data['type'])) $kind = $data['type'] === 'lead' ? 'lead' : 'deal';

    $id = (int)($data['data']['FIELDS']['ID'] ?? $data['deal_id'] ?? $data['lead_id'] ?? $data['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); exit('no id'); }

    try {
        $ok = ref_refresh($kind, $id);
        ev('bitrix.hook', ['actor_type' => 'bitrix', 'entity' => [$kind, $id],
            'payload' => ['event' => $event, 'updated' => $ok]]);
    } catch (Throwable $e) {
        error_log('[hook] ' . $e->getMessage());
        http_response_code(500);
        exit('error');
    }
    header('Content-Type: application/json');
    exit(json_encode(['ok' => true, 'updated' => $ok]));
}

/**
 * Короткий адрес с меткой канала: referral.example.com/drive016.
 * Сначала пробуем как личный код участника, иначе запоминаем метку на 60 дней —
 * она поедет в utm_medium во всех ссылках этого человека.
 */
if (preg_match('#^/([a-zA-Z0-9][a-zA-Z0-9_-]{2,23})$#', $path, $tm)) {
    $tag = $tm[1];
    $u = q('SELECT * FROM users WHERE ref_code = ?', [strtoupper($tag)])->fetch();
    if ($u) {
        if (!is_bot_visit()) {
            $clicks = (int)(kv_get('clicks:' . $u['phone'], null) ?? 0);
            kv_set('clicks:' . $u['phone'], $clicks + 1);
            ev('link.clicked', ['actor_type' => 'visitor', 'entity' => ['user', $u['phone']],
                'payload' => ['code' => strtoupper($tag), 'clicks' => $clicks + 1]]);
        }
        header('Location: ' . landing_url($u), true, 302);
        exit;
    }
    $medium = mb_strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $tag));
    setcookie('dp_medium', $medium, [
        'expires' => time() + 60 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!is_bot_visit()) ev('link.medium', ['actor_type' => 'visitor', 'payload' => ['medium' => $medium]]);
    header('Location: /', true, 302);
    exit;
}

if (strpos($path, '/api/') !== 0) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, must-revalidate');   // страница всегда свежая, иначе ловим старый app.js
    readfile(__DIR__ . '/app.html');
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input') ?: '';
$in = json_decode($body ?: '[]', true) ?: [];

/**
 * Запрос от другого нашего портала (сервер к серверу): он уже знает, кто его пользователь.
 * Подпись общим секретом вместо куки — так раздел работает внутри team.example.com без перехода сюда.
 */
$internalPhone = null;
if (($_SERVER['HTTP_X_INT_PHONE'] ?? '') !== '') {
    $secret = cfg('sso_secret');
    $p = norm_phone((string)$_SERVER['HTTP_X_INT_PHONE']);
    $ts = (int)($_SERVER['HTTP_X_INT_TS'] ?? 0);
    $calc = hash_hmac('sha256', $p . '|' . $ts . '|' . $path . '|' . hash('sha256', $body), (string)$secret);
    if ($secret === '' || !hash_equals($calc, (string)($_SERVER['HTTP_X_INT_SIG'] ?? ''))) fail('Подпись не сходится', 403);
    if (abs(time() - $ts) > 120) fail('Запрос устарел', 403);
    $internalPhone = $p;
} elseif ($method === 'POST' && ($_SERVER['HTTP_X_APP'] ?? '') !== '1') {
    fail('Bad request', 403);
}

$route = $method . ' ' . substr($path, 4);
$ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);

try {
    switch ($route) {

    // ---------- Вход ----------

    case 'POST /auth/call': {
        throttle('call:' . $ip, 20);
        $phone = norm_phone((string)($in['phone'] ?? ''));
        if (strlen($phone) !== 11) fail('Введите номер полностью');
        $res = request_call_code($phone);
        ev('auth.code_sent', ['actor' => $phone, 'entity' => ['user', $phone]]);
        out($res);
    }

    case 'POST /auth/verify': {
        throttle('verify:' . $ip, 30);
        $phone = norm_phone((string)($in['phone'] ?? ''));
        $code = preg_replace('/\D+/', '', (string)($in['code'] ?? ''));
        check_call_code($phone, $code);
        ev('auth.verified', ['actor' => $phone, 'entity' => ['user', $phone], 'payload' => ['way' => 'code']]);
        finish_login($phone, $in);
    }

    // Человек сам звонит на наш номер — отдаём, куда звонить
    case 'POST /auth/confirm': {
        throttle('confirm:' . $ip, 20);
        $phone = norm_phone((string)($in['phone'] ?? ''));
        if (strlen($phone) !== 11) fail('Введите номер полностью');
        out(confirm_start($phone));
    }

    // Проверяем, дозвонился ли он
    case 'POST /auth/confirm/check': {
        throttle('confirmcheck:' . $ip, 120);
        $phone = norm_phone((string)($in['phone'] ?? ''));
        if (!phone_verified($phone) && !confirm_done($phone)) out(['waiting' => true]);
        ev('auth.verified', ['actor' => $phone, 'entity' => ['user', $phone], 'payload' => ['way' => 'self_call']]);
        finish_login($phone, $in);
    }

    // по какой ссылке пришёл человек: показываем его город и партнёра на экране входа
    case 'GET /campaign': {
        $code = mb_strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['code'] ?? '')));
        $c = $code !== '' ? campaign_get($code) : null;
        if (!$c) out(['found' => false]);
        out(['found' => true, 'code' => $c['code'], 'title' => $c['title'],
             'partner' => $c['partner'], 'city' => $c['city']]);
    }

    // ключ нужен service worker'у, когда он переподписывает устройство сам
    case 'GET /push/key':
        out(['key' => cfg('push')['public']]);

    case 'POST /auth/logout':
        end_session();
        out(['ok' => true]);
    }

    // ---------- Дальше только для вошедших ----------

    if ($internalPhone !== null) {
        // участник приходит из другого портала: заводим его, если он у нас впервые
        $me = q('SELECT * FROM users WHERE phone = ?', [$internalPhone])->fetch();
        if (!$me) {
            $name = trim((string)($_SERVER['HTTP_X_INT_NAME'] ?? '')) ?: 'Участник';
            $from = preg_replace('/[^a-z]/', '', (string)($_SERVER['HTTP_X_INT_FROM'] ?? 'team'));
            q('INSERT INTO users (phone, name, city, source, created_at) VALUES (?, ?, ?, ?, ?)',
                [$internalPhone, $name, 'kazan', $from, date('c')]);
            ev('user.registered', ['actor' => $internalPhone, 'entity' => ['user', $internalPhone],
                'payload' => ['name' => $name, 'source' => $from, 'way' => 'internal']]);
            $me = q('SELECT * FROM users WHERE phone = ?', [$internalPhone])->fetch();
        }
        ref_code($internalPhone);
        $me = q('SELECT * FROM users WHERE phone = ?', [$internalPhone])->fetch();
    } else {
        $me = current_user();
    }
    if (!$me) fail('Нужно войти', 401);
    $ref = cfg('referral');

    switch ($route) {

    case 'GET /me': {
        // сделка в воронке «Рефералка» нужна, чтобы менеджеру было куда смотреть
        // откуда пришёл сам участник — метка поедет в ссылки его друзей
        try {
            if ((string)($me['campaign'] ?? '') === '' && !kv_get('campaign:' . $me['phone'], 86400)) {
                user_campaign($me);
                kv_set('campaign:' . $me['phone'], 1);
                $me = q('SELECT * FROM users WHERE phone = ?', [$me['phone']])->fetch();
            }
        } catch (Throwable $e) { error_log('[campaign] ' . $e->getMessage()); }
        try { $me = ensure_referrer_deal($me); } catch (Throwable $e) { error_log('[b24] ' . $e->getMessage()); }
        // пришёл из чат-бота и впервые открыл портал — подтягиваем его друзей за всё время
        try { ref_backfill($me['phone']); } catch (Throwable $e) { error_log('[backfill] ' . $e->getMessage()); }
        // заявки на выплату из Битрикса — свои и те, что остались от чат-бота
        if (!kv_get('payoutsync:' . $me['phone'], 300)) {
            payouts_sync_user($me['phone']);
            kv_set('payoutsync:' . $me['phone'], time());
        }

        $rows = q('SELECT deal_id, name, phone, status, updated_at, reject FROM refs
                   WHERE owner_phone = ? ORDER BY updated_at DESC', [$me['phone']])->fetchAll();
        // одна строка на приглашённого: держим самый дальний этап
        $rank = ['lost' => 0, 'lead' => 1, 'paid' => 2, 'gibdd' => 3, 'payout' => 4, 'done' => 5];
        $byPerson = [];
        foreach ($rows as $r) {
            $key = $r['phone'] ?: 'd' . $r['deal_id'];
            if (!isset($byPerson[$key]) || $rank[$r['status']] > $rank[$byPerson[$key]['status']]) $byPerson[$key] = $r;
        }
        $invited = array_values($byPerson);

        $payouts = q('SELECT id, ref_deal_id, method, amount, created_at, updated_at, paid_at, data_json
                      FROM payouts WHERE owner_phone = ?', [$me['phone']])->fetchAll();
        foreach ($payouts as &$p) {
            $p['data'] = json_decode((string)$p['data_json'], true) ?: new stdClass();
            unset($p['data_json']);
        }
        unset($p);
        $paidDeals = array_column($payouts, 'ref_deal_id');
        $fromB24 = payouts_of($me['phone'])['by_friend'];
        foreach ($invited as &$r) {
            if (in_array($r['deal_id'], $paidDeals, true)) $r['status'] = 'payout';
            // заявка из Битрикса сильнее: по ней видно, подана она или уже оплачена
            $bp = $r['phone'] ? ($fromB24[$r['phone']] ?? null) : null;
            if ($bp && empty($bp['is_test'])) {
                if ($bp['status'] === 'paid') {
                    $r['status'] = 'done';
                    $r['payout_at'] = substr((string)($bp['paid_at'] ?: $bp['updated_at']), 0, 10);
                } elseif ($bp['status'] === 'rejected') {
                    $r['payout_rejected'] = substr((string)$bp['updated_at'], 0, 10);   // можно подать заново
                } else {
                    $r['status'] = 'payout';
                    $r['payout_at'] = substr((string)$bp['created_at'], 0, 10);
                }
                $r['payout_method'] = $bp['method'];
            }
            if (!empty($r['reject'])) $r['reject_text'] = ref_reject_text((string)$r['reject']);
            $r['phone'] = $r['phone'] ? substr($r['phone'], 0, 4) . '•••' . substr($r['phone'], -2) : '';
        }
        unset($r);

        out([
            'user' => [
                'phone' => $me['phone'], 'name' => $me['name'], 'city' => $me['city'],
                'code' => $me['ref_code'],
            ],
            'link'     => short_link($me),
            'clicks'   => (int)(kv_get('clicks:' . $me['phone'], null) ?? 0),
            'push_key' => cfg('push')['public'],
            'notify'   => notify_settings($me['phone']),
            'push_devices' => (int)q('SELECT COUNT(*) FROM push_subs WHERE phone = ?', [$me['phone']])->fetchColumn(),
            'is_admin' => is_admin($me['phone']),
            'claim_on' => (bool)cfg('claim')['enabled'],
            'bot'      => function_exists('lt_contact') && lt_contact($me['phone']) !== null,
            'invited'  => $invited,
            'claims'   => cfg('claim')['enabled'] ? claims_of($me['phone']) : [],
            'payouts'  => $payouts,
            'earned'   => array_sum(array_column(array_filter($payouts, fn($p) => $p['paid_at']), 'amount')),
            'terms'    => [
                'reward' => $ref['reward'], 'reward_card' => $ref['reward_card'],
                'ndfl' => $ref['ndfl'], 'discount' => $ref['discount'],
                'offer' => $ref['offer_url'], 'days' => $ref['payout_days'],
                'edit_minutes' => $ref['edit_minutes'],
            ],
        ]);
    }

    case 'POST /profile': {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
        if (mb_strlen($name) < 2) fail('Укажите имя');
        $city = ($in['city'] ?? '') === 'cherepovets' ? 'cherepovets' : 'kazan';
        q('UPDATE users SET name = ?, city = ? WHERE phone = ?', [$name, $city, $me['phone']]);
        out(['ok' => true]);
    }

    // ---------- Руководителю: вся программа ----------

    case 'GET /admin': {
        if (!is_admin($me['phone'])) fail('Нет доступа', 403);
        out(admin_overview(!empty($_GET['fresh'])));
    }

    case 'GET /admin/campaigns': {
        if (!is_admin($me['phone'])) fail('Нет доступа', 403);
        $fresh = !empty($_GET['fresh']);
        $list = [];
        foreach (campaigns_all() as $c) {
            $list[] = $c + ['url' => campaign_short($c), 'target' => campaign_url($c),
                             'stats' => campaign_stats((string)$c['code'], $fresh)];
        }
        out(['campaigns' => $list]);
    }

    case 'GET /admin/user': {
        if (!is_admin($me['phone'])) fail('Нет доступа', 403);
        $phone = norm_phone((string)($_GET['phone'] ?? ''));
        if (strlen($phone) !== 11) fail('Нужен номер участника');
        out(admin_user($phone));
    }

    // ---------- Друг записался без ссылки ----------

    case 'POST /claim': {
        if (!cfg('claim')['enabled']) fail('Этот раздел временно отключён', 403);
        throttle('claim:' . $me['phone'], 20);
        claim_add($me, (string)($in['phone'] ?? ''), (string)($in['name'] ?? ''));
        out(['claims' => claims_of($me['phone'])]);
    }

    case 'POST /claim/cancel': {
        if (!cfg('claim')['enabled']) fail('Этот раздел временно отключён', 403);
        q("DELETE FROM claims WHERE id = ? AND owner_phone = ? AND status = 'wait'",
            [(int)($in['id'] ?? 0), $me['phone']]);
        out(['claims' => claims_of($me['phone'])]);
    }

    // ---------- Заявка на выплату ----------

    case 'POST /payout':
    case 'POST /payout/update': {
        $isUpdate = $route === 'POST /payout/update';
        $dealId = (int)($in['deal_id'] ?? 0);
        $r = q('SELECT * FROM refs WHERE deal_id = ? AND owner_phone = ?', [$dealId, $me['phone']])->fetch();
        if (!$r) fail('Приглашённый не найден');
        if (!empty($r['reject'])) fail(ref_reject_text((string)$r['reject']) . '. Если это ошибка, скажите представителю автошколы.');
        // по этому другу заявка уже есть в Битриксе — второй раз не принимаем
        if (!$isUpdate && payout_exists($me['phone'], (string)$r['phone'])) {
            fail('За этого друга заявка уже подана. Если нужно что-то изменить, скажите представителю автошколы.');
        }
        $old = q('SELECT * FROM payouts WHERE ref_deal_id = ? AND owner_phone = ?', [$dealId, $me['phone']])->fetch();

        if ($isUpdate) {
            if (!$old) fail('Заявка не найдена');
            if ($old['paid_at']) fail('Выплата уже прошла, изменить заявку нельзя. Свяжитесь с представителем автошколы.');
            // править можно только по горячим следам, дальше — через менеджера
            if (time() - strtotime((string)$old['created_at']) > $ref['edit_minutes'] * 60) {
                fail('Изменить заявку можно в течение ' . $ref['edit_minutes'] . ' минут после отправки. Свяжитесь с представителем автошколы.');
            }
        } else {
            if ($r['status'] !== 'gibdd') fail('Выплата открывается, когда курсанта зарегистрируют в ГИБДД');
            if ($old) fail('Заявка по этому другу уже отправлена — её можно изменить');
        }

        $method = ($in['method'] ?? '') === 'debt' ? 'debt' : 'money';
        $amount = $method === 'debt' ? $ref['reward'] : $ref['reward_card'];
        $data = [];
        $requisites = '';
        $fio = '';
        if ($method === 'money') {
            [$data, $requisites] = payout_check($in);   // те же поля, что собирает чат-бот
            $fio = $data['fio'];
        } else {
            [$data, $requisites] = payout_check_person($in);
            $fio = $data['fio'];
        }

        if ($isUpdate) {
            q('UPDATE payouts SET method = ?, amount = ?, fio = ?, requisites = ?, data_json = ?, updated_at = ?, b24_sent = 0
               WHERE id = ?', [$method, $amount, $fio, $requisites, json_encode($data, JSON_UNESCAPED_UNICODE), date('c'), $old['id']]);
            $id = (int)$old['id'];
        } else {
            q('INSERT INTO payouts (owner_phone, ref_deal_id, method, amount, fio, requisites, data_json, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$me['phone'], $dealId, $method, $amount, $fio, $requisites,
                 json_encode($data, JSON_UNESCAPED_UNICODE), date('c')]);
            $id = (int)db()->lastInsertId();
        }

        // при правке заявки берём уже готовый итог проверки, чтобы метка не пропала из заголовка
        $ashk = $isUpdate ? ashk_saved((int)($old['b24_deal_id'] ?? 0)) : null;

        // в Битрикс: отдельная сделка-заявка в воронке «Рефералка», на неё робот поставит задачу
        try {
            $payoutDeal = payout_to_b24($me, $r,
                ['method' => $method, 'amount' => $amount, 'requisites' => $requisites, 'fio' => $fio, 'ashk' => $ashk],
                $isUpdate ? (int)($old['b24_deal_id'] ?? 0) ?: null : null);
            if ($payoutDeal) {
                // вознаграждение положено только тем, кто сам учился: заявку принимаем, итог проверки видит бухгалтер
                if ($ref['require_student']) ashk_enqueue($payoutDeal, $me['phone']);
                q('UPDATE payouts SET b24_sent = 1, b24_deal_id = ? WHERE id = ?', [$payoutDeal, $id]);
                // сразу показываем заявку в кабинете, не дожидаясь синхронизации
                q('INSERT INTO b24_payouts (deal_id, owner_phone, friend_phone, status, method, amount, created_at, updated_at)
                   VALUES (:d, :o, :f, \'request\', :m, :a, :t, :t)
                   ON CONFLICT(deal_id) DO UPDATE SET method = :m, amount = :a, updated_at = :t',
                   ['d' => $payoutDeal, 'o' => $me['phone'], 'f' => (string)$r['phone'],
                    'm' => $method, 'a' => $amount, 't' => date('c')]);
            }
        } catch (Throwable $e) {
            error_log('[b24 payout] ' . $e->getMessage());
        }

        // журнал для маркетинга: какой способ выбрали и правили ли заявку
        notify($me['phone'], 'payout.sent', $isUpdate ? 'Заявка обновлена' : 'Заявка на выплату принята',
            ($method === 'debt' ? 'Спишем ' . $ref['reward'] : 'Переведём ' . $ref['reward_card'])
            . ' ₽ в течение ' . $ref['payout_days'] . ' дней. За друга: ' . $r['name']);

        ev($isUpdate ? 'payout.updated' : 'payout.created', [
            'actor' => $me['phone'], 'entity' => ['payout', $id],
            'payload' => [
                'method' => $method === 'debt' ? 'Списать в счет долга' : 'Деньги',
                'amount' => $amount, 'friend' => $r['name'], 'friend_deal' => $dealId,
                'city' => $me['city'], 'source' => $me['source'] ?? 'self',
            ],
        ]);

        sheet_log_payout([
            date('d.m.Y H:i'), $me['name'], '+' . $me['phone'],
            $r['name'], $r['phone'], (string)$dealId,
            $method === 'debt' ? 'Списать в счет долга' : 'Деньги',
            $amount, $me['city'] === 'cherepovets' ? 'Череповец' : 'Казань',
            $isUpdate ? 'изменение заявки' : 'новая заявка',
        ]);

        out(['ok' => true, 'method' => $method, 'amount' => $amount, 'days' => $ref['payout_days'], 'updated' => $isUpdate]);
    }

    // ---------- Уведомления ----------

    case 'POST /push/subscribe': {
        $sub = $in['subscription'] ?? [];
        $endpoint = (string)($sub['endpoint'] ?? '');
        if (!preg_match('#^https://#', $endpoint)) fail('Некорректная подписка');
        q('INSERT INTO push_subs (phone, endpoint, p256dh, auth, user_agent, created_at, last_ok)
           VALUES (:phone, :ep, :p, :a, :ua, :now, NULL)
           ON CONFLICT(endpoint) DO UPDATE SET phone = :phone, p256dh = :p, auth = :a', [
            'phone' => $me['phone'], 'ep' => $endpoint,
            'p' => $sub['keys']['p256dh'] ?? null, 'a' => $sub['keys']['auth'] ?? null,
            'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200), 'now' => date('c'),
        ]);
        ev('push.subscribed', ['actor' => $me['phone'], 'entity' => ['user', $me['phone']]]);
        out(['ok' => true]);
    }

    case 'POST /push/unsubscribe': {
        q('DELETE FROM push_subs WHERE phone = ? AND endpoint = ?', [$me['phone'], (string)($in['endpoint'] ?? '')]);
        out(['ok' => true]);
    }

    // service worker забирает текст уведомления уже после того, как push пришёл
    case 'GET /push/pending': {
        $rows = q('SELECT id, title, body, url FROM notifications
                   WHERE phone = ? AND shown_at IS NULL ORDER BY id LIMIT 5', [$me['phone']])->fetchAll();
        if ($rows) {
            $in2 = implode(',', array_fill(0, count($rows), '?'));
            q("UPDATE notifications SET shown_at = ? WHERE id IN ($in2)", array_merge([date('c')], array_column($rows, 'id')));
        }
        out(['items' => $rows]);
    }

    case 'POST /notify-settings': {
        $allowed = array_keys(notify_events());
        $cur = notify_settings($me['phone']);
        foreach ($allowed as $k) {
            if (array_key_exists($k, $in)) $cur[$k] = (bool)$in[$k];
        }
        q('UPDATE users SET notify_json = ? WHERE phone = ?', [json_encode($cur), $me['phone']]);
        out(['ok' => true, 'settings' => $cur]);
    }

    case 'POST /push/test': {
        notify($me['phone'], 'payout.sent', 'Проверка уведомлений', 'Если вы это видите, push работает', '/');
        out(['ok' => true]);
    }

    // бюджет и аналитика программы — только для руководства: портал сотрудников
    // пускает сюда одних админов, поэтому принимаем лишь подписанные запросы от него
    case 'GET /stats': {
        if ($internalPhone === null) fail('Нет доступа', 403);
        out(ref_stats(!empty($_GET['fresh'])));
    }

    case 'POST /seen': {
        q('UPDATE refs SET seen = 1 WHERE owner_phone = ?', [$me['phone']]);
        out(['ok' => true]);
    }
    }

    fail('Not found', 404);
} catch (Throwable $e) {
    error_log('[portal] ' . $e->getMessage());
    fail('Ошибка сервера: ' . $e->getMessage(), 500);
}

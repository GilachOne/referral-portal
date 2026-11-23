<?php
/**
 * Админский взгляд на программу: кто участвует, кого привёл, что с выплатами.
 * Нужен, пока люди переезжают из чат-ботов: сразу видно, кто уже в портале, а кто ещё нет.
 */
declare(strict_types=1);

const ADMIN_REG_TTL = 600;

function is_admin(string $phone): bool
{
    return in_array($phone, cfg('admins') ?: [], true);
}

/**
 * Реестр участников по Битриксу. Участник — это любой, чей номер стоит в UTM_CAMPAIGN
 * у приглашённого, даже если карточки в воронке «Рефералка» ему так и не завели:
 * чат-боты заводили её не всем, а человек всё равно привёл друга.
 */
function admin_registry(bool $fresh = false): array
{
    if (!$fresh && ($c = kv_get('admin_registry', ADMIN_REG_TTL)) !== null) return $c;
    $b = cfg('bitrix');
    $reg = [];

    $touch = function (string $raw) use (&$reg): ?string {
        $phone = norm_phone($raw);
        if (strlen($phone) !== 11) return null;
        $reg[$phone] = $reg[$phone] ?? ['phone' => $phone, 'card' => 0, 'card_at' => null, 'friends' => 0, 'last' => null];
        return $phone;
    };
    $later = fn(?string $a, string $b) => $b > (string)$a ? $b : $a;

    // 1. карточки участников в воронке «Рефералка» — их заводил бот и заводит портал
    foreach (b24_all('crm.deal.list', [
        'filter' => ['CATEGORY_ID' => $b['category']],
        'select' => ['ID', 'TITLE', 'UTM_CAMPAIGN', 'DATE_CREATE'],
        'order'  => ['ID' => 'ASC'],
    ]) as $d) {
        // у карточек из чат-бота UTM_CAMPAIGN пустой, телефон только в названии «Реффер (79…)»
        $phone = $touch(ref_deal_phone($d));
        if ($phone === null || $reg[$phone]['card']) continue;
        $reg[$phone]['card'] = (int)$d['ID'];
        $reg[$phone]['card_at'] = (string)($d['DATE_CREATE'] ?? '');
    }

    // 2. приглашённые: по ним видно живых участников, даже если карточки нет
    foreach (b24_all('crm.deal.list', [
        'filter' => ['UTM_SOURCE' => 'referral', 'CATEGORY_ID' => $b['friend_categories']],
        'select' => ['ID', 'CATEGORY_ID', 'UTM_CAMPAIGN', 'DATE_CREATE'],
        'order'  => ['ID' => 'ASC'],
    ]) as $d) {
        if ((int)($d['CATEGORY_ID'] ?? 0) === $b['category']) continue;   // на всякий случай: карточка участника
        $phone = $touch((string)($d['UTM_CAMPAIGN'] ?? ''));
        if ($phone === null) continue;
        $reg[$phone]['friends']++;
        $reg[$phone]['last'] = $later($reg[$phone]['last'], (string)($d['DATE_CREATE'] ?? ''));
    }
    foreach (b24_all('crm.lead.list', [
        'filter' => ['UTM_SOURCE' => 'referral', '!STATUS_ID' => 'CONVERTED'],
        'select' => ['ID', 'UTM_CAMPAIGN', 'DATE_CREATE'],
        'order'  => ['ID' => 'ASC'],
    ]) as $l) {
        $phone = $touch((string)($l['UTM_CAMPAIGN'] ?? ''));
        if ($phone === null) continue;
        $reg[$phone]['friends']++;
        $reg[$phone]['last'] = $later($reg[$phone]['last'], (string)($l['DATE_CREATE'] ?? ''));
    }

    $reg = array_values($reg);
    kv_set('admin_registry', $reg);
    return $reg;
}

/** Имя человека в CRM по телефону — для тех, кого ещё нет в портале */
function admin_name_by_phone(string $phone): string
{
    try {
        $dup = b24('crm.duplicate.findbycomm', ['entity_type' => 'CONTACT', 'type' => 'PHONE', 'values' => ['+' . $phone]]);
        $id = (int)($dup['result']['CONTACT'][0] ?? 0);
        if ($id > 0) return b24_contact($id)['name'];
    } catch (Throwable $e) {
        error_log('[admin name] ' . $e->getMessage());
    }
    return '';
}

/** Кого привёл человек, которого в портале ещё нет — читаем прямо из Битрикса */
function admin_friends_b24(string $phone): array
{
    $b = cfg('bitrix');
    $camp = array_values(array_unique([$phone, '+' . $phone, '8' . substr($phone, 1)]));
    $out = [];
    try {
        $deals = b24_all('crm.deal.list', [
            'filter' => ['UTM_CAMPAIGN' => $camp, 'CATEGORY_ID' => $b['friend_categories']],
            'select' => ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', 'CONTACT_ID', 'DATE_CREATE', 'DATE_MODIFY',
                         'OPPORTUNITY', $b['uf_gibdd']],
            'order'  => ['ID' => 'DESC'],
        ]);
        $ids = array_values(array_unique(array_filter(array_column($deals, 'CONTACT_ID'))));
        $names = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            foreach (b24('crm.contact.list', ['filter' => ['ID' => $chunk], 'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE']])['result'] ?? [] as $c) {
                $p = '';
                foreach ($c['PHONE'] ?? [] as $ph) {
                    $p = norm_phone((string)($ph['VALUE'] ?? ''));
                    if ($p !== '') break;
                }
                $names[(int)$c['ID']] = ['name' => trim(($c['NAME'] ?? '') . ' ' . ($c['LAST_NAME'] ?? '')), 'phone' => $p];
            }
        }
        foreach ($deals as $d) {
            $c = $names[(int)($d['CONTACT_ID'] ?? 0)] ?? ['name' => '', 'phone' => ''];
            $out[] = [
                'deal_id'  => (int)$d['ID'],
                'name'     => $c['name'] !== '' ? $c['name'] : trim(preg_replace('/^Рефералка,?\s*/u', '', (string)($d['TITLE'] ?? ''))),
                'phone'    => $c['phone'],
                'status'   => ref_status($d),
                'stage'    => (string)($d['STAGE_ID'] ?? ''),
                'category' => (int)($d['CATEGORY_ID'] ?? 0),
                'amount'   => (float)($d['OPPORTUNITY'] ?? 0),
                'created_at' => (string)($d['DATE_CREATE'] ?? ''),
                'updated_at' => (string)($d['DATE_MODIFY'] ?? ''),
            ];
        }
        foreach (b24_all('crm.lead.list', [
            'filter' => ['UTM_CAMPAIGN' => $camp, '!STATUS_ID' => 'CONVERTED'],
            'select' => ['ID', 'TITLE', 'NAME', 'LAST_NAME', 'STATUS_ID', 'PHONE', 'DATE_CREATE', 'DATE_MODIFY'],
            'order'  => ['ID' => 'DESC'],
        ]) as $l) {
            $p = '';
            foreach ($l['PHONE'] ?? [] as $ph) {
                $p = norm_phone((string)($ph['VALUE'] ?? ''));
                if ($p !== '') break;
            }
            $out[] = [
                'deal_id'  => -(int)$l['ID'],
                'name'     => trim((string)($l['NAME'] ?? '') . ' ' . (string)($l['LAST_NAME'] ?? '')) ?: (string)($l['TITLE'] ?? ''),
                'phone'    => $p,
                'status'   => ($l['STATUS_ID'] ?? '') === 'JUNK' ? 'lost' : 'lead',
                'stage'    => (string)($l['STATUS_ID'] ?? ''),
                'category' => -1,
                'amount'   => 0,
                'created_at' => (string)($l['DATE_CREATE'] ?? ''),
                'updated_at' => (string)($l['DATE_MODIFY'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        error_log('[admin friends] ' . $e->getMessage());
    }
    return $out;
}

/** Сводка по всем участникам портала + те, кто в Битриксе есть, а в портале ещё нет */
function admin_overview(bool $fresh = false): array
{
    $ref = cfg('referral');
    $test = array_map('strval', $ref['test_phones'] ?? []);

    $users = q('SELECT phone, name, city, source, manager_id, ref_code, created_at, last_login FROM users
                ORDER BY created_at DESC')->fetchAll();

    // друзья и деньги — одним проходом по таблицам, без запроса на каждого
    $byOwner = [];
    foreach (q('SELECT owner_phone, status, COUNT(*) n FROM refs GROUP BY owner_phone, status')->fetchAll() as $r) {
        $byOwner[(string)$r['owner_phone']][$r['status']] = (int)$r['n'];
    }
    $pay = [];
    foreach (q('SELECT owner_phone, method, amount, paid_at FROM payouts')->fetchAll() as $p) {
        $k = (string)$p['owner_phone'];
        $pay[$k]['requested'] = ($pay[$k]['requested'] ?? 0) + (float)$p['amount'];
        if ($p['paid_at']) $pay[$k]['paid'] = ($pay[$k]['paid'] ?? 0) + (float)$p['amount'];
    }
    $claims = [];
    foreach (q('SELECT owner_phone, status, COUNT(*) n FROM claims GROUP BY owner_phone, status')->fetchAll() as $r) {
        $claims[(string)$r['owner_phone']][$r['status']] = (int)$r['n'];
    }

    $list = [];
    foreach ($users as $u) {
        $phone = (string)$u['phone'];
        $s = $byOwner[$phone] ?? [];
        $done = ($s['paid'] ?? 0) + ($s['gibdd'] ?? 0) + ($s['payout'] ?? 0) + ($s['done'] ?? 0);
        $list[] = [
            'phone'    => $phone,
            'name'     => $u['name'],
            'city'     => $u['city'],
            'source'   => $u['source'],
            'manager'  => $u['manager_id'],
            'code'     => $u['ref_code'],
            'created'  => $u['created_at'],
            'last'     => $u['last_login'],
            'test'     => in_array($phone, $test, true),
            'friends'  => array_sum($s),
            'lead'     => $s['lead'] ?? 0,
            'paid'     => $done,
            'gibdd'    => ($s['gibdd'] ?? 0) + ($s['payout'] ?? 0) + ($s['done'] ?? 0),
            'lost'     => $s['lost'] ?? 0,
            'requested' => $pay[$phone]['requested'] ?? 0,
            'money'    => $pay[$phone]['paid'] ?? 0,
            'claims'   => array_sum($claims[$phone] ?? []),
        ];
    }
    usort($list, fn($a, $b) => [$b['friends'], $b['paid']] <=> [$a['friends'], $a['paid']]);

    // кто есть в Битриксе, но в портал ещё не заходил
    $missing = [];
    $registry = [];
    try {
        $known = array_map('strval', array_column($users, 'phone'));
        $registry = admin_registry($fresh);
        foreach ($registry as $m) {
            if (in_array($m['phone'], $known, true)) continue;
            $missing[] = $m;
        }
        usort($missing, fn($a, $b) => [$b['friends'], (string)$b['last']] <=> [$a['friends'], (string)$a['last']]);
    } catch (Throwable $e) {
        error_log('[admin registry] ' . $e->getMessage());
    }

    // друг прошёл ГИБДД, а заявки на выплату по нему нет — значит человеку должны
    $debts = q("SELECT r.owner_phone, r.name AS friend, r.phone AS friend_phone, r.updated_at,
                        u.name AS owner_name
                 FROM refs r LEFT JOIN users u ON u.phone = r.owner_phone
                 WHERE r.status = 'gibdd' AND (r.reject IS NULL OR r.reject = '')
                   AND NOT EXISTS (SELECT 1 FROM b24_payouts p
                                   WHERE p.owner_phone = r.owner_phone AND p.friend_phone = r.phone)
                 ORDER BY r.updated_at")->fetchAll();

    // бухгалтерия закрыла задачу, а карточку на «Оплачено» не двинули — воронку стоит дочистить
    $stale = q("SELECT p.deal_id, p.task_id, p.owner_phone, p.paid_at, u.name AS owner_name
                 FROM b24_payouts p LEFT JOIN users u ON u.phone = p.owner_phone
                 WHERE p.stale = 1 AND p.is_test = 0 ORDER BY p.paid_at DESC LIMIT 50")->fetchAll();

    // заявки, по которым портал не видит права на выплату: друг не дошёл до ГИБДД
    $holds = q("SELECT p.deal_id, p.owner_phone, p.friend_phone, p.hold, p.task_status, p.task_title,
                       substr(p.created_at, 1, 10) AS created, u.name AS owner_name,
                       (SELECT r.status FROM refs r WHERE r.owner_phone = p.owner_phone AND r.phone = p.friend_phone
                          ORDER BY r.updated_at DESC LIMIT 1) AS friend_status
                FROM b24_payouts p LEFT JOIN users u ON u.phone = p.owner_phone
                WHERE p.hold != '' AND p.is_test = 0 ORDER BY p.deal_id DESC LIMIT 50")->fetchAll();

    $waiting = q("SELECT c.*, u.name AS owner_name FROM claims c LEFT JOIN users u ON u.phone = c.owner_phone
                  ORDER BY c.id DESC LIMIT 50")->fetchAll();
    $texts = claim_reasons();
    foreach ($waiting as &$w) {
        $w['text'] = $w['status'] === 'rejected' ? ($texts[$w['reason']] ?? '') : '';
    }
    unset($w);

    return [
        'totals' => [
            'users'     => count($list),
            'active'    => count(array_filter($list, fn($u) => $u['friends'] > 0)),
            'friends'   => (int)q('SELECT COUNT(*) FROM refs')->fetchColumn(),
            'paid'      => array_sum(array_column($list, 'paid')),
            'gibdd'     => array_sum(array_column($list, 'gibdd')),
            'money'     => array_sum(array_column($list, 'money')),
            'requested' => array_sum(array_column($list, 'requested')),
            'b24'       => count($registry),
            'missing'   => count($missing),
            'debt'      => count($debts),
            'debt_sum'  => count($debts) * (int)$ref['reward'],
            'reward'    => (int)$ref['reward'],
            'requests'  => (int)q("SELECT COUNT(*) FROM b24_payouts WHERE status = 'request' AND is_test = 0")->fetchColumn(),
            'paid_out'  => (int)q("SELECT COUNT(*) FROM b24_payouts WHERE status = 'paid' AND is_test = 0")->fetchColumn(),
            'rejected'  => (int)q("SELECT COUNT(*) FROM b24_payouts WHERE status = 'rejected' AND is_test = 0")->fetchColumn(),
            'stale'     => count($stale),
            'holds'     => count($holds),
            'rejects'   => (int)q("SELECT COUNT(*) FROM refs WHERE reject IS NOT NULL AND reject != ''")->fetchColumn(),
        ],
        'rejects' => q("SELECT r.owner_phone, u.name AS owner_name, r.name AS friend, r.phone AS friend_phone,
                               r.reject, r.reject_at
                        FROM refs r LEFT JOIN users u ON u.phone = r.owner_phone
                        WHERE r.reject IS NOT NULL AND r.reject != '' ORDER BY r.reject_at DESC LIMIT 50")->fetchAll(),
        'money'   => accruals_totals(),
        'months'  => accruals_by_month(6),
        'debts'   => $debts,
        'stale'   => $stale,
        'holds'   => $holds,
        'users'   => $list,
        'missing' => $missing,
        'claims'  => $waiting,
    ];
}

/** Карточка участника: его друзья, заявки и выплаты */
function admin_user(string $phone): array
{
    $u = q('SELECT * FROM users WHERE phone = ?', [$phone])->fetch();
    $inPortal = (bool)$u;
    $friends = q('SELECT deal_id, name, phone, status, stage, category, amount, created_at, updated_at, reject
                  FROM refs WHERE owner_phone = ? ORDER BY updated_at DESC', [$phone])->fetchAll();
    $payouts = q('SELECT id, ref_deal_id, method, amount, fio, created_at, updated_at, paid_at, exported_at
                  FROM payouts WHERE owner_phone = ? ORDER BY id DESC', [$phone])->fetchAll();
    $claims = q('SELECT id, friend_name, friend_phone, status, reason, created_at, decided_at
                 FROM claims WHERE owner_phone = ? ORDER BY id DESC', [$phone])->fetchAll();

    if (!$inPortal) {
        // человек есть в Битриксе, но в портал не заходил — показываем, что про него знает CRM
        $card = null;
        foreach (admin_registry() as $m) {
            if ($m['phone'] === $phone) { $card = $m; break; }
        }
        $u = ['phone' => $phone, 'name' => admin_name_by_phone($phone) ?: 'Нет в портале', 'city' => '',
              'source' => 'bot', 'created_at' => $card['card_at'] ?? null, 'last_login' => null,
              'ref_code' => null, 'b24_deal_id' => $card['card'] ?? null, 'manager_id' => null];
        $friends = admin_friends_b24($phone);
    }

    // один человек может висеть сразу в нескольких воронках — показываем его один раз
    $rank = ['lost' => 0, 'lead' => 1, 'paid' => 2, 'gibdd' => 3, 'payout' => 4, 'done' => 5];
    $one = [];
    foreach ($friends as $f) {
        $key = $f['phone'] ?: 'd' . $f['deal_id'];
        if (!isset($one[$key]) || ($rank[$f['status']] ?? 0) > ($rank[$one[$key]['status']] ?? 0)) $one[$key] = $f;
    }
    $friends = array_values($one);

    // заявки из воронки «Рефералка»: и свои, и оставшиеся от чат-бота
    if (!q('SELECT 1 FROM b24_payouts WHERE owner_phone = ?', [$phone])->fetch()) {
        try { payouts_sync_user($phone); } catch (Throwable $e) { error_log('[admin payouts] ' . $e->getMessage()); }
    }
    $b24pay = payouts_of($phone);
    foreach ($friends as &$f) {
        $bp = $f['phone'] ? ($b24pay['by_friend'][$f['phone']] ?? null) : null;
        $f['payout'] = $bp ? ['status' => $bp['status'], 'method' => $bp['method'],
                               'at' => substr((string)($bp['status'] === 'paid' ? ($bp['paid_at'] ?: $bp['updated_at']) : $bp['created_at']), 0, 10),
                               'dup' => (int)($bp['dup'] ?? 1), 'deal' => (int)$bp['deal_id'],
                               'task' => (int)($bp['task_id'] ?? 0), 'test' => (int)($bp['is_test'] ?? 0)] : null;
    }
    unset($f);

    return [
        'b24_payouts' => $b24pay['all'],
        'user' => [
            'phone' => (string)$u['phone'], 'name' => $u['name'], 'city' => $u['city'] ?? '',
            'source' => $u['source'] ?? '', 'manager' => $u['manager_id'] ?? null,
            'code' => $u['ref_code'] ?? null, 'created' => $u['created_at'] ?? null,
            'last' => $u['last_login'] ?? null, 'deal' => $u['b24_deal_id'] ?? null,
            'in_portal' => $inPortal,
            'devices' => (int)q('SELECT COUNT(*) FROM push_subs WHERE phone = ?', [$phone])->fetchColumn(),
        ],
        'friends' => $friends,
        'payouts' => $payouts,
        'claims'  => $claims,
    ];
}

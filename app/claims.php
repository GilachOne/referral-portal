<?php
/**
 * Друг записался в автошколу без реферальной ссылки.
 * Участник вводит его номер — портал сам ищет заявку в Битриксе, проставляет метки
 * и дальше ведёт этого друга как обычного реферала.
 *
 * Если заявки ещё нет, запрос ждёт: друг может позвонить в автошколу завтра.
 */
declare(strict_types=1);

/** Понятные человеку причины отказа */
function claim_reasons(): array
{
    $c = cfg('claim');
    return [
        'already' => 'Этот человек уже закреплён за другим участником',
        'self'    => 'Это ваш собственный номер',
        'late'    => 'Заявка друга старше ' . $c['max_age_days'] . ' дней — обратитесь к представителю автошколы',
        'expired' => 'За ' . $c['wait_days'] . ' дней заявки от этого человека так и не появилось',
    ];
}

/** Сделки и лиды человека по номеру телефона, кроме воронки «Рефералка» */
function b24_find_by_phone(string $phone): array
{
    $cfg = cfg('bitrix');
    $out = ['deals' => [], 'leads' => []];
    $val = '+' . $phone;

    $dup = b24('crm.duplicate.findbycomm', ['entity_type' => 'CONTACT', 'type' => 'PHONE', 'values' => [$val]]);
    $contactIds = array_map('intval', $dup['result']['CONTACT'] ?? []);
    foreach (array_chunk($contactIds, 50) as $chunk) {
        $r = b24('crm.deal.list', [
            'filter' => ['CONTACT_ID' => $chunk, 'CATEGORY_ID' => $cfg['friend_categories']],
            'select' => ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', 'UTM_SOURCE', 'UTM_CAMPAIGN', 'CONTACT_ID',
                         'DATE_CREATE', 'DATE_MODIFY', 'OPPORTUNITY', $cfg['uf_gibdd']],
            'order'  => ['ID' => 'DESC'],
        ]);
        foreach ($r['result'] ?? [] as $d) $out['deals'][] = $d;
    }

    $dupL = b24('crm.duplicate.findbycomm', ['entity_type' => 'LEAD', 'type' => 'PHONE', 'values' => [$val]]);
    $leadIds = array_map('intval', $dupL['result']['LEAD'] ?? []);
    foreach (array_chunk($leadIds, 50) as $chunk) {
        $r = b24('crm.lead.list', [
            'filter' => ['ID' => $chunk],
            'select' => ['ID', 'TITLE', 'NAME', 'LAST_NAME', 'STATUS_ID', 'UTM_SOURCE', 'UTM_CAMPAIGN', 'PHONE',
                         'DATE_CREATE', 'DATE_MODIFY'],
        ]);
        foreach ($r['result'] ?? [] as $l) {
            if ((string)($l['STATUS_ID'] ?? '') === 'CONVERTED') continue;   // стал сделкой, её и смотрим
            $out['leads'][] = $l;
        }
    }
    return $out;
}

/** Запрос на привязку от участника. Сразу пробуем найти друга в Битриксе */
function claim_add(array $me, string $friendPhone, string $friendName): array
{
    $cfg = cfg('claim');
    $friendPhone = norm_phone($friendPhone);
    if (strlen($friendPhone) !== 11) fail('Введите номер друга полностью');
    if ($friendPhone === $me['phone']) fail(claim_reasons()['self']);

    $today = date('Y-m-d');
    $cnt = (int)q("SELECT COUNT(*) FROM claims WHERE owner_phone = ? AND substr(created_at, 1, 10) = ?",
        [$me['phone'], $today])->fetchColumn();
    if ($cnt >= $cfg['per_day']) fail('На сегодня хватит: не больше ' . $cfg['per_day'] . ' друзей в день');

    // этот друг уже чей-то — ни в своём списке, ни в чужом второй раз не заводим
    $own = q('SELECT * FROM claims WHERE owner_phone = ? AND friend_phone = ?', [$me['phone'], $friendPhone])->fetch();
    if ($own && $own['status'] !== 'rejected') fail('Вы уже добавляли этот номер');
    $other = q("SELECT owner_phone FROM claims WHERE friend_phone = ? AND owner_phone != ? AND status IN ('wait', 'linked')",
        [$friendPhone, $me['phone']])->fetchColumn();
    if ($other) fail(claim_reasons()['already']);
    if (q('SELECT 1 FROM refs WHERE phone = ? AND owner_phone = ?', [$friendPhone, $me['phone']])->fetch()) {
        fail('Этот друг уже в вашем списке');
    }
    $inRefs = q('SELECT owner_phone FROM refs WHERE phone = ? AND owner_phone != ?', [$friendPhone, $me['phone']])->fetchColumn();
    if ($inRefs) fail(claim_reasons()['already']);

    $name = trim(preg_replace('/\s+/u', ' ', $friendName));
    if ($own) {
        q('UPDATE claims SET friend_name = ?, status = ?, reason = NULL, deal_id = NULL, created_at = ?, decided_at = NULL
           WHERE id = ?', [$name, 'wait', date('c'), $own['id']]);
        $id = (int)$own['id'];
    } else {
        q('INSERT INTO claims (owner_phone, friend_phone, friend_name, status, created_at) VALUES (?, ?, ?, ?, ?)',
            [$me['phone'], $friendPhone, $name, 'wait', date('c')]);
        $id = (int)db()->lastInsertId();
    }
    ev('claim.created', ['actor' => $me['phone'], 'entity' => ['claim', $id],
        'payload' => ['friend' => $name, 'friend_phone' => $friendPhone]]);

    $claim = q('SELECT * FROM claims WHERE id = ?', [$id])->fetch();
    try {
        $claim = claim_try($claim);
    } catch (Throwable $e) {
        error_log('[claim] ' . $e->getMessage());   // Битрикс недоступен — попробуем на синхронизации
    }
    return $claim;
}

/** Ищем заявку друга и, если можно, привязываем её к участнику */
function claim_try(array $c): array
{
    $cfg = cfg('claim');
    $owner = (string)$c['owner_phone'];
    $found = b24_find_by_phone((string)$c['friend_phone']);
    $fresh = static fn(array $e) => strtotime((string)($e['DATE_CREATE'] ?? '')) > time() - $cfg['max_age_days'] * 86400;

    // 1. метка участника уже стоит — просто показываем друга в списке, в CRM ничего не меняем
    foreach (array_merge($found['deals'], $found['leads']) as $e) {
        if (norm_phone((string)($e['UTM_CAMPAIGN'] ?? '')) !== $owner) continue;
        return claim_link($c, isset($e['CATEGORY_ID']) ? 'deal' : 'lead', $e);
    }

    // 2. у кого-то стоит метка другого участника — спорить не даём
    foreach (array_merge($found['deals'], $found['leads']) as $e) {
        $camp = norm_phone((string)($e['UTM_CAMPAIGN'] ?? ''));
        if ($camp !== '' && $camp !== $owner && $fresh($e)) return claim_close($c, 'rejected', 'already');
    }

    // 3. берём самую свежую подходящую запись: сделка важнее лида
    $deals = array_values(array_filter($found['deals'], $fresh));
    $leads = array_values(array_filter($found['leads'], $fresh));
    if ($deals) return claim_link($c, 'deal', $deals[0]);
    if ($leads) return claim_link($c, 'lead', $leads[0]);

    // 4. записи есть, но все старые — такое решает человек, не портал
    if ($found['deals'] || $found['leads']) return claim_close($c, 'rejected', 'late');

    // 5. друг ещё не обращался — ждём, вдруг позвонит завтра
    if (strtotime((string)$c['created_at']) < time() - $cfg['wait_days'] * 86400) {
        return claim_close($c, 'rejected', 'expired');
    }
    return $c;
}

/** Ставим метки реферальной программы на заявку друга */
function claim_link(array $c, string $kind, array $e): array
{
    $owner = (string)$c['owner_phone'];
    $me = q('SELECT * FROM users WHERE phone = ?', [$owner])->fetch();
    $id = (int)$e['ID'];
    $already = norm_phone((string)($e['UTM_CAMPAIGN'] ?? '')) === $owner;

    if (!$already) {
        $fields = [
            'UTM_SOURCE'   => 'referral',
            'UTM_CAMPAIGN' => $owner,
            'UTM_MEDIUM'   => 'portal',
            'UTM_CONTENT'  => (string)($me['ref_code'] ?? ''),
        ];
        b24('crm.' . $kind . '.update', ['id' => $id, 'fields' => $fields]);
        try {
            b24('crm.timeline.comment.add', ['fields' => [
                'ENTITY_ID'   => $id,
                'ENTITY_TYPE' => $kind,
                'COMMENT'     => 'Реферальная программа: клиента привёл ' . ($me['name'] ?? '') . ' (+' . $owner . ').'
                    . ' Привязано в портале по заявке участника — он сообщил, что друг записался без ссылки.',
            ]]);
        } catch (Throwable $ex) {
            error_log('[claim timeline] ' . $ex->getMessage());
        }
    }

    // сразу показываем друга в списке участника
    ref_silent(true);
    if ($kind === 'deal') {
        $e['UTM_CAMPAIGN'] = $owner;
        ref_save_deal($e);
        $dealId = $id;
    } else {
        $e['UTM_CAMPAIGN'] = $owner;
        ref_save_lead($e);
        $dealId = -$id;
    }
    ref_silent(false);

    q('UPDATE claims SET status = ?, reason = NULL, deal_id = ?, decided_at = ? WHERE id = ?',
        ['linked', $dealId, date('c'), $c['id']]);
    ev('claim.linked', ['actor' => $owner, 'entity' => ['claim', (int)$c['id']],
        'payload' => ['friend' => $c['friend_name'], 'kind' => $kind, 'b24_id' => $id, 'was_marked' => $already]]);
    notify($owner, 'claim.linked', 'Друг привязан к вам',
        trim((string)$c['friend_name']) !== ''
            ? $c['friend_name'] . ' теперь в вашем списке друзей.'
            : 'Друг, которого вы добавили, теперь в вашем списке.');

    return q('SELECT * FROM claims WHERE id = ?', [$c['id']])->fetch();
}

function claim_close(array $c, string $status, string $reason): array
{
    q('UPDATE claims SET status = ?, reason = ?, decided_at = ? WHERE id = ?', [$status, $reason, date('c'), $c['id']]);
    ev('claim.' . $status, ['actor' => (string)$c['owner_phone'], 'entity' => ['claim', (int)$c['id']],
        'payload' => ['friend' => $c['friend_name'], 'reason' => $reason]]);
    if ($reason !== 'expired') {
        notify((string)$c['owner_phone'], 'claim.rejected', 'Друга не привязали',
            claim_reasons()[$reason] ?? 'Проверьте номер друга.');
    }
    return q('SELECT * FROM claims WHERE id = ?', [$c['id']])->fetch();
}

/** Раз в 15 минут проверяем, не появились ли заявки от тех, кого ждём */
function claim_sweep(): int
{
    $n = 0;
    foreach (q("SELECT * FROM claims WHERE status = 'wait' ORDER BY id")->fetchAll() as $c) {
        try {
            $r = claim_try($c);
            if (($r['status'] ?? 'wait') !== 'wait') $n++;
        } catch (Throwable $e) {
            error_log('[claim sweep] ' . $e->getMessage());
        }
    }
    return $n;
}

/** Список для экрана участника */
function claims_of(string $phone): array
{
    $rows = q('SELECT id, friend_name, friend_phone, status, reason, created_at FROM claims
               WHERE owner_phone = ? ORDER BY id DESC', [$phone])->fetchAll();
    $texts = claim_reasons();
    foreach ($rows as &$r) {
        $r['phone'] = substr($r['friend_phone'], 0, 4) . '•••' . substr($r['friend_phone'], -2);
        unset($r['friend_phone']);
        $r['text'] = $r['status'] === 'linked'
            ? 'Привязан к вам'
            : ($r['status'] === 'wait'
                ? 'Ищем его заявку. Как только он обратится в автошколу, друг появится в списке.'
                : ($texts[$r['reason']] ?? 'Не удалось привязать'));
    }
    return $rows;
}

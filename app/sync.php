<?php
/**
 * Подстраховка для хука: раз в 15 минут сами спрашиваем Битрикс, что изменилось.
 * Запуск:  php /srv/portal/app/sync.php
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/sheets.php';
require __DIR__ . '/events.php';
require __DIR__ . '/push.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/claims.php';
require __DIR__ . '/payouts.php';
require __DIR__ . '/accruals.php';
require __DIR__ . '/leadteh.php';
require __DIR__ . '/ashk.php';

$cfg = cfg('bitrix');
$since = kv_get('sync_since', null) ?: date('c', time() - 30 * 86400);
$startedAt = date('c');
$updated = 0;

// ---------- 1. Сделки приглашённых ----------

$deals = [];
$start = 0;
do {
    $r = b24('crm.deal.list', [
        'filter' => ['UTM_SOURCE' => 'referral', 'CATEGORY_ID' => $cfg['friend_categories'], '>DATE_MODIFY' => $since],
        'select' => ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', 'UTM_CAMPAIGN', 'CONTACT_ID',
                     'DATE_CREATE', 'DATE_MODIFY', 'OPPORTUNITY', $cfg['uf_gibdd']],
        'order'  => ['ID' => 'ASC'],
        'start'  => $start,
    ]);
    $deals = array_merge($deals, $r['result'] ?? []);
    $start = $r['next'] ?? null;
} while ($start !== null && count($deals) < 3000);

// контакты одним запросом на всех — так быстрее, чем по одному
$contactIds = array_values(array_unique(array_filter(array_column($deals, 'CONTACT_ID'))));
$contacts = [];
foreach (array_chunk($contactIds, 50) as $chunk) {
    $r = b24('crm.contact.list', ['filter' => ['ID' => $chunk], 'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE']]);
    foreach ($r['result'] ?? [] as $c) {
        $phone = '';
        foreach ($c['PHONE'] ?? [] as $p) {
            $phone = norm_phone((string)($p['VALUE'] ?? ''));
            if ($phone !== '') break;
        }
        $contacts[(int)$c['ID']] = ['name' => trim(($c['NAME'] ?? '') . ' ' . ($c['LAST_NAME'] ?? '')), 'phone' => $phone];
    }
}

foreach ($deals as $d) {
    if (ref_save_deal($d, $contacts[(int)($d['CONTACT_ID'] ?? 0)] ?? null)) $updated++;
}

// ---------- 2. Лиды, которые ещё не стали сделками ----------

$leads = [];
$start = 0;
do {
    $r = b24('crm.lead.list', [
        'filter' => ['UTM_SOURCE' => 'referral', '>DATE_MODIFY' => $since, '!STATUS_ID' => 'CONVERTED'],
        'select' => ['ID', 'TITLE', 'NAME', 'LAST_NAME', 'STATUS_ID', 'UTM_CAMPAIGN', 'PHONE', 'DATE_CREATE', 'DATE_MODIFY'],
        'order'  => ['ID' => 'ASC'],
        'start'  => $start,
    ]);
    $leads = array_merge($leads, $r['result'] ?? []);
    $start = $r['next'] ?? null;
} while ($start !== null && count($leads) < 2000);

foreach ($leads as $l) {
    if (ref_save_lead($l)) $updated++;
}

q("DELETE FROM refs WHERE deal_id < 0 AND phone != '' AND phone IN (SELECT phone FROM refs WHERE deal_id > 0)");

// ---------- 2б. Ждём друзей, которых участники добавили вручную ----------

$claims = cfg('claim')['enabled'] ? claim_sweep() : 0;

// ---------- 2в. Заявки на выплату из воронки «Рефералка» ----------

$b24pay = payouts_sync_all();

// ---------- 2г. Начисления: сводим приглашённых и заявки в одну картину ----------

$acc = accruals_rebuild();

// ---------- 2д. Контакты чат-ботов: по ним доставляем уведомления ----------

$ltRows = 0;
if (cfg('leadteh')['enabled'] && !kv_get('leadteh_sync', 86400)) {
    try { $ltRows = lt_sync_contacts(time() - 3 * 86400); } catch (Throwable $e) { error_log('[leadteh sync] ' . $e->getMessage()); }
}

// ---------- 3. Выплаты: сделка в воронке «Рефералка» закрыта как оплаченная ----------

$exported = 0;
// u.b24_deal_id не берём: это карточка участника, она затёрла бы p.b24_deal_id — сделку-заявку
$pending = q("SELECT p.*, u.name AS owner_name, u.city
              FROM payouts p JOIN users u ON u.phone = p.owner_phone
              WHERE p.exported_at IS NULL AND p.b24_sent = 1")->fetchAll();

foreach ($pending as $p) {
    if (empty($p['b24_deal_id'])) continue;
    try {
        $r = b24('crm.deal.get', ['id' => (int)$p['b24_deal_id']]);
    } catch (Throwable $e) {   // сделку удалили в Битриксе — пропускаем выплату, а не роняем синхронизацию
        error_log('[sync payout] ' . $e->getMessage());
        continue;
    }
    $stage = (string)($r['result']['STAGE_ID'] ?? '');
    if (!str_ends_with($stage, ':WON')) continue;          // ещё не выплачено — ждём

    // выплачено тогда, когда сделку перевели в «Оплачено», а не когда мы это заметили
    $paidTs = strtotime((string)($r['result']['MOVED_TIME'] ?? '')) ?: time();
    $debt = $p['method'] === 'debt';

    // Учёт выплаты — только здесь, в своей базе (payouts.paid_at + сумма + способ).
    // В «Отчёт РК» не пишем никогда: таблица чужая, B:G там — IMPORTRANGE, лишняя строка ломает формулу.
    // Уведомление о переводе на карту уже отправил разбор заявок (payouts.php), о списании долга — сообщаем сами.
    q('UPDATE payouts SET paid_at = ?, exported_at = ? WHERE id = ?', [date('c', $paidTs), date('c'), $p['id']]);
    ev('payout.paid', ['actor' => $p['owner_phone'], 'entity' => ['payout', $p['id']],
        'payload' => ['amount' => (float)$p['amount'], 'method' => $p['method']]]);
    if ($debt) {
        notify($p['owner_phone'], 'payout.paid', 'Выплата прошла',
            number_format((float)$p['amount'], 0, '', ' ') . ' ₽ списали с вашей задолженности за обучение.');
    }
    $exported++;
}

// ---------- Проверка рефереров в АШК: очередь с лимитом ----------

ashk_process();

kv_set('sync_since', $startedAt);
ev('sync.finished', ['actor_type' => 'cron', 'payload' => [
    'deals' => count($deals), 'leads' => count($leads), 'updated' => $updated,
    'claims' => $claims, 'b24_payouts' => $b24pay, 'accruals' => $acc, 'exported' => $exported]]);
ev_flush();
kv_set('sync_stats', ['at' => $startedAt, 'deals' => count($deals), 'leads' => count($leads), 'updated' => $updated, 'exported' => $exported]);
echo 'сделок: ' . count($deals) . ', лидов: ' . count($leads) . ", обновлено: $updated, заявок: $b24pay, выгружено выплат: $exported\n";
printf("начислено %d (%s ₽), заявлено %d, выплачено %d, отказов %d%s\n", $acc['accrued'], number_format($acc['sum_accrued'], 0, '', ' '),
    $acc['requested'], $acc['paid'], $acc['rejected'], $acc['avg_days'] !== null ? ', средний срок ' . $acc['avg_days'] . ' дн' : '');

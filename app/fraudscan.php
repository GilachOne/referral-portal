<?php
/**
 * Разовая проверка уже накопленных приглашённых на накрутки.
 *   php app/fraudscan.php --dry   посмотреть, кого не засчитаем
 *   php app/fraudscan.php         записать результат
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/events.php';
require __DIR__ . '/push.php';
require __DIR__ . '/stats.php';

$dry = in_array('--dry', $argv, true);
$rows = q('SELECT deal_id, owner_phone, name, phone, status, created_at FROM refs ORDER BY created_at')->fetchAll();
printf("приглашённых в базе: %d\n", count($rows));

$found = [];
// контакты сделок забираем пакетами: 50 карточек за один запрос
$cmd = [];
foreach ($rows as $r) if ($r['deal_id'] > 0) $cmd['d' . $r['deal_id']] = ['crm.deal.get', ['id' => (int)$r['deal_id']]];
$cards = b24_batch($cmd);
printf("карточек прочитано пакетами: %d\n", count($cards));

foreach ($rows as $r) {
    $contactId = (int)($cards['d' . $r['deal_id']]['CONTACT_ID'] ?? 0);
    $code = ref_fraud_check((string)$r['owner_phone'], (string)$r['phone'], $contactId, (string)$r['created_at']);
    if ($code === '') continue;
    $found[] = $r + ['reject' => $code];
    printf("  %-13s за %-26s %s — %s\n", $r['owner_phone'], mb_substr((string)$r['name'], 0, 24), $r['phone'] ?: '-', ref_reject_text($code));
    if (!$dry) {
        q('UPDATE refs SET reject = ?, reject_at = ? WHERE deal_id = ?', [$code, date('c'), (int)$r['deal_id']]);
        ev('ref.rejected', ['actor' => (string)$r['owner_phone'], 'entity' => ['ref', (int)$r['deal_id']],
            'payload' => ['friend' => $r['name'], 'reason' => $code, 'scan' => true]]);
    }
}
if (!$dry) ev_flush();
printf("\nне засчитано: %d из %d%s\n", count($found), count($rows), $dry ? ' (примерка)' : '');

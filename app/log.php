<?php
/**
 * Чтение журнала событий из консоли:
 *   php app/log.php            последние 30 событий
 *   php app/log.php 79000000001  всё по участнику
 *   php app/log.php --funnel   путь друзей по этапам
 *   php app/log.php --payouts   что выбирают при выплате
 *   php app/log.php --daily     сводка по дням
 */
declare(strict_types=1);

if (!function_exists('mb_str_pad')) {
    function mb_str_pad(string $s, int $len): string { return $s . str_repeat(' ', max(0, $len - mb_strlen($s))); }
}
require __DIR__ . '/lib.php';
require __DIR__ . '/events.php';

$db = ev_db();
if (!$db) exit("Нет связи с базой журнала\n");

$arg = $argv[1] ?? '';
$print = function (array $rows) {
    if (!$rows) { echo "пусто\n"; return; }
    $cols = array_keys($rows[0]);
    $w = [];
    foreach ($cols as $c) {
        $w[$c] = min(60, max(mb_strlen($c), ...array_map(fn($r) => mb_strlen((string)$r[$c]), $rows)));
    }
    foreach ([$cols, null] as $head) {
        if ($head === null) { echo str_repeat('-', array_sum($w) + 3 * count($w)) . "\n"; continue; }
        echo implode('   ', array_map(fn($c) => mb_str_pad($c, $w[$c]), $cols)) . "\n";
    }
    foreach ($rows as $r) {
        echo implode('   ', array_map(fn($c) => mb_str_pad(mb_substr((string)$r[$c], 0, $w[$c]), $w[$c]), $cols)) . "\n";
    }
};

if ($arg === '--funnel') {
    $sql = "SELECT to_char(ts,'DD.MM HH24:MI') AS когда, participant AS участник, event AS событие,
                   coalesce(friend,'') AS друг, coalesce(from_status,'') || '→' || coalesce(to_status,'') AS этап
            FROM v_referral_funnel WHERE event LIKE 'ref.%' LIMIT 40";
} elseif ($arg === '--payouts') {
    $sql = "SELECT month AS месяц, method AS способ, requests AS заявок, total AS сумма FROM v_payout_choice";
} elseif ($arg === '--daily') {
    $sql = "SELECT day AS день, event AS событие, ok AS успешно, errors AS ошибок FROM v_events_daily LIMIT 40";
} elseif (preg_match('/^\d{11}$/', $arg)) {
    $sql = "SELECT to_char(ts,'DD.MM HH24:MI') AS когда, event AS событие, status AS итог,
                   left(payload::text, 70) AS данные
            FROM events WHERE actor_id = " . $db->quote($arg) . " ORDER BY id DESC LIMIT 50";
} else {
    $sql = "SELECT to_char(ts,'DD.MM HH24:MI') AS когда, app AS портал, event AS событие,
                   coalesce(actor_id, actor_type) AS кто, status AS итог, left(payload::text, 60) AS данные
            FROM events ORDER BY id DESC LIMIT " . (int)($arg ?: 30);
}

$print($db->query($sql)->fetchAll());

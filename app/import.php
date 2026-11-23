<?php
/**
 * Разовый перенос участников из чат-ботов в портал.
 * Берём всех, кто в Битриксе хоть раз привёл друга (UTM_CAMPAIGN = его номер),
 * заводим им кабинет и подтягиваем приглашённых за всё время.
 *
 * Имена: сначала контакт в Битриксе, если там пусто — имя из выгрузки LeadTeh
 * (data/leadteh-names.json, собрана отдельно по ключу API).
 *
 *   php app/import.php --dry     только показать, кого добавим
 *   php app/import.php           завести кабинеты
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/events.php';
require __DIR__ . '/push.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/claims.php';
require __DIR__ . '/admin.php';

$dry = in_array('--dry', $argv, true);
$namesFile = __DIR__ . '/data/leadteh-names.json';
$leadteh = is_file($namesFile) ? (json_decode((string)file_get_contents($namesFile), true) ?: []) : [];
printf("справочник имён из ботов: %d номеров\n", count($leadteh));

$registry = admin_registry(true);
printf("участников в Битриксе: %d\n", count($registry));

$added = $skipped = $friends = 0;
foreach ($registry as $m) {
    $phone = $m['phone'];
    $exists = q('SELECT phone FROM users WHERE phone = ?', [$phone])->fetch();
    if ($exists) { $skipped++; continue; }

    $name = admin_name_by_phone($phone);
    // в CRM попадаются служебные заголовки вместо имени — такие не тащим
    if (preg_match('/^(без имени|входящий вызов|исходящий|не определ|unknown|[0-9]+)/iu', trim($name))) $name = '';
    $city = '';
    if (isset($leadteh[$phone])) {
        if ($name === '') $name = (string)($leadteh[$phone]['name'] ?? '');
        $city = (string)($leadteh[$phone]['city'] ?? '');
    }
    $name = trim(preg_replace('/\s+/u', ' ', $name)) ?: 'Участник';
    $city = $city === 'cherepovets' ? 'cherepovets' : 'kazan';

    printf("%-13s %-34s друзей в CRM: %2d  %s\n", $phone, mb_substr($name, 0, 32), $m['friends'], $dry ? '(примерка)' : '');
    if ($dry) { $added++; continue; }

    q('INSERT INTO users (phone, name, city, source, created_at) VALUES (?, ?, ?, ?, ?)',
        [$phone, $name, $city, 'bot', $m['card_at'] ?: date('c')]);
    ref_code($phone);
    ev('user.imported', ['actor' => $phone, 'entity' => ['user', $phone],
        'payload' => ['name' => $name, 'from' => 'bot', 'friends_expected' => $m['friends']]]);
    $added++;
    $friends += ref_backfill($phone, true);
    usleep(300000);   // Битрикс не любит больше двух запросов в секунду
}

if (!$dry) ev_flush();
printf("\nзавели кабинетов: %d, уже были: %d, подтянуто приглашённых: %d\n", $added, $skipped, $friends);

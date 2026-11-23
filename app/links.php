<?php
/**
 * Персональные ссылки сотрудников: по ним видно, кто привёл участника.
 *   php app/links.php              все активные сотрудники
 *   php app/links.php 60012 59720  только нужные
 *   php app/links.php --csv        для вставки в таблицу
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$args = array_slice($argv, 1);
$csv = in_array('--csv', $args, true);
$ids = array_values(array_filter($args, fn($a) => preg_match('/^\d+$/', $a)));

$users = [];
if ($ids) {
    foreach ($ids as $id) {
        $u = b24('user.get', ['ID' => $id])['result'][0] ?? null;
        if ($u) $users[] = $u;
    }
} else {
    $start = 0;
    do {
        $r = b24('user.get', ['FILTER' => ['ACTIVE' => true], 'start' => $start]);
        $users = array_merge($users, $r['result'] ?? []);
        $start = $r['next'] ?? null;
    } while ($start !== null);
}

usort($users, fn($a, $b) => strcmp(($a['LAST_NAME'] ?? '') . $a['NAME'], ($b['LAST_NAME'] ?? '') . $b['NAME']));
$base = rtrim(cfg('base_url'), '/');

if ($csv) echo "ID;Сотрудник;Ссылка\n";
foreach ($users as $u) {
    $name = trim(($u['LAST_NAME'] ?? '') . ' ' . ($u['NAME'] ?? ''));
    $link = $base . '/m/' . $u['ID'];
    $pad = $name . str_repeat(' ', max(0, 28 - mb_strlen($name)));
    echo $csv ? "{$u['ID']};{$name};{$link}\n"
              : str_pad((string)$u['ID'], 8) . $pad . $link . "\n";
}

<?php
/**
 * Заводим партнёрский канал из командной строки:
 *   php app/campaign.php coffeelike "Coffee Like Череповец" --city=cherepovets --partner="Coffee Like" --content=sertifikat
 *   php app/campaign.php --list
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/events.php';
require __DIR__ . '/push.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/campaigns.php';

$args = array_slice($argv, 1);
$opt = [];
$plain = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z_]+)=?(.*)$/', $a, $m)) $opt[$m[1]] = $m[2];
    else $plain[] = $a;
}

if (isset($opt['list']) || !$plain) {
    foreach (campaigns_all() as $c) {
        $s = campaign_stats((string)$c['code']);
        printf("%-14s %-28s %s\n   %s\n   переходов %d · заявок %d · сделок %d · оплатили %d · в ГИБДД %d\n",
            $c['code'], mb_substr((string)$c['title'], 0, 26), $c['city'] === 'cherepovets' ? 'Череповец' : 'Казань',
            campaign_short($c), (int)$c['clicks'], $s['leads'], $s['deals'], $s['paid'], $s['gibdd']);
        printf("   ведёт %s · участников из канала %d, они привели %d (оплатили %d)
",
            ($c['target'] ?? 'landing') === 'portal' ? 'в кабинет рефералки' : 'на сайт автошколы',
            $s['referrers'] ?? 0, $s['friends_total'] ?? 0, $s['friends_paid'] ?? 0);
    }
    exit;
}

$code = $plain[0];
$c = campaign_save($code, [
    'title'       => $plain[1] ?? ($opt['title'] ?? $code),
    'partner'     => $opt['partner'] ?? '',
    'city'        => $opt['city'] ?? 'kazan',
    'landing'     => $opt['landing'] ?? '',
    'utm_source'  => $opt['source'] ?? 'partner',
    'utm_medium'  => $opt['medium'] ?? 'qr',
    'utm_content' => $opt['content'] ?? '',
    'target'      => $opt['target'] ?? 'landing',
]);
ev('campaign.saved', ['actor_type' => 'cli', 'payload' => ['code' => $c['code'], 'title' => $c['title']]]);
ev_flush();

echo "канал: {$c['code']} — {$c['title']}\n";
echo "ссылка для QR: " . campaign_short($c) . "\n";
echo "ведёт на:      " . campaign_destination($c) . "\n";
if (($c['target'] ?? '') === 'portal') echo "метка канала прилипнет к участнику и поедет в ссылки его друзей\n";

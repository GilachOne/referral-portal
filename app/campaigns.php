<?php
/**
 * Партнёрские ссылки: QR на сертификатах, листовки, совместные акции.
 * Это не реферал-человек, а канал: ссылка ведёт сразу на лендинг автошколы
 * с метками, по которым потом видно, сколько учеников пришло из этого канала.
 *
 * Короткий адрес: referral.example.com/go/КОД
 */
declare(strict_types=1);

const CAMPAIGN_TTL = 600;

function campaign_get(string $code): ?array
{
    $row = q('SELECT * FROM campaigns WHERE code = ?', [strtolower($code)])->fetch();
    return $row ?: null;
}

function campaigns_all(): array
{
    return q('SELECT * FROM campaigns ORDER BY created_at DESC')->fetchAll();
}

/** Куда ведёт партнёрская ссылка: лендинг города со всеми метками */
function campaign_url(array $c): string
{
    $r = cfg('referral');
    $base = $c['landing'] ?: ($c['city'] === 'cherepovets' ? $r['landing_cherepovets'] : $r['landing']);
    $q = [];
    if ($c['city'] === 'cherepovets') $q['utm'] = 'chp';   // так размечены все череповецкие ссылки
    $q += [
        'utm_source'   => $c['utm_source'] ?: 'partner',
        'utm_medium'   => $c['utm_medium'] ?: 'qr',
        'utm_campaign' => $c['code'],
        'ref'          => $c['code'],                      // дубль метки для лендинга
    ];
    if ($c['utm_content'] !== '') $q['utm_content'] = $c['utm_content'];
    return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($q);
}

/** Куда отправляем человека: в кабинет рефералки или сразу на сайт автошколы */
function campaign_destination(array $c, string $phone = ''): string
{
    if (($c['target'] ?? 'landing') !== 'portal') return campaign_url($c);
    // в портал: метка уже в куке. Если бот подставил телефон — человек сразу на вводе кода
    $q = ['from' => $c['code']];
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) >= 10) $q['p'] = $digits;
    return rtrim(cfg('base_url'), '/') . '/?' . http_build_query($q);
}

function campaign_short(array $c): string
{
    return rtrim(cfg('base_url'), '/') . '/go/' . $c['code'];
}

function campaign_click(array $c): void
{
    q('UPDATE campaigns SET clicks = clicks + 1, last_click = ? WHERE code = ?', [date('c'), $c['code']]);
}

/** Заводим или обновляем канал */
function campaign_save(string $code, array $in): array
{
    $code = mb_strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $code));
    if ($code === '') throw new RuntimeException('Пустой код канала');
    $old = campaign_get($code);
    $fields = [
        'target'      => ($in['target'] ?? ($old['target'] ?? 'landing')) === 'portal' ? 'portal' : 'landing',
        'title'       => (string)($in['title'] ?? ($old['title'] ?? $code)),
        'partner'     => (string)($in['partner'] ?? ($old['partner'] ?? '')),
        'city'        => ($in['city'] ?? ($old['city'] ?? 'kazan')) === 'cherepovets' ? 'cherepovets' : 'kazan',
        'landing'     => (string)($in['landing'] ?? ($old['landing'] ?? '')),
        'utm_source'  => (string)($in['utm_source'] ?? ($old['utm_source'] ?? 'partner')),
        'utm_medium'  => (string)($in['utm_medium'] ?? ($old['utm_medium'] ?? 'qr')),
        'utm_content' => (string)($in['utm_content'] ?? ($old['utm_content'] ?? '')),
    ];
    if ($old) {
        q('UPDATE campaigns SET title = :title, partner = :partner, city = :city, landing = :landing,
             utm_source = :utm_source, utm_medium = :utm_medium, utm_content = :utm_content, target = :target
           WHERE code = :code', $fields + ['code' => $code]);
    } else {
        q('INSERT INTO campaigns (code, title, partner, city, landing, utm_source, utm_medium, utm_content, target, created_at)
           VALUES (:code, :title, :partner, :city, :landing, :utm_source, :utm_medium, :utm_content, :target, :created)',
            $fields + ['code' => $code, 'created' => date('c')]);
    }
    return campaign_get($code);
}

/**
 * Сколько людей пришло по каналу: смотрим в Битриксе лиды и сделки с нашей меткой.
 * Считаем воронки «Автошкола» и «Череповец» — те же, что и для приглашённых.
 */
function campaign_stats(string $code, bool $fresh = false): array
{
    $key = 'campaign:' . $code;
    if (!$fresh && ($c = kv_get($key, CAMPAIGN_TTL)) !== null) return $c;

    $b = cfg('bitrix');
    $out = ['leads' => 0, 'deals' => 0, 'paid' => 0, 'gibdd' => 0, 'lost' => 0, 'sum' => 0.0,
             'referrers' => 0, 'invited' => 0, 'invited_paid' => 0, 'people' => []];
    try {
        $deals = b24_all('crm.deal.list', [
            'filter' => ['UTM_CAMPAIGN' => $code, 'CATEGORY_ID' => $b['friend_categories']],
            'select' => ['ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID', 'CONTACT_ID', 'OPPORTUNITY',
                         'DATE_CREATE', 'DATE_MODIFY', $b['uf_gibdd']],
            'order'  => ['ID' => 'DESC'],
        ]);
        $seen = [];
        foreach ($deals as $d) {
            $key2 = (int)($d['CONTACT_ID'] ?? 0) ?: 'd' . $d['ID'];
            if (isset($seen[$key2])) continue;        // один человек может висеть в двух воронках
            $seen[$key2] = true;
            $status = ref_status($d);
            $out['deals']++;
            if ($status === 'lost') $out['lost']++;
            if (in_array($status, ['paid', 'gibdd'], true)) { $out['paid']++; $out['sum'] += (float)$d['OPPORTUNITY']; }
            if ($status === 'gibdd') $out['gibdd']++;
            if (count($out['people']) < 50) {
                $out['people'][] = ['id' => (int)$d['ID'], 'title' => (string)$d['TITLE'],
                                    'status' => $status, 'at' => substr((string)$d['DATE_CREATE'], 0, 10)];
            }
        }
        $leads = b24_all('crm.lead.list', [
            'filter' => ['UTM_CAMPAIGN' => $code, '!STATUS_ID' => 'CONVERTED'],
            'select' => ['ID', 'TITLE', 'STATUS_ID', 'DATE_CREATE'],
            'order'  => ['ID' => 'DESC'],
        ]);
        $out['leads'] = count($leads);

        // вторая волна: тех, кого привели пришедшие из этого канала.
        // метка канала едет в utm_medium по всей цепочке приглашений
        $second = b24_all('crm.deal.list', [
            'filter' => ['UTM_SOURCE' => 'referral', 'UTM_MEDIUM' => $code, 'CATEGORY_ID' => $b['friend_categories']],
            'select' => ['ID', 'STAGE_ID', 'CATEGORY_ID', 'CONTACT_ID', 'OPPORTUNITY', $b['uf_gibdd']],
            'order'  => ['ID' => 'DESC'],
        ]);
        $seen2 = [];
        foreach ($second as $d) {
            $k = (int)($d['CONTACT_ID'] ?? 0) ?: 'd' . $d['ID'];
            if (isset($seen2[$k])) continue;
            $seen2[$k] = true;
            $out['invited']++;
            $st = ref_status($d);
            if (in_array($st, ['paid', 'gibdd'], true)) $out['invited_paid']++;
        }
        $out['invited'] += count(b24_all('crm.lead.list', [
            'filter' => ['UTM_SOURCE' => 'referral', 'UTM_MEDIUM' => $code, '!STATUS_ID' => 'CONVERTED'],
            'select' => ['ID'], 'order' => ['ID' => 'DESC'],
        ]));
        // участники, пришедшие из канала, и что они принесли
        $rows = q("SELECT u.phone, u.name, u.city, u.campaign_src, u.created_at,
                          (SELECT COUNT(*) FROM refs r WHERE r.owner_phone = u.phone
                             AND (r.reject IS NULL OR r.reject = '')) AS friends,
                          (SELECT COUNT(*) FROM refs r WHERE r.owner_phone = u.phone
                             AND r.status IN ('paid','gibdd','payout','done')
                             AND (r.reject IS NULL OR r.reject = '')) AS paid
                   FROM users u WHERE u.campaign = ? ORDER BY friends DESC", [$code])->fetchAll();
        $out['referrers'] = count($rows);
        $out['referrers_list'] = array_slice($rows, 0, 50);
        $out['friends_total'] = array_sum(array_column($rows, 'friends'));
        $out['friends_paid'] = array_sum(array_column($rows, 'paid'));
    } catch (Throwable $e) {
        error_log('[campaign stats] ' . $e->getMessage());
    }
    kv_set($key, $out);
    return $out;
}

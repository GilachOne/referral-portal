<?php
/**
 * Бюджет и аналитика рефералки для руководства.
 * Считаем по всей программе, а не только по участникам портала: приглашённые — из Битрикса
 * (сделки и лиды с UTM_SOURCE=referral, в том числе из чат-бота), выплаты — из «Отчёта РК».
 * Считать долго (десятки запросов к Битриксу), поэтому держим результат в кэше.
 */
declare(strict_types=1);

const STATS_TTL = 600;

function ref_stats(bool $fresh = false): array
{
    if (!$fresh && ($cached = kv_get('ref_stats', STATS_TTL))) return $cached;
    $data = ref_stats_build();
    kv_set('ref_stats', $data);
    return $data;
}

/** Все страницы списка Битрикса */
function b24_all(string $method, array $params, int $limit = 5000): array
{
    $rows = [];
    $start = 0;
    do {
        $r = b24($method, $params + ['start' => $start]);
        $rows = array_merge($rows, $r['result'] ?? []);
        $start = $r['next'] ?? null;
    } while ($start !== null && count($rows) < $limit);
    return $rows;
}

/** «-6 000,00» → 6000.0 */
function sheet_money(string $v): float
{
    $v = str_replace([' ', "\u{00A0}", "\u{202F}", '₽'], '', $v);
    return abs((float)str_replace(',', '.', $v));
}

/** «04.08.2026» → «2026-08» */
function sheet_month(string $v): ?string
{
    return preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', trim($v), $m) ? sprintf('%s-%02d', $m[3], $m[2]) : null;
}

function ref_stats_build(): array
{
    $b = cfg('bitrix');
    $ref = cfg('referral');
    $month = fn(?string $iso) => $iso ? substr($iso, 0, 7) : null;

    // ---------- 1. Приглашённые: сделки в воронках «Автошкола» и «Череповец», сведённые к людям ----------
    // остальные воронки (обучение, сервис, реанимация) — служебные копии той же продажи

    $deals = b24_all('crm.deal.list', [
        'filter' => ['UTM_SOURCE' => 'referral', 'CATEGORY_ID' => $b['friend_categories']],
        'select' => ['ID', 'CATEGORY_ID', 'STAGE_ID', 'OPPORTUNITY', 'DATE_CREATE', 'DATE_MODIFY', 'UTM_CAMPAIGN', 'CONTACT_ID',
                     $b['uf_gibdd'], $b['uf_sale_date']],
        'order'  => ['ID' => 'ASC'],
    ]);
    $semantics = b24_stage_semantics($b['friend_categories']);

    $contactIds = array_values(array_unique(array_filter(array_map('intval', array_column($deals, 'CONTACT_ID')))));
    $phones = [];
    $cnames = [];
    foreach (array_chunk($contactIds, 50) as $chunk) {
        foreach (b24_all('crm.contact.list', ['filter' => ['ID' => $chunk], 'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE']]) as $c) {
            $cnames[(int)$c['ID']] = trim(($c['NAME'] ?? '') . ' ' . ($c['LAST_NAME'] ?? ''));
            foreach ($c['PHONE'] ?? [] as $p) {
                $ph = norm_phone((string)($p['VALUE'] ?? ''));
                if ($ph !== '') { $phones[(int)$c['ID']] = $ph; break; }
            }
        }
    }

    // повторная заявка того же человека — ещё одна сделка; берём самый дальний этап
    $rank = ['lost' => 0, 'lead' => 1, 'paid' => 2, 'gibdd' => 3];
    $people = [];
    foreach ($deals as $d) {
        $cid = (int)($d['CONTACT_ID'] ?? 0);
        $key = $phones[$cid] ?? ($cid ? 'c' . $cid : 'd' . $d['ID']);
        // успешная стадия («Я молодец!») — оплатил, провальная — не дошёл, отметка ГИБДД — в этой же сделке
        $sem = $semantics[(string)$d['STAGE_ID']] ?? 'process';
        $st = $sem === 'success' ? 'paid' : (in_array($sem, ['failure', 'apology'], true) ? 'lost' : 'lead');
        if ($st !== 'lost' && (string)($d[$b['uf_gibdd']] ?? '') === $b['gibdd_yes']) $st = 'gibdd';
        $p = $people[$key] ?? [
            'status' => 'lost', 'owner' => '', 'created' => $d['DATE_CREATE'], 'paid_month' => null, 'revenue' => 0.0,
            'name' => $cnames[$cid] ?? '', 'sale' => null, 'gibdd_at' => null,
        ];
        if ($d['DATE_CREATE'] < $p['created']) $p['created'] = $d['DATE_CREATE'];
        $owner = norm_phone((string)($d['UTM_CAMPAIGN'] ?? ''));
        if (strlen($owner) === 11) $p['owner'] = $owner;
        if (empty($p['link']) || $rank[$st] >= $rank[$p['status']]) $p['link'] = ['deal', (int)$d['ID']];
        if ($rank[$st] > $rank[$p['status']]) $p['status'] = $st;
        // своей даты у отметки ГИБДД нет — берём дату изменения сделки
        if ($st === 'gibdd') $p['gibdd_at'] = substr((string)$d['DATE_MODIFY'], 0, 10);
        if ($st === 'paid' || $st === 'gibdd') {
            $sale = substr((string)($d[$b['uf_sale_date']] ?: $d['DATE_MODIFY']), 0, 10);
            $p['sale'] = $p['sale'] ? min($p['sale'], $sale) : $sale;
            $p['paid_month'] = $month($p['sale']);
            $p['revenue'] = max($p['revenue'], (float)$d['OPPORTUNITY']);
        }
        $people[$key] = $p;
    }

    // лиды, которые так и не стали сделками
    $leads = b24_all('crm.lead.list', [
        'filter' => ['UTM_SOURCE' => 'referral', '!STATUS_ID' => 'CONVERTED'],
        'select' => ['ID', 'STATUS_ID', 'DATE_CREATE', 'UTM_CAMPAIGN', 'PHONE', 'NAME', 'LAST_NAME'],
        'order'  => ['ID' => 'ASC'],
    ]);
    foreach ($leads as $l) {
        $ph = '';
        foreach ($l['PHONE'] ?? [] as $x) {
            $ph = norm_phone((string)($x['VALUE'] ?? ''));
            if ($ph !== '') break;
        }
        $key = $ph !== '' ? $ph : 'l' . $l['ID'];
        if (isset($people[$key])) continue;
        $owner = norm_phone((string)($l['UTM_CAMPAIGN'] ?? ''));
        $people[$key] = [
            'status' => ($l['STATUS_ID'] ?? '') === 'JUNK' ? 'lost' : 'lead',
            'owner' => strlen($owner) === 11 ? $owner : '', 'created' => $l['DATE_CREATE'],
            'paid_month' => null, 'revenue' => 0.0, 'link' => ['lead', (int)$l['ID']],
            'name' => trim((string)($l['NAME'] ?? '') . ' ' . (string)($l['LAST_NAME'] ?? '')), 'sale' => null, 'gibdd_at' => null,
        ];
    }
    // друзья тестовых пригласивших — это проверки, не люди
    $test = ref_test_phones();
    $testName = cfg('referral')['test_names'] ?? '';
    $people = array_filter($people, fn($p, $key) => !in_array($p['owner'], $test, true) && !in_array((string)$key, $test, true)
        && !($testName && preg_match($testName, (string)$p['name'])), ARRAY_FILTER_USE_BOTH);
    [$people, $forecast, $lag] = ref_forecast($people);

    // ---------- 2. Выплаты по факту: «выгрузка расходов» в «Отчёте РК» ----------

    $m = cfg('marketing');
    $rows = [];
    try {
        $v = http_json('GET', 'https://sheets.googleapis.com/v4/spreadsheets/' . $m['sheet_id']
            . '/values/' . rawurlencode($m['expenses_range']), null, g_token());
        $rows = array_slice($v['values'] ?? [], 1);
    } catch (Throwable $e) {
        error_log('[stats sheet] ' . $e->getMessage());
    }
    $spent = [];      // месяц => выплачено по рефералке
    $marketing = [];  // месяц => источник => расход
    foreach ($rows as $r) {
        $src = trim((string)($r[0] ?? ''));
        $mon = sheet_month((string)($r[4] ?? ''));
        $sum = sheet_money((string)($r[1] ?? ''));
        if ($src === '' || !$mon || $sum <= 0) continue;
        $marketing[$mon][$src] = ($marketing[$mon][$src] ?? 0) + $sum;
        if ($src === 'Рефералка') $spent[$mon] = ($spent[$mon] ?? 0) + $sum;
    }

    // ---------- 3. Что выбирают пригласившие: поле «Тип выплаты» в воронке «Рефералка» ----------

    $c70 = b24_all('crm.deal.list', [
        'filter' => ['CATEGORY_ID' => $b['category']],
        'select' => ['ID', 'TITLE', 'STAGE_ID', 'CONTACT_ID', 'UTM_CAMPAIGN', $b['uf_payout']],
    ]);
    $c70 = array_filter($c70, fn($d) => !in_array(ref_deal_phone($d), $test, true));
    $choice = ['money' => 0, 'debt' => 0, 'none' => 0];
    $c70stages = ['new' => 0, 'payout' => 0, 'won' => 0, 'lost' => 0];
    foreach ($c70 as $d) {
        $t = (string)($d[$b['uf_payout']] ?? '');
        $choice[$t === $b['payout_money'] ? 'money' : ($t === $b['payout_debt'] ? 'debt' : 'none')]++;
        $s = (string)$d['STAGE_ID'];
        $c70stages[$s === $b['stage_pay'] ? 'payout' : (str_ends_with($s, ':WON') ? 'won' : (str_ends_with($s, ':LOSE') ? 'lost' : 'new'))]++;
    }
    $payouts = ref_stats_payouts($people);

    // ---------- 4. Сводка по месяцам ----------

    $months = [];
    $touch = function (string $mon) use (&$months) {
        $months[$mon] ??= ['month' => $mon, 'invited' => 0, 'paid' => 0, 'revenue' => 0.0, 'spent' => 0.0, 'marketing' => 0.0];
    };
    foreach ($people as $p) {
        $mon = $month($p['created']);
        $touch($mon);
        $months[$mon]['invited']++;
        if ($p['paid_month']) {
            $touch($p['paid_month']);
            $months[$p['paid_month']]['paid']++;
            $months[$p['paid_month']]['revenue'] += $p['revenue'];
        }
    }
    foreach ($spent as $mon => $sum) { $touch($mon); $months[$mon]['spent'] = $sum; }
    foreach ($marketing as $mon => $bySrc) { $touch($mon); $months[$mon]['marketing'] = array_sum($bySrc); }
    ksort($months);

    // ---------- 5. Кто приводит ----------

    $owners = [];
    foreach ($people as $p) {
        if ($p['owner'] === '') continue;
        $o = &$owners[$p['owner']];
        $o ??= ['invited' => 0, 'paid' => 0, 'gibdd' => 0];
        $o['invited']++;
        if (in_array($p['status'], ['paid', 'gibdd'], true)) $o['paid']++;
        if ($p['status'] === 'gibdd') $o['gibdd']++;
        unset($o);
    }
    uasort($owners, fn($a, $b2) => [$b2['paid'], $b2['invited']] <=> [$a['paid'], $a['invited']]);
    // имена: из портала, а пришедших из чат-бота — по контакту их сделки в воронке «Рефералка»
    $topOwners = array_slice($owners, 0, 10, true);
    $names = array_column(q('SELECT phone, name FROM users')->fetchAll(), 'name', 'phone');
    $need = [];
    foreach ($c70 as $d) {
        $ph = ref_deal_phone($d);
        if (isset($topOwners[$ph]) && empty($names[$ph]) && (int)$d['CONTACT_ID']) $need[(int)$d['CONTACT_ID']] = $ph;
    }
    if ($need) {
        try {
            foreach (b24('crm.contact.list', ['filter' => ['ID' => array_keys($need)], 'select' => ['ID', 'NAME', 'LAST_NAME']])['result'] ?? [] as $c) {
                $names[$need[(int)$c['ID']]] = trim(($c['NAME'] ?? '') . ' ' . ($c['LAST_NAME'] ?? ''));
            }
        } catch (Throwable $e) {
            error_log('[stats names] ' . $e->getMessage());
        }
    }
    $top = [];
    foreach ($topOwners as $phone => $o) {
        $phone = (string)$phone;
        $top[] = $o + [
            'name'  => $names[$phone] ?? '',
            'phone' => substr($phone, 0, 4) . '•••' . substr($phone, -4),
        ];
    }

    // ---------- Итог ----------

    $count = fn(array $st) => count(array_filter($people, fn($p) => in_array($p['status'], $st, true)));
    $paid = $count(['paid', 'gibdd']);
    $gibdd = $count(['gibdd']);
    $discounts = $paid * $ref['discount'];           // скидки друзьям — тоже наш расход
    $revenue = array_sum(array_column($people, 'revenue'));
    $full = $paid * ($ref['reward'] + $ref['discount']);   // во что обойдутся все оплатившие

    $users = q("SELECT source, COUNT(*) AS n FROM users GROUP BY source")->fetchAll();
    $clicks = 0;
    foreach (q("SELECT v FROM kv WHERE k LIKE 'clicks:%'")->fetchAll() as $r) $clicks += (int)json_decode($r['v']);

    return [
        'built_at' => date('c'),
        'terms'    => ['reward' => $ref['reward'], 'discount' => $ref['discount'], 'budget_month' => (int)($ref['budget_month'] ?? 0)],
        'funnel'   => [
            'invited'  => count($people),
            'active'   => $count(['lead']),
            'lost'     => $count(['lost']),
            'paid'     => $paid,
            'gibdd'    => $gibdd,
            'referrers'=> count($owners),
        ],
        'budget'   => [
            'spent'     => array_sum($spent),                     // выплачено по «Отчёту РК»
            // заявки на стадии «Выплата» без тестов и без закрытой задачи — то, что реально ждёт денег
            'to_pay'    => count(array_filter($payouts, fn($p) => $p['open'] && !$p['test'] && !$p['task_done'])) * $ref['reward'],
            'forecast'  => array_sum(array_column($forecast['months'], 'sum')),  // оплатили, ждут ГИБДД
            'discounts' => $discounts,
            'full'      => $full,
            'revenue'   => $revenue,                              // сумма договоров оплативших
            'per_rub'   => $full ? round($revenue / $full, 1) : 0,
        ],
        'choice'   => $choice,
        'c70'      => $c70stages,
        'payouts'  => $payouts,
        'forecast' => $forecast + ['lag' => $lag],
        // по человеку на строку — для когорт на экране
        'people'   => array_values(array_map(fn($p) => [
            'month'  => substr((string)$p['created'], 0, 7),
            'name'   => $p['name'],
            'status' => $p['stale'] ? 'stale' : $p['status'],
            'link'   => $p['link'] ?? null,
            'sale'   => $p['sale'],
            'gibdd'  => $p['gibdd_at'],
            'expect' => $p['expect'],
        ], $people)),
        'b24_url'  => b24_portal_url(),
        'months'   => array_values($months),
        'channels' => $marketing,
        'top'      => $top,
        'portal'   => ['users' => array_column($users, 'n', 'source'), 'clicks' => $clicks],
    ];
}

/** https://example.bitrix24.ru — из адреса вебхука, для ссылок на карточки */
function b24_portal_url(): string
{
    return preg_replace('#/rest/.*$#', '', cfg('bitrix')['webhook']);
}

/**
 * Заявки на выплату: сделки воронки «Рефералка» на стадиях «Выплата» и «Оплачено»,
 * с задачами по ним и сделками друзей. Помечаем тесты, повторы и закрытые задачи —
 * задача закрыта, а стадия не сдвинута, значит деньги, скорее всего, уже ушли.
 */
function ref_stats_payouts(array $people): array
{
    $b = cfg('bitrix');
    $won = 'C' . $b['category'] . ':WON';
    $deals = b24_all('crm.deal.list', [
        'filter' => ['CATEGORY_ID' => $b['category'], 'STAGE_ID' => [$b['stage_pay'], $won]],
        'select' => ['ID', 'TITLE', 'STAGE_ID', 'DATE_CREATE', 'DATE_MODIFY', 'CONTACT_ID', 'UTM_CAMPAIGN', 'COMMENTS', $b['uf_payout']],
        'order'  => ['ID' => 'DESC'],
    ]);
    if (!$deals) return [];

    // задачи, привязанные к этим сделкам
    $tasks = [];
    foreach (array_chunk(array_column($deals, 'ID'), 50) as $chunk) {
        try {
            $r = b24('tasks.task.list', [
                'filter' => ['UF_CRM_TASK' => array_map(fn($id) => 'D_' . $id, $chunk)],
                'select' => ['ID', 'TITLE', 'STATUS', 'RESPONSIBLE_ID', 'UF_CRM_TASK'],
            ]);
            foreach ($r['result']['tasks'] ?? [] as $t) {
                foreach ((array)($t['ufCrmTask'] ?? []) as $bind) {
                    if (str_starts_with($bind, 'D_')) $tasks[(int)substr($bind, 2)][] = [
                        'id' => (int)$t['id'], 'title' => (string)$t['title'],
                        'done' => (int)$t['status'] === 5, 'user' => (int)$t['responsibleId'],
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('[stats tasks] ' . $e->getMessage());
        }
    }

    // друзья по телефону пригласившего
    $friends = [];
    foreach ($people as $p) {
        if ($p['owner'] !== '' && !empty($p['link'])) $friends[$p['owner']][] = ['type' => $p['link'][0], 'id' => $p['link'][1], 'status' => $p['status']];
    }

    $out = [];
    $openByPhone = [];
    foreach ($deals as $d) {
        $phone = ref_deal_phone($d);
        if (in_array($phone, ref_test_phones(), true)) continue;
        $text = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string)$d['COMMENTS']));
        $fio = preg_match('/ФИО:\s*([^\n]+?)\s*(Расч|Корр|БИК|\n|$)/u', $text, $fm) ? trim($fm[1]) : '';
        $type = (string)($d[$b['uf_payout']] ?? '');
        $open = $d['STAGE_ID'] === $b['stage_pay'];
        $t = $tasks[(int)$d['ID']] ?? [];
        $row = [
            'id'        => (int)$d['ID'],
            'open'      => $open,
            'created'   => substr((string)$d['DATE_CREATE'], 0, 10),
            'modified'  => substr((string)$d['DATE_MODIFY'], 0, 10),
            'name'      => $fio !== '' ? $fio : trim((string)$d['TITLE']),
            'phone'     => $phone,
            'method'    => $type === $b['payout_debt'] ? 'debt' : ($type === $b['payout_money'] ? 'money' : ''),
            'tasks'     => $t,
            'task_done' => $t && !array_filter($t, fn($x) => !$x['done']),
            'test'      => (bool)preg_match('/\bтест\b|ФИО:\s*ФИО\b|Расчетный сч[её]т:\s*0\b/iu', $text),
            'friends'   => $friends[$phone] ?? [],
        ];
        if ($open && !$row['test']) $openByPhone[$phone][] = $row['id'];
        $out[] = $row;
    }
    // несколько открытых заявок от одного человека — повод проверить, не дубль ли;
    // заявок больше, чем оплативших друзей, — возможно, заплатили лишнее
    $allByPhone = array_count_values(array_column(array_filter($out, fn($r) => !$r['test']), 'phone'));
    foreach ($out as &$row) {
        $row['dup'] = $row['open'] && count($openByPhone[$row['phone']] ?? []) > 1;
        $paidFriends = count(array_filter($row['friends'], fn($f) => in_array($f['status'], ['paid', 'gibdd'], true)));
        $row['over'] = !$row['test'] && ($allByPhone[$row['phone']] ?? 0) > $paidFriends;
    }
    unset($row);
    return $out;
}

/** Телефон пригласившего по сделке воронки «Рефералка»; у сделок из чат-бота он только в названии: «Реффер (79000000005)» */
function ref_deal_phone(array $d): string
{
    $ph = norm_phone((string)($d['UTM_CAMPAIGN'] ?? ''));
    if (strlen($ph) !== 11 && preg_match('/\((\+?[\d\s()-]{10,})\)/', (string)($d['TITLE'] ?? ''), $tm)) $ph = norm_phone($tm[1]);
    return $ph;
}

/** @return string[] */
function ref_test_phones(): array
{
    return array_map(fn($p) => norm_phone((string)$p), cfg('referral')['test_phones'] ?? []);
}

/**
 * Прогноз выплат: когда оплативший друг дойдёт до регистрации в ГИБДД.
 * Срок берём из нашей же истории — медиана дней от «Даты продажи» до отметки ГИБДД.
 * Кто оплатил давно, а отметки так и нет, в прогноз не идёт: отметку стали ставить
 * не сразу, и старые выплаты по ним, скорее всего, уже прошли через чат-бот.
 * @return array{0: array, 1: array, 2: int}
 */
function ref_forecast(array $people): array
{
    $reward = cfg('referral')['reward'];
    $days = fn(string $a, string $b) => (int)round((strtotime($b) - strtotime($a)) / 86400);

    $lags = [];
    foreach ($people as $p) {
        if ($p['status'] === 'gibdd' && $p['sale'] && $p['gibdd_at']) {
            $d = $days($p['sale'], $p['gibdd_at']);
            if ($d >= 0 && $d <= 180) $lags[] = $d;
        }
    }
    sort($lags);
    $lag = $lags ? $lags[intdiv(count($lags), 2)] : 35;
    $staleAfter = max(60, $lag * 3);   // столько ждём отметку, дальше считаем, что её уже не поставят
    $today = date('Y-m-d');

    $months = [];
    $stale = 0;
    foreach ($people as &$p) {
        $p['stale'] = false;
        $p['expect'] = null;
        if ($p['status'] !== 'paid' || !$p['sale']) continue;
        if ($days($p['sale'], $today) > $staleAfter) { $p['stale'] = true; $stale++; continue; }
        $exp = date('Y-m-d', strtotime($p['sale'] . " +$lag days"));
        $p['expect'] = max($exp, $today);   // срок уже прошёл — ждём отметку со дня на день
        $m = substr($p['expect'], 0, 7);
        $months[$m] ??= ['month' => $m, 'friends' => 0, 'sum' => 0, 'overdue' => 0];
        $months[$m]['friends']++;
        $months[$m]['sum'] += $reward;
        if ($exp < $today) $months[$m]['overdue']++;
    }
    unset($p);
    ksort($months);

    // ещё не оплатившие: сколько из них, судя по прошлым месяцам, дойдёт до оплаты
    $old = array_filter($people, fn($p) => $days(substr((string)$p['created'], 0, 10), $today) > 30);
    $closed = count(array_filter($old, fn($p) => $p['status'] !== 'lead'));
    $won = count(array_filter($old, fn($p) => in_array($p['status'], ['paid', 'gibdd'], true)));
    $rate = $closed ? $won / $closed : 0;
    $leads = count(array_filter($people, fn($p) => $p['status'] === 'lead'));

    return [$people, [
        'months'   => array_values($months),
        'stale'    => $stale,
        'stale_after' => $staleAfter,
        'pipeline' => ['leads' => $leads, 'rate' => round($rate, 2), 'sum' => (int)round($leads * $rate) * $reward],
    ], $lag];
}

/**
 * Группа каждой стадии: success — успех, failure и apology — провал, process — в работе.
 * @return array<string, string> STAGE_ID => семантика
 */
function b24_stage_semantics(array $categories): array
{
    $out = [];
    foreach ($categories as $cat) {
        $entity = (int)$cat === 0 ? 'DEAL_STAGE' : 'DEAL_STAGE_' . (int)$cat;
        foreach (b24('crm.status.list', ['filter' => ['ENTITY_ID' => $entity]])['result'] ?? [] as $s) {
            $out[(string)$s['STATUS_ID']] = (string)($s['EXTRA']['SEMANTICS'] ?? '') ?: 'process';
        }
    }
    return $out;
}

<?php
/**
 * Учётная система школы: учился ли реферер у нас.
 * Один точечный запрос на человека и только при заявке на выплату — базу целиком не забираем.
 * Запросы идут через очередь: заявка только встаёт в неё, обрабатывает sync.php раз в 15 минут,
 * не больше ASHK_PER_RUN запросов за запуск и с паузой между ними.
 * Адрес и ключ API лежат отдельно в ashk.env (ASHK_API_URL, ASHK_API_KEY), нет файла — проверка выключена.
 */
declare(strict_types=1);

const ASHK_PER_RUN = 2;     // сколько заявок проверяем за запуск sync.php (раз в 15 минут)
const ASHK_PAUSE = 30;      // секунд между запросами — бережём чужой API
const ASHK_MAX_TRIES = 8;   // CRM не отвечает примерно два часа — ставим метку «нет ответа»
const ASHK_STATES = [
    'CRT' => 'Зачислен', 'LRN' => 'Идёт обучение', 'DRV' => 'Допущен к вождению', 'SUS' => 'Обучение приостановлено',
    'PASS' => 'Аттестован, допущен к экзамену', 'ADL' => 'Требуется дополнительное обучение', 'GD1' => 'Сдал теорию в ГИБДД',
    'GD2' => 'Сдал автодром в ГИБДД', 'GD3' => 'Сдал экзамены в ГИБДД', 'TAK' => 'Забрал документы',
    'CMP' => 'Обучение завершено', 'EXLE' => 'Отчислен досрочно', 'EXTR' => 'Дополнительное обучение',
];

function ashk_cfg(): ?array
{
    $file = __DIR__ . '/ashk.env';
    if (!is_file($file)) return null;
    $e = parse_ini_file($file, false, INI_SCANNER_RAW) ?: [];
    $url = trim((string)($e['ASHK_API_URL'] ?? ''));
    $key = (string)($e['ASHK_API_KEY'] ?? '');
    return $url !== '' && $key !== '' ? ['api_url' => $url, 'api_key' => $key] : null;
}

/** Договоры по телефону или null, если учётная система не ответила */
function ashk_contracts(string $phone): ?array
{
    return ashk_list(['phone' => $phone], 10);
}

/**
 * Адаптер к API школьной CRM: список договоров по фильтру (телефон или ФИО).
 * Ожидаемые поля строки: Id, PersonName, PhoneValue, BirthDate, ContractName, ContractDate, State, StudyGroupName.
 * null — CRM не ответила (заявка останется в очереди и будет проверена позже).
 */
function ashk_list(array $filters, int $count): ?array
{
    $cfg = ashk_cfg();
    if (!$cfg || empty($cfg['api_url'])) return null;
    $ch = curl_init(rtrim($cfg['api_url'], '/') . '/contracts?' . http_build_query(['count' => $count] + $filters));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . ($cfg['api_key'] ?? ''), 'Accept: application/json']]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($body === false || $code !== 200) return null;
    $j = json_decode((string)$body, true);
    return is_array($j) ? ($j['data'] ?? $j) : null;
}

/** Из договоров человека берём действующий, отчисленный — только если других нет. Телефон сверяем сами */
function ashk_pick(array $rows, string $phone): array
{
    $tail = substr($phone, -10);
    $mine = array_values(array_filter($rows, static fn($r) =>
        substr((string)preg_replace('/\D+/', '', (string)($r['PhoneValue'] ?? '')), -10) === $tail));
    return $mine ? ashk_result($mine) : ['status' => 'not_found'];
}

/** Договор для отметки: действующий важнее отчисленного */
function ashk_result(array $rows): array
{
    usort($rows, static fn($a, $b) => (($a['State'] ?? '') === 'EXLE') <=> (($b['State'] ?? '') === 'EXLE'));
    $c = $rows[0];
    $state = (string)($c['State'] ?? '');
    return [
        'status'   => $state === 'EXLE' ? 'expelled' : 'found',
        'id'       => (int)($c['Id'] ?? 0),
        'contract' => trim((string)($c['ContractName'] ?? '')),
        'date'     => substr((string)($c['ContractDate'] ?? ''), 0, 10),
        'name'     => trim((string)($c['PersonName'] ?? '')),
        'state'    => ASHK_STATES[$state] ?? $state,
        'group'    => trim((string)($c['StudyGroupName'] ?? '')),
    ];
}

/**
 * Запасной путь, если телефон сменился: ФИО и дата рождения из анкеты на выплату.
 * Ищем по имени и отчеству — так находится и тот, кто сменил фамилию; дату рождения сверяем сами.
 * null — АШК не ответил
 */
function ashk_by_person(string $fio, string $birth): ?array
{
    $norm = static fn(string $s) => preg_split('/\s+/u', trim(str_replace('ё', 'е', mb_strtolower($s))), -1, PREG_SPLIT_NO_EMPTY);
    $w = $norm($fio);
    if (count($w) < 2 || $birth === '') return ['status' => 'not_found'];
    $rows = ashk_list(['name' => count($w) >= 3 ? $w[1] . ' ' . $w[2] : implode(' ', $w)], 100);
    if ($rows === null) return null;
    $same = [];
    $other = [];
    foreach ($rows as $r) {
        if (substr((string)($r['BirthDate'] ?? ''), 0, 10) !== $birth) continue;
        $n = $norm((string)($r['PersonName'] ?? ''));
        if (array_slice($n, 1, count($w) - 1) !== array_slice($w, 1)) continue;
        if (($n[0] ?? '') === $w[0]) $same[] = $r; else $other[] = $r;
    }
    if (!$same && !$other) return ['status' => 'not_found'];
    $a = ashk_result($same ?: $other);
    $a['by'] = 'fio';
    // фамилия в АШК другая — выплату подтверждает документ о смене фамилии
    if (!$same && $a['status'] === 'found') $a['status'] = 'other_surname';
    return $a;
}

/** ФИО и дата рождения из анкеты на выплату (при списании долга анкеты нет) */
function ashk_payout_person(int $dealId): ?array
{
    $v = q('SELECT data_json FROM payouts WHERE b24_deal_id = ?', [$dealId])->fetchColumn();
    $d = $v ? json_decode((string)$v, true) : null;
    $birth = ru_date_to_iso((string)($d['birth'] ?? ''));
    return !empty($d['fio']) && $birth ? ['fio' => (string)$d['fio'], 'birth' => $birth] : null;
}

/** Сначала по телефону, не нашли — по ФИО и дате рождения. null — АШК не ответил */
function ashk_lookup(int $dealId, string $phone): ?array
{
    $rows = ashk_contracts($phone);
    if ($rows === null) return null;
    $a = ashk_pick($rows, $phone);
    if ($a['status'] === 'found' || !($p = ashk_payout_person($dealId))) return $a;
    sleep(ASHK_PAUSE);
    $b = ashk_by_person($p['fio'], $p['birth']);
    if ($b === null) return null;
    return $b['status'] === 'not_found' ? $a : $b;
}

function ashk_table(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS ashk_queue (
        deal_id INTEGER PRIMARY KEY, phone TEXT NOT NULL, tries INTEGER NOT NULL DEFAULT 0,
        result TEXT, created_at TEXT, done_at TEXT)');
}

/** Заявка встаёт в очередь. Сбой здесь не должен мешать самой заявке */
function ashk_enqueue(int $dealId, string $phone): void
{
    if (!$dealId || !ashk_cfg()) return;
    try {
        ashk_table();
        q('INSERT OR IGNORE INTO ashk_queue (deal_id, phone, created_at) VALUES (?, ?, ?)', [$dealId, $phone, date('c')]);
    } catch (Throwable $e) {
        error_log('[ashk] ' . $e->getMessage());
    }
}

/** Готовый итог проверки по заявке, если он уже есть */
function ashk_saved(int $dealId): ?array
{
    if (!$dealId || !ashk_cfg()) return null;
    ashk_table();
    $v = q('SELECT result FROM ashk_queue WHERE deal_id = ? AND done_at IS NOT NULL', [$dealId])->fetchColumn();
    return $v ? json_decode((string)$v, true) : null;
}

/** Берём из очереди не больше ASHK_PER_RUN заявок, между запросами пауза */
function ashk_process(): int
{
    if (!ashk_cfg()) return 0;
    ashk_table();
    $rows = q('SELECT * FROM ashk_queue WHERE done_at IS NULL AND tries <= ? ORDER BY created_at LIMIT ' . ASHK_PER_RUN,
        [ASHK_MAX_TRIES])->fetchAll();
    $done = 0;
    foreach ($rows as $i => $row) {
        if ($i) sleep(ASHK_PAUSE);
        $tries = (int)$row['tries'] + 1;
        try {
            $a = ashk_lookup((int)$row['deal_id'], (string)$row['phone']);
        } catch (Throwable $e) {
            error_log('[ashk] ' . $e->getMessage());
            $a = null;
        }
        if ($a === null && $tries < ASHK_MAX_TRIES) {
            q('UPDATE ashk_queue SET tries = ? WHERE deal_id = ?', [$tries, $row['deal_id']]);
            break;   // АШК не отвечает — остальных сейчас не дёргаем
        }
        $a ??= ['status' => 'unknown'];
        try {
            ashk_apply((int)$row['deal_id'], (string)$row['phone'], $a);
        } catch (Throwable $e) {
            error_log('[ashk] ' . $e->getMessage());
            q('UPDATE ashk_queue SET tries = ? WHERE deal_id = ?', [$tries, $row['deal_id']]);
            continue;
        }
        q('UPDATE ashk_queue SET tries = ?, result = ?, done_at = ? WHERE deal_id = ?',
            [$tries, json_encode($a, JSON_UNESCAPED_UNICODE), date('c'), $row['deal_id']]);
        $done++;
    }
    return $done;
}

/** Итог проверки — в заявку, её задачу и карточку реферера */
function ashk_apply(int $dealId, string $phone, array $a): void
{
    $mark = ashk_title($a);
    $title = (string)(b24('crm.deal.get', ['id' => $dealId])['result']['TITLE'] ?? '');
    if (strpos($title, '[АШК:') === false) {
        b24('crm.deal.update', ['id' => $dealId, 'fields' => ['TITLE' => $title . $mark]]);
    }
    b24('crm.timeline.comment.add', ['fields' => [
        'ENTITY_ID' => $dealId, 'ENTITY_TYPE' => 'deal', 'COMMENT' => 'Проверка реферера. ' . ashk_text($a),
    ]]);
    $tasks = b24('tasks.task.list', ['filter' => ['UF_CRM_TASK' => 'D_' . $dealId], 'select' => ['ID', 'TITLE']])['result']['tasks'] ?? [];
    foreach ($tasks as $t) {
        if (strpos((string)$t['title'], '[АШК:') !== false) continue;
        b24('tasks.task.update', ['taskId' => $t['id'], 'fields' => ['TITLE' => $t['title'] . $mark]]);
    }
    ashk_mark_referrer((int)q('SELECT b24_deal_id FROM users WHERE phone = ?', [$phone])->fetchColumn(), $a);
}

/** Метка в заголовке сделки: заголовок целиком уходит в задачу бухгалтеру */
function ashk_title(?array $a): string
{
    if ($a === null) return '';
    return [
        'found'     => ' [АШК: ' . ($a['contract'] ?? '') . (($a['by'] ?? '') === 'fio' ? ' по ФИО' : '') . ']',
        'other_surname' => ' [АШК: ' . ($a['contract'] ?? '') . ', другая фамилия — проверить]',
        'expelled'  => ' [АШК: ОТЧИСЛЕН — проверить]',
        'not_found' => ' [АШК: НЕ НАЙДЕН — проверить]',
        'unknown'   => ' [АШК: нет ответа — проверить]',
    ][$a['status']];
}

function ashk_text(array $a): string
{
    if ($a['status'] === 'unknown') return 'АШК не ответил, реферера надо проверить вручную';
    if ($a['status'] === 'not_found') return 'В АШК договор не найден ни по телефону, ни по ФИО с датой рождения';
    return (($a['by'] ?? '') === 'fio' ? "Найден не по телефону, а по ФИО и дате рождения из анкеты\n" : '')
        . ($a['status'] === 'other_surname' ? "Фамилия в АШК другая — нужен документ о смене фамилии\n" : '')
        . 'В АШК: ' . $a['name'] . ', договор ' . $a['contract'] . ' от ' . $a['date'] . ' (ID ' . $a['id'] . ')'
        . "\nГруппа: " . $a['group'] . "\nСтатус: " . $a['state'];
}

/** Итог проверки — в карточку реферера. Если АШК не ответил, поля не трогаем */
function ashk_mark_referrer(int $dealId, array $a): void
{
    if (!$dealId) return;
    $b = cfg('bitrix');
    // сбой здесь не должен мешать самой заявке: иначе она уйдёт в Битрикс повторно
    try {
        if ($a['status'] !== 'unknown') {
            $found = in_array($a['status'], ['found', 'other_surname'], true);
            $fields = [$b['uf_ashk_added'] => $found ? $b['ashk_added_yes'] : $b['ashk_added_no']];
            if ($found) {
                $fields[$b['uf_ashk_contract']] = $a['contract'];
                $fields[$b['uf_ashk_date']] = $a['date'];
            }
            b24('crm.deal.update', ['id' => $dealId, 'fields' => $fields]);
        }
        b24('crm.timeline.comment.add', ['fields' => [
            'ENTITY_ID' => $dealId, 'ENTITY_TYPE' => 'deal', 'COMMENT' => 'Проверка при заявке на выплату. ' . ashk_text($a),
        ]]);
    } catch (Throwable $e) {
        error_log('[ashk] ' . $e->getMessage());
    }
}

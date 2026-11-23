<?php
/**
 * Заявки на выплату живут в воронке «Рефералка»: карточка реффера.
 * Чат-бот заводил по карточке на каждую заявку, портал переводит стадию у одной.
 *
 * Факт выплаты определяем по задаче, а не по стадии: на входе в стадию «Выплата»
 * робот ставит задачу «Выплата» на бухгалтерию, и деньги уходят, когда её закрывают.
 * Стадию «Оплачено» двигают не всегда, поэтому задача — главный признак.
 *
 * За какого друга заявка, видно в комментарии:
 *   бот    — строка «Телефон реферала: 79…»
 *   портал — строка «Друг: Имя (79…), сделка 12345»
 */
declare(strict_types=1);

const PAYOUT_SELECT = ['ID', 'TITLE', 'STAGE_ID', 'UTM_CAMPAIGN', 'COMMENTS', 'OPPORTUNITY', 'DATE_CREATE', 'DATE_MODIFY'];

/** Задачи, привязанные к карточкам: [id сделки => задача] */
function payout_tasks(array $dealIds): array
{
    $out = [];
    foreach (array_chunk($dealIds, 50) as $chunk) {
        try {
            $r = b24('tasks.task.list', [
                'filter' => ['UF_CRM_TASK' => array_map(fn($id) => 'D_' . $id, $chunk)],
                'select' => ['ID', 'TITLE', 'STATUS', 'CLOSED_DATE', 'CREATED_DATE', 'RESPONSIBLE_ID', 'UF_CRM_TASK'],
            ]);
            foreach ($r['result']['tasks'] ?? [] as $t) {
                foreach ((array)($t['ufCrmTask'] ?? []) as $bind) {
                    if (!str_starts_with($bind, 'D_')) continue;
                    $id = (int)substr($bind, 2);
                    // если задач несколько, держим самую свежую
                    if (!isset($out[$id]) || (int)$t['id'] > (int)$out[$id]['id']) $out[$id] = $t;
                }
            }
        } catch (Throwable $e) {
            error_log('[payout tasks] ' . $e->getMessage());
        }
    }
    return $out;
}

/** Разбираем карточку воронки «Рефералка» в заявку на выплату */
function payout_parse(array $d, ?array $task = null): ?array
{
    $b = cfg('bitrix');
    $stage = (string)($d['STAGE_ID'] ?? '');
    $won = 'C' . $b['category'] . ':WON';
    $lose = 'C' . $b['category'] . ':LOSE';
    if (!in_array($stage, [$b['stage_pay'], $won, $lose], true)) return null;   // ещё не заявка

    $owner = ref_deal_phone($d);
    if (strlen($owner) !== 11) return null;

    $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string)($d['COMMENTS'] ?? ''))));
    $friend = '';
    if (preg_match('/Телефон реферала:\s*([\d+() -]{10,})/u', $text, $m)) $friend = norm_phone($m[1]);
    elseif (preg_match('/Друг:[^\n]*?\(([\d+() -]{10,})\)/u', $text, $m)) $friend = norm_phone($m[1]);
    if (strlen($friend) !== 11) $friend = '';

    $type = (string)($d[$b['uf_payout']] ?? '');
    $method = $type === $b['payout_debt'] ? 'debt' : ($type === $b['payout_money'] ? 'money' : '');
    if ($method === '' && preg_match('/Способ получения:([^\n]+)/u', $text, $m)) {
        $method = preg_match('/долг/iu', $m[1]) ? 'debt' : 'money';
    }

    $ts = (int)($task['status'] ?? 0);           // 5 — завершена, 7 — отклонена
    $closed = (string)($task['closedDate'] ?? '');
    $taskTitle = (string)($task['title'] ?? '');

    // задачу закрывают и тогда, когда платить не надо: дубль, тест, отмена.
    // плюс проверяем, было ли вообще право на выплату — друг должен быть в ГИБДД
    $stop = (bool)preg_match('/дубл|тест|отмен|не плат|ошибк|возврат/iu', $taskTitle . ' ' . (string)($d['TITLE'] ?? ''));
    $entitled = $friend !== '' && (bool)q("SELECT 1 FROM refs WHERE owner_phone = ? AND phone = ?
            AND status IN ('gibdd','payout','done') AND (reject IS NULL OR reject = '')",
        [$owner, $friend])->fetch();
    $hold = '';

    // реферер должен сам быть нашим курсантом — иначе заявка не проходит
    if ($hold === '' && cfg('bitrix')['require_student']) {
        $st = ref_is_student($owner);
        if (empty($st['student']) && empty($st['unknown'])) $hold = 'not_student';
    }

    if ($stage === $lose || $ts === 7) {
        $status = 'rejected';
    } elseif ($stage === $won) {
        $status = 'paid';                        // стадию двигал человек — верим ему
    } elseif ($ts === 5) {
        // закрытая задача считается выплатой только когда всё чисто
        if ($stop)           { $status = 'request'; $hold = 'dup'; }
        elseif (!$entitled)  { $status = 'request'; $hold = 'no_right'; }
        else                 { $status = 'paid'; }
    } else {
        $status = 'request';
        if (!$entitled) $hold = 'no_right';
    }

    return [
        'deal_id'  => (int)$d['ID'],
        'owner'    => $owner,
        'friend'   => $friend,
        'status'   => $status,
        'method'   => $method,
        'amount'   => (float)($d['OPPORTUNITY'] ?? 0),
        'task_id'  => (int)($task['id'] ?? 0),
        'task'     => $ts,
        'paid_at'  => $status === 'paid' ? ($closed ?: (string)($d['DATE_MODIFY'] ?? '')) : '',
        // задачу закрыли, а карточку не двинули — воронку стоит дочистить,
        // но только по чистым заявкам: спорные ждут человека
        'stale'    => (int)($ts === 5 && $stage !== $won && $hold === ''),
        'hold'       => $hold,
        'task_title' => $taskTitle,
        // тестовые заявки бухгалтерия закрывает «просто так», в деньги их не считаем
        'test'     => (int)(bool)preg_match('/\bтест\b|ФИО:\s*ФИО\b|Расчетный сч[её]т:\s*0\b/iu', $text),
        'created'  => (string)($d['DATE_CREATE'] ?? ''),
        'updated'  => (string)($d['DATE_MODIFY'] ?? ''),
    ];
}

function payout_store(array $p): void
{
    q('INSERT INTO b24_payouts (deal_id, owner_phone, friend_phone, status, method, amount,
                                task_id, task_status, paid_at, stale, is_test, hold, task_title, created_at, updated_at)
       VALUES (:id, :owner, :friend, :status, :method, :amount, :task, :tstatus, :paid, :stale, :test, :hold, :ttitle, :created, :updated)
       ON CONFLICT(deal_id) DO UPDATE SET
         owner_phone = :owner, friend_phone = :friend, status = :status, method = :method, amount = :amount,
         task_id = :task, task_status = :tstatus, paid_at = :paid, stale = :stale, is_test = :test,
         hold = :hold, task_title = :ttitle, updated_at = :updated', [
        'id' => $p['deal_id'], 'owner' => $p['owner'], 'friend' => $p['friend'],
        'status' => $p['status'], 'method' => $p['method'], 'amount' => $p['amount'],
        'task' => $p['task_id'], 'tstatus' => $p['task'], 'paid' => $p['paid_at'],
        'stale' => $p['stale'], 'test' => $p['test'],
        'hold' => $p['hold'] ?? '', 'ttitle' => $p['task_title'] ?? '',
        'created' => $p['created'], 'updated' => $p['updated'],
    ]);
}

/** Сохраняем пачку карточек вместе с их задачами */
function payouts_store_deals(array $deals, bool $notify = false): int
{
    if (!$deals) return 0;
    $tasks = payout_tasks(array_map('intval', array_column($deals, 'ID')));
    $n = 0;
    foreach ($deals as $d) {
        $p = payout_parse($d, $tasks[(int)$d['ID']] ?? null);
        if (!$p) continue;
        $was = q('SELECT status FROM b24_payouts WHERE deal_id = ?', [$p['deal_id']])->fetchColumn();
        // задача закрыта, а карточка осталась на «Выплате» — дочищаем воронку сами,
        // чтобы отчёты по стадиям сходились с фактом
        if (!$p['test'] && cfg('bitrix')['close_paid'] && ($p['stale'] || ($p['status'] === 'paid' && $p['amount'] <= 0))) {
            try {
                $fix = [];
                if ($p['stale']) $fix['STAGE_ID'] = 'C' . cfg('bitrix')['category'] . ':WON';
                // расход компании одинаков при любом способе: 2610 на карту плюс 390 НДФЛ
                if ($p['amount'] <= 0) {
                    $fix['OPPORTUNITY'] = cfg('referral')['reward'];
                    $p['amount'] = $fix['OPPORTUNITY'];
                }
                b24('crm.deal.update', ['id' => $p['deal_id'], 'fields' => $fix]);
                $p['stale'] = 0;
                ev('bitrix.payout_closed', ['actor_type' => 'cron', 'entity' => ['deal', $p['deal_id']],
                    'payload' => ['owner' => $p['owner'], 'task' => $p['task_id'], 'amount' => $p['amount']]]);
            } catch (Throwable $e) {
                error_log('[payout close] ' . $e->getMessage());
            }
        }
        payout_store($p);
        $n++;
        if (!$notify || $was === false || $was === $p['status'] || $p['test']) continue;
        if ($p['status'] === 'paid') {
            notify($p['owner'], 'payout.paid', 'Выплата прошла',
                'Вознаграждение за друга отправлено. Если что-то не так — скажите представителю автошколы.');
        } elseif ($p['status'] === 'rejected') {
            notify($p['owner'], 'payout.rejected', 'Заявка на выплату отклонена',
                'Свяжитесь с представителем автошколы — подскажем, что поправить.');
        }
    }
    return $n;
}

/** Все заявки и выплаты по программе — для крона */
function payouts_sync_all(): int
{
    $b = cfg('bitrix');
    return payouts_store_deals(b24_all('crm.deal.list', [
        'filter' => ['CATEGORY_ID' => $b['category'],
                     'STAGE_ID' => [$b['stage_pay'], 'C' . $b['category'] . ':WON', 'C' . $b['category'] . ':LOSE']],
        'select' => array_merge(PAYOUT_SELECT, [$b['uf_payout']]),
        'order'  => ['ID' => 'ASC'],
    ]), true);
}

/** Заявки одного участника — зовём при входе, чтобы человек сразу видел свои */
function payouts_sync_user(string $phone): int
{
    $b = cfg('bitrix');
    $deals = [];
    try {
        foreach ([['%TITLE' => $phone], ['UTM_CAMPAIGN' => $phone]] as $filter) {
            foreach (b24_all('crm.deal.list', [
                'filter' => $filter + ['CATEGORY_ID' => $b['category']],
                'select' => array_merge(PAYOUT_SELECT, [$b['uf_payout']]),
                'order'  => ['ID' => 'ASC'],
            ]) as $d) {
                if (ref_deal_phone($d) === $phone) $deals[(int)$d['ID']] = $d;
            }
        }
    } catch (Throwable $e) {
        error_log('[payouts user] ' . $e->getMessage());
        return 0;
    }
    return payouts_store_deals(array_values($deals));
}

/** Заявки участника из нашей базы: [телефон друга => заявка] плюс те, где друг не указан */
function payouts_of(string $phone): array
{
    $rows = q('SELECT deal_id, friend_phone, status, method, amount, task_id, task_status, paid_at,
                      stale, is_test, created_at, updated_at
               FROM b24_payouts WHERE owner_phone = ? ORDER BY deal_id', [$phone])->fetchAll();
    $rank = ['rejected' => 0, 'request' => 1, 'paid' => 2];
    $byFriend = [];
    $other = [];
    foreach ($rows as $r) {
        if (!$r['friend_phone']) { $other[] = $r; continue; }
        $cur = $byFriend[$r['friend_phone']] ?? null;
        if (!$cur) { $byFriend[$r['friend_phone']] = $r + ['dup' => 1]; continue; }
        // из дублей показываем самую «сильную»: выплаченную важнее поданной
        $dup = ($cur['dup'] ?? 1) + 1;
        if (($rank[$r['status']] ?? 0) > ($rank[$cur['status']] ?? 0)) $byFriend[$r['friend_phone']] = $r;
        $byFriend[$r['friend_phone']]['dup'] = $dup;
    }
    return ['by_friend' => $byFriend, 'other' => $other, 'all' => $rows];
}

/** Есть ли уже заявка по этому другу — чтобы не плодить дубли */
function payout_exists(string $owner, string $friendPhone): bool
{
    if ($friendPhone === '') return false;
    return (bool)q("SELECT 1 FROM b24_payouts WHERE owner_phone = ? AND friend_phone = ? AND status != 'rejected'",
        [$owner, $friendPhone])->fetch();
}

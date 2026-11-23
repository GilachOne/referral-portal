<?php
/**
 * Начисления: по строке на пару «участник + друг». Это единственное место,
 * где виден весь путь денег целиком, и по нему сверяются портал, Битрикс и расходы.
 *
 *   начислено  — друг зарегистрирован в ГИБДД, участнику положены деньги
 *   заявлено   — участник попросил выплату, в Битриксе висит задача
 *   выплачено  — задача закрыта или карточка на стадии «Оплачено»
 *   отказано   — задачу отклонили или карточку завалили
 */
declare(strict_types=1);

/** Пересобираем начисления из приглашённых и заявок. Возвращает счётчики */
function accruals_rebuild(): array
{
    $r = cfg('referral');
    $test = array_map('strval', $r['test_phones'] ?? []);
    $now = date('c');

    // 1. право на деньги появляется, когда друг прошёл ГИБДД
    $rows = q("SELECT owner_phone, name, phone, deal_id, status, updated_at
               FROM refs WHERE status IN ('gibdd', 'payout', 'done')
                 AND (reject IS NULL OR reject = '') ORDER BY updated_at")->fetchAll();

    $seen = [];
    foreach ($rows as $f) {
        $owner = (string)$f['owner_phone'];
        $key = (string)$f['phone'] !== '' ? (string)$f['phone'] : 'd' . $f['deal_id'];
        if (isset($seen[$owner . '|' . $key])) continue;      // один друг — одно начисление
        $seen[$owner . '|' . $key] = true;

        q('INSERT INTO accruals (owner_phone, friend_key, friend_name, friend_phone, friend_deal,
                                 status, amount, gibdd_at, is_test, updated_at)
           VALUES (:o, :k, :n, :p, :d, \'accrued\', :a, :g, :t, :u)
           ON CONFLICT(owner_phone, friend_key) DO UPDATE SET
             friend_name = :n, friend_phone = :p, friend_deal = :d, gibdd_at = COALESCE(accruals.gibdd_at, :g), updated_at = :u', [
            'o' => $owner, 'k' => $key, 'n' => (string)$f['name'], 'p' => (string)$f['phone'],
            'd' => (int)$f['deal_id'], 'a' => $r['reward'], 'g' => (string)$f['updated_at'],
            't' => (int)in_array($owner, $test, true), 'u' => $now,
        ]);
    }

    // 2. накладываем заявки и выплаты
    foreach (q('SELECT * FROM b24_payouts ORDER BY deal_id')->fetchAll() as $p) {
        $owner = (string)$p['owner_phone'];
        $key = (string)$p['friend_phone'];
        if ($key === '') continue;
        $status = $p['status'] === 'paid' ? 'paid' : ($p['status'] === 'rejected' ? 'rejected' : 'requested');
        // в деньгах программы считаем полный расход: 3 000 ₽ при любом способе
        $amount = (float)$p['amount'] > 0 ? (float)$p['amount'] : (float)$r['reward'];

        q('INSERT INTO accruals (owner_phone, friend_key, friend_phone, status, amount, method,
                                 requested_at, paid_at, rejected_at, payout_deal, task_id, is_test, updated_at)
           VALUES (:o, :k, :p, :s, :a, :m, :req, :paid, :rej, :deal, :task, :t, :u)
           ON CONFLICT(owner_phone, friend_key) DO UPDATE SET
             status = :s, amount = :a, method = :m,
             requested_at = COALESCE(accruals.requested_at, :req),
             paid_at = :paid, rejected_at = :rej, payout_deal = :deal, task_id = :task,
             is_test = MAX(accruals.is_test, :t), updated_at = :u', [
            'o' => $owner, 'k' => $key, 'p' => $key, 's' => $status, 'a' => $amount,
            'm' => (string)$p['method'], 'req' => (string)$p['created_at'],
            'paid' => $status === 'paid' ? (string)($p['paid_at'] ?: $p['updated_at']) : null,
            'rej' => $status === 'rejected' ? (string)$p['updated_at'] : null,
            'deal' => (int)$p['deal_id'], 'task' => (int)$p['task_id'],
            't' => (int)((int)$p['is_test'] || in_array($owner, $test, true)), 'u' => $now,
        ]);
    }

    return accruals_totals();
}

/** Сводка по деньгам программы */
function accruals_totals(): array
{
    $out = ['accrued' => 0, 'requested' => 0, 'paid' => 0, 'rejected' => 0,
            'sum_accrued' => 0.0, 'sum_requested' => 0.0, 'sum_paid' => 0.0];
    foreach (q("SELECT status, COUNT(*) n, SUM(amount) s FROM accruals WHERE is_test = 0 GROUP BY status")->fetchAll() as $row) {
        $out[$row['status']] = (int)$row['n'];
        if ($row['status'] === 'paid') $out['sum_paid'] = (float)$row['s'];
        elseif ($row['status'] === 'requested') $out['sum_requested'] = (float)$row['s'];
    }
    $out['sum_accrued'] = (float)q("SELECT COALESCE(SUM(amount), 0) FROM accruals WHERE is_test = 0 AND status = 'accrued'")->fetchColumn();
    // сколько в среднем ждут деньги: от ГИБДД до выплаты
    $days = q("SELECT AVG(julianday(paid_at) - julianday(gibdd_at)) FROM accruals
               WHERE is_test = 0 AND status = 'paid' AND paid_at IS NOT NULL AND gibdd_at IS NOT NULL")->fetchColumn();
    $out['avg_days'] = $days !== null ? round((float)$days, 1) : null;
    return $out;
}

/** Помесячно: сколько начислено и выплачено — для аналитики и планирования бюджета */
function accruals_by_month(int $limit = 12): array
{
    $rows = q("SELECT substr(COALESCE(paid_at, gibdd_at), 1, 7) AS month,
                      SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) paid,
                      SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) sum_paid,
                      COUNT(*) total
               FROM accruals WHERE is_test = 0 AND COALESCE(paid_at, gibdd_at) IS NOT NULL
               GROUP BY month ORDER BY month DESC LIMIT " . (int)$limit)->fetchAll();
    return array_reverse($rows);
}

-- Журнал событий всех порталов «Автошкола». Одна база на сервере, пишут все проекты.
-- Правило: сюда пишем факты («что произошло»), а не состояние. Состояние живёт в приложениях.

CREATE TABLE IF NOT EXISTS events (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ts          TIMESTAMPTZ NOT NULL DEFAULT now(),
    app         TEXT NOT NULL,              -- referral | team | portal
    event       TEXT NOT NULL,              -- auth.code_sent, ref.status_changed, payout.created …
    actor_type  TEXT,                       -- user | bitrix | cron | system
    actor_id    TEXT,                       -- телефон участника или id робота
    entity_type TEXT,                       -- user | ref | payout | deal | lead
    entity_id   TEXT,
    status      TEXT NOT NULL DEFAULT 'ok', -- ok | error
    message     TEXT,
    payload     JSONB NOT NULL DEFAULT '{}'::jsonb,
    request_id  TEXT,                       -- связывает события одного запроса
    ip          INET,
    user_agent  TEXT
);

CREATE INDEX IF NOT EXISTS events_ts       ON events (ts DESC);
CREATE INDEX IF NOT EXISTS events_event    ON events (event, ts DESC);
CREATE INDEX IF NOT EXISTS events_actor    ON events (actor_id, ts DESC);
CREATE INDEX IF NOT EXISTS events_entity   ON events (entity_type, entity_id, ts DESC);
CREATE INDEX IF NOT EXISTS events_request  ON events (request_id);
CREATE INDEX IF NOT EXISTS events_payload  ON events USING gin (payload);

-- Воронка участника: от входа до выплаты, одной строкой на событие
CREATE OR REPLACE VIEW v_referral_funnel AS
SELECT ts, actor_id AS participant, event, entity_id, status,
       payload->>'friend'  AS friend,
       payload->>'from'    AS from_status,
       payload->>'to'      AS to_status,
       payload->>'method'  AS payout_method,
       (payload->>'amount')::numeric AS amount
FROM events
WHERE app = 'referral'
ORDER BY ts DESC;

-- Сколько чего за день: быстрая сводка для отчётов
CREATE OR REPLACE VIEW v_events_daily AS
SELECT date_trunc('day', ts)::date AS day, app, event,
       count(*) FILTER (WHERE status = 'ok')    AS ok,
       count(*) FILTER (WHERE status = 'error') AS errors
FROM events
GROUP BY 1, 2, 3
ORDER BY 1 DESC, 4 DESC;

-- Что выбирают при выплате — то, что просило руководство
CREATE OR REPLACE VIEW v_payout_choice AS
SELECT date_trunc('month', ts)::date AS month,
       payload->>'method' AS method,
       count(*) AS requests,
       sum((payload->>'amount')::numeric) AS total
FROM events
WHERE event IN ('payout.created', 'payout.updated')
GROUP BY 1, 2
ORDER BY 1 DESC, 3 DESC;

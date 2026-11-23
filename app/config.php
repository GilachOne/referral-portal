<?php
// Ключи лежат рядом в .env — в git и в веб-корне их нет
$env = static function (string $key, string $default = '') {
    static $vals = null;
    if ($vals === null) {
        $vals = [];
        $file = __DIR__ . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $vals[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    return $vals[$key] ?? $default;
};

return [
    'db'           => __DIR__ . '/data/portal.sqlite',
    // push-уведомления в приложении
    'push' => [
        'public'      => $env('VAPID_PUBLIC'),
        'private_pem' => $env('VAPID_PRIVATE_PEM'),
        'subject'     => $env('VAPID_SUBJECT', 'mailto:admin@example.com'),
    ],

    // журнал событий: общая база PostgreSQL на сервере, пишут все проекты
    'events' => [
        'app'  => 'referral',
        'dsn'  => $env('PGDSN'),
        'user' => $env('PGUSER'),
        'pass' => $env('PGPASS'),
    ],
    'session_days' => 365,
    'tz'           => 'Europe/Moscow',
    'base_url'     => $env('APP_BASE_URL', 'https://referral.example.com'),
    // общий секрет с порталами сотрудников и курсантов: вход одной кнопкой, без повторного звонка
    'sso_secret'   => $env('REF_SSO_SECRET'),

    // Подтверждение номера звонком (zvonok.com) — те же кампании, что у портала сотрудников
    'zvonok' => [
        'enabled'     => true,
        'campaign_id' => $env('ZVONOK_CAMPAIGN_ID'),
        'confirm_campaign_id' => $env('ZVONOK_CONFIRM_CAMPAIGN_ID'),
        'public_key'  => $env('ZVONOK_PUBLIC_KEY'),
        'code_ttl'    => 600,   // сколько живёт код, секунд
        'resend_wait' => 40,    // пауза между звонками, секунд
        'per_day'     => 5,     // звонков в сутки на один номер
    ],

    // Битрикс24: входящий вебхук (тот же, что у чат-бота)
    'bitrix' => [
        'webhook'    => rtrim($env('BITRIX_WEBHOOK'), '/'),
        'category'   => 70,                  // воронка «Рефералка» — по сделке на каждого пригласившего
        'stage_new'  => 'C70:NEW',           // Новый реффер
        'close_paid' => true,                // задача «Выплата» закрыта — сами двигаем карточку в «Оплачено»
        'stage_pay'  => 'C70:UC_7WUFXH',     // Выплата
        'source_id'  => 'WEB',
        'uf_gibdd'   => 'UF_CRM_1781780615', // «Зарегистрирован в ГИБДД», 2904 = Да
        'uf_sale_date' => 'UF_CRM_1734589428170', // «Дата продажи» в сделке обучения
        'gibdd_yes'  => '2904',
        'uf_payout'  => 'UF_CRM_1791361907', // «Тип выплаты»
        'payout_money' => '3012',            // Деньги
        'payout_debt'  => '3014',            // Списать в счет долга
        'uf_referrer'  => 'UF_CRM_1790582385', // «Реферер»
        'uf_ashk_added'    => 'UF_CRM_1765307307', // «Добавлен в АШК?»
        'ashk_added_yes'   => '2150',
        'ashk_added_no'    => '2152',
        'uf_ashk_date'     => 'UF_CRM_1765307352', // «Дата добавления в АШК»
        'uf_ashk_contract' => 'UF_CRM_1782199369', // «ID договора АШК», вид 32438-2026
        // по просьбе руководства в отдельное поле пишем только способ выплаты,
        // реквизиты и паспорт остаются текстом в комментарии сделки
        'study_category' => 32,              // воронка обучения: сделка там = человек оплатил
        'friend_categories' => [0, 26],
        // воронки обучения: попал сюда — значит учился у нас
        'student_categories' => [32, 76],      // где живут сделки приглашённых: «Автошкола» и «Череповец»
        'hook_token'     => $env('BITRIX_HOOK_TOKEN'), // пароль для входящего хука из Битрикса

        // откуда человек пришёл в программу → что ставим в карточке
        'uf_source'   => 'UF_CRM_1753900970',          // «Источник New»
        'source_map'  => [
            'sms'  => ['SOURCE_ID' => '9995761292', 'uf' => '2218'],   // Рассылки
            'post' => ['SOURCE_ID' => '9995761292', 'uf' => '2218'],
            'qr'   => ['SOURCE_ID' => '9995761298', 'uf' => '2496'],   // Рефералка
            'team' => ['SOURCE_ID' => '9995761298', 'uf' => '2496'],
            'self' => ['SOURCE_ID' => '9995761298', 'uf' => '2496'],
        ],
    ],

    // Маркетинговая таблица «Отчет РК»: расходы и журнал выплат по рефералке
    'marketing' => [
        'sheet_id'       => $env('MARKETING_SHEET_ID', ''),
        'expenses_range' => 'выгрузка расходов!A:G',
        'log_sheet_id'   => $env('MARKETING_SHEET_ID', ''),
        'log_range'      => 'Рефералка выплаты!A:I',
    ],

    // Условия программы — тексты берём из чат-бота, чтобы везде было одинаково
    // чат-боты LeadTeh как канал доставки уведомлений
    'leadteh' => [
        'enabled' => true,
        'token'   => $env('LEADTEH_TOKEN'),
        'base'    => 'https://app.leadteh.ru/api/v1',
        // из какого бота писать: первый, где человек подписан
        'bots'    => array_values(array_filter(array_map('intval', explode(',', $env('LEADTEH_BOTS'))))),
    ],

    // кому видна вся программа: все участники, их друзья и выплаты
    'admins' => array_values(array_filter(array_map('trim', explode(',', $env('REF_ADMINS', '79000000001'))))),

    // друг записался без ссылки — участник просит привязать его к себе
    'claim' => [
        'enabled'      => false,   // привязка друга без ссылки выключена: включить — поставить true
        'wait_days'    => 30,   // сколько ждём, пока друг обратится в автошколу
        'max_age_days' => 60,   // насколько старую заявку друга ещё можно привязать
        'per_day'      => 5,    // сколько друзей участник может добавить за сутки
    ],

    'referral' => [
        'reward'      => 3000,   // пригласившему, рублей
        'reward_card' => 2610,   // на карту после удержания НДФЛ
        'ndfl'        => 390,
        'discount'    => 3000,   // скидка другу
        'offer_url'   => 'https://example.com/offer',
        'landing'     => 'https://school.example.com/',
        'landing_cherepovets' => 'https://school.example.com/cherepovets',
        // вознаграждение положено только нашим курсантам
        'require_student' => true,

        // защита от накруток: кого не засчитываем как приглашённого
        'fraud' => [
            'self'       => true,   // участник записал сам себя
            'old_client' => true,   // человек учился раньше, чем перешёл по ссылке
            'taken'      => true,   // его уже привёл другой участник
        ],
        'payout_days' => 30,
        'edit_minutes' => 30,   // сколько можно править заявку после отправки
        // лимит выплат в месяц для экрана «Бюджет и аналитика»; 0 — лимит не задан
        'budget_month' => (int)$env('REF_BUDGET_MONTH', '0'),
        // тестовые пригласившие: их друзья и заявки не попадают в аналитику
        'test_phones'  => array_filter(explode(',', $env('REF_TEST_PHONES', '79000000001,79000000002,79000000003,79000000004'))),
        // и по имени друга — тестовые заявки заводят под разными номерами
        'test_names'   => '/data\s*drive|^\s*тест\b|провер/iu',
    ],
];

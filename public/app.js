'use strict';

const $app = document.getElementById('app');
const state = { me: null, phone: '', isNew: false };

// ---------- утилиты ----------

function h(tag, attrs, ...kids) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (k === 'class') el.className = v;
    else if (k === 'html') el.innerHTML = v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const kid of kids.flat(Infinity)) if (kid != null && kid !== false) el.append(kid.nodeType ? kid : String(kid));
  return el;
}

async function api(path, body) {
  const opts = { credentials: 'same-origin', headers: { 'X-App': '1' } };
  if (body !== undefined) {
    opts.method = 'POST';
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch('/api' + path, opts);
  } catch (e) {
    throw new Error('Нет связи с сервером. Проверьте интернет.');
  }
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) { state.me = null; showLogin(); throw new Error(''); }
  if (!res.ok) throw new Error(data.error || 'Ошибка ' + res.status);
  return data;
}

function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.hidden = false;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => (t.hidden = true), 3500);
}

const money = n => Number(n || 0).toLocaleString('ru-RU') + ' ₽';

// память браузера: в приватном окне её может не быть, поэтому всё в try
const store = {
  get(k) { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } },
  set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
  del(k) { try { localStorage.removeItem(k); } catch (e) {} },
};

function formatPhone(v) {
  let d = v.replace(/\D/g, '');
  if (d.startsWith('8')) d = '7' + d.slice(1);
  if (d && !d.startsWith('7')) d = '7' + d;
  d = d.slice(0, 11);
  const p = [d.slice(1, 4), d.slice(4, 7), d.slice(7, 9), d.slice(9, 11)];
  let s = '+7';
  if (p[0]) s += ' (' + p[0];
  if (p[0].length === 3) s += ')';
  if (p[1]) s += ' ' + p[1];
  if (p[2]) s += '-' + p[2];
  if (p[3]) s += '-' + p[3];
  return s;
}

function screen(title, back, ...content) {
  const top = document.getElementById('top');
  top.hidden = !state.me;
  document.getElementById('title').textContent = title;
  const b = document.getElementById('back');
  b.hidden = !back;
  b.onclick = back || null;
  document.getElementById('who').textContent = state.me ? state.me.user.name : '';
  $app.replaceChildren(...content.flat(Infinity).filter(x => x != null && x !== false));
  window.scrollTo(0, 0);
}

// ---------- вход ----------

function showLogin(err) {
  const phone = h('input', {
    type: 'tel', inputmode: 'tel', autocomplete: 'tel', placeholder: '+7 (___) ___-__-__',
    value: state.phone ? formatPhone(state.phone) : '',
    oninput: e => (e.target.value = formatPhone(e.target.value)),
  });
  const error = h('div', { class: 'alert err', hidden: !err }, err || '');
  const btn = h('button', { class: 'btn', type: 'submit' }, 'Получить код звонком');
  const form = h('form', {
    onsubmit: async e => {
      e.preventDefault();
      const digits = phone.value.replace(/\D/g, '');
      if (digits.length !== 11) { error.textContent = 'Введите номер полностью'; error.hidden = false; return; }
      btn.disabled = true;
      try {
        state.phone = digits;
        const r = await api('/auth/call', { phone: digits });
        showCode(r.wait || 40);
      } catch (ex) {
        if (ex.message) { error.textContent = ex.message; error.hidden = false; }
      } finally { btn.disabled = false; }
    },
  },
    h('div', { class: 'field' }, phone),
    error, btn,
  );

  // по партнёрской ссылке показываем её город и партнёра: человек должен узнать свою автошколу
  const cityText = () => {
    const c = state.campaign;
    return 'Автошкола ' + (c && c.city === 'cherepovets' ? 'Череповец' : 'Казань')
      + (c && c.partner ? ' · от ' + c.partner : '');
  };
  const cityLine = h('p', { class: 'sub', style: 'text-align:center' }, cityText());
  if (state.from && !state.campaign) {
    api('/campaign?code=' + encodeURIComponent(state.from)).then(c => {
      if (!c || !c.found) return;
      state.campaign = c;
      cityLine.textContent = cityText();
    }).catch(() => {});
  }

  screen('Приведи друга', null,
    h('div', { class: 'brand' }, h('img', { src: '/icon-192.png', alt: 'Автошкола' })),
    h('h1', { style: 'text-align:center' }, 'Приведи друга'),
    cityLine,

    h('div', { class: 'card terms' },
      h('div', { class: 'term' }, h('b', {}, '3 000 ₽'), h('span', {}, 'вам за каждого друга, который заключит договор')),
      h('div', { class: 'term' }, h('b', {}, '3 000 ₽'), h('span', {}, 'скидка другу на обучение')),
      h('div', { class: 'term' }, h('b', {}, '30 дней'), h('span', {}, 'срок выплаты после регистрации друга в ГИБДД')),
      h('a', { class: 'btn ghost', href: 'https://example.com/offer', target: '_blank', rel: 'noopener' }, 'Условия программы и оферта')),

    h('div', { class: 'card' },
      h('div', { class: 'label' }, 'Введите номер телефона — и получите свою ссылку'),
      form),
    h('p', { class: 'hint', style: 'text-align:center' },
      'Вам позвонит робот. Код — последние 4 цифры его номера. Если вместо номера видно название, возьмите трубку: робот продиктует код. Звонок бесплатный.'),
  );
  setTimeout(() => phone.focus(), 50);
}

function showCode(wait) {
  const code = h('input', { type: 'tel', inputmode: 'numeric', maxlength: 6, placeholder: '····', autocomplete: 'one-time-code', style: 'text-align:center; font-size:24px; letter-spacing:.3em' });
  const name = h('input', { type: 'text', placeholder: 'Как к вам обращаться', autocapitalize: 'words' });
  const nameField = h('div', { class: 'field', hidden: true }, h('label', {}, 'Имя и фамилия'), name);
  const citySeg = h('div', { class: 'seg' });
  let city = state.campaign && state.campaign.city === 'cherepovets' ? 'cherepovets' : 'kazan';
  const renderCity = () => citySeg.replaceChildren(
    h('button', { type: 'button', class: 'chip' + (city === 'kazan' ? ' on' : ''), onclick: () => { city = 'kazan'; renderCity(); } }, 'Казань'),
    h('button', { type: 'button', class: 'chip' + (city === 'cherepovets' ? ' on' : ''), onclick: () => { city = 'cherepovets'; renderCity(); } }, 'Череповец'));
  renderCity();
  const cityField = h('div', { class: 'field', hidden: true }, h('div', { class: 'label' }, 'Город'), citySeg);

  const error = h('div', { class: 'alert err', hidden: true });
  const btn = h('button', { class: 'btn', type: 'submit' }, 'Войти');
  const again = h('button', { class: 'btn ghost', type: 'button', disabled: true }, 'Позвонить ещё раз');

  let left = wait;
  const tick = setInterval(() => {
    left--;
    again.textContent = left > 0 ? `Позвонить ещё раз через ${left} с` : 'Позвонить ещё раз';
    again.disabled = left > 0;
    if (left <= 0) clearInterval(tick);
  }, 1000);
  again.textContent = `Позвонить ещё раз через ${left} с`;
  again.onclick = async () => {
    again.disabled = true;
    try { const r = await api('/auth/call', { phone: state.phone }); clearInterval(tick); showCode(r.wait || 40); }
    catch (ex) { if (ex.message) toast(ex.message); again.disabled = false; }
  };

  const form = h('form', {
    onsubmit: async e => {
      e.preventDefault();
      error.hidden = true;
      btn.disabled = true;
      try {
        const body = { phone: state.phone, code: code.value };
        if (!nameField.hidden) { body.name = name.value.trim(); body.city = city; }
        const r = await api('/auth/verify', body);
        if (r.need_name) {
          nameField.hidden = false;
          cityField.hidden = false;
          error.textContent = 'Вы у нас впервые — укажите имя и город';
          error.hidden = false;
          name.focus();
          return;
        }
        clearInterval(tick);
        await loadMe();
        showHome();
      } catch (ex) {
        if (ex.message) { error.textContent = ex.message; error.hidden = false; }
      } finally { btn.disabled = false; }
    },
  },
    h('div', { class: 'field' }, h('label', {}, 'Код из звонка'), code),
    nameField, cityField, error, btn,
  );

  screen('Приведи друга', null,
    h('h1', {}, 'Введите код'),
    h('p', { class: 'sub' }, 'Звоним на ' + formatPhone(state.phone) + '. Код — последние 4 цифры номера, с которого идёт звонок, отвечать не нужно.'),
    h('p', { class: 'hint' }, 'Если вместо номера видно название, возьмите трубку — робот продиктует код.'),
    h('div', { class: 'card' }, form),
    again,
    h('button', { class: 'btn secondary', onclick: () => { clearInterval(tick); showSelfCall(); } }, 'Позвонить самому'),
    h('button', { class: 'btn ghost', onclick: () => { clearInterval(tick); showLogin(); } }, 'Другой номер'),
  );
  setTimeout(() => code.focus(), 50);
}

// Второй способ: человек сам звонит на наш номер — звонок бесплатный, код вводить не нужно
async function showSelfCall() {
  screen('Приведи друга', null, h('div', { class: 'loading' }, 'Готовим номер…'));
  let numbers;
  try {
    numbers = (await api('/auth/confirm', { phone: state.phone })).numbers || [];
  } catch (ex) {
    if (ex.message) toast(ex.message);
    return showLogin(ex.message);
  }

  const num = numbers[0];
  const pretty = formatPhone(num);
  const status = h('div', { class: 'alert warn' }, 'Ждём ваш звонок…');
  const name = h('input', { type: 'text', placeholder: 'Как к вам обращаться', autocapitalize: 'words' });
  const nameField = h('div', { class: 'field', hidden: true }, h('label', {}, 'Имя и фамилия'), name);
  const citySeg = h('div', { class: 'seg' });
  let city = 'kazan';
  const renderCity = () => citySeg.replaceChildren(
    h('button', { type: 'button', class: 'chip' + (city === 'kazan' ? ' on' : ''), onclick: () => { city = 'kazan'; renderCity(); } }, 'Казань'),
    h('button', { type: 'button', class: 'chip' + (city === 'cherepovets' ? ' on' : ''), onclick: () => { city = 'cherepovets'; renderCity(); } }, 'Череповец'));
  renderCity();
  const cityField = h('div', { class: 'field', hidden: true }, h('div', { class: 'label' }, 'Город'), citySeg);
  const saveBtn = h('button', { class: 'btn', hidden: true }, 'Войти');

  let stop = false;
  const send = async () => {
    const body = { phone: state.phone };
    if (!nameField.hidden) { body.name = name.value.trim(); body.city = city; }
    const r = await api('/auth/confirm/check', body);
    if (r.waiting) return false;
    if (r.need_name) {
      stop = true;
      status.className = 'alert ok';
      status.textContent = 'Звонок принят! Вы у нас впервые — укажите имя и город';
      nameField.hidden = false;
      cityField.hidden = false;
      saveBtn.hidden = false;
      name.focus();
      return false;
    }
    stop = true;
    await loadMe();
    showHome();
    return true;
  };

  saveBtn.onclick = async () => {
    if (name.value.trim().length < 2) { toast('Укажите имя'); return; }
    saveBtn.disabled = true;
    try { await send(); } catch (ex) { if (ex.message) toast(ex.message); } finally { saveBtn.disabled = false; }
  };

  // ждём звонок до 5 минут
  let tries = 0;
  const poll = setInterval(async () => {
    if (stop || ++tries > 100) { clearInterval(poll); return; }
    try { if (await send()) clearInterval(poll); } catch (e) {}
  }, 3000);

  screen('Приведи друга', null,
    h('h1', {}, 'Позвоните нам'),
    h('p', { class: 'sub' }, 'Звонок бесплатный. Мы сбросим его сами — отвечать не нужно, код вводить тоже.'),
    h('div', { class: 'card' },
      h('div', { class: 'label' }, 'Наберите этот номер'),
      h('a', { class: 'btn', href: 'tel:+' + num }, '📞 ' + pretty),
      h('div', { class: 'input', style: 'margin-top:10px; text-align:center; font-size:17px' }, pretty),
      h('div', { class: 'hint' }, 'Звоните именно с номера ' + formatPhone(state.phone) + ' — по нему мы вас и узнаем'),
      nameField, cityField, saveBtn,
    ),
    status,
    h('button', { class: 'btn secondary', onclick: () => { stop = true; clearInterval(poll); showLogin(); } }, 'Вернуться к коду'),
  );
}

async function loadMe() {
  state.me = await api('/me');
  ensurePushSubscribed();   // разрешение уже дано — просто убеждаемся, что устройство в списке
  return state.me;
}

// ---------- главный экран ----------

const STATUS = {
  lead:   { t: 'Оставил заявку', cls: '', hint: 'Ждём оплату обучения' },
  paid:   { t: 'Оплатил обучение', cls: 'ok', hint: 'Выплата после регистрации в ГИБДД' },
  gibdd:  { t: 'Зарегистрирован в ГИБДД', cls: 'ok', hint: 'Можно получить вознаграждение' },
  payout: { t: 'Заявка на выплату', cls: 'warn', hint: 'Выплатим в течение 30 дней' },
  done:   { t: 'Выплачено', cls: 'ok', hint: '' },
  lost:   { t: 'Не дошёл', cls: 'bad', hint: '' },
};

// Друг записался сам, без ссылки: берём его номер и ищем заявку в базе автошколы
function showClaim() {
  const name = h('input', { type: 'text', placeholder: 'Имя и фамилия друга', autocapitalize: 'words' });
  const phone = h('input', {
    type: 'tel', inputmode: 'tel', placeholder: '+7 (___) ___-__-__',
    oninput: e => (e.target.value = formatPhone(e.target.value)),
  });
  const error = h('div', { class: 'alert err', hidden: true });
  const btn = h('button', { class: 'btn', type: 'submit' }, 'Найти друга');

  const form = h('form', {
    onsubmit: async e => {
      e.preventDefault();
      error.hidden = true;
      if (phone.value.replace(/\D/g, '').length !== 11) {
        error.textContent = 'Введите номер друга полностью';
        error.hidden = false;
        return;
      }
      btn.disabled = true;
      try {
        state.me.claims = (await api('/claim', { phone: phone.value, name: name.value })).claims;
        const last = (state.me.claims || [])[0];
        toast(last && last.status === 'linked' ? 'Друг привязан к вам' : 'Добавили. Ищем его заявку');
        showHome();
      } catch (ex) {
        if (ex.message) { error.textContent = ex.message; error.hidden = false; }
      } finally { btn.disabled = false; }
    },
  },
    h('div', { class: 'field' }, h('label', {}, 'Номер друга'), phone),
    h('div', { class: 'field' }, h('label', {}, 'Имя друга'), name),
    error, btn,
  );

  screen('Друг без ссылки', showHome,
    h('h1', {}, 'Друг записался без ссылки'),
    h('p', { class: 'sub' }, 'Найдём его заявку в базе автошколы и закрепим за вами. Если он ещё не обращался — подождём, пока обратится.'),
    h('div', { class: 'card' }, form),
    h('div', { class: 'card' },
      h('div', { class: 'label' }, 'Когда не получится'),
      h('p', { class: 'sub', style: 'margin:0' },
        'Если друг уже закреплён за другим участником или записался больше двух месяцев назад — привязку сделает только представитель автошколы.')),
  );
  setTimeout(() => phone.focus(), 50);
}

function showHome() {
  const me = state.me;
  const t = me.terms;
  const invited = me.invited || [];
  // к каждому другу прикладываем его заявку на выплату, если она уже подана
  const byDeal = Object.fromEntries((me.payouts || []).map(p => [p.ref_deal_id, p]));
  invited.forEach(r => (r.payout = byDeal[r.deal_id] || null));
  const ready = invited.filter(r => r.status === 'gibdd');

  const linkBox = h('div', { class: 'input', style: 'word-break:break-all; font-size:14px' }, me.link);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(me.link);
      toast('Ссылка скопирована');
    } catch (e) {
      const r = document.createRange();
      r.selectNodeContents(linkBox);
      const sel = getSelection();
      sel.removeAllRanges();
      sel.addRange(r);
      toast('Нажмите «Копировать» в меню выделения');
    }
  };
  const share = async () => {
    const text = `Учись водить в автошколе — по моей ссылке тебе скидка ${t.discount} ₽: ${me.link}`;
    if (navigator.share) { try { await navigator.share({ text }); return; } catch (e) {} }
    copy();
  };

  screen('Приведи друга', null,
    pushPrompt(),
    installCard(),
    h('div', { class: 'card brand-card' },
      h('h2', { style: 'margin:0 0 6px' }, money(t.reward) + ' за каждого друга'),
      h('p', { class: 'sub' }, `Другу — скидка на обучение +2 практики в подарок. Деньги платим, когда друг заключит договор, оплатит и зарегистрируется в ГИБДД.`),
    ),

    h('div', { class: 'card' },
      h('div', { class: 'label' }, 'Ваша ссылка'),
      linkBox,
      h('div', { class: 'row2', style: 'margin-top:10px' },
        h('button', { class: 'btn', onclick: share }, 'Поделиться'),
        h('button', { class: 'btn secondary', onclick: copy }, 'Копировать')),
      h('div', { id: 'qr', class: 'qr-box' }),
      h('div', { class: 'hint' }, `Переходов по ссылке: ${me.clicks}`),
    ),

    h('div', { class: 'stats' },
      h('div', { class: 'stat' }, h('b', {}, invited.length), h('span', {}, 'друзей пришло')),
      h('div', { class: 'stat' }, h('b', {}, invited.filter(r => ['paid', 'gibdd', 'payout', 'done'].includes(r.status)).length), h('span', {}, 'оплатили')),
      h('div', { class: 'stat' }, h('b', {}, money(me.earned)), h('span', {}, 'получено')),
    ),

    ready.length ? h('div', { class: 'alert ok' },
      `Можно получить вознаграждение за ${ready.length === 1 ? 'друга' : ready.length + ' друзей'}. Нажмите «Получить деньги» в списке.`) : null,

    h('h2', {}, 'Мои друзья'),
    invited.length ? invited.map(r => {
      const s = STATUS[r.status] || STATUS.lead;
      return h('div', { class: 'card report' },
        h('div', { class: 'head' },
          h('b', {}, r.name || 'Без имени'),
          h('span', { class: 'st ' + (r.reject ? 'bad' : s.cls) }, r.reject ? 'Не засчитан' : s.t)),
        r.reject_text ? h('div', { class: 'meta' }, r.reject_text) : null,
        r.phone ? h('div', { class: 'meta' }, r.phone) : null,
        s.hint ? h('div', { class: 'meta' }, s.hint) : null,
        r.payout_rejected ? h('div', { class: 'meta' }, 'Заявка отклонена ' + d2(r.payout_rejected) + ' — можно подать заново') : null,
        r.payout_at ? h('div', { class: 'meta' }, (r.status === 'done' ? 'Выплачено ' : 'Заявка на выплату от ') + d2(r.payout_at)
          + (r.payout_method === 'debt' ? ' · списанием с долга' : r.payout_method === 'money' ? ' · на карту' : '')) : null,
        r.status === 'gibdd' && !r.reject ? h('button', { class: 'btn', style: 'margin-top:10px', onclick: () => showPayout(r) }, 'Получить деньги') : null,
        r.payout && !r.payout.paid_at ? (() => {
          // правка открыта первые полчаса после отправки, дальше только через менеджера
          const left = Math.ceil((new Date(r.payout.created_at).getTime() + t.edit_minutes * 60000 - Date.now()) / 60000);
          return [
            h('div', { class: 'meta' }, (r.payout.method === 'debt' ? 'Выбрано: списать в счёт долга' : 'Выбрано: деньгами на карту')
              + (r.payout.updated_at ? ' · заявка изменена' : '')),
            left > 0
              ? [h('button', { class: 'btn secondary', style: 'margin-top:8px', onclick: () => showPayout(r, r.payout) }, 'Изменить заявку'),
                 h('div', { class: 'hint' }, 'Изменить можно ещё ' + left + ' мин')]
              : h('div', { class: 'hint' }, 'Заявку уже не изменить. Если нужно поправить данные, свяжитесь с представителем автошколы.'),
          ];
        })() : null,
      );
    }) : h('p', { class: 'sub' }, 'Пока никого. Отправьте ссылку друзьям, которые собираются учиться на права.'),

    me.claim_on ? h('div', { class: 'card' },
      h('div', { class: 'label' }, 'Друг записался без вашей ссылки?'),
      h('p', { class: 'sub', style: 'margin:0 0 10px' },
        'Добавьте его номер — найдём заявку в базе автошколы и закрепим друга за вами.'),
      h('button', { class: 'btn secondary', onclick: showClaim }, 'Добавить друга по номеру'),
      (me.claims || []).map(c => h('div', { class: 'report', style: 'margin-top:12px' },
        h('div', { class: 'head' },
          h('b', {}, c.friend_name || c.phone),
          h('span', { class: 'st ' + (c.status === 'linked' ? 'ok' : c.status === 'rejected' ? 'bad' : '') },
            c.status === 'linked' ? 'Привязан' : c.status === 'rejected' ? 'Не вышло' : 'Ищем')),
        h('div', { class: 'meta' }, c.text),
        c.status === 'wait' ? h('button', {
          class: 'btn ghost', style: 'margin-top:6px',
          onclick: async () => {
            try { state.me.claims = (await api('/claim/cancel', { id: c.id })).claims; showHome(); }
            catch (ex) { if (ex.message) toast(ex.message); }
          },
        }, 'Убрать') : null)),
    ) : null,

    h('a', { class: 'btn secondary', style: 'margin-top:20px', href: t.offer, target: '_blank', rel: 'noopener' },
      'Условия программы и оферта'),
    h('button', { class: 'btn secondary', onclick: showNotifySettings }, '🔔 Уведомления'),
    me.is_admin ? h('button', { class: 'btn secondary', onclick: showAdmin }, '⚙ Все участники') : null,
    h('button', { class: 'btn ghost', style: 'margin-top:16px', onclick: async () => {
      await api('/auth/logout', {}).catch(() => {});
      state.me = null;
      showLogin();
    } }, 'Выйти'),
  );

  drawQr(me.link);
}

// QR рисуется, если библиотека подключена; без неё блок просто пустой
function drawQr(text) {
  const box = document.getElementById('qr');
  if (!box || typeof window.qrcode !== 'function') return;
  try {
    const qr = window.qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    box.append(h('div', { class: 'hint' }, 'Покажите этот код другу — он откроет вашу ссылку камерой'));
  } catch (e) {}
}

// ---------- уведомления ----------

const PUSH_LABELS = {
  'ref.clicked': ['Переход по ссылке', 'друг открыл вашу ссылку'],
  'ref.lead':    ['Друг оставил заявку', 'записался в автошколу'],
  'ref.paid':    ['Друг оплатил обучение', 'ждём регистрацию в ГИБДД'],
  'ref.gibdd':   ['Можно получить деньги', 'друг зарегистрирован в ГИБДД'],
  'payout.sent': ['Заявка на выплату принята', 'подтверждение отправки'],
  'payout.paid': ['Выплата прошла', 'деньги отправлены'],
};

const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
const isIosDevice = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const isStandaloneApp = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

/** Если разрешение уже дано — тихо убеждаемся, что устройство подписано */
// Ключ сервера мог смениться — старая подписка тогда молча перестаёт работать (403 от Google).
// Поэтому перед отправкой сверяем ключ подписки с текущим и при расхождении подписываемся заново.
function pushKeyBytes(key) {
  return Uint8Array.from(atob(key.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
}

function subFitsKey(sub, key) {
  try {
    const a = new Uint8Array(sub.options.applicationServerKey);
    const b = pushKeyBytes(key);
    return a.length === b.length && a.every((v, i) => v === b[i]);
  } catch (e) { return true; }   // не смогли проверить — не трогаем рабочую подписку
}

async function freshSubscription(reg, key) {
  let sub = await reg.pushManager.getSubscription();
  if (sub && !subFitsKey(sub, key)) {
    try { await sub.unsubscribe(); } catch (e) {}
    sub = null;
  }
  return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: pushKeyBytes(key) });
}

async function ensurePushSubscribed() {
  if (!pushSupported() || Notification.permission !== 'granted' || !state.me || !state.me.push_key) return;
  try {
    const reg = await navigator.serviceWorker.ready;
    const sub = await freshSubscription(reg, state.me.push_key);
    await api('/push/subscribe', { subscription: sub.toJSON() });
  } catch (e) {}
}

/**
 * Спрашиваем разрешение сразу после входа — на компьютере, Android и Huawei это обычный запрос браузера,
 * на iPhone он возможен только внутри установленного приложения, поэтому там показываем подсказку.
 */
function pushPrompt() {
  if (!state.me) return null;
  const granted = pushSupported() && Notification.permission === 'granted';
  const denied = pushSupported() && Notification.permission === 'denied';
  if (granted) return null;

  // просим разрешение не на пустом экране, а когда появился первый друг:
  // в этот момент человеку есть что отслеживать, и соглашаются заметно чаще
  const hasFriends = (state.me.invited || []).length > 0;
  if (!hasFriends) return null;

  // браузер больше не покажет свой запрос — остаётся объяснить, где его включить руками
  if (denied) {
    if (store.get('push_denied_seen')) return null;
    return h('div', { class: 'card push-ask' },
      h('b', {}, '🔔 Уведомления выключены в браузере'),
      h('p', { class: 'sub' }, state.me.bot
        ? 'Пока пишем вам в чат-бот. Если хотите получать и на телефон — включите уведомления для сайта: значок замка слева от адреса → «Уведомления» → «Разрешить».'
        : 'Включить можно вручную: значок замка слева от адреса → «Уведомления» → «Разрешить». На телефоне — настройки сайта в меню браузера.'),
      h('button', { class: 'btn ghost', onclick: () => { store.set('push_denied_seen', 1); showHome(); } }, 'Понятно'));
  }

  const snoozed = Number(store.get('push_snooze') || 0) > Date.now() || store.get('push_done');
  if (snoozed) return null;

  // iPhone вне установленного приложения: сначала иконка на экран
  if (isIosDevice() && !isStandaloneApp()) {
    return h('div', { class: 'card push-ask' },
      h('b', {}, '🔔 Уведомления о друзьях'),
      h('p', { class: 'sub' }, 'Чтобы приходили уведомления на iPhone, добавьте приложение на экран «Домой»: кнопка «Поделиться» → «На экран Домой». Потом откройте «Автошкола» с экрана и разрешите уведомления.'),
      h('button', { class: 'btn ghost', onclick: () => { store.set('push_snooze', Date.now() + 7 * 864e5); showHome(); } }, 'Понятно'));
  }
  if (!pushSupported()) return null;

  return h('div', { class: 'card push-ask' },
    h('b', {}, '🔔 Присылать уведомления по рефералам?'),
    h('p', { class: 'sub' }, 'Сообщим, когда друг оставит заявку, оплатит обучение, зарегистрируется в ГИБДД и когда пройдёт выплата. Отключить можно в любой момент.'),
    h('div', { class: 'row2' },
      h('button', { class: 'btn', onclick: async e => {
        e.target.disabled = true;
        try {
          if (await enablePush()) { toast('Уведомления включены'); showHome(); }
          else { store.set('push_snooze', Date.now() + 864e5); showHome(); }
        } catch (ex) { toast('Не получилось включить: ' + ex.message); e.target.disabled = false; }
      } }, 'Разрешить'),
      h('button', { class: 'btn secondary', onclick: () => { store.set('push_snooze', Date.now() + 7 * 864e5); showHome(); } }, 'Не сейчас')));
}

async function enablePush() {
  if (!pushSupported()) { toast('Браузер не умеет уведомления. На iPhone добавьте приложение на экран «Домой».'); return false; }
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') { toast('Уведомления запрещены в настройках браузера'); return false; }
  const reg = await navigator.serviceWorker.ready;
  const sub = await freshSubscription(reg, state.me.push_key);
  await api('/push/subscribe', { subscription: sub.toJSON() });
  store.set('push_done', 1);   // на этом браузере больше не спрашиваем
  return true;
}

async function showNotifySettings() {
  const me = state.me;
  const settings = Object.assign({}, me.notify || {});
  const perm = pushSupported() ? Notification.permission : 'unsupported';
  const rows = h('div', { class: 'card' });

  // жива ли подписка именно на этом устройстве: разрешение и подписка — разные вещи
  let here = false;
  try {
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();
    here = !!sub && subFitsKey(sub, me.push_key);
  } catch (e) {}

  const fixBtn = h('button', { class: 'btn', onclick: async e => {
    e.target.disabled = true;
    try {
      const reg = await navigator.serviceWorker.ready;
      const sub = await freshSubscription(reg, me.push_key);
      await api('/push/subscribe', { subscription: sub.toJSON() });
      toast('Устройство подключено заново');
      showNotifySettings();
    } catch (ex) { toast('Не получилось: ' + ex.message); e.target.disabled = false; }
  } }, 'Подключить это устройство заново');

  const renderRows = () => rows.replaceChildren(...Object.entries(PUSH_LABELS).map(([k, [title, hint]]) =>
    h('label', { class: 'switch-row' },
      h('div', {}, h('b', {}, title), h('span', {}, hint)),
      h('input', {
        type: 'checkbox', id: 'n-' + k, checked: !!settings[k],
        onchange: async e => {
          settings[k] = e.target.checked;
          try { await api('/notify-settings', { [k]: e.target.checked }); }
          catch (ex) { if (ex.message) toast(ex.message); }
        },
      }))));
  renderRows();

  const enableBtn = h('button', { class: 'btn', onclick: async e => {
    e.target.disabled = true;
    try { if (await enablePush()) { toast('Уведомления включены'); showNotifySettings(); } }
    catch (ex) { toast('Не получилось включить: ' + ex.message); }
    finally { e.target.disabled = false; }
  } }, 'Включить уведомления');

  screen('Уведомления', showHome,
    perm === 'granted' && here
      ? h('div', { class: 'alert ok' }, 'Уведомления работают на этом устройстве. Всего устройств: ' + (me.push_devices || 0))
      : perm === 'granted' && !here
      ? h('div', {},
          h('div', { class: 'alert warn' }, 'Разрешение есть, но подписка на этом устройстве слетела — такое бывает после чистки браузера или обновления.'),
          fixBtn)
      : perm === 'denied'
        ? h('div', { class: 'alert err' }, 'Уведомления запрещены. Разрешите их для сайта в настройках браузера.')
        : perm === 'unsupported'
          ? h('div', { class: 'alert warn' }, 'Чтобы получать уведомления на iPhone, добавьте приложение на экран «Домой» и откройте его оттуда.')
          : h('div', {}, h('p', { class: 'sub' }, 'Включите, чтобы узнавать о друзьях и выплатах, не заходя в приложение.'), enableBtn),
    me.bot ? h('div', { class: 'card' },
      h('b', {}, '💬 Дублируем в чат-бот'),
      h('p', { class: 'sub', style: 'margin:0' },
        'Эти же сообщения приходят вам в бота автошколы — даже если push выключен или телефон сменился.')) : null,
    h('h2', {}, 'О чём сообщать'),
    rows,
    perm === 'granted'
      ? h('button', { class: 'btn secondary', onclick: async () => {
          try { await api('/push/test', {}); toast('Отправили проверочное уведомление'); }
          catch (ex) { if (ex.message) toast(ex.message); }
        } }, 'Проверить уведомление')
      : null,
  );
}

// ---------- выплата ----------

function showPayout(ref, existing) {
  const t = state.me.terms;
  const prev = (existing && existing.data) || {};
  let method = existing ? existing.method : '';
  const err = h('div', { class: 'alert err', hidden: true });
  const fields = h('div');
  const agree = h('input', { type: 'checkbox', id: 'agree' });

  const field = (id, label, extra) => {
    const inp = h('input', Object.assign({ type: 'text', id: 'f-' + id }, extra || {}));
    return { el: h('div', { class: 'field' }, h('label', { for: 'f-' + id }, label), inp), inp };
  };
  const f = {};
  const build = () => {
    fields.replaceChildren();
    for (const k in f) delete f[k];
    if (!method) return;
    // при списании долга реквизиты не нужны, но ФИО и дату рождения спрашиваем: по ним проверяем, учился ли человек
    [['fio', 'ФИО как в паспорте', { autocapitalize: 'words', placeholder: 'Иванов Иван Иванович' }],
     ['birth', 'Дата рождения', { inputmode: 'numeric', placeholder: '01.02.2000' }],
     ['pass_serie', 'Серия паспорта', { inputmode: 'numeric', maxlength: 5, placeholder: '9200' }],
     ['pass_num', 'Номер паспорта', { inputmode: 'numeric', maxlength: 7, placeholder: '123456' }],
     ['pass_by', 'Кем выдан', { placeholder: 'МВД по Республике Татарстан' }],
     ['pass_date', 'Дата выдачи', { inputmode: 'numeric', placeholder: '01.02.2020' }],
     ['pass_code', 'Код подразделения', { inputmode: 'numeric', maxlength: 7, placeholder: '160-001' }],
     ['reg_addr', 'Адрес прописки', { placeholder: 'г. Казань, ул. Родины, д. 26, кв. 5' }],
     ['citizenship', 'Гражданство', { placeholder: 'РФ' }],
     ['inn', 'ИНН', { inputmode: 'numeric', maxlength: 12 }],
     ['snils', 'СНИЛС (11 цифр)', { inputmode: 'numeric', maxlength: 14 }],
     ['bank', 'Банк', { placeholder: 'Сбербанк' }],
     ['rs', 'Номер счёта (20 цифр)', { inputmode: 'numeric', maxlength: 24 }],
     ['ks', 'Корреспондентский счёт (20 цифр)', { inputmode: 'numeric', maxlength: 24 }],
     ['bik', 'БИК (9 цифр)', { inputmode: 'numeric', maxlength: 9 }]
    ].slice(0, method === 'debt' ? 2 : undefined).forEach(([id, label, extra]) => {
      const made = field(id, label, extra);
      f[id] = made.inp;
      if (prev[id]) made.inp.value = prev[id];   // при правке заявки поля уже заполнены
      fields.append(made.el);
    });
    agree.checked = !!existing;
    fields.append(h('label', { class: 'check' }, agree,
      h('span', {}, 'Согласен на обработку персональных данных и получение вознаграждения указанным способом')));
  };

  const seg = h('div', { class: 'picks' });
  const renderSeg = () => seg.replaceChildren(
    h('button', { type: 'button', class: 'pick' + (method === 'debt' ? ' on' : ''), onclick: () => { method = 'debt'; renderSeg(); build(); } },
      h('span', {}, 'Списать в счёт долга за обучение'), h('small', {}, money(t.reward))),
    h('button', { type: 'button', class: 'pick' + (method === 'money' ? ' on' : ''), onclick: () => { method = 'money'; renderSeg(); build(); } },
      h('span', {}, 'Деньгами на карту'), h('small', {}, money(t.reward_card) + ' · НДФЛ ' + money(t.ndfl))));
  renderSeg();
  if (method) build();

  const submit = h('button', { class: 'btn', type: 'submit' }, existing ? 'Сохранить изменения' : 'Отправить заявку');
  const form = h('form', {
    onsubmit: async e => {
      e.preventDefault();
      err.hidden = true;
      if (!method) { err.textContent = 'Выберите способ получения'; err.hidden = false; return; }
      const body = { deal_id: ref.deal_id, method, agree: agree.checked };
      Object.keys(f).forEach(k => (body[k] = f[k].value.trim()));
      submit.disabled = true;
      try {
        const r = await api(existing ? '/payout/update' : '/payout', body);
        await loadMe();
        screen('Заявка отправлена', null,
          h('div', { class: 'success' }, h('div', { class: 'big' }, '✅'), h('h1', {}, r.updated ? 'Заявка обновлена' : 'Заявка принята'),
            h('p', { class: 'sub' }, r.method === 'debt'
              ? `${money(r.amount)} спишем с вашей задолженности за обучение.`
              : `${money(r.amount)} поступят на счёт в течение ${r.days} дней.`)),
          h('button', { class: 'btn', onclick: showHome }, 'На главную'));
      } catch (ex) {
        if (ex.message) { err.textContent = ex.message; err.hidden = false; }
      } finally { submit.disabled = false; }
    },
  },
    h('div', { class: 'card' },
      h('div', { class: 'label' }, 'Способ получения'), seg),
    fields, err, submit,
  );

  screen(existing ? 'Изменить заявку' : 'Вознаграждение', showHome,
    h('p', { class: 'sub' }, 'За друга: ' + (ref.name || 'без имени')),
    existing ? h('div', { class: 'alert warn' }, 'Заявка уже отправлена. Исправьте данные и сохраните — менеджер увидит изменения.') : null,
    form);
}

// Установленное приложение получает уведомления надёжнее, чем вкладка браузера
let installPrompt = null;
addEventListener('beforeinstallprompt', e => { e.preventDefault(); installPrompt = e; });

function installCard() {
  if (!installPrompt || isStandaloneApp()) return null;
  return h('div', { class: 'card' },
    h('b', {}, '📲 Поставьте приложение на экран'),
    h('p', { class: 'sub' }, 'Так уведомления приходят стабильнее и кабинет открывается в один тап.'),
    h('button', { class: 'btn secondary', onclick: async () => {
      const p = installPrompt;
      installPrompt = null;
      try { await p.prompt(); } catch (e) {}
      showHome();
    } }, 'Установить'));
}

// ---------- руководителю: вся программа ----------

const d2 = v => (v ? new Date(v).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) : '');

async function showAdmin(fresh) {
  screen('Все участники', showHome, h('div', { class: 'loading' }, 'Считаем…'));
  let d;
  try { d = await api('/admin' + (fresh === true ? '?fresh=1' : '')); }
  catch (ex) { if (ex.message) toast(ex.message); showHome(); return; }

  const t = d.totals;
  const list = h('div');
  const search = h('input', { type: 'search', placeholder: 'Имя или номер' });

  const card = u => h('div', {
    class: 'card report', style: 'cursor:pointer',
    onclick: () => showAdminUser(u.phone),
  },
    h('div', { class: 'head' },
      h('b', {}, (u.test ? '🧪 ' : '') + (u.name || 'Без имени')),
      h('span', { class: 'st ' + (u.friends ? 'ok' : '') }, u.friends ? u.friends + ' др.' : '—')),
    h('div', { class: 'meta' }, formatPhone(u.phone) + (u.city === 'cherepovets' ? ' · Череповец' : '')),
    h('div', { class: 'meta' },
      [u.paid ? u.paid + ' оплатили' : null, u.gibdd ? u.gibdd + ' в ГИБДД' : null,
       u.money ? money(u.money) + ' выплачено' : null, u.claims ? u.claims + ' заявок на привязку' : null]
        .filter(Boolean).join(' · ') || 'друзей пока нет'),
    h('div', { class: 'hint' }, 'в портале с ' + d2(u.created) + (u.last ? ' · заходил ' + d2(u.last) : ' · ни разу не заходил')),
  );

  const render = () => {
    const s = search.value.trim().toLowerCase();
    const digits = s.replace(/\D/g, '');
    const ok = u => !s || (u.name || '').toLowerCase().includes(s) || (digits && u.phone.includes(digits));
    list.replaceChildren(...d.users.filter(ok).map(card));
  };
  search.oninput = render;
  render();

  screen('Все участники', showHome,
    h('div', { class: 'stats' },
      h('div', { class: 'stat' }, h('b', {}, t.users), h('span', {}, 'участников')),
      h('div', { class: 'stat' }, h('b', {}, t.friends), h('span', {}, 'друзей')),
      h('div', { class: 'stat' }, h('b', {}, t.gibdd), h('span', {}, 'в ГИБДД'))),
    h('div', { class: 'stats' },
      h('div', { class: 'stat' }, h('b', {}, t.active), h('span', {}, 'с друзьями')),
      h('div', { class: 'stat' }, h('b', {}, money(t.money)), h('span', {}, 'выплачено')),
      h('div', { class: 'stat' }, h('b', {}, t.missing), h('span', {}, 'не в портале'))),
    h('div', { class: 'stats' },
      h('div', { class: 'stat' }, h('b', {}, t.requests), h('span', {}, 'заявок в работе')),
      h('div', { class: 'stat' }, h('b', {}, t.paid_out), h('span', {}, 'выплачено заявок')),
      h('div', { class: 'stat' }, h('b', {}, t.debt), h('span', {}, 'ждут выплату'))),
    t.rejected || t.stale ? h('div', { class: 'stats' },
      h('div', { class: 'stat' }, h('b', {}, t.rejected || 0), h('span', {}, 'отказов')),
      h('div', { class: 'stat' }, h('b', {}, t.stale || 0), h('span', {}, 'дочистить воронку'))) : null,

    h('div', { class: 'field' }, search),
    list,

    d.missing.length ? [
      h('h2', {}, 'Из ботов, ещё не заходили'),
      h('p', { class: 'sub' }, 'Эти люди уже приводили друзей, но в портал не заходили. Откройте карточку — друзья видны прямо из Битрикса. В свой кабинет человек попадёт, войдя по номеру.'),
      d.missing.map(m => h('div', {
        class: 'card report', style: 'cursor:pointer', onclick: () => showAdminUser(m.phone),
      },
        h('div', { class: 'head' }, h('b', {}, formatPhone(m.phone)),
          h('span', { class: 'st ' + (m.friends ? 'ok' : '') }, m.friends ? m.friends + ' др.' : '—')),
        h('div', { class: 'meta' }, m.friends ? 'привёл друзей: ' + m.friends : 'друзей пока нет'),
        h('div', { class: 'hint' }, (m.card ? 'карточка в воронке: ' + m.card : 'карточки в воронке нет')
          + (m.last ? ' · последний друг ' + d2(m.last) : '')))),
    ] : null,

    d.rejects && d.rejects.length ? [
      h('h2', {}, 'Не засчитаны (' + d.rejects.length + ')'),
      h('p', { class: 'sub' }, 'Проверка не пропустила: сам себе друг, старый клиент или чужой реферал.'),
      d.rejects.map(x => h('div', { class: 'card report', style: 'cursor:pointer', onclick: () => showAdminUser(x.owner_phone) },
        h('div', { class: 'head' }, h('b', {}, x.owner_name || formatPhone(x.owner_phone)),
          h('span', { class: 'st bad' }, x.reject === 'self' ? 'сам себя' : x.reject === 'taken' ? 'чужой' : 'старый клиент')),
        h('div', { class: 'meta' }, 'за друга: ' + (x.friend || '') + (x.friend_phone ? ' · ' + formatPhone(x.friend_phone) : '')))),
    ] : null,

    d.money ? [
      h('h2', {}, 'Деньги программы'),
      h('div', { class: 'stats' },
        h('div', { class: 'stat' }, h('b', {}, money(d.money.sum_paid)), h('span', {}, 'выплачено')),
        h('div', { class: 'stat' }, h('b', {}, money(d.money.sum_requested)), h('span', {}, 'в заявках')),
        h('div', { class: 'stat' }, h('b', {}, money(d.money.sum_accrued)), h('span', {}, 'начислено, не заявлено'))),
      h('p', { class: 'sub' }, d.money.paid + ' выплат · ' + d.money.requested + ' заявок в работе · '
        + d.money.accrued + ' ждут заявки · ' + d.money.rejected + ' отказов'
        + (d.money.avg_days != null ? ' · от ГИБДД до денег в среднем ' + d.money.avg_days + ' дн' : '')),
      (d.months || []).length ? h('div', { class: 'card' },
        h('div', { class: 'label' }, 'По месяцам'),
        d.months.map(m => h('div', { class: 'meta' },
          m.month + ' — выплачено ' + m.paid + ' на ' + money(m.sum_paid) + ', всего начислений ' + m.total))) : null,
    ] : null,

    d.holds && d.holds.length ? [
      h('h2', {}, 'Заявки без права на выплату (' + d.holds.length + ')'),
      h('p', { class: 'sub' }, 'Заявка есть, а друг до ГИБДД не дошёл — платить рано. Портал такие не засчитывает, даже если задачу закрыли.'),
      d.holds.map(x => h('div', { class: 'card report' },
        h('div', { class: 'head' }, h('b', {}, x.owner_name || formatPhone(x.owner_phone)),
          h('span', { class: 'st bad' }, x.hold === 'dup' ? 'дубль' : 'нет права')),
        h('div', { class: 'meta' }, 'за ' + formatPhone(x.friend_phone)
          + ' · у нас: ' + (x.friend_status || 'нет в базе')),
        h('div', { class: 'hint' }, 'сделка ' + x.deal_id + ' от ' + d2(x.created)
          + (x.task_title ? ' · задача «' + x.task_title + '»' : '')))),
    ] : null,

    d.stale && d.stale.length ? [
      h('h2', {}, 'Дочистить воронку (' + d.stale.length + ')'),
      h('p', { class: 'sub' }, 'Задача «Выплата» закрыта — деньги ушли, а карточка осталась на стадии «Выплата». В портале такие уже показаны как выплаченные.'),
      d.stale.map(x => h('div', { class: 'card report', style: 'cursor:pointer', onclick: () => showAdminUser(x.owner_phone) },
        h('div', { class: 'head' }, h('b', {}, x.owner_name || formatPhone(x.owner_phone)),
          h('span', { class: 'st ok' }, 'выплачено')),
        h('div', { class: 'hint' }, 'сделка ' + x.deal_id + ' · задача ' + x.task_id + (x.paid_at ? ' · закрыта ' + d2(x.paid_at) : '')))),
    ] : null,

    d.debts && d.debts.length ? [
      h('h2', {}, 'Кому должны (' + d.debts.length + ')'),
      h('p', { class: 'sub' }, 'Друг прошёл ГИБДД, а заявки на выплату по нему нет. Всего ' + money(t.debt_sum) + '.'),
      d.debts.map(x => h('div', {
        class: 'card report', style: 'cursor:pointer', onclick: () => showAdminUser(x.owner_phone),
      },
        h('div', { class: 'head' }, h('b', {}, x.owner_name || formatPhone(x.owner_phone)),
          h('span', { class: 'st warn' }, money(t.reward))),
        h('div', { class: 'meta' }, 'за друга: ' + (x.friend || '') + (x.friend_phone ? ' · ' + formatPhone(x.friend_phone) : '')),
        h('div', { class: 'hint' }, 'в ГИБДД с ' + d2(x.updated_at)))),
    ] : null,

    d.claims.length ? [
      h('h2', {}, 'Заявки на привязку'),
      d.claims.map(c => h('div', { class: 'card report' },
        h('div', { class: 'head' },
          h('b', {}, (c.friend_name || '') + ' · ' + formatPhone(c.friend_phone)),
          h('span', { class: 'st ' + (c.status === 'linked' ? 'ok' : c.status === 'rejected' ? 'bad' : '') },
            c.status === 'linked' ? 'привязан' : c.status === 'rejected' ? 'отказ' : 'ждём')),
        h('div', { class: 'meta' }, 'просит: ' + (c.owner_name || formatPhone(c.owner_phone))),
        c.text ? h('div', { class: 'hint' }, c.text) : null)),
    ] : null,

    h('button', { class: 'btn secondary', style: 'margin-top:16px', onclick: showCampaigns }, '🔗 Партнёрские каналы'),
    h('button', { class: 'btn secondary', onclick: () => showAdmin(true) }, 'Пересчитать'),
  );
}

// Партнёрские каналы: QR в кофейнях и прочие акции. Видно и прямых клиентов, и тех,
// кого привели пришедшие из канала — метка едет по всей цепочке приглашений
async function showCampaigns(fresh) {
  screen('Каналы', showAdmin, h('div', { class: 'loading' }, 'Считаем…'));
  let d;
  try { d = await api('/admin/campaigns' + (fresh === true ? '?fresh=1' : '')); }
  catch (ex) { if (ex.message) toast(ex.message); showAdmin(); return; }

  screen('Каналы', showAdmin,
    h('h1', {}, 'Партнёрские каналы'),
    h('p', { class: 'sub' }, 'Ссылка для QR ведёт на сайт автошколы с меткой канала. Если пришедший потом сам начнёт приглашать друзей, метка поедет дальше по цепочке.'),
    (d.campaigns || []).length ? d.campaigns.map(c => {
      const s = c.stats || {};
      const link = h('div', { class: 'input', style: 'word-break:break-all; font-size:14px' }, c.url);
      return h('div', { class: 'card' },
        h('div', { class: 'label' }, (c.title || c.code) + (c.city === 'cherepovets' ? ' · Череповец' : ' · Казань')),
        link,
        h('div', { class: 'row2', style: 'margin-top:10px' },
          h('button', { class: 'btn secondary', onclick: async () => {
            try { await navigator.clipboard.writeText(c.url); toast('Ссылка скопирована'); }
            catch (e) { toast('Скопируйте вручную'); }
          } }, 'Копировать'),
          h('a', { class: 'btn ghost', href: c.target, target: '_blank', rel: 'noopener' }, 'Куда ведёт')),
        h('div', { class: 'stats', style: 'margin-top:10px' },
          h('div', { class: 'stat' }, h('b', {}, c.clicks || 0), h('span', {}, 'переходов')),
          h('div', { class: 'stat' }, h('b', {}, (s.leads || 0) + (s.deals || 0)), h('span', {}, 'обращений')),
          h('div', { class: 'stat' }, h('b', {}, s.paid || 0), h('span', {}, 'оплатили'))),
        h('div', { class: 'stats' },
          h('div', { class: 'stat' }, h('b', {}, s.gibdd || 0), h('span', {}, 'сдали ГИБДД')),
          h('div', { class: 'stat' }, h('b', {}, s.referrers || 0), h('span', {}, 'стали рефералами')),
          h('div', { class: 'stat' }, h('b', {}, s.invited || 0), h('span', {}, 'привели друзей'))),
        h('div', { class: 'hint' }, c.target === 'portal'
          ? 'Ведёт в кабинет рефералки: человек регистрируется и получает свою ссылку с меткой канала'
          : 'Ведёт на сайт автошколы: человек оставляет заявку на обучение'),
        (s.referrers_list || []).length ? [
          h('div', { class: 'label', style: 'margin-top:12px' }, 'Кто пришёл из канала'),
          s.referrers_list.map(r => h('div', { class: 'report', style: 'margin-top:8px' },
            h('div', { class: 'head' },
              h('b', {}, r.name || formatPhone(r.phone)),
              h('span', { class: 'st ' + (r.friends ? 'ok' : '') }, r.friends ? r.friends + ' др.' : '—')),
            h('div', { class: 'meta' }, formatPhone(r.phone)
              + (r.paid ? ' · оплатили ' + r.paid : '')
              + (r.campaign_src === 'chain' ? ' · по цепочке' : r.campaign_src === 'crm' ? ' · по заявке в CRM' : '')))),
        ] : null,
        s.invited ? h('div', { class: 'hint' }, 'из приведённых оплатили: ' + (s.invited_paid || 0)) : null);
    }) : h('p', { class: 'sub' }, 'Каналов пока нет.'),
    h('button', { class: 'btn secondary', style: 'margin-top:16px', onclick: () => showCampaigns(true) }, 'Пересчитать'),
  );
}

async function showAdminUser(phone) {
  screen('Участник', showAdmin, h('div', { class: 'loading' }, 'Загрузка…'));
  let d;
  try { d = await api('/admin/user?phone=' + encodeURIComponent(phone)); }
  catch (ex) { if (ex.message) toast(ex.message); showAdmin(); return; }
  const u = d.user;

  screen('Участник', showAdmin,
    h('h1', {}, u.name || 'Без имени'),
    h('p', { class: 'sub' }, formatPhone(u.phone)),
    h('div', { class: 'card' },
      h('div', { class: 'meta' }, 'Город: ' + (u.city === 'cherepovets' ? 'Череповец' : 'Казань')),
      h('div', { class: 'meta' }, 'Откуда: ' + (u.in_portal ? (u.source || 'сам') : 'чат-бот, в портал не заходил')),
      u.code ? h('div', { class: 'meta' }, 'Ссылка: ' + location.origin + '/r/' + u.code) : null,
      u.manager ? h('div', { class: 'meta' }, 'Менеджер в Битриксе: ' + u.manager) : null,
      u.deal ? h('div', { class: 'meta' }, 'Сделка в воронке «Рефералка»: ' + u.deal) : null,
      h('div', { class: 'hint' }, 'регистрация ' + d2(u.created) + (u.last ? ' · последний вход ' + d2(u.last) : '')
        + ' · устройств с уведомлениями: ' + u.devices)),

    h('h2', {}, 'Друзья (' + d.friends.length + ')'),
    d.friends.length ? d.friends.map(r => {
      const s = STATUS[r.status] || STATUS.lead;
      return h('div', { class: 'card report' },
        h('div', { class: 'head' }, h('b', {}, r.name || 'Без имени'),
          h('span', { class: 'st ' + (r.reject ? 'bad' : s.cls) }, r.reject ? 'не засчитан' : s.t)),
        h('div', { class: 'meta' }, (r.phone ? formatPhone(r.phone) + ' · ' : '')
          + (r.deal_id > 0 ? 'сделка ' + r.deal_id : 'лид ' + -r.deal_id)),
        r.payout ? h('div', { class: 'meta' },
          (r.payout.status === 'paid' ? '💰 выплачено ' : r.payout.status === 'rejected' ? '🚫 отказ ' : '📝 заявка от ') + d2(r.payout.at)
          + (r.payout.method === 'debt' ? ' · с долга' : r.payout.method === 'money' ? ' · на карту' : '')
          + ' · сделка ' + r.payout.deal
          + (r.payout.dup > 1 ? ' · дублей: ' + r.payout.dup : '')) : null,
        h('div', { class: 'hint' }, 'пришёл ' + d2(r.created_at) + ' · изменился ' + d2(r.updated_at)));
    }) : h('p', { class: 'sub' }, 'Пока никого'),

    d.payouts.length ? [h('h2', {}, 'Выплаты'),
      d.payouts.map(p => h('div', { class: 'card report' },
        h('div', { class: 'head' }, h('b', {}, money(p.amount)),
          h('span', { class: 'st ' + (p.paid_at ? 'ok' : '') }, p.paid_at ? 'выплачено' : 'в работе')),
        h('div', { class: 'meta' }, (p.method === 'debt' ? 'списание с долга' : 'на карту') + (p.fio ? ' · ' + p.fio : '')),
        h('div', { class: 'hint' }, 'заявка ' + d2(p.created_at) + (p.paid_at ? ' · выплата ' + d2(p.paid_at) : ''))))] : null,

    d.claims.length ? [h('h2', {}, 'Заявки на привязку'),
      d.claims.map(c => h('div', { class: 'card report' },
        h('div', { class: 'head' }, h('b', {}, (c.friend_name || '') + ' · ' + formatPhone(c.friend_phone)),
          h('span', { class: 'st ' + (c.status === 'linked' ? 'ok' : c.status === 'rejected' ? 'bad' : '') }, c.status)),
        h('div', { class: 'hint' }, d2(c.created_at) + (c.reason ? ' · ' + c.reason : ''))))] : null,
  );
}

// ---------- старт ----------

// если что-то пошло не так, человек должен увидеть экран, а не вечную «Загрузку…»
function bootFailed(e) {
  console.error(e);
  $app.replaceChildren(
    h('div', { class: 'alert err' }, 'Не удалось открыть приложение. Обновите страницу.'),
    h('button', { class: 'btn', onclick: () => location.reload(true) }, 'Обновить'),
    h('button', { class: 'btn secondary', onclick: async () => {
      // чистим кэш и фонового помощника — помогает после обновления версии
      try {
        const regs = await navigator.serviceWorker.getRegistrations();
        await Promise.all(regs.map(r => r.unregister()));
        const keys = await caches.keys();
        await Promise.all(keys.map(k => caches.delete(k)));
      } catch (err) {}
      location.reload(true);
    } }, 'Сбросить кэш и открыть заново'));
}

window.addEventListener('error', e => {
  if (document.querySelector('#app .loading')) bootFailed(e.error || e.message);
});

(async function init() {
  // экран рисуем сразу, не дожидаясь сервера: «Загрузка…» не должна висеть никогда
  // ссылка из чат-бота приводит с готовым номером: ?p=79XXXXXXXXX
  const params = new URLSearchParams(location.search);
  state.from = (params.get('from') || '').replace(/[^a-zA-Z0-9_-]/g, '');
  const pre = (params.get('p') || '').replace(/\D/g, '');
  if (pre.length >= 10) {
    state.phone = pre.length === 11 && pre[0] === '8' ? '7' + pre.slice(1) : pre;
    history.replaceState(null, '', location.pathname);
  }
  try { showLogin(); } catch (e) { bootFailed(e); return; }
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
  try {
    await loadMe();
    if (location.hash === '#admin' && state.me && state.me.is_admin) showAdmin();
    else showHome();
  } catch (e) { /* не вошёл — остаётся экран входа */ }
})();

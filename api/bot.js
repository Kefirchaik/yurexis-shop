// Vercel Serverless Function → /api/bot  (webhook Telegram-бота YUREXIS RP Pay)
// Бот только для тебя: выставляет счета в Stars и USDT и сообщает об оплате.
// Переменные окружения (Vercel → Settings → Environment Variables):
//   BOT_TOKEN        токен от @BotFather
//   ADMIN_ID         твой Telegram ID (число, узнать у @userinfobot)
//   WEBHOOK_SECRET   любая длинная строка из латиницы/цифр/_/-
//   CRYPTO_PAY_TOKEN токен из @CryptoBot → Crypto Pay → Create App (нужен только для USDT)
const SITE = 'https://yurexis-shop.vercel.app';
const SUPPORT = '@kefirchaik';

const tg = (method, body) =>
  fetch(`https://api.telegram.org/bot${process.env.BOT_TOKEN}/${method}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  }).then((r) => r.json());

const say = (chat_id, text) => tg('sendMessage', { chat_id, text, disable_web_page_preview: true });
const who = (u) => (u ? (u.username ? '@' + u.username : u.first_name || 'без имени') + ' (id ' + u.id + ')' : '—');

const HELP =
  'Команды администратора:\n\n' +
  '/stars 88 Скин Ivan_Petrov — ссылка на оплату 88 Stars\n' +
  '/usdt 1.10 Скин Ivan_Petrov — ссылка на оплату 1.10 USDT (CryptoBot)\n' +
  '/refund USER_ID CHARGE_ID — вернуть Stars (ID платежа приходит в уведомлении)\n\n' +
  'Текст после суммы — описание заказа: ник, товар, ID. Оно придёт тебе в уведомлении об оплате.';

async function onMessage(m) {
  const admin = String(m.from && m.from.id) === String(process.env.ADMIN_ID);

  // Оплата звёздами прошла
  if (m.successful_payment) {
    const sp = m.successful_payment;
    await say(process.env.ADMIN_ID,
      '✅ Оплачено звёздами: ' + sp.total_amount + ' Stars\n' +
      '📦 Заказ: ' + sp.invoice_payload + '\n' +
      '👤 Покупатель: ' + who(m.from) + '\n' +
      '🧾 ID платежа: ' + sp.telegram_payment_charge_id);
    await say(m.chat.id, 'Оплата получена ✅ Администратор выдаст товар, обычно в течение 24 часов. Вопросы: ' + SUPPORT);
    return;
  }

  const text = (m.text || '').trim();
  const cmd = text.match(/^\/(\w+)(?:@\w+)?\s*([\s\S]*)$/);
  if (!cmd) return;
  const name = cmd[1].toLowerCase(), rest = cmd[2].trim();

  // Команды для всех (Telegram требует /paysupport и /terms у ботов с оплатой)
  if (name === 'paysupport' || name === 'support') return say(m.chat.id, 'По вопросам оплаты пишите ' + SUPPORT);
  if (name === 'terms') return say(m.chat.id, 'Условия и оферта: ' + SITE + '/#offer');
  if (name === 'start' && !admin) return say(m.chat.id, 'Это бот оплаты YUREXIS RP. Заказ оформляется на сайте: ' + SITE);
  if (!admin) return;

  if (name === 'start' || name === 'help') return say(m.chat.id, HELP);

  if (name === 'stars') {
    const a = rest.match(/^(\d+)\s+([\s\S]+)$/);
    if (!a) return say(m.chat.id, 'Формат: /stars 88 Скин Ivan_Petrov');
    const r = await tg('createInvoiceLink', {
      title: 'Заказ YUREXIS RP',
      description: a[2].slice(0, 255),
      payload: a[2].slice(0, 60),
      currency: 'XTR',
      prices: [{ label: 'Заказ', amount: Number(a[1]) }],
    });
    return say(m.chat.id, r.ok ? '⭐ Ссылка на оплату ' + a[1] + ' Stars:\n' + r.result : 'Ошибка Telegram: ' + r.description);
  }

  if (name === 'usdt') {
    const a = rest.match(/^(\d+(?:[.,]\d+)?)\s+([\s\S]+)$/);
    if (!a) return say(m.chat.id, 'Формат: /usdt 1.10 Скин Ivan_Petrov');
    if (!process.env.CRYPTO_PAY_TOKEN) return say(m.chat.id, 'Не задан CRYPTO_PAY_TOKEN в настройках Vercel.');
    const amount = a[1].replace(',', '.');
    const r = await fetch('https://pay.crypt.bot/api/createInvoice', {
      method: 'POST',
      headers: { 'Crypto-Pay-API-Token': process.env.CRYPTO_PAY_TOKEN, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        currency_type: 'crypto', asset: 'USDT', amount,
        description: a[2].slice(0, 1000), payload: a[2].slice(0, 1000),
        allow_comments: false, allow_anonymous: false, expires_in: 86400,
      }),
    }).then((x) => x.json());
    const link = r.ok && r.result && (r.result.bot_invoice_url || r.result.pay_url);
    return say(m.chat.id, link ? '🪙 Ссылка на оплату ' + amount + ' USDT (действует 24 часа):\n' + link : 'Ошибка CryptoBot: ' + JSON.stringify(r.error || r));
  }

  if (name === 'refund') {
    const a = rest.match(/^(\d+)\s+(\S+)$/);
    if (!a) return say(m.chat.id, 'Формат: /refund USER_ID CHARGE_ID');
    const r = await tg('refundStarPayment', { user_id: Number(a[1]), telegram_payment_charge_id: a[2] });
    return say(m.chat.id, r.ok ? 'Возврат звёзд выполнен ✅' : 'Ошибка Telegram: ' + r.description);
  }

  return say(m.chat.id, HELP);
}

module.exports = async (req, res) => {
  if (req.method !== 'POST') return res.status(200).send('ok');
  if (req.headers['x-telegram-bot-api-secret-token'] !== process.env.WEBHOOK_SECRET) return res.status(401).send('no');
  const u = req.body || {};
  try {
    if (u.pre_checkout_query) await tg('answerPreCheckoutQuery', { pre_checkout_query_id: u.pre_checkout_query.id, ok: true });
    else if (u.message) await onMessage(u.message);
  } catch (e) {
    console.error('bot error', e);
  }
  return res.status(200).send('ok');
};

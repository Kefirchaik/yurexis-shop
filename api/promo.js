// Vercel Serverless Function → /api/promo
// Промокоды хранятся ТОЛЬКО здесь, на сервере: посетители сайта их не видят и не могут подобрать из исходника.
// Формат: КОД: { percent: скидка %, until: 'ГГГГ-ММ-ДД' (последний день), only: ['skin','car','bc','pack'] (необязательно), note: 'текст игроку' }
const PROMOS = {
  WELCOME15: { percent: 15, until: '2026-12-31', note: '−15% на любой заказ' },
  BC20: { percent: 20, until: '2026-11-30', only: ['bc'], note: '−20% на донат-валюту BC' },
};

const TYPES = ['skin', 'car', 'bc', 'pack'];
const WINDOW_MS = 60 * 1000;
const MAX_TRIES = 8; // попыток в минуту с одного IP (защита от подбора)
const tries = new Map();

function limited(ip) {
  const now = Date.now();
  const arr = (tries.get(ip) || []).filter((t) => now - t < WINDOW_MS);
  arr.push(now);
  tries.set(ip, arr);
  if (tries.size > 5000) tries.clear();
  return arr.length > MAX_TRIES;
}

module.exports = (req, res) => {
  res.setHeader('Cache-Control', 'no-store');
  if (req.method !== 'POST') return res.status(405).json({ ok: false, error: 'Метод не поддерживается' });

  const ip = String(req.headers['x-forwarded-for'] || '').split(',')[0].trim() || 'unknown';
  if (limited(ip)) return res.status(429).json({ ok: false, error: 'Слишком много попыток. Подождите минуту' });

  let body = req.body;
  if (typeof body === 'string') { try { body = JSON.parse(body); } catch (e) { body = {}; } }
  body = body || {};

  const code = String(body.code || '').trim().toUpperCase();
  const type = String(body.type || '');
  const notFound = { ok: false, error: 'Промокод не найден' };

  if (!/^[A-Z0-9_-]{2,24}$/.test(code) || TYPES.indexOf(type) < 0) return res.status(200).json(notFound);
  const p = Object.prototype.hasOwnProperty.call(PROMOS, code) ? PROMOS[code] : null;
  if (!p) return res.status(200).json(notFound);

  if (p.until && Date.now() > new Date(p.until + 'T23:59:59+02:00').getTime())
    return res.status(200).json({ ok: false, error: 'Срок действия промокода истёк' });
  if (p.only && p.only.indexOf(type) < 0)
    return res.status(200).json({ ok: false, error: 'Этот промокод не подходит для этого товара' });

  return res.status(200).json({ ok: true, code: code, percent: p.percent, note: p.note });
};

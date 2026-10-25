// Vercel Serverless Function → /api/cryptopay  (webhook Crypto Pay: сообщает об оплате счёта в USDT)
// Адрес для вебхука: https://yurexis-shop.vercel.app/api/cryptopay?s=ТВОЙ_WEBHOOK_SECRET
// Включается в @CryptoBot → Crypto Pay → My Apps → твоё приложение → Webhooks.
// Тело запроса не берём на веру: статус счёта перепроверяется через API Crypto Pay.
module.exports = async (req, res) => {
  if (req.method !== 'POST') return res.status(200).send('ok');
  if (!process.env.WEBHOOK_SECRET || (req.query && req.query.s) !== process.env.WEBHOOK_SECRET) return res.status(401).send('no');
  try {
    let u = req.body;
    if (typeof u === 'string') u = JSON.parse(u);
    const id = u && u.update_type === 'invoice_paid' && u.payload && u.payload.invoice_id;
    if (id) {
      const r = await fetch('https://pay.crypt.bot/api/getInvoices?invoice_ids=' + encodeURIComponent(id), {
        headers: { 'Crypto-Pay-API-Token': process.env.CRYPTO_PAY_TOKEN },
      }).then((x) => x.json());
      const inv = r.ok && r.result && r.result.items && r.result.items[0];
      if (inv && inv.status === 'paid') {
        await fetch(`https://api.telegram.org/bot${process.env.BOT_TOKEN}/sendMessage`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            chat_id: process.env.ADMIN_ID,
            text: '✅ Оплачено в крипте: ' + inv.amount + ' ' + inv.asset + '\n📦 Заказ: ' + (inv.payload || inv.description || '—') + '\n🧾 Счёт: ' + inv.invoice_id,
          }),
        });
      }
    }
  } catch (e) {
    console.error('cryptopay error', e);
  }
  return res.status(200).send('ok');
};

// Telegram webhook for YUREXIS RP SHOP (Vercel Node.js runtime).
// Required: BOT_TOKEN, ADMIN_TELEGRAM_ID, TELEGRAM_WEBHOOK_SECRET,
// UPSTASH_REDIS_REST_URL, UPSTASH_REDIS_REST_TOKEN.
// Optional: SHOP_URL (defaults to deployed storefront), PAYMENT_CARD_TEXT,
// PAYMENT_STARS_TEXT, PAYMENT_CRYPTO_TEXT.
// Configure webhook with secret_token and URL /api/telegram-shop.
const TOKEN = process.env.BOT_TOKEN;
const ADMIN = String(process.env.ADMIN_TELEGRAM_ID || '');
const SECRET = process.env.TELEGRAM_WEBHOOK_SECRET;
const REDIS = process.env.UPSTASH_REDIS_REST_URL;
const REDIS_TOKEN = process.env.UPSTASH_REDIS_REST_TOKEN;
const SHOP = process.env.SHOP_URL || 'https://yurexis-shop.vercel.app/';
const items = [
  ['bc30','🪙 30 000 BC',25],['bc60','🪙 60 000 BC',50],
  ['bc100','🪙 100 000 BC',100],['bc200','🪙 200 000 BC',200],
  ['bc300','🪙 300 000 BC',300],['car','🚘 Любое Т/С',25],
  ['skin','🎭 Любой скин',15],['v100','💰 100 млн виртов',50],
  ['v500','💰 500 млн виртов',100],['v1000','💰 1 млрд виртов',150],
  ['v2000','💰 2 млрд виртов',200]
];
const itemById = Object.fromEntries(items.map(x=>[x[0],x]));
const methods = {card:'💳 Карта (₴)',stars:'⭐ Telegram Stars',crypto:'💎 CryptoBot'};
const statuses = {pending:'Ожидает оплаты',review:'На проверке',paid:'Оплата подтверждена',delivered:'Товар выдан',rejected:'Отклонён'};
async function redis(...args) {
  const r = await fetch(REDIS, {method:'POST',headers:{Authorization:'Bearer '+REDIS_TOKEN,'Content-Type':'application/json'},body:JSON.stringify(args)});
  if(!r.ok) throw new Error('Redis unavailable');
  const j=await r.json(); if(j.error) throw new Error('Redis error'); return j.result;
}
async function tg(method, body) {
  const r=await fetch('https://api.telegram.org/bot'+TOKEN+'/'+method,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  const j=await r.json(); if(!j.ok) throw new Error('Telegram API: '+j.description); return j.result;
}
const buttons=(rows)=>({inline_keyboard:rows.map(row=>row.map(([text,callback_data])=>({text,callback_data})))});
async function say(chat_id,text,reply_markup){return tg('sendMessage',{chat_id,text,reply_markup});}
async function state(id,value){await redis('SET','shop:state:'+id,JSON.stringify(value),'EX',86400);}
async function readState(id){const v=await redis('GET','shop:state:'+id);return v?JSON.parse(v):null;}
async function order(id){const v=await redis('GET','shop:order:'+id);return v?JSON.parse(v):null;}
async function save(o){await redis('SET','shop:order:'+o.id,JSON.stringify(o));}
function summary(o){return 'Заказ #'+o.id+'\n👤 '+o.nick+'\n🛍 '+o.name+'\n📌 '+(o.itemId||'—')+'\n💳 '+methods[o.method]+'\n💵 '+o.price+' ₴ / '+o.price+' ⭐ (для CryptoBot сумма согласуется отдельно)\n📦 '+statuses[o.status];}
async function catalog(chat){await say(chat,'🛒 YUREXIS RP SHOP\nВыберите товар. Цены: 1 ⭐ = 1 ₴.',buttons(items.map(x=>[[x[1]+' — '+x[2]+' ₴', 'item:'+x[0]]]).concat([[['🌐 Открыть магазин','webhint']],[['📦 Мои заказы','mine']]])));}
async function handleMessage(m){
 const id=String(m.from.id), chat=m.chat.id, txt=(m.text||'').trim();
 if(txt.startsWith('/start shop_')){const key=txt.slice('/start shop_'.length).trim();if(itemById[key]){await state(id,{step:'nick',item:key});await say(chat,'Вы выбрали '+itemById[key][1]+'.\\nВведите игровой ник Name_Surname.');return;}}\n if(txt==='/start'||txt==='/shop'||txt==='🏪 Магазин'){await state(id,{});await catalog(chat);return;}
 if(txt==='/orders'){await mine(id,chat);return;}
 const s=await readState(id);
 if(!s||!s.step){await say(chat,'Для покупки нажмите /shop');return;}
 if(s.step==='nick'){
  if(!/^[A-Za-zА-Яа-яЁёІіЇїЄє]{2,}_[A-Za-zА-Яа-яЁёІіЇїЄє]{2,}$/.test(txt)){await say(chat,'Введите ник в формате Name_Surname');return;}
  s.nick=txt;
  if(s.item==='car'||s.item==='skin'){s.step='itemId';await state(id,s);await say(chat,'Введите числовой ID '+(s.item==='car'?'транспорта':'скина'));return;}
  s.step='method';await state(id,s);await chooseMethod(chat);return;
 }
 if(s.step==='itemId'){
  if(!/^\\d{1,6}$/.test(txt)){await say(chat,'Введите ID цифрами (до 6 цифр).');return;}
  s.itemId=txt;s.step='method';await state(id,s);await chooseMethod(chat);return;
 }
 await say(chat,'Используйте кнопки выбора оплаты или /shop.');
}
async function chooseMethod(chat){await say(chat,'Выберите способ оплаты:',buttons([[['💳 Карта','method:card']],[['⭐ Stars','method:stars']],[['💎 CryptoBot','method:crypto']]]));}
async function mine(id,chat){
 const ids=await redis('LRANGE','shop:user:'+id,0,19);
 if(!ids||!ids.length){await say(chat,'У вас пока нет заказов. /shop');return;}
 for(const oid of ids){const o=await order(oid);if(o) await say(chat,summary(o));}
}
async function handleCallback(q){
 const id=String(q.from.id),chat=q.message?.chat.id,data=q.data||'';
 await tg('answerCallbackQuery',{callback_query_id:q.id});
 if(data==='webhint'){await say(chat,'🌐 Магазин: '+SHOP);return;}
 if(data==='mine'){await mine(id,chat);return;}
 if(data.startsWith('item:')){
  const key=data.slice(5);if(!itemById[key])return;
  await state(id,{step:'nick',item:key});
  await say(chat,'Вы выбрали '+itemById[key][1]+'.\nВведите игровой ник Name_Surname.');return;
 }
 if(data.startsWith('method:')){
  const s=await readState(id),method=data.slice(7);
  if(!s||s.step!=='method'||!methods[method]||!itemById[s.item])return;
  const item=itemById[s.item],oid=String(await redis('INCR','shop:next-order'));
  const o={id:oid,user:id,nick:s.nick,item:s.item,name:item[1],price:item[2],itemId:s.itemId||'',method,status:'pending',createdAt:new Date().toISOString()};
  await save(o);await redis('LPUSH','shop:user:'+id,oid);await state(id,{});
  const instruction=process.env['PAYMENT_'+method.toUpperCase()+'_TEXT']||'Напишите @kefirchaik для получения инструкций по оплате.';
  await say(chat,summary(o)+'\n\n'+instruction+'\n\nПосле фактической оплаты нажмите кнопку:',buttons([[['✅ Я оплатил','claim:'+oid]]]));
  await say(ADMIN,'🆕 '+summary(o)+'\nTelegram ID: '+id);
  return;
 }
 if(data.startsWith('claim:')){
  const o=await order(data.slice(6));if(!o||o.user!==id||o.status!=='pending')return;
  o.status='review';await save(o);await say(chat,'Заказ #'+o.id+' передан на ручную проверку. Это не подтверждение оплаты.');
  await say(ADMIN,'🔔 Проверьте поступление денег!\n'+summary(o),buttons([[['✅ Подтвердить','admin:paid:'+o.id],['❌ Отклонить','admin:rejected:'+o.id]]]));
  return;
 }
 if(data.startsWith('admin:')){
  if(id!==ADMIN)return;
  const parts=data.split(':'),next=parts[1],o=await order(parts[2]);
  if(!o||!statuses[next])return;
  if(next==='paid'&&o.status!=='review')return;
  if(next==='delivered'&&o.status!=='paid')return;
  if(next==='rejected'&&o.status!=='review')return;
  o.status=next;await save(o);
  await say(o.user,'📦 Обновление заказа:\n'+summary(o));
  await say(ADMIN,'Обновлено:\n'+summary(o),next==='paid'?buttons([[['📦 Товар выдан','admin:delivered:'+o.id]]]):undefined);
 }
}
module.exports=async function handler(req,res){
 if(req.method!=='POST')return res.status(405).json({error:'Method not allowed'});
 if(!TOKEN||!ADMIN||!SECRET||!REDIS||!REDIS_TOKEN)return res.status(503).json({error:'Not configured'});
 if(req.headers['x-telegram-bot-api-secret-token']!==SECRET)return res.status(403).json({error:'Forbidden'});
 try{
  const update=req.body||{};
  // Deduplicate Telegram retries; only mark after handling to allow retry on failure.
  const key='shop:update:'+update.update_id;
  if(update.update_id!=null && await redis('GET',key))return res.status(200).json({ok:true});
  if(update.message)await handleMessage(update.message);
  if(update.callback_query)await handleCallback(update.callback_query);
  if(update.update_id!=null)await redis('SET',key,'1','EX',86400);
  return res.status(200).json({ok:true});
 }catch(e){console.error('Shop webhook failed',e);return res.status(500).json({error:'Processing failed'});}
};

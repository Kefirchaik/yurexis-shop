# YUREXIS RP Telegram Shop — draft integration

This branch adds a **separate** webhook at `/api/telegram-shop`. The existing site and its payment integrations are untouched. Orders are stored in Upstash Redis; the administrator checks **all** payments manually. The bot does not accept card details.

## Environment variables (Vercel)

- `BOT_TOKEN`: BotFather token (secret)
- `ADMIN_TELEGRAM_ID`: your existing numeric Telegram admin ID
- `TELEGRAM_WEBHOOK_SECRET`: random secret used for Telegram webhook authentication
- `UPSTASH_REDIS_REST_URL` and `UPSTASH_REDIS_REST_TOKEN`: persistent Upstash Redis REST credentials
- `SHOP_URL`: optional URL of the current website
- `PAYMENT_CARD_TEXT`, `PAYMENT_STARS_TEXT`, `PAYMENT_CRYPTO_TEXT`: optional **manual** payment instructions. Do not put secrets in public files. If unset, users are directed to @kefirchaik.

## Telegram webhook

After configuring environment variables, set webhook using Bot API `setWebhook` with:
- `url`: `https://yurexis-shop.vercel.app/api/telegram-shop`
- `secret_token`: exactly the value of `TELEGRAM_WEBHOOK_SECRET`
- `allowed_updates`: `["message","callback_query"]`

**Important:** Telegram allows only one webhook per bot. Before changing it, inspect the current webhook and migrate its existing handlers; otherwise existing Stars/CryptoBot functionality may stop working. This branch is **not yet ready to enable** on the production bot.

## Implemented

- Chat catalog, username and vehicle/skin ID collection
- Manual payment claim and admin-only confirmation/rejection/delivery
- Redis order persistence, basic Telegram update deduplication, user order history
- Price table as agreed: 1 Star = 1 UAH for display

## Still required before deployment

- Merge existing bot webhook routing, preserving the existing Stars/CryptoBot invoice creation and callback handling
- Add verified Telegram Mini App authentication and API, then integrate the UI
- Add server-side purchase amount checks for real invoice providers; do not rely on the 'I paid' button
- Test with a separate test bot and Redis instance before switching the production webhook
- Review Telegram's rules for payments for digital goods and Stars before enabling card/crypto payment paths inside Telegram

No real payments are initiated by this draft handler.

# Hamsukhan for Shopify

A Shopify app with one **theme app embed** that adds the Hamsukhan call button to a store.
It loads the same `widget.js` as the plain website snippet, so calls, bookings and settings all work the same way.
The app asks for no access scopes and reads no store data.

```
shopify.app.toml                                  app config (client_id only, no secrets)
extensions/hamsukhan-widget/shopify.extension.toml
extensions/hamsukhan-widget/blocks/hamsukhan-embed.liquid   the app embed (target: body)
extensions/hamsukhan-widget/locales/en.default.json
```

## Deploy (once, from a Shopify Partner account)
1. Create a Partner account at partners.shopify.com, then **Apps → Create app → Create app manually**.
2. In this folder:
   ```sh
   npm install
   npx shopify app config link     # pick the app you created; fills in client_id
   npx shopify app deploy          # uploads the theme app extension and releases a version
   ```
3. Install it on a store: either a custom distribution link (Partner dashboard → Distribution → Custom), or publish
   it in the Shopify App Store later (needs app review).
4. To try it on a development store first: `npx shopify app dev`.

## What the merchant does
1. Install the app.
2. **Online Store → Themes → Customize → App embeds** (left sidebar, puzzle icon) → turn on **Hamsukhan voice assistant**.
3. Paste the bot key from Hamsukhan (**Bot Studio → Embed**) and save.

## Without the app (works today)
1. Shopify admin → **Online Store → Themes → … → Edit code**.
2. Open `layout/theme.liquid` and paste this line just before `</body>`:
   ```html
   <script src="https://hamsukhan.techvocalz.com/widget.js" data-bot="BOT_PUBLIC_KEY" defer></script>
   ```
3. Save and reload the store.

## Allowed domains
If the bot has an allowed-domains list (**Bot Studio → Widget → Allowed websites**), add **both**:
- the store's `your-store.myshopify.com` domain (used by the theme editor preview and stores without a custom domain), and
- the custom domain, e.g. `shop.example.com`.

Don't add plain `myshopify.com`: that would let every Shopify store use your bot.

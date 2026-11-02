# Hamsukhan for Shopify

A Shopify app that adds the Hamsukhan call button to a store, two ways:

- **Connect (recommended):** the owner clicks *Connect Shopify* in Hamsukhan → Integrations (or opens the app in
  Shopify admin). After Shopify's OAuth, Hamsukhan adds `widget.js?bot=KEY` to the storefront as a ScriptTag, so
  nothing has to be pasted. Scope: `write_script_tags` only, no store data is read. Offline tokens don't expire, so the
  store stays connected until the app is uninstalled (`app/uninstalled` webhook marks it).
- **Theme app embed:** the merchant turns the embed on in the theme editor and pastes the bot key.

Both load the same `widget.js` as the plain website snippet, so calls, bookings and settings work the same way.

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

## Switch it on in Hamsukhan
Super admin → Settings → **Plugins → Shopify app**: tick *Customers can connect Shopify stores*, paste the app's
**Client ID** and **Client secret** (Partner dashboard → your app → Client credentials), save. Copy the three URLs shown
there into the Partner dashboard (they match `shopify.app.toml`):

| Partner dashboard field | URL |
|---|---|
| App URL | `https://hamsukhan.techvocalz.com/shopify/install` |
| Allowed redirection URL | `https://hamsukhan.techvocalz.com/integrations/shopify/callback` |
| Privacy webhooks (customer data request, customer redact, shop redact) | `https://hamsukhan.techvocalz.com/api/shopify/webhooks` |

## What the merchant does (theme app embed)
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

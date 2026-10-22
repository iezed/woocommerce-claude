# Briefing for WooCommerce

Lets [Briefing for WooCommerce](https://briefing.woocommerce.com/) read a store's figures, orders and catalogue so it can write the merchant a short briefing every Monday and answer their questions about it.

The merchant connects from the Briefing site and approves a **read-only** key on WooCommerce's own approval screen. Briefing then reads through this plugin's REST route, `POST /wp-json/briefing-for-woocommerce/call`. It sends all of a request's calls together (`{ "calls": [ { "name": "briefing-totals", "arguments": { … } } ] }`) and gets one result per call, in order (`{ "results": [ { "ok": true, "data": … }, { "ok": false, "code": …, "error": … } ] }`).

Up to 0.3.0 this was an MCP endpoint at `/wp-json/briefing-for-woocommerce/mcp`, served by the MCP adapter WooCommerce bundled. WooCommerce 11.3 stops bundling the adapter, so 0.4.0 answers on a plain route and needs no MCP adapter. It also no longer switches off the adapter's default server, which is where WooCommerce's own MCP tools live from 11.3. Its tools are marked `mcp.public => false`, so they never appear on that server.

From 0.5.0, `GET /wp-json/briefing-for-woocommerce/tools`, behind the same key, lists the tools with their descriptions and input schemas (`{ "version": …, "tools": [ { "name": "briefing-totals", "label": …, "description": …, "input_schema": … } ] }`). The app hands these to the AI for follow-up questions, so a tool added or changed here reaches them with a plugin release and no app release.

## What it can read

Twelve tools, and nothing else:

| Tool | What it answers |
|---|---|
| `briefing/totals` | Revenue, orders (with statuses), customers (new and returning), customer value, tax, refunds, customer concentration (share of revenue from the top 10 customers and the top fifth), fulfilment time (payment to completion: median, p90, buckets) and margin (from cost of goods, with how much of revenue has costs) — for a period against the one before |
| `briefing/breakdown` | One subject by one dimension: revenue by category, country, state, shipping country, payment or shipping method; attribution by channel, source, campaign or device; products by product or variation; refunds by product, country, or new and returning customers; tax by rate; coupons; customers by country (new and returning); first-time customer cohorts by first channel, coupon or product (repeat rate, average lifetime spend); products bought together; fulfilment time by shipping method |
| `briefing/series` | Customers or top products by day, week or month |
| `briefing/store-profile` | Store name, currency, time zone, country, and whether order tables (HPOS) are on |
| `briefing/stock-at-risk` | Products that sold in the period and are now out of stock, on backorder, or nearly out |
| `briefing/rows` | Filtered questions ("orders over £100 from Germany with a coupon", "products in Men and what each sold"): counts, sums and shares for everything that matched, or a list of the top matches (at most 50) — orders, products, or customers as `Customer #N`. At most 366 days, starting no more than three years ago and ending no later than today |
| `briefing/catalog` | The category tree (slugs and product counts), attribute terms and product types |
| `briefing/search-products` | Products by name, description or category, with completeness scores |
| `briefing/product-details` | One product: description, attributes, images, categories, related products, completeness |
| `briefing/readiness-score` | How ready the store is for AI shopping assistants, 0–100, by factor |
| `briefing/recommendations` | The changes that would raise the readiness score most |
| `briefing/suggest-improvements` | Specific improvements for one product or the whole store |

**Never a name, email, phone number or address.** The analytics tools wrap the shared `wc-analytics/*` implementations in `php-packages/commerce-abilities`, with narrower inputs, and remove anything that names or contacts a person on the way out (such as the top-customer list in customer value). Order rows carry the order number, date, status, totals, and the customer as `Customer #N` or `Guest`, with links to the store's admin; customer rows carry `Customer #N`, country, state and their order history (the shared engine's city and postcode are removed). The rows tool refuses filters on customer IDs, cities and postcodes, and filters orders and customers only on the fields it lists. The catalogue tools wrap the same store knowledge and readiness scoring as WooCommerce for Claude and Hey Woo, under their own registry (`briefing-for-woocommerce`). Up to 0.4.0, orders and customers came back as counts and sums only, with sums withheld for groups under three; 0.5.0 drops both, so questions about which orders or customers can be answered.

## How it differs from WooCommerce for Claude

WooCommerce for Claude is a general connector for Claude: tools, resources and prompts, an OAuth server and setup screens. Briefing carries only the read path: the same analytics and catalogue tools, without the prompts, resources or set-up. The two can be active together; they share the commerce-abilities package (each loads only classes the other hasn't). Briefing doesn't use MCP at all.

## Access

- `read` and `read_write` WooCommerce REST API keys authenticate, sent as Basic auth; `write`-only keys don't. A refused key gets WordPress's `rest_forbidden`, which the app reads as a revoked key. Each tool also requires `manage_woocommerce`.
- The route runs only the tools above, at most 20 calls a request.
- Deactivating the plugin revokes every key approved for Briefing (keys WooCommerce described as `Briefing for WooCommerce… - API (…)`).
- No settings, no admin screens, no background work, nothing stored.

## Updates

The plugin updates itself like one from WordPress.org. Its `Update URI` header (`https://briefing.woocommerce.com/`) sends WordPress's update check to `Updater`, which asks `https://briefing.woocommerce.com/api/plugin/update` for the latest release (cached six hours; a failed check, one hour). WordPress compares versions and shows the update; auto-updates are switched on at activation and the merchant can turn them off on the Plugins screen. Only this repo's release assets are accepted as the package (`…/releases/download/briefing-for-woocommerce-v<version>/briefing-for-woocommerce.zip`), whatever the site says.

### Releasing

1. Bump the version in the plugin header, `BRIEFING_FOR_WOOCOMMERCE_VERSION` and `package.json` on a PR, and merge it.
2. Run the **Release Briefing for WooCommerce** workflow with that version. It builds the zip and publishes the `briefing-for-woocommerce-v<version>` release.
3. Bump `PLUGIN_VERSION` in the Briefing app's `shared/plugin.ts` and publish the app. Installed stores pick the update up within about twelve hours (WordPress's own check interval), sooner from the Plugins screen.

## Development

Lives in the woocommerce-claude monorepo next to WooCommerce for Claude and Hey Woo. wp-env mounts it and activates it; its tests are in `plugins/woocommerce-for-claude/tests/integration/test-briefing-for-woocommerce.php` (the integration bootstrap loads both plugins). Build the release zip from the repo root:

```bash
pnpm run briefing-plugin-zip
```

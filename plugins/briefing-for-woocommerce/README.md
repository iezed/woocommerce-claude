# Briefing for WooCommerce

Lets [Briefing for WooCommerce](https://briefing.woocommerce.com/) read a store's weekly totals so it can write the merchant a short briefing every Monday.

The merchant connects from the Briefing site and approves a **read-only** key on WooCommerce's own approval screen. Briefing then reads through this plugin's MCP endpoint at `/wp-json/briefing-for-woocommerce/mcp`.

## What it can read

Six tools, and nothing else:

| Tool | What it answers |
|---|---|
| `briefing/totals` | Revenue, orders (with statuses), customers (new and returning), customer value, tax, refunds, customer concentration (share of revenue from the top 10 customers and the top fifth), fulfilment time (payment to completion: median, p90, buckets) and margin (from cost of goods, with how much of revenue has costs) — for a period against the one before |
| `briefing/breakdown` | One subject by one dimension: revenue by category, country, state, shipping country, payment or shipping method; attribution by channel, source, campaign or device; products by product or variation; refunds by product, country, or new and returning customers; tax by rate; coupons; customers by country (new and returning); first-time customer cohorts by first channel, coupon or product (repeat rate, average lifetime spend); products bought together; fulfilment time by shipping method |
| `briefing/series` | Customers or top products by day, week or month |
| `briefing/store-profile` | Store name, currency, time zone, country, and whether order tables (HPOS) are on |
| `briefing/stock-at-risk` | Products that sold in the period and are now out of stock, on backorder, or nearly out |
| `briefing/rows` | Filtered questions ("orders over £100 from Germany with a coupon"): counts and sums for orders and customers, or a short list of products (at most 25). At most 366 days, starting no more than three years ago and ending no later than today |

**Totals, never people.** The analytics tools wrap the shared `wc-analytics/*` implementations in `php-packages/commerce-abilities`, with narrower inputs, and remove anything about an individual on the way out (such as the pseudonymised top-customer list in customer value). Where a total needs to tell customers apart — concentration, cohorts — that happens inside the database query (guests by billing email) and only the totals come out. The rows tool lists products only: for orders and customers it returns counts and sums, never a list, and when a filter matches fewer than three it returns the count alone, so a narrow filter can't read back one customer's spend or one order's total. It also refuses filters on customer IDs, cities and postcodes. No order, customer, name or email leaves the store.

## How it differs from WooCommerce for Claude

WooCommerce for Claude is a general connector for Claude: about a dozen tools, resources and prompts, an OAuth server and setup screens. Briefing needs a fraction of that, so this plugin carries only the read path. The two can be active together; they share the commerce-abilities package (each loads only classes the other hasn't) and run separate MCP servers.

## Access

- `read` and `read_write` WooCommerce REST API keys authenticate; `write`-only keys don't. Each tool also requires `manage_woocommerce`.
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

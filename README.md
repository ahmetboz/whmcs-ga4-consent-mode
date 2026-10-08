# GA4 + Google Ads for WHMCS, with Consent Mode v2

A single WHMCS hook that adds Google Analytics 4 (and optionally Google Ads) to the client area the way GDPR expects it: every storage type starts **denied**, a small banner asks once, and the visitor's choice is kept in a first-party cookie. Until consent is given Google receives only cookieless pings, so you still see modelled traffic without setting a single cookie.

Built and used in production by [VPSPioneer](https://vpspioneer.com), a UK hosting company running WHMCS 9.

## What it does

- **Consent Mode v2 defaults**: `ad_storage`, `ad_user_data`, `ad_personalization` and `analytics_storage` are `denied` before gtag loads, with `wait_for_update` so an existing choice is applied in time.
- **Banner**: shown until the visitor accepts or declines; either choice is remembered for a year. Styled by default, or bring your own CSS.
- **Shared consent across subdomains**: set `cookie_domain` to `.example.com` and a choice made on your marketing site (write the same cookie there) is honoured in the client area, and the other way round.
- **GA4 `purchase` event** on the order-complete page: order number as `transaction_id`, order total, the client's currency, and one item per product, domain and addon.
- **Google Ads conversion** (optional): sent on the same page with the real value and currency; the order number de-duplicates repeats; `new_customer` is computed from the client's order history.
- **Lazy loading**: `gtag()` queues into `dataLayer` immediately, the library is fetched after first paint, so nothing lands in the critical rendering path.
- **Fails quietly**: a database hiccup on the thank-you page produces no event, never an error.

## Install

1. Copy `includes/hooks/ga4_consent_mode.php` into your WHMCS `includes/hooks/` directory.
2. Copy `includes/hooks/ga4_consent_mode.config.sample.php` next to it as `ga4_consent_mode.config.php` and set your measurement id:

   ```php
   return [
       'ga_id' => 'G-XXXXXXXXXX',
   ];
   ```

3. Load any client-area page. The banner appears; the GA4 realtime report shows the hit (cookieless until you click Accept).

No template edits, no Smarty changes, no database tables. Removing the file removes everything.

## Settings

All keys are optional except `ga_id`.

| Key | Default | Meaning |
| --- | --- | --- |
| `ga_id` | `''` | GA4 measurement id. The hook does nothing while this is empty. |
| `ads_id` | `''` | Google Ads tag id (`AW-…`). Configured on every page so the click id is kept. |
| `ads_conversion_label` | `''` | Label of your purchase conversion action. With `ads_id`, sends the conversion on the order-complete page. |
| `ads_new_customer` | `true` | Add `new_customer: true/false` to the conversion (first order of the client or not). |
| `cookie_name` | `ga4cm_consent` | Name of the consent cookie. |
| `cookie_domain` | `''` | Cookie domain, e.g. `.example.com` to share the choice with other subdomains. Empty = current host. |
| `cookie_days` | `365` | How long the choice is remembered. |
| `purchase_event` | `true` | Send the GA4 `purchase` event on the order-complete page. |
| `banner` | `true` | Show the built-in banner. Set `false` if another consent tool writes the same cookie. |
| `banner_text`, `banner_accept`, `banner_decline` | English defaults | Texts. |
| `banner_policy_url`, `banner_policy_label` | `''`, `Privacy policy` | Link shown after the banner text. |
| `banner_css` | `true` | Inline default styling. Set `false` and style `#ga4cm-banner` in your theme. |

## Sharing consent with your main website

The hook only reads and writes a cookie, so any site on the same registrable domain can take part. On your marketing site, run the same Consent Mode defaults and, when the visitor accepts, write:

```js
document.cookie = 'ga4cm_consent=granted; Max-Age=31536000; Path=/; Domain=.example.com; SameSite=Lax; Secure';
```

With `cookie_domain` set to `.example.com` in the hook, the client area sees the choice and skips the banner.

## Verifying

- **Before consent**: no `_ga` or `_gcl_*` cookies on the client-area domain; GA4 DebugView shows hits with consent `denied`.
- **After Accept**: `_ga` appears and the same session continues with `analytics_storage: granted`.
- **Order complete**: view the page source of `cart.php?a=complete` and look for `gtag('event', 'purchase', …)` (and `'conversion'` when Ads is configured).

## Compatibility

Tested on WHMCS 9.0 with PHP 8.3 and the Twenty-One and Nexus-based themes. Uses only `ClientAreaHeadOutput` and `ClientAreaFooterOutput`, which every WHMCS 8/9 theme renders, and the WHMCS database layer (`Capsule`) for the purchase event.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE](LICENSE). Google Analytics and Google Ads are trademarks of Google LLC; WHMCS is a trademark of WHMCS Ltd. This project is not affiliated with either.

<?php
/**
 * GA4 + Google Ads for the WHMCS client area, under Google Consent Mode v2.
 *
 * - gtag runs with every storage type DENIED until the visitor accepts the
 *   banner, so Google receives only cookieless pings before consent.
 * - The choice is kept in a first-party cookie (default: ga4cm_consent, one
 *   year) and can be shared across subdomains, e.g. between a marketing site
 *   and the client area, by setting 'cookie_domain'.
 * - On the checkout "complete" page a GA4 `purchase` event carries the order
 *   number, total, currency and items; optionally a Google Ads conversion is
 *   sent once per order (de-duplicated by the order number).
 *
 * Install: copy this file to <whmcs>/includes/hooks/ and copy
 * ga4_consent_mode.config.sample.php next to it as ga4_consent_mode.config.php,
 * then fill in your measurement id. Nothing else to configure.
 *
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/** Settings: defaults merged with includes/hooks/ga4_consent_mode.config.php when present. */
function ga4cm_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $defaults = [
        'ga_id' => '',                 // G-XXXXXXXXXX (required; the hook does nothing while empty)
        'ads_id' => '',                // AW-XXXXXXXXX (optional; the Ads tag is configured on every page)
        'ads_conversion_label' => '',  // label of the "purchase" conversion action (optional)
        'ads_new_customer' => true,    // send new_customer with the conversion
        'cookie_name' => 'ga4cm_consent',
        'cookie_domain' => '',         // e.g. .example.com to share the choice with other subdomains; '' = current host
        'cookie_days' => 365,
        'purchase_event' => true,      // GA4 purchase event on the order complete page
        'banner' => true,              // show the built-in banner until a choice is stored
        'banner_text' => 'We use analytics cookies to see which pages help people and which do not. Nothing is set until you say so.',
        'banner_policy_url' => '',     // link shown after the text; '' hides it
        'banner_policy_label' => 'Privacy policy',
        'banner_accept' => 'Accept',
        'banner_decline' => 'Decline',
        'banner_css' => true,          // inline default styling; false if your theme styles #ga4cm-banner itself
    ];
    $file = __DIR__ . '/ga4_consent_mode.config.php';
    $custom = is_file($file) ? (array) (include $file) : [];

    return $config = array_merge($defaults, $custom);
}

/** The gtag snippet for the <head>: consent defaults, stored choice, config, purchase event. */
add_hook('ClientAreaHeadOutput', 1, function (array $vars): string {
    $c = ga4cm_config();
    if ($c['ga_id'] === '') {
        return '';
    }
    $events = '';
    if ($c['purchase_event'] && ($vars['filename'] ?? '') === 'cart' && ($vars['templatefile'] ?? '') === 'complete' && !empty($vars['orderid'])) {
        $events = ga4cm_purchase_events((int) $vars['orderid'], $c);
    }
    $gaId = json_encode($c['ga_id']);
    $adsConfig = $c['ads_id'] !== '' ? "gtag('config', " . json_encode($c['ads_id']) . ");\n" : '';
    $cookie = json_encode($c['cookie_name']);

    return <<<HTML
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  (function () {
    // gtag() queues into dataLayer at once; the library that drains the queue is
    // fetched after first paint so it never sits in the critical path.
    function load() {
      var s = document.createElement('script'); s.async = true;
      s.src = 'https://www.googletagmanager.com/gtag/js?id=' + {$gaId};
      document.head.appendChild(s);
    }
    if (document.readyState === 'complete') load();
    else window.addEventListener('load', function () { setTimeout(load, 0); });
  })();
  gtag('consent', 'default', {
    ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied',
    analytics_storage: 'denied', wait_for_update: 500
  });
  try {
    var ga4cmMatch = document.cookie.match(new RegExp('(?:^|; )' + {$cookie} + '=(granted|denied)'));
    if (ga4cmMatch && ga4cmMatch[1] === 'granted') {
      gtag('consent', 'update', { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted', analytics_storage: 'granted' });
    }
  } catch (e) {}
  gtag('js', new Date());
  gtag('config', {$gaId});
  {$adsConfig}{$events}
</script>
HTML;
});

/** GA4 purchase (and optionally the Ads conversion) for one order, as gtag() calls. */
function ga4cm_purchase_events(int $orderId, array $c): string
{
    try {
        $order = Capsule::table('tblorders')->where('id', $orderId)->first();
        if (!$order) {
            return '';
        }
        // tblorders has no currency column; it is the client's currency.
        $currencyId = (int) Capsule::table('tblclients')->where('id', (int) $order->userid)->value('currency');
        $currency = Capsule::table('tblcurrencies')->where('id', $currencyId)->value('code')
            ?: (Capsule::table('tblcurrencies')->where('default', 1)->value('code') ?: 'USD');

        $items = [];
        foreach (Capsule::table('tblhosting')->where('orderid', $order->id)->get(['packageid', 'amount', 'billingcycle']) as $h) {
            $name = Capsule::table('tblproducts')->where('id', $h->packageid)->value('name') ?: 'Product ' . $h->packageid;
            $items[] = ['item_id' => 'p' . $h->packageid, 'item_name' => $name, 'item_variant' => $h->billingcycle, 'price' => (float) $h->amount, 'quantity' => 1];
        }
        foreach (Capsule::table('tbldomains')->where('orderid', $order->id)->get(['domain', 'type', 'firstpaymentamount']) as $d) {
            $items[] = ['item_id' => 'domain', 'item_name' => 'Domain ' . $d->type, 'price' => (float) $d->firstpaymentamount, 'quantity' => 1];
        }
        foreach (Capsule::table('tblhostingaddons')->where('orderid', $order->id)->get(['addonid', 'setupfee', 'recurring']) as $a) {
            $name = Capsule::table('tbladdons')->where('id', $a->addonid)->value('name') ?: 'Addon ' . $a->addonid;
            $items[] = ['item_id' => 'a' . $a->addonid, 'item_name' => $name, 'price' => (float) $a->recurring + (float) $a->setupfee, 'quantity' => 1];
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $js = "gtag('event', 'purchase', " . json_encode([
            'transaction_id' => (string) $order->ordernum,
            'value' => (float) $order->amount,
            'currency' => $currency,
            'items' => $items,
        ], $flags) . ");\n";

        if ($c['ads_id'] !== '' && $c['ads_conversion_label'] !== '') {
            $conversion = [
                'send_to' => $c['ads_id'] . '/' . $c['ads_conversion_label'],
                'value' => (float) $order->amount,
                'currency' => $currency,
                'transaction_id' => (string) $order->ordernum, // Google Ads drops repeats of the same id
            ];
            if ($c['ads_new_customer']) {
                $conversion['new_customer'] = !Capsule::table('tblorders')->where('userid', (int) $order->userid)->where('id', '<', (int) $order->id)->exists();
            }
            $js .= "  gtag('event', 'conversion', " . json_encode($conversion, $flags) . ");\n";
        }

        return $js;
    } catch (\Throwable $e) {
        // Analytics must never break the thank-you page.
        return '';
    }
}

/** The banner, only until a choice is stored. Writes the cookie and updates gtag. */
add_hook('ClientAreaFooterOutput', 1, function (array $vars): string {
    $c = ga4cm_config();
    if ($c['ga_id'] === '' || !$c['banner']) {
        return '';
    }
    $e = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $policy = $c['banner_policy_url'] !== ''
        ? ' <a href="' . $e($c['banner_policy_url']) . '">' . $e($c['banner_policy_label']) . '</a>'
        : '';
    $cookie = json_encode($c['cookie_name']);
    $domain = json_encode($c['cookie_domain']);
    $maxAge = (int) $c['cookie_days'] * 86400;
    $css = $c['banner_css'] ? <<<CSS
<style>
  #ga4cm-banner { position: fixed; left: 16px; right: 16px; bottom: 16px; z-index: 9998; max-width: 560px; margin: 0 auto; background: #111; color: #fff; padding: 16px 18px; display: flex; align-items: center; gap: 16px; font-size: 14px; line-height: 1.5; border-radius: 6px; }
  #ga4cm-banner p { margin: 0; flex: 1 1 auto; color: rgba(255,255,255,.9); }
  #ga4cm-banner a { color: #fff; text-decoration: underline; }
  #ga4cm-banner .ga4cm-actions { display: flex; gap: 8px; flex: 0 0 auto; }
  #ga4cm-banner button { font: inherit; font-size: 13px; padding: 7px 12px; cursor: pointer; border-radius: 4px; border: 1px solid rgba(255,255,255,.5); background: transparent; color: #fff; }
  #ga4cm-banner button[data-consent="granted"] { background: #fff; color: #111; border-color: #fff; }
  @media (max-width: 640px) { #ga4cm-banner { flex-direction: column; align-items: stretch; } #ga4cm-banner .ga4cm-actions { justify-content: flex-end; } }
</style>
CSS : '';

    return $css . <<<HTML
<div id="ga4cm-banner" hidden role="dialog" aria-live="polite" aria-label="Cookie preferences">
  <p>{$e($c['banner_text'])}{$policy}</p>
  <div class="ga4cm-actions">
    <button type="button" data-consent="denied">{$e($c['banner_decline'])}</button>
    <button type="button" data-consent="granted">{$e($c['banner_accept'])}</button>
  </div>
</div>
<script>
  (function () {
    var box = document.getElementById('ga4cm-banner'); if (!box) return;
    var name = {$cookie}, domain = {$domain};
    var stored = null;
    try { var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=(granted|denied)')); stored = m ? m[1] : null; } catch (e) {}
    if (stored) return;
    box.hidden = false;
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-consent]'); if (!b) return;
      var v = b.getAttribute('data-consent');
      var cookie = name + '=' + v + '; Max-Age={$maxAge}; Path=/; SameSite=Lax';
      if (domain) cookie += '; Domain=' + domain;
      if (location.protocol === 'https:') cookie += '; Secure';
      try { document.cookie = cookie; } catch (e) {}
      if (window.gtag) gtag('consent', 'update', { ad_storage: v, ad_user_data: v, ad_personalization: v, analytics_storage: v });
      box.hidden = true;
    });
  })();
</script>
HTML;
});

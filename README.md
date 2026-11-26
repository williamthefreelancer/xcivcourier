# XCIV Courier

Block theme for [xcivcourier.com](https://xcivcourier.com). Marketing layouts via the **Site Editor + Kadence Blocks (free)**. The only custom-designed piece is the **Booking Form** block.

## Local (XAMPP)

WordPress runs at:

`http://localhost/xciv-courier/`

Install path: `C:\Users\johns\xampp\htdocs\xciv-courier`  
Theme is a junction to this repo: `wp-content/themes/xciv-courier`

1. Create DB `xciv_courier` (installer or MySQL).
2. Open the URL above and finish the WordPress install.
3. Activate **XCIV Courier** (Appearance → Themes).
4. Activate **Kadence Blocks** (Plugins).
5. Build pages with Kadence in the block editor.
6. Activate **WooCommerce** (installed via WP-CLI). Checkout pages are created automatically.
7. Activate **WooPayments** (`woocommerce-payments`, installed via WP-CLI). Then finish account setup:
   - WP Admin → **Payments** → **Complete setup**
   - Connect WordPress.com with **`william@thesuperninetyfoureventscompany.com`**
   - Finish Stripe business / payout details in the onboarding popup (`connect.stripe.com`)
   - Local is HTTP — use a **test account** first; live payouts need HTTPS on production
8. **Memberships (Courier One / Purple):** low-cost monthly or yearly upsells on the logged-in My Account dashboard.
   - Plans: **XCIV Courier One** `$4.99/mo` or `$39/yr` · **XCIV Courier Purple** `$9.99/mo` or `$79/yr`
   - Stripe keys: **Settings → XCIV Stripe** (or `XCIV_STRIPE_*` constants)
   - Webhook URL: `/wp-json/xciv/v1/stripe/webhook` (events: `checkout.session.completed`, `payment_intent.succeeded`, `charge.refunded`)
   - Checkout REST: `POST /wp-json/xciv/v1/stripe/checkout` `{ "plan": "courier-one-monthly" }`
   - Or use **Continue to checkout** (vanilla.js → Woo cart → existing checkout steps)
   - Shortcode anywhere: `[xciv_membership_plans]`
   - WC Cron / Action Scheduler: `xciv_membership_daily_sweep` (+ per-user expire/remind)
9. For booking: open the site popup (**Book a line sitter** / `#book`), assign template **Booking**, or insert the **Booking Form** block. The form is a **6-step wizard**; submit sends the booking to WooCommerce checkout.
10. For the live service catalog: create a page, assign template **Service Listings** (or insert shortcode `[xciv_service_listings]`). Clients can search/filter listings; selecting one tracks **William A. Johnson** on the lead rider map with a live ETA for delivery or queue swap.
11. SMS alerts via [SMS Alert](https://www.smsalert.co.in/): activate the **SMS Alert** plugin (`sms-alert`), log in under **WooCommerce → SMS Alert**, then set admin phone / booking toggles at **Settings → XCIV SMS**. Optional constants: `XCIV_SMSALERT_API_KEY`, `XCIV_SMSALERT_SENDER`, `XCIV_SMS_ADMIN_PHONE`. Admin gets a text on new bookings; customers who buy **Live SMS updates** get a confirmation.

## Theme layout (block theme)

| Path | Role |
|------|------|
| `theme.json` | Global styles & settings (FSE) |
| `templates/` | HTML block templates |
| `parts/` | Header / footer template parts |
| `blocks/booking-form/` | **Only custom section** — design here |

Customize the booking UI in:

- `blocks/booking-form/render.php`
- `blocks/booking-form/style.css`
- `blocks/booking-form/view.js`
- `blocks/booking-form/edit.js`

## Deploy (DreamHost)

Push to `main` → [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml) SFTP-deploys the theme and **XCIV Courier Core** plugin.

Secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_REMOTE_PATH`.

Install **Kadence Blocks**, **WooCommerce**, and **WooPayments** on production once (not deployed by this workflow). Activate **XCIV Courier Core** after first deploy (`Plugins → XCIV Courier Core`). Complete WooPayments signup under **Payments → Complete setup** with WordPress.com login **`william@thesuperninetyfoureventscompany.com`**. Optional: `define( 'XCIV_MAPBOX_TOKEN', 'pk.…' );` in `wp-config.php`, or set the token under **XCIV Courier → Map**. Shortcode: `[xciv_lead_rider_map]`.

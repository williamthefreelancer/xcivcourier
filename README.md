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
7. For booking: open the site popup (**Book a line sitter** / `#book`), assign template **Booking**, or insert the **Booking Form** block. The form is a **6-step wizard**; submit sends the booking to WooCommerce checkout.
8. SMS alerts via [SMS Alert](https://www.smsalert.co.in/): activate the **SMS Alert** plugin (`sms-alert`), log in under **WooCommerce → SMS Alert**, then set admin phone / booking toggles at **Settings → XCIV SMS**. Optional constants: `XCIV_SMSALERT_API_KEY`, `XCIV_SMSALERT_SENDER`, `XCIV_SMS_ADMIN_PHONE`. Admin gets a text on new bookings; customers who buy **Live SMS updates** get a confirmation.

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

Push theme changes to `main` → [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml) SFTP-deploys the theme.

Secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_REMOTE_PATH`.

Install **Kadence Blocks** on production once (not deployed by this workflow).

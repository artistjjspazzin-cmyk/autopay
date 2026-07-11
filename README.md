# Autopay

Private source backup for the production payment dashboard at `https://autopay.builtbyjj.dev/`.

## Current status

As of July 10, 2026, the production app includes:

- One-time card charges through Squire, with Stripe used for card tokenization.
- Recurring autopay and scheduled future charges.
- Reusable multi-product payment links that remain active after checkout.
- Customer, transaction, deposit, dispute, session, activity-log, and business-loan views.
- Customer add/edit/delete flows and field-level audit logging.
- Upcoming-charge reporting and automated cron processing.
- US proxy routing for Stripe tokenization and Squire API traffic.
- Duplicate-charge protection, invalid-amount checks, missing-card checks, and retry cooldowns.
- Phone-number formatting as `(555) 123-4567` throughout the UI.

The live payment link created during the current work is:

`https://autopay.builtbyjj.dev/?pay=f835e558e116d670`

Its products currently display as `$30/month`, `$35/month`, and `$80/3 months`.

## Important accounting context

Historical transaction data was partially lost before this repository was created. Some restored approved transactions have `$0` amounts, so raw transaction totals do not represent all historical processing.

The dashboard currently handles that gap as follows:

- Net Balance uses a historical baseline of `$2,693.63` and then applies new revenue, deposits, and fees.
- Business Loan processed volume uses total deposits plus approved revenue recorded after the baseline.
- Live deposits totaled `$6,566.45` after the July 10, 2026 deposit.

The production files in `data/` remain the source of truth for current financial totals. They are intentionally excluded from Git.

## Architecture

- `index.php` — login, dashboard UI, public payment links, charges, customers, autopay, deposits, and reporting.
- `api.php` — authenticated API endpoints for external applications and webhooks.
- `cron_autopay.php` — recurring and scheduled-charge processor.
- `squire_proxy.py` — local Cloudscraper HTTP proxy for Squire API requests.
- `squire-proxy.service` — systemd service definition for the local proxy.
- `data/` — runtime JSON databases, tokens, credentials, sessions, logs, and encrypted cards. Runtime contents are not committed.

## Requirements

- PHP 8.3 with cURL and OpenSSL extensions.
- Python 3 with the dependency in `requirements.txt`.
- Nginx and PHP-FPM for the current production deployment.
- A writable `data/` directory owned by the web-server user.

Install the Python dependency:

```bash
python3 -m pip install -r requirements.txt
```

Basic syntax checks:

```bash
php -l index.php
php -l api.php
php -l cron_autopay.php
python3 -m py_compile squire_proxy.py
```

## Configuration

Copy `.env.example` values into the process environment. The application reads these variables directly; it does not automatically load a `.env` file.

Required production values:

- `ADMIN_USER`
- `ADMIN_PASSWORD`
- `SQUIRE_SHOP_ID`
- `STRIPE_PUBLISHABLE_KEY`
- `US_PROXY_URL`
- `AUTOPAY_API_KEYS_JSON` when `api.php` is enabled

Optional values and defaults are documented in `.env.example`.

Never commit real passwords, API keys, proxy credentials, Squire tokens, saved cards, transaction records, sessions, or webhook secrets.

## Runtime data

Production uses JSON files under `/var/www/html/autopay/data/`, including:

- `transactions.json`
- `autopay.json`
- `saved_cards.json`
- `deposits.json`
- `payment_links.json`
- `audit_log.json`
- `sessions.json`
- `webhooks.json`
- `squire_creds.json`
- `squire_token.txt`

These files can contain financial information, personal information, card data, credentials, and active sessions. Back them up only to encrypted private storage, never GitHub.

## Production operations

Current production path: `/var/www/html/autopay`

The proxy listens on `127.0.0.1:9876` by default. Install and start the systemd unit after placing the app at the production path:

```bash
sudo cp squire-proxy.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now squire-proxy
```

The cron processor is intended to run every five minutes:

```cron
*/5 * * * * php /var/www/html/autopay/cron_autopay.php >> /var/log/autopay.log 2>&1
```

## Next-developer notes

1. Do not replace production `data/` files when deploying source updates.
2. Back up `index.php`, `api.php`, `cron_autopay.php`, and the runtime data before production changes.
3. Validate PHP syntax before each deployment.
4. Keep payment links reusable; successful checkout records the last use but should not mark a link expired.
5. Do not calculate historical processing solely from `transactions.json` because restored records contain missing amounts.
6. Real issuer declines still occur. Recent analysis showed MasterCard approval below Visa; most valid-card failures were issuer risk holds or insufficient funds, not proxy failures.
7. Test navigation after UI edits. A PHP fatal error before JavaScript loads can make all dashboard tabs appear blank or unclickable.
8. Keep POST handlers above the `Unknown action` fallthrough. Misplaced handlers previously broke payment-link and deposit actions.

## Security

This repository contains sanitized source only. Production credentials were replaced with environment variables, and all live customer/payment data is excluded by `.gitignore`.

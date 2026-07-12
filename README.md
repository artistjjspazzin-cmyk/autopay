# Autopay

Sanitized source for the production payment dashboard at `https://autopay.builtbyjj.dev/`.

## Current status

As of July 11, 2026, the production app includes:

- One-time card charges through Squire, with Stripe used for card tokenization.
- Recurring autopay and scheduled future charges.
- Reusable multi-product payment links that remain active after checkout.
- Customer, transaction, deposit, dispute, session, activity-log, and business-loan views.
- Customer add/edit/delete flows and field-level audit logging.
- Upcoming-charge reporting and automated cron processing.
- US proxy routing for Stripe tokenization and Squire API traffic.
- Invalid-amount checks, missing-card checks, and retry cooldowns for automated charges.
- Phone-number formatting as `(555) 123-4567` throughout the UI.
- MySQL-backed persistent storage for all application documents.

The production payment link created during the current work is:

`https://autopay.builtbyjj.dev/?pay=f835e558e116d670`

Its products currently display as `$30/month`, `$35/month`, and `$80/3 months`.

## Persistence

MySQL is the application source of truth. Existing runtime structures are preserved as JSON documents in the `app_documents` table, which avoids changing payment and accounting behavior during the migration.

The original files under `/var/www/html/autopay/data/` were retained and were not deleted or overwritten. The application no longer reads or writes those files during normal operation. Keep them as migration evidence and an emergency rollback snapshot until a newer verified database backup exists.

Database-backed documents include:

- Transactions, autopay subscriptions, saved cards, deposits, and disputes.
- Sessions, audit logs, payment links, and webhooks.
- Squire credentials and token state.
- Cloudflare cookie state and the cron round-robin marker.
- The Stripe secret text document when that legacy file exists.

The migration records a canonical SHA-256 hash and item count for every imported document. `migrate_to_database.php` reads each stored document back and verifies the hash before succeeding.

## Important accounting context

Historical transaction data was partially lost before this repository was created. The restored zero-dollar transaction records were removed from the live database on July 12, 2026 after a protected full database backup and separate export. Current transaction totals still do not represent all historical processing because the lost historical amounts cannot be reconstructed.

The dashboard currently handles that gap as follows:

- Net Balance uses a historical baseline of `$2,693.63` and then applies new revenue, deposits, and fees.
- Business Loan processed volume uses total deposits plus approved revenue recorded after the baseline.
- Deposits totaled `$6,566.45` after the July 10, 2026 deposit.

Do not calculate historical processing solely from the transaction document.

## Architecture

- `index.php` — login, dashboard UI, public payment links, charges, customers, autopay, deposits, and reporting.
- `api.php` — authenticated API endpoints for external applications and webhooks.
- `cron_autopay.php` — recurring and scheduled-charge processor.
- `storage.php` — shared PDO connection and database document read/write helpers.
- `database_schema.sql` — MySQL schema reference for `app_documents`.
- `migrate_to_database.php` — non-destructive JSON/text import and hash verification tool.
- `verify_database.php` — safe count and financial-total verification without printing customer or credential data.
- `squire_proxy.py` — stateless local Cloudscraper HTTP proxy for Squire API requests.
- `squire-proxy.service` — systemd service definition for the local proxy.
- `data/` — retained legacy migration files; runtime contents are excluded from Git.

## Requirements

- PHP 8.3 with PDO MySQL, cURL, and OpenSSL extensions.
- MySQL 8.
- Python 3 with the dependency in `requirements.txt`.
- Nginx and PHP-FPM for the current production deployment.

Install the Python dependency:

```bash
python3 -m pip install -r requirements.txt
```

## Configuration

The PHP storage layer uses environment variables when provided. Otherwise it loads `/etc/autopay/database.env`, or the file selected by `AUTOPAY_DB_CONFIG`.

Required database variables:

```ini
AUTOPAY_DB_HOST=127.0.0.1
AUTOPAY_DB_PORT=3306
AUTOPAY_DB_NAME=autopay
AUTOPAY_DB_USER=autopay_app
AUTOPAY_DB_PASSWORD=replace-with-a-strong-database-password
```

Protect the production configuration from other users:

```bash
sudo install -d -m 0750 -o root -g www-data /etc/autopay
sudo chown root:www-data /etc/autopay/database.env
sudo chmod 0640 /etc/autopay/database.env
```

Other application settings are documented in `.env.example`. The application reads environment variables directly and does not automatically load a project `.env` file.

Never commit real passwords, database credentials, API keys, proxy credentials, Squire tokens, saved cards, transaction records, sessions, or webhook secrets.

## Initial database setup and migration

Create an empty database and least-privilege application user. Replace the placeholders before running these statements:

```sql
CREATE DATABASE autopay CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE USER 'autopay_app'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-strong-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX
    ON autopay.* TO 'autopay_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Back up the application and legacy files before importing. Then run:

```bash
php migrate_to_database.php --data-dir=/var/www/html/autopay/data
php migrate_to_database.php --data-dir=/var/www/html/autopay/data --verify-only
php verify_database.php
```

The migration:

1. Creates the table when needed.
2. Imports every present known JSON/text document.
3. Leaves missing optional files alone.
4. Never deletes or modifies a source file.
5. Reads the stored value back and verifies its canonical SHA-256 hash.
6. Refuses to overwrite different database content unless `--force` is explicitly supplied.

Use `--force` only during a controlled final cutover after confirming the legacy files are the newest source. Do not run it after production has begun writing to MySQL, because retained JSON files then become stale.

## Verification

Run syntax checks:

```bash
php -l index.php
php -l api.php
php -l cron_autopay.php
php -l storage.php
php -l migrate_to_database.php
php -l verify_database.php
python3 -m py_compile squire_proxy.py
```

`verify_database.php` prints only document counts and aggregate financial totals. It does not print customer, card, credential, token, session, or webhook contents.

A safe write-path check is to authenticate with a fresh browser session and confirm the database `sessions.json` item count or update timestamp changes while the retained legacy `data/sessions.json` hash stays unchanged.

## Backups and rollback

Before each deployment:

```bash
sudo tar -czf /secure/path/autopay-files.tar.gz /var/www/html/autopay
sudo mysqldump --single-transaction autopay | gzip > /secure/path/autopay-database.sql.gz
```

Store backups in encrypted private storage. To roll back the database migration:

1. Stop PHP-FPM and the autopay cron to prevent concurrent writes.
2. Back up the current MySQL database before changing anything.
3. Restore the pre-cutover PHP files that use the retained JSON files.
4. Verify the retained files and PHP syntax.
5. Restart PHP-FPM and cron.

Do not delete either the database or the retained legacy files during rollback.

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

1. MySQL is now the source of truth. Do not re-import retained JSON files with `--force` after new database writes exist.
2. Do not delete `/var/www/html/autopay/data/`; it is the preserved pre-migration fallback requested by the owner.
3. Back up application files, the legacy data directory, and MySQL before production changes.
4. Keep production-only credentials out of this repository.
5. Keep payment links reusable; successful checkout records the last use but must not expire a link.
6. Preserve the historical accounting baselines and current Business Loan formula unless the owner explicitly requests changes.
7. Real issuer declines still occur. Do not weaken amount, missing-card, or retry-cooldown validation.
8. Test navigation after UI edits. A PHP fatal error before JavaScript loads can make all dashboard tabs appear blank or unclickable.
9. Keep POST handlers above the `Unknown action` fallthrough.
10. Never test cron by running a live due-charge batch unintentionally. Verify reads with `verify_database.php` and use a controlled non-production dataset for charge-path testing.

## Security

This repository contains sanitized source only. Live customer/payment data and production credentials are excluded by `.gitignore`.

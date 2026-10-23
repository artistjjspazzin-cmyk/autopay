# CreditGod Payments

Separate payments app served at `https://autopay.builtbyjj.dev/creditgod`. It shares the
payment gateway credentials with the Autopay terminal but keeps its own records
(`creditgod_transactions.json`, `creditgod_autopay.json`, `creditgod_saved_cards.json`,
`creditgod_webhooks.json`, `creditgod_audit_log.json`), so nothing from CreditGod appears in the
Autopay dashboard, transaction list, deposits, or accounting cards.

## API

Base URL: `https://autopay.builtbyjj.dev/creditgod/api.php`
Auth: `Authorization: Bearer <api key>` (test keys simulate charges, live keys charge real cards).

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `?endpoint=charge` | One-time checkout payment |
| POST | `?endpoint=autopay` | Create a recurring subscription |
| POST | `?endpoint=refund` | Refund a transaction |
| POST | `?endpoint=cancel-autopay` | Cancel a subscription |
| GET | `?endpoint=transactions` | List transactions |
| GET | `?endpoint=subscriptions` | List subscriptions |
| GET | `?endpoint=customer&name=` | Look up a customer |
| POST/GET/DELETE | `?endpoint=webhooks` | Manage webhooks |

### Charge example

```bash
curl -X POST 'https://autopay.builtbyjj.dev/creditgod/api.php?endpoint=charge' \
  -H 'Authorization: Bearer sk_test_cg_...' \
  -H 'Content-Type: application/json' \
  -d '{"amount":49.99,"client_name":"Jane Doe","client_email":"jane@example.com",
       "card_number":"4242424242424242","exp_month":"12","exp_year":"2030","cvc":"123",
       "zip":"90001","description":"CreditGod plan","metadata":{"plan":"pro"}}'
```

In test mode a card ending in `0002` simulates a decline; anything else is approved.

Refunds: send the `transaction_id` returned by `charge`, using the same kind of key that made the charge. Test transactions (`cg_test_...`) are refunded locally with the test key and never reach the processor; live transactions are refunded at the processor with the live key.

## Recurring charges

`cron_creditgod.php` charges subscriptions whose `nextCharge` is due:

```
*/5 * * * * php /var/www/html/autopay/creditgod/cron_creditgod.php >> /var/log/creditgod.log 2>&1
```

## Dashboard

`/creditgod` lists CreditGod transactions, subscriptions, and masked API keys. It reuses the
terminal's admin session, so sign in at `/` first.

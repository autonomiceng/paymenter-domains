# Installed NameSilo TEST runtime

Set `domains.namesilo_account.environment` to `test`, connect a key through the existing reviewed account action,
and turn Orders on. The local enrollment must match the current encrypted credentials. Shared queue/cache/session
requirements still apply. This installs the shared acquisition, reconciliation, renewal and baseline management
ports without a provider call. Orders starts Off; turning it Off preserves readers and reconciliation.

TEST uses only `https://ote.namesilo.com`, named API commands, current credential revisions, and the same
pre-seal reservation controls as the existing admitted NameSilo transport. TEST call/spend counters are separate
from Live. `domains.namesilo_test.daily_call_cap` and `daily_spend_limit_usd` default to 1000 and `1000.00`;
these are local OTE limits, not provider limits. Boolean `catalogue`, `registration`, `transfer`, `renewal` and
`management` fields in that configuration can independently withdraw features (default true in explicit TEST mode).

The retained catalogue imports costs with one `getPrices` per account. Prices do not prove registry requirements,
renewal safety or supported terms. Until independently dated TEST protocol evidence is configured, public sales
stay unavailable. The evidence input is `domains.namesilo_test.accounts[stable_identity]`:

```php
[
    'fingerprint' => '<current binding fingerprint>',
    'credential_revision' => '<current local enrollment revision>',
    'facts' => [
        'com' => [
            'terms' => ['register' => [1], 'renew' => [1], 'transfer' => [1]],
            'registry_fields' => [],
            'renewal_safe' => true,
            'requires_nameservers' => false,
            'auto_renew_only' => false,
            'observed_at' => '<actual observation date in DATE_ATOM format>',
            'fresh_until' => '<evidence expiry in DATE_ATOM format>',
        ],
    ],
]
```

Use actual retained evidence dates, never the price-sync time. A cold facts check validates that evidence and
uses one bounded `getPrices` read to confirm the ending is still listed. It preserves the original observation
and expiry. Unknown, expired, future or nonannual facts refuse. Rotation requires the new local credential
revision; it cannot reuse prior sale-readiness authority. Staff intent, selected seller, current costs, annual
renewal support, native publication and locked checkout checks still decide each sale.

NameSilo's `registerDomain` reply has no order number, so a registration completes only when one matching order
appears in NameSilo's order history. TEST accounts read that history with built-in values measured on OTE: order
dates in UTC, a 120 second listing delay and 30 days of history. Until the delay passes, the request waits for its
next check instead of asking staff to contact NameSilo. Each history read counts toward the limit of 5 reads, even
when the history cannot confirm the order. After the fifth, checks stop and staff are asked to check with NameSilo and
record its answer. A saved history setting replaces these values, and the staff settings review marks the built-in
values as built in. A failed purchase send keeps only its fixed failure kind, such as `http 500`, for staff.

No settings page or Live activation is added. Live deployment, site, controls, receipts and transport policy
retain their existing gates; TEST evidence cannot authorize Live. Offline fixtures do not prove OTE or Live
provider acceptance.

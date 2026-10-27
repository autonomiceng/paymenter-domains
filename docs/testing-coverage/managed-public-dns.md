# Managed public DNS coverage

## Managed public DNS storage

`managed-public-storage-test.php` covers the target union, separate encrypted immutable
facts, no managed watch creation, dormant defaults, retention and missing-history refusal.
`managed-public-upgrade-test.php` upgrades original 000018 enrollment rows in place,
preserving IDs, continuity/profile pointers and awaiting-acceptance state. Partial retained
upgrades refuse repair. These serialized SQLite tests make no DNS or registrar calls.

## Managed public DNS runtime

Synthetic reader tests cover dormant A3 row acceptance in place, do-not-renew holdings,
atomic predecessor relink and continuity-only pointers, after-commit hints and daily repair,
retained expired/deleted/moved-owner facts, full stamp races and exact claim replacement.
Quota tests prove the shared watch/managed sixty-query pool, two-job ceiling and local
saturation without failure. Long-TTL confirmation keeps badge freshness independent;
101-source batches and invalid/conflicting hosting sources retain independent apex reads.
Owner fairness, bounded source rotation, managed-only DTO selection and registrar/financial
isolation are exercised without network. The existing watch observer runs through the same
pool. Mutation checks reject stamp bypass, watch creation and separate quota admission.
These serialized SQLite checks do not establish live resolver or MariaDB concurrency acceptance.

# Domain imports

## Enable imports

The server administrator sets `domains.adoption.enabled` to `true` in the site's
reviewed Laravel deployment configuration. This is not a panel setting. Keep it
`false` until the site has passed the import acceptance checks in
[Runtime](runtime.md) and [Adoption preview](design/adoption-preview.md).

Before enabling it, prove the live registrar account and ordinary renewal price,
managed public DNS, retained-record upgrades, and simultaneous staff and customer
edits against the site's database. Let active customer, order and service edits
finish, then restart web and queue workers with the reviewed configuration.

Give staff the import permissions only after those checks. Checking a domain does
not import it: staff must preview the result and confirm. Import never buys,
transfers or renews a domain, and never changes past invoices.

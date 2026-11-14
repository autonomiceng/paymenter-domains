# Domain imports

Importing links a domain the customer already holds at your registrar account to their
service, so it renews through Paymenter. It never buys, transfers or renews a domain, and
it never changes past invoices.

## Turn on imports

Importing is off by default. There is no panel setting: the server administrator turns it
on in the site configuration.

1. Check that the site passes the import checks in [Runtime](runtime.md) and
   [Adoption preview](design/adoption-preview.md): the live registrar account, the
   renewal price, managed DNS and saved records.
2. Let open customer, order and service edits finish.
3. In the Paymenter site, create `config/domains.php` (or edit it) so it returns
   `['adoption' => ['enabled' => true]]`. The setting is `domains.adoption.enabled`.
4. Reload the configuration (`php artisan config:cache` when the site caches it) and
   restart the web and queue workers.
5. Give the import permissions to the staff roles that should import.

Staff can check a domain while importing is off. To import, they preview the result and
confirm it.

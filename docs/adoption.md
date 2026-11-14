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
3. In the Paymenter site, create `config/domains.php` only if it does not exist.
   Set `'adoption' => ['enabled' => true]` in its returned array, preserving all
   existing entries, including any other adoption settings. The setting is
   `domains.adoption.enabled`.
4. Reload the configuration (`php artisan config:cache` when the site caches it) and
   restart the web and queue workers.
5. Give the staff roles that should import all five permissions: View domains
   (`admin.domains.view`), Preview domain import (`admin.domains.adopt.preview`),
   Import domains (`admin.domains.adopt`), Manage domain operations
   (`admin.domains.manage`), and the service update permission
   (`admin.services.update`).

Staff can check a domain while importing is off. To import, they preview the result and
confirm it.

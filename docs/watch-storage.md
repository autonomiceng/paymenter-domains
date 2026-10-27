# Dormant domain watch storage

Product mappings save one exact primary service-property key, disabled, with a reason and
optimistic revision. Enabling remains refused until the audited enable action exists. Watch view/manage permissions default denied; the existing Domains view
permission is also required. Saving never reads service properties or invokes discovery.

Names are unique per customer and canonical ASCII name, not globally. The PSL revision belongs
to the saved derivation. `service` is the only origin; no manual add or alias list is provided.
Registration and DNS axes use `platform|external|null`; public facts cannot create managed authority.
A linked watch is a retained tombstone. Registration evidence and audit data are encrypted and
hidden from generic serialization. Names, mappings, sources and append-only audit survive down
and uninstall. Profiles are deferred; nullable profile references have no foreign key yet.

Numeric increment/decrement helpers are refused. Normal model writes require an enclosing transaction and exact next revision. Owner/name
identities cannot be reparented. A source has exactly one watch target in `watch`, exactly one
managed target in `managed_link`, and neither in `held|retired`. Targets match the source customer
and canonical name; managed links also require the native service customer and exclude adoption
pending and terminal failures. Only active/grace/expired holdings and pending acquisitions link. Pending acquisition progress links cannot assert platform registration. SQL, quiet and bulk writes remain privileged maintenance bypasses, not customer APIs.

The unique nullable source `service_id` identifies its current native service. Retirement records
that ID in encrypted append-only audit before the transaction commits, releases the unique key,
and retains the old source customer/hostname/name/map. A later customer gets a new source row;
old source/watch history stays with its original customer. Retirement refuses dirty or stale
instances, audits the persisted service and former targets, and freezes all retired source metadata. Live hostname, derived name and mapping identities are frozen; target changes require a future audited
relink primitive or retirement plus a new row. Held rows keep only canonical names and safe reason codes.
No rows are deleted for source removal.

Discovery, cursors, service-event hints, same-customer progress links, profile conflicts and the
staff preview/enable screen are follow-on work. This storage introduces no scheduler, public
lookup, customer surface or activation switch. SQLite model tests do not establish MariaDB races.

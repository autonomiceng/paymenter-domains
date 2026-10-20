# Public notice episodes

The pure WatchNoticePlan consumes normalized current owner-validated public facts and an injected
UTC clock. It has no provider, database, mail or financial authority. Persistence and delivery must
serialize its retained state under owner/target locks. Managed targets may call DNS policy only.

Expiry selects the nearest 90/60/30/14/7/1/0-day window on discovery; later crossings catch up for
at most two days. First expired discovery selects zero once. Fresh extensions resolve the old
condition; earlier corrections retain pending urgency and permit one notice per 24 hours. Keys for
every previously selected date/window remain retained through extensions and corrections. A clock
reversal consumes nothing. Invalid, incomplete, stale or mismatched facts produce portal conditions
and no customer delivery. The persistence layer must deduplicate staff health alerts separately.

DNS consumes the current observer’s ns_confirmed proof: two distinct complete samples under
an unchanged profile, after the earlier TTL or 15 minutes, capped at six hours. It does not
reimplement confirmation timing. Unknown samples neither confirm nor clear. Two matching samples
resolve drift. Restoration is eligible only for channels with prior accepted or uncertain drift
handoff. Profile revisions restart observer confirmation without changing lineage or open episode identity. A/MX facts never enter this email planner.

Delivery identity includes owner, canonical name, profile lineage and episode, independent of
watch/managed target IDs; expiry identity also includes its threshold and delivery its channel.
The relinked() boundary carries retained episode state and channel keys only, never
fresh observation proof. Evidence references always belong to the current target's public table.
Current means the latest matching owner/target observation, not an older still-valid fact.
The delivery layer retains capped pending notices and rechecks truth/freshness the next day;
uncertain handoff counts as delivered for optional restoration. The next boundary owns per-channel committed claims, suppression and first-party owner-safe links.

Pending corrections exist only for an applicable, not-yet-keyed window. Past-date changes after
an expired notice stay portal-only until a fresh future extension begins another episode. Open
conditions survive unknown facts; clock reversal is a staff health signal. Urgent expiry (14 days
or less) precedes drift, followed by longer expiry windows and optional restoration.

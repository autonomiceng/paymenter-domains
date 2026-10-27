# Adoption preview boundary

Adoption remains disabled. Preview evidence is never a holding, payment, dispatch
permission or renewal anchor. NameSilo adoption remains unavailable.

Enom adoption v1 reads only GetDomainInfo, GetDomainExp, GetRenew and GetExtendInfo.
A narrow enum and a transport check enforce that list independently of the general
read vocabulary. One named reader owns a six-request/90-second context, with at
most 25 seconds per exchange and the remaining deadline. It never retries or calls
full readDomain (which also reads privacy and lock). No inventory, order, price,
availability, contact, DNS, email or mutation command is admitted.

The shared Identity and Expiry parsers retain the MIT Enom 444f31c provenance.
Later upstream DNS-read fixes do not change these parsers. Unknown UID/provider ID,
contradictory or unproved expiry, renewal on/unknown, hold/redemption/unknown state,
expired names and expiry beyond ten years refuse. Synthetic fixtures establish local
interpretation only; production provider acceptance remains a separate gate.

Expiry reconciliation reuses the renewal parser: a scalar US date from extend info
may confirm the Pacific calendar day of the exact authenticated timestamp. It never
replaces that timestamp with an invented midnight; contradictory aliases refuse.

## Fresh staff review

`AdoptionReader::preview` requires current view and dedicated adopt-preview permission. It captures revision/intent
digests before unlocked reads, rechecks current native/account/site/catalogue state, and retains only encrypted
normalized A1 evidence. Required permission grants and effective/requested DNR intent are frozen in the digest.
Ready/refused projections expose no account/provider identifiers, raw replies, credentials or request authority.
TEST is rehearsal only; every preview has confirmation and live-anchor eligibility disabled.

The native service must have null expiry, exact annual registration route, quantity one/USD and matching order owner.
Pending IDs/dates must agree; competing registrar services, unresolved debt or operations without the existing bound
definitive-unsent proof refuse. Ordinary no-server hosting and explicitly mapped hosting can coexist; known registrar
extensions cannot use a hosting map to escape. Retained cancelled peers still require explicit qualification.
Current published renewal retail is grossed through the same native helper; retained/below-cost alerts are shown
without a price floor. Billing and preview share the validated issue/due calendar (21/7 defaults). Existing native
end-of-period cancellation forces effective DNR; the preview never creates or deletes one.

A stock Filament action/page on adoption-pending records reads only on explicit submission with a reason. Results,
history IDs/status, environment, expiry, due date, charge, price alert and DNR are escaped native entries. Confirm is
disabled with “Adoption is not enabled yet”. No holding, service, invoice, operation or authority is committed here.

Pending conversions compare fresh expiry against encrypted `conversion_source_expiry`, falling back to a retained
provider date. Missing/invalid pending source dates require separate qualification; the preview never substitutes a
registrar date for an unknown converted date. New matched services without a pending source still require all fresh
provider expiry reads to agree. TEST success is labelled rehearsal; live success remains a disabled eligible preview.

Converted pending rows are matched by canonical name even when their reservation is null. Preview preserves their ID and never acquires a reservation; competing names or reservations still refuse.

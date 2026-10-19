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

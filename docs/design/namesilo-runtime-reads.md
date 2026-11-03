# NameSilo installed runtime readers

This is the read-only split of the runtime installation. No boot path invokes it, no account is
activated, and no mutation protocol is installed. Live reads remain behind the existing default-off
`domains.namesilo_live.catalogue` deployment switch and account/site/budget controls.

A deployment that already has connected-mode acceptance may explicitly register one bound factory:

```php
$registry->registerBoundReader($binding,
    $bindings->nameSiloRuntimeReaderFactory($binding, $clock, $readAcceptance));
```

`$readAcceptance(AccountBinding $binding, string $credentialRevision): bool` is a trusted, local,
read-only evidence check. It must bind the actual site, account, credential revision and connected-mode
contract acceptance. Missing acceptance refuses. NS5 owns the persisted activation receipt; this API
creates no receipt and a boolean deployment flag alone is not evidence. Test/A11 installed acceptance
remains unproven; this factory deliberately accepts only the Live transport supplied by NS3.

Factory construction registers nothing, decrypts nothing and sends nothing. Reader construction
validates the exact binding, encrypted credentials, local enrollment revision, deployment policy and
annual catalogue acceptance. Every physical request repeats the evidence and fresh credential checks.
Cached readers pin their revision and key: rotation invalidates them, and creating a new reader still
requires acceptance for the new revision. Stable local account lineage retains the durable budget.

The shared registry's bound override applies to both ordinary and balance-only callers. Once an
account has an override, any refusal propagates; it never falls back to the former balance transport.
Other accounts and Enom retain their existing factories. Import remains separately gated.

The genuine NameSilo Driver supplies the existing catalogue, domain observation, renewal assessment,
reconciliation and transfer-poller contracts through one admitted client. Interactive reads use `/api`,
worker reads use `/apibatch`, and each physical request consumes the NS2 ledger once with no spend.
Normal RequestContext deadlines, budgets and caller-supplied account limiter remain in force. Reads
remain possible while spending is paused, as for existing holdings. Account capabilities confer no
purchase or management authority; unsupported terms and unknown evidence retain existing semantics.

## Remaining runtime split

Acquisition, renewal and management assembly must add fresh-funded, exact-command reservations before
the durable seal, while retaining each genuine engine grant. Reserve/cap refusal before send must have
operation-specific unsent handling. Lost replies must remain unresolved without replay. Profile creation
and auto-renew cleanup need separate durable management children; neither can reuse a purchase scope.
These shared engine/protocol changes and their engine-to-wire tests belong in a follow-up bounded split.
Do not install writers by merely injecting AdmittedTransport::exchange into RuntimeComposition.

Offline tests exercise encrypted installed account rows, synthetic cURL, the real daily balance worker,
shared transfer polling, key rotation, revocation, off/site/server guards, exact call accounting and lost
replies. They do not establish A11, provider history completeness or any real OTE/Live acceptance.

Admitted Live sends cap connect/total timeouts at the whole milliseconds left in the UTC reservation day, preserving shorter caller deadlines. Submillisecond windows refuse before physical handoff; counted reservations remain retained. This bounds the local transport window, not the provider's processing or receipt time. A timed-out mutation remains consumed and unresolved, never replayable merely because the day changed.

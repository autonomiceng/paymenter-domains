# Local Live activation engine

`Services/LiveActivation` owns deployment/feature readiness, dated USD history,
balance freshness, worker/payment hooks, schedule checks, review stamps and retained
activation receipts. `Contracts/LiveActivationEvidence` supplies provider policy
without transport. Readiness takes a required price-check closure: no prices are
read, inferred or written by the shared engine itself.

`EnomLiveActivation` remains the installed staff/runtime entry point. Its existing
price interface, summaries, transaction/audit, feature writers, transfer default
Off and dated transfer pilot requirement remain unchanged. The Enom adapter uses
`EnomLive`; existing receipt bytes and review stamps remain valid. Price-module
cutover and transfer policy changes belong to their own work.

The NameSilo adapter is dormant. Nothing registers it in staff actions, runtime
factories or feature writers. Calling its local checks cannot enable processing,
write feature settings, authenticate a key or call a provider. Installed activation
and final price integration remain separate dependencies.

For that future composition, it reads `domains.namesilo_account.environment` and
`domains.namesilo_live`: explicit HTTPS `site`, boolean catalogue/features,
`runtime_revision`, and `accounts[stable_local_identity]`. Each account requires
its exact `fingerprint`, `currency: USD`, `balance_funding: true`, positive daily
call/spend limits and `evidence`. This matches the dormant admitted transport's
account configuration; processing is deliberately not required to review readiness.
The runtime revision identifies the deployed admitted composition and must change
when its semantics change. RuntimePorts independently checks actual composition.

Evidence carries `fingerprint`, `site`, `confirmed_on` (YYYY-MM-DD, not future),
redacted `reference`, `sha256`, `currency: USD`, `complete_history: true` and
`history[registration|renewal|transfer]` with timezone, integer lag and retention.
The same dated packet records `portal_controls` with all three strictly true:
`reject_invalid_contacts`, `restore_disabled`, `auto_replenishment_disabled`.
Retain the underlying redacted portal/provider evidence. These controls reuse the
existing activation review; they introduce no routine button or identity check.
Transfer history is required when selected. NameSilo has no Enom pilot receipt.

NameSilo checks current NS1 local enrollment and credential revision without
reading a default contact or asserting provider account identity. Its receipts
also bind the credential revision and deployment configuration/runtime revision;
key rotation and relevant configuration changes stale receipts and displayed
reviews. Saved choices remain readable so withdrawal works with missing evidence.
A synthetic receipt test proves local policy only, never installed or Live parity.

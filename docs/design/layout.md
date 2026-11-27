# Runtime layout

Where code belongs under `Domains/`. Namespaces follow folders from `Paymenter\Extensions\Servers\Domains`.
Most runtime code still sits in a few large folders; it moves to its feature folder when that feature next
changes. New code goes straight to its target folder.

## Rules

- A directory holds at most 40 PHP files of its own. `Services/`, `Admin/Support/`, `Data/` and
  `resources/views/partials/` are above that today and may only shrink; `tests/test_directory_sizes.py` holds
  their counts.
- `Registrars/<Provider>/Api/` is wire protocol code and names only its own namespace. Today's references to
  `Data`, `Services` and the provider root are listed in `tests/test_registrar_api_imports.py` and may only shrink.
- Other code reaches a provider through `Registrars/Dispatch/`. Today's direct references to provider classes,
  including the `Enom*` and `NameSilo*` classes in `Services/`, are listed in `tests/registrar-reverse-imports.json`
  and may only shrink.
- A feature folder owns its writer, rules and read projection. Other features read its published types and ports;
  shared value types keep their current contract.
- A class moved out of a folder keeps a narrow entry point at its old name only while proven outside callers use
  it: host integrations, Blade, reflection, discovery, schema registration or test file names. Check both names
  resolve to the same object. Remove the entry point once callers move.

## Feature folders

Status: **exists** when the folder is in the tree today, **target** when it is still planned. Class names are
today's names in `Services/` unless a folder is given.

| Folder | Owns | Status and code to move there |
|---|---|---|
| `Registration/Checkout`, `Registration/Execution` | Registration order and frozen acquisition intent, admissibility, first send, result, initial DNS and profile handoff | Target. `Acquisition*`, `Registration*`, `CartAdmission` and `CheckoutEligibility` purchase decisions, `UnpaidAcquisitionProof`, `ContactSetup`, the registration path of `DomainOperations` |
| `Renewal/Execution`, `Renewal/Cycle`, `Renewal/Reminders` | Renewal obligation, admitted attempt, completion and expiry; cycles, Auto Pay and billing; reminder schedules and notices | `Execution/` exists with the admitted attempt, decision, proof, completion and repeat wait; `Cycle/` and `Reminders/` are target. `DomainBilling`, `Reminder*`. Funding stays with Money |
| `Transfer/Input`, `Transfer/Execution` | Precheck, auth code acceptance, deadline, transfer attempt, provider pending and completion | Target. `Transfer*` and the transfer branches of acquisition |
| `Operations/Authority`, `Operations/Reconciliation`, `Operations/Work` | Shared operation and attempt writer, authority revision, send certainty, reconciler, due work and status projection | `Authority/` exists with the operation, step, attempt and evidence tables, their writer (`OperationWriter`) and read projection (`OperationAttempts`); `Reconciliation/` and `Work/` are target. `OperationClaims`, `OperationReconciliation`, `OperationTerminalProof`, `ReconciliationEvidence`, `CapturedChargeReview`, shared `Write*`, `Runtime*` work and dispatch |
| `Management/Changes`, `Management/Records`, `Management/Contacts` | Management writer and step readback; DNS templates and record inventory; contact profiles and recovery | `Changes/` (forms, sequence, progress, corrections, nameservers) and `Contacts/` (contact profiles) exist; `Records/` is target. `DomainManagement`, the rest of `Management*` and `Correction*`, `OwnerRecords`, `OwnerSetup`, `ContactDetails`, `ContactValidation`. Neutral DNS value types and wire contracts stay in `Dns/` |
| `Holding/Identity`, `Holding/Adoption`, `Holding/Observation`, `Holding/Retention` | Canonical name, verified owner, epoch and registrar; adoption; observation and read budgets; departure and archive | `Adoption/`, `Observation/` (observation records and eligibility) and `Retention/` exist; `Identity/` is target. `Holding*` (except `HoldingNativeLocks`, which goes to Money), `DomainReservations`, `NativeIdentity*`, `NativeDomainGuard`, `Adoption*`, `Observation*`, `DomainObservation`, `Departure*`, `History*`, `Retention*`, `PrivacyRetirement`, `NativeUserDeletion`. `OwnedDomains`, `DomainState*` and `NativePresenter` read from here. `Models/Domain` stays the ORM record |
| `Money/Native`, `Money/Allocations`, `Money/Returns` | Native invoice and receipt locks, settled amounts, funding reservations and allocations, returns, reversals and disputes | `Native/` owns invoice locks and paid hooks. `Allocations/` owns renewal reservations and signed native postings. `Returns/` owns receipt returns, reversals and disputes. Shared billing policies and some return callers remain in `Services/` |
| `Catalogue/Publication`, `Catalogue/Pricing`, `Catalogue/Feed`, `Catalogue/Staff` | Seller selection, publish revision, admitted terms, native price projection, offer feed and expiry; staff catalogue next steps, snapshots and settings reads | `Catalogue/`, `Publication/`, `Pricing/`, `Feed/` and `Staff/` exist. `SellerPolicies`, `NativeCatalogue`, `PolicyEndings`, `PriceCatalogue`, `PricingRules`, `HolderPricing`, `CatalogueSync`; split the `Catalogue/` root by role |
| `Storefront/Checkout`, `Storefront/Search` | Query translation, cart refresh and handoff, public lookup quotas | `Search/` exists with the DNS and RDAP quotas; `Checkout/` is target. `Storefront*`, `Cart*`, `EndingChoices`, `PriceList`, `Rdap*`, `PublicRegistryReader`. Reads published facts through Catalogue; Registration approves acquisition |
| `Staff/Release`, `Staff/Requests`, `Staff/Holdings`, `Staff/Settings` | Staff authorization, actions and request, holding and settings projections | `Release/`, `Requests/` and `Holdings/` exist; `Settings/` is target. `StaffOperation*`, `StaffHoldingReview`, `StaffSettings*`, `StaffSyncReview`, `AuditEvents`, and `Admin/Support/` classes as their screens change |
| `Registrars/Accounts`, `Registrars/Enom`, `Registrars/NameSilo`, `Registrars/Dispatch` | Verified registrar assignment and environment, enrolment, provider caps, send grants, factories and driver results | `Enom/`, `NameSilo/` and `Dispatch/` (registry, reader factories, catalogue offers, renewal writer and unsent proof) exist; `Accounts/` is target. `AccountBindings`, the old `RegistrarRegistry` and `RegistrarFactories` alias names, the `Enom*` and `NameSilo*` classes in `Services/`, balance and call limit code |
| `Installation`, `Installation/Runtime` | Fresh install and verification, autoload and bootstrap, native composition, one registry of feature-owned table definitions | `Installation/` exists; `Runtime/` is target. `FreshInstallation`, `Installation`, `Runtime`, `RuntimePorts`, `RuntimeComposition`, `NativeServerGuard`, hook registration |
| `Support` | Clock and typed shared infrastructure | Exists |

## Folders that keep their role

- `Admin/`: Filament pages, resources and their presentation adapters. `Admin/Support/` classes that only configure
  Filament (for example `DialogFooter`) stay; classes that decide access or read facts move to Staff subfolders.
- `Data/`: genuinely shared value types. Feature-owned types move to their feature or a named contract subfolder.
- `Contracts/`, `Dns/`, `Models/`, `Presentation/`, `Http/`, `Jobs/`, `Mail/`, `Console/`, `Policies/`, `Casts/`,
  `resources/`, `routes/`, `database/`: unchanged.

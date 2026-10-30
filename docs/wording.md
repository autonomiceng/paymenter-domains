# Wording

One word list for every page. Customers and staff see the same word for the same thing.

## Rules

- Plain words a hosting customer knows. Short sentences.
- What happened, then one next step.
- No legal look. One short line, plus a "?" helper or a docs link for the rest. Make a new "?" with `HelpHint`, so
  keyboard users can reach it.
- A required registrar agreement is one checkbox line with a link.
- Customers never see a registrar or API name. Staff see one only when it is the real name of the account or the place
  to act.
- Every Missing says what to do next. If nothing is needed, it says so and goes muted.
- Do not tell staff to reload. Do not send them to logs, a README or a config file.

## Words

| Use | Not |
| --- | --- |
| TLD | ending, extension, suffix |
| Missing | Unknown, Not set, Unavailable, N/A (for a value we expect) |
| Registrar account | server, Domains server, provider, seller account |
| Registrar for new orders | seller, default seller |
| Orders (on, paused) | processing, purchases on |
| For sale | catalogue enabled, supported, accepted |
| What you sell | catalogue, supported TLDs, acceptance |
| Sync prices | price import, catalogue refresh |
| Prices last synced | last sync (for prices) |
| Import | adopt, adoption |
| Test | TEST, OTE, sandbox, rehearsal |
| This site | deployment, environment, server config |
| Monitoring | watch, domain checks, public checks |
| Auth code | transfer code, EPP code (named once, in help) |
| WHOIS privacy | domain privacy, ID protection |
| Transfer lock | registrar lock, reg lock |
| Auto-renew | renewal on, renewal authority |
| Auto Pay | automatic payment, billing agreement, saved card agreement |
| Account credit | refund (when money goes to credit), wallet |
| Cancel and add credit | cancel and refund |
| Request | operation, intent, attempt |
| Check at the registrar | verify with provider, reconcile |
| Expires | expiry date (as a label), anchor |
| Not charged | zero cost, no cost recorded (when it was never sent) |
| The registrar did not take this change. | Registrar refused the change |
| Not offered | Unavailable (the registrar does not sell it; no action needed) |
| Needs a price sync | Sync TLD prices needed |
| Site admin | installation administrator, deployment administrator, server administrator |
| Live account ID | account fingerprint |
| Resolver IP address | bootstrap IP |
| Saved payment method | saved card, saved method |
| Unpaid | outstanding, awaiting funds (Awaiting payment is the request status) |
| Sample data | demo data, synthetic data |
| Your site admin | a support ticket (when staff can't fix it themselves) |

A scheduled cancel may say Ending or Ends on: it is a date, not a TLD. A value like Not recognized is a real state, not
Missing.

## Statuses

Domain: Active, Pending registration, Pending transfer, Action required, Under review, Expired, Redemption, Failed,
Cancelled.

Request: see `Domains/Presentation/RequestStatus.php`. Failed means it did not work. An unpaid order that was closed is
Cancelled, not Failed.

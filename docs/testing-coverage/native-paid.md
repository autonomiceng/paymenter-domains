# Native paid processing guard map

Every entrypoint uses the same installed runtime composition and native payment hooks in
`Support/native-paid-fixture.php`. No behaviour assertions were removed from the original
matrix. The runner's verified core/commerce database snapshots remain shared inputs;
each file owns its mutations and real committed native payment processing.

| Test | Original guards retained |
| --- | --- |
| native-paid-baseline | Activation mail/default/custom/blank/null text; real mixed domain/hosting/Credit checkout; unpaid early jobs; native synchronous activation timing; managed expiry; duplicate Paid/manual/worker no resend, duplicate operation, credit or financial mutation; queue failure preserves siblings |
| native-paid-contacts | Missing-contact hold; byte-preserving callback replay; audited contact replacement permits one send |
| native-paid-refunds | Real preflight refusal and staff refund; delayed serialized Create/Unsuspend jobs; no spend or double credit; repeated/new late money; native reactivation with original refund history |
| native-paid-retirement | Unpaid proof releases reservation; later money never reclaims it; partial-money cancellation retains intent/reservation and never sends; completed/non-refunded orders stay quiet; cancelled completed reactivation is reviewed |
| native-paid-closure | Transfer timeout closure before credit failure and lost wake; durable staff refund authorization before credit; money and Active discrepancies survive; completed cancellation evidence survives missed wake |
| native-paid-hooks | Missing exact Paid listener; scheduler recovery; days-long transfer pacing and single wake; missing exact Service saving hook preserves operation/money, shows staff checks, and recovers once after restoration |
| native-paid-staff | Actual filtered staff list, request relation, domain next step, invoice URL and invoice permission narrowing |

Staff surface setup arranges only the three required states using real checkout, payment,
refund and retirement. Their full lifecycle assertions live in baseline, refunds and retirement.
The baseline starts without Credit so its native Credit sibling still proves row creation.

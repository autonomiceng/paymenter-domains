# Durable NameSilo profile operations

Dormant, explicitly injected service; no writer factory, runtime registration or activation.

`ProfileContactOperations` owns `domains_profile_operations`, unique per funded parent acquisition.
It is not a domain Operation child: it cannot introduce a sibling acquisition obligation.
Its owner, account, generation, payload digest and parent claim are immutable. Attempt evidence
(including returned provider ID) is encrypted. Parent funding locks precede profile ledger locks;
provider work runs outside transactions.

A separate management reservation admits exactly `contactAdd`, without monetary spend. The
profile seal and one-use dispatch marker commit before transport. Purchase reservations remain
separate. Contact creation never borrows the parent's purchase seal or grant.

A returned ID is retained before readback. A reconstructed worker can read only that ID, prove
all normalized fields and non-default status, then retain the local owner-bound profile handle.
Proof expires after 120 seconds and is refreshed by readback alone. Fixed creation-proof bytes
make recording idempotent across a crash before linking the local handle.

Unknown execution holds because no returned ID exists and account inventory cannot safely
identify which contact was created. A sealed attempt proven locally unsent retains that proof
and seal; it holds because no replacement-attempt policy authorizes another contact mutation.
Neither outcome can replay. Pre-seal reservation refusal leaves a ready row eligible for fresh
admission. Expired sealed workers without returned identity become uncertain. Known created
identities survive failed readback or changed parent authority; they never authorize purchase
without current funded parent authority and fresh exact readback.

NS4d owns renewal, management and auto-renew operation assembly. Writer factory/A11 synthetic
installed route assembly and NS5 activation remain later work; connected acceptance is NS18.

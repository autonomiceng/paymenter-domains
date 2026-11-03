# Durable management sequences

An opt-in `ManagementProtocol::plan` reads a complete provider preimage outside holding
locks. One save freezes that preimage, the owner's intent, local configuration identity
and at most 50 ordered deltas in encrypted operation policy. The holding stamp must still
match when the save commits. Reusing the same request key returns the original sequence
without another inventory read.

Each delta is an ordinary, non-billable management operation with its own one-use grant,
seal and observation history. The final delta carries the original request key and ID;
there is no extra completion write, queue or schema. Earlier deltas refer to that request.
Each entry advances at most one delta. Claims and consumption require unchanged native
authority and configuration plus verified predecessor evidence. The next preimage comes
only from that predecessor's retained, independent readback.

Unknown or possibly sent deltas are reconciled by reads and never replayed. A failed
unsealed preparation or retained, exact unconsumed-reservation proof permits the existing
save/retirement boundary to close remaining unsent work. Completed deltas and all original
seals remain intact. Unknown active work continues to own the setting. Shared progress
reports verified counts and gives a save-again or contact-support next step when blocked.

The existing contact-profile prerequisite remains supported. Enom does not opt in and
keeps its current whole-list behavior. Provider wire mapping stays outside the sequence
engine.

`NameSilo\DnsManagement` is a dormant adapter; no runtime factory or form selects it.
It plans updates, deletions and additions from a complete `Dns` inventory using NS11's
per-record mappings. Every child rechecks the entire ID/content/TTL preimage. Readback
must show exactly its delta while retaining every unrelated row. Add/update recovery
requires a unique new ID and the original acknowledged ID when available; deletion
requires the old ID absent from a complete inventory, including explicit empty inventory.
Opaque unsupported rows are preserved. Missing or partial inventory never proves absence.
The admitted transport recognizes these commands but still requires existing feature,
account, reservation and physical-send checks. Runtime activation and DNS forms remain
separate work.

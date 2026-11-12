# Enom TEST history fixtures

Sanitized from TEST replies captured on November 10, 2026: GetOrderList and a
successful two-year renewal GetOrderDetail. Order IDs, domain, tracking key and
server identity are fictional or blank. No credentials or account data remain.
Tags, status vocabulary, pagination and local date formats follow the captures.

The saved list is page 1: 25 rows out of 81. Tests construct later pages using
the same row structure and the documented Start/previous/next positions; those
later pages and empty-list cases are scenarios, not additional captures.
The list has no domain or product type. OrderDate is a receipt clock and is
used only to exclude whole days outside every possible send-time UTC offset.
BillDate still supplies the strict charge timestamp. Dense days exceeding the
existing detail budget remain unresolved. Fixtures do not grant acceptance,
prove Live clock behavior, or establish visibility lag or retention.

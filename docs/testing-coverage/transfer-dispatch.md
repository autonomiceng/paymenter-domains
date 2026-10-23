# Transfer dispatch coverage

## Transfer dispatch command budget

Transfer dispatch scenarios run as three independent native commands: Enom sends/RDAP/order
references (`transfer-dispatch-test.php`), NameSilo sends (`transfer-dispatch-namesilo-test.php`)
and input/feature/pre-seal refusals (`transfer-dispatch-refusals-test.php`). The shared
`Support/transfer-dispatch-fixture.php` retains the original native checkout, funding and
five-mode dispatch assertions once per registrar. Splitting bounds cumulative fixture rows
without replacing native checkout or weakening replay, refund, admission or uncertainty checks.
These are offline synthetic SQLite scenarios; provider and concurrent-database acceptance remain separate.

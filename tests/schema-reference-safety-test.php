<?php
// Reference ownership and cleanup regressions. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services {
    function random_bytes(int $length): string
    {
        return isset($GLOBALS['schemaReferenceRandom']) ? str_repeat('a', $length) : \random_bytes($length);
    }
}
namespace {
    require __DIR__.'/native-app.php';
    use Illuminate\Database\QueryException;
    use Illuminate\Database\Schema\{Blueprint,SQLiteBuilder};
    use Illuminate\Support\Facades\{DB,Schema};
    use Paymenter\Extensions\Servers\Domains\Services\SchemaVerifier;

    $builder = new class(DB::connection()) extends SQLiteBuilder {
        public array $references = [];
        public function getColumns($table)
        {
            foreach (debug_backtrace() as $frame) {
                if (($frame['class'] ?? '') === SchemaVerifier::class && $frame['function'] === 'verify' && $table !== $frame['args'][0]) {
                    $this->references[] = $table;
                }
            }
            return parent::getColumns($table);
        }
    };
    Schema::swap($builder);
    $definition = static function (Blueprint $t): void { $t->id(); $t->string('value')->unique(); };
    Schema::create('domains_reference_test', $definition);
    $GLOBALS['schemaReferenceRandom'] = true;
    SchemaVerifier::verify('domains_reference_test', $definition);
    $reference = end($builder->references);
    Schema::create($reference, static function (Blueprint $t): void { $t->string('sentinel'); });
    DB::table($reference)->insert(['sentinel' => 'owned by another verifier']);
    try { SchemaVerifier::verify('domains_reference_test', $definition); throw new \LogicException('Collision accepted'); }
    catch (QueryException) { /* Atomic CREATE refuses the collision. */ }
    expect(Schema::hasTable($reference), 'Collision must never drop another invocation\'s table');
    same('owned by another verifier', DB::table($reference)->value('sentinel'), 'Collision preserves the other owner\'s rows');
    Schema::drop($reference);
    unset($GLOBALS['schemaReferenceRandom']);
    $before = Schema::getTableListing(); sort($before);
    for ($i = 0; $i < 20; $i++) { SchemaVerifier::verify('domains_reference_test', $definition); }
    same(20, count(array_unique(array_slice($builder->references, -20))), 'Independent concurrent invocations have distinct names');
    // CREATE succeeds, but a later index statement fails: finally must remove the owned table.
    try {
        SchemaVerifier::verify('domains_reference_test', static function (Blueprint $t): void {
            $t->id(); $t->string('value'); $t->unique('value', 'duplicate_reference_index'); $t->index('value', 'duplicate_reference_index');
        });
        throw new \LogicException('Invalid definition accepted');
    } catch (QueryException) { /* expected */ }
    $after = Schema::getTableListing(); sort($after);
    same($before, $after, 'Owned table cleaned when an index statement fails');
    echo "Reference collision preserves the other owner; independent names and partial-DDL cleanup passed.\n";
    $nativeSuccessful = true;
}

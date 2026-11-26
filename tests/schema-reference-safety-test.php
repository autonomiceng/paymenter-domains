<?php
// Reference ownership and cleanup regressions. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Installation {
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
    use Paymenter\Extensions\Servers\Domains\Installation\SchemaVerifier;

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
    $references = [];
    for ($i = 0; $i < 20; $i++) {
        $start = count($builder->references);
        SchemaVerifier::verify('domains_reference_test', $definition);
        // Verification may inspect a reference more than once; compare one name per invocation.
        $references[] = $builder->references[$start];
    }
    same(20, count(array_unique($references)), 'Independent concurrent invocations have distinct names');
    // CREATE succeeds, but a later index statement fails: finally must remove the owned table.
    try {
        SchemaVerifier::verify('domains_reference_test', static function (Blueprint $t): void {
            $t->id(); $t->string('value'); $t->unique('value', 'duplicate_reference_index'); $t->index('value', 'duplicate_reference_index');
        });
        throw new \LogicException('Invalid definition accepted');
    } catch (QueryException) { /* expected */ }
    $after = Schema::getTableListing(); sort($after);
    same($before, $after, 'Owned table cleaned when an index statement fails');
    // A repeated dependency can share its expected shape, but never a successful check of the actual table.
    DB::flushQueryLog(); DB::enableQueryLog();
    SchemaVerifier::batch(static function () use ($definition): void {
        SchemaVerifier::verify('domains_reference_test', $definition);
        SchemaVerifier::verify('domains_reference_test', $definition);
    });
    $creates = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_starts_with(strtolower($q['query']), 'create table '));
    DB::disableQueryLog(); same(1, count($creates), 'Repeated dependencies create one reference');
    SchemaVerifier::batch(static function () use ($definition): void {
        SchemaVerifier::verify('domains_reference_test', $definition);
        Schema::table('domains_reference_test', static fn (Blueprint $t) => $t->dropUnique(['value']));
        try { SchemaVerifier::verify('domains_reference_test', $definition); throw new \LogicException('Actual schema drift accepted'); }
        catch (\RuntimeException $error) { expect(str_contains($error->getMessage(), 'schema mismatch'), 'A reused reference still refuses actual index loss'); }
        Schema::table('domains_reference_test', static fn (Blueprint $t) => $t->unique('value'));
        try {
            SchemaVerifier::verify('domains_reference_test', static function (Blueprint $t): void { $t->id(); $t->string('value'); });
            throw new \LogicException('Changed definition accepted');
        } catch (\RuntimeException $error) { expect(str_contains($error->getMessage(), 'schema mismatch'), 'A different definition needs its own reference'); }
    });
    $before = Schema::getTableListing(); sort($before);
    try { SchemaVerifier::batch(static fn () => throw new \RuntimeException('Synthetic callback failure')); } catch (\RuntimeException) {}
    $start = count($builder->references); SchemaVerifier::verify('domains_reference_test', $definition);
    expect(count($builder->references) > $start, 'A failed batch retains no reference cache');
    $after = Schema::getTableListing(); sort($after); same($before, $after, 'Batched references leave no tables behind');
    // Foreign keys compare by columns, target and actions: the healthy key passes, a missing or changed one is refused.
    Schema::create('domains_reference_parent', static fn (Blueprint $t) => $t->id());
    $child = static fn (?string $onDelete): \Closure => static function (Blueprint $t) use ($onDelete): void {
        $t->id(); $t->unsignedBigInteger('parent_id');
        if ($onDelete !== null) {
            $t->foreign('parent_id', $t->getTable().'_parent_f')->references('id')->on('domains_reference_parent')->onDelete($onDelete)->restrictOnUpdate();
        }
    };
    Schema::create('domains_reference_child', $child('restrict'));
    SchemaVerifier::verify('domains_reference_child', $child('restrict'));
    foreach (['missing key' => null, 'changed action' => 'cascade'] as $case => $actual) {
        Schema::drop('domains_reference_child'); Schema::create('domains_reference_child', $child($actual));
        try { SchemaVerifier::verify('domains_reference_child', $child('restrict')); throw new \LogicException($case.' accepted'); }
        catch (\RuntimeException $error) { same('Retained Domains schema mismatch: domains_reference_child', $error->getMessage(), $case.' refused'); }
    }
    // A same-named target in another schema, or one cased differently, is another reference. MariaDB proves it physically.
    $reference = new \ReflectionMethod(SchemaVerifier::class, 'reference');
    $key = Schema::getForeignKeys('domains_reference_child')[0];
    expect($reference->invoke(null, $key) !== $reference->invoke(null, ['foreign_schema' => 'restored_copy'] + $key), 'Reference target includes its schema');
    expect($reference->invoke(null, ['foreign_schema' => 'paymenter'] + $key) !== $reference->invoke(null, ['foreign_schema' => 'Paymenter'] + $key), 'A case-distinct schema is another target');
    expect($reference->invoke(null, $key) !== $reference->invoke(null, ['foreign_table' => strtoupper($key['foreign_table'])] + $key), 'A case-distinct table is another target');
    Schema::drop('domains_reference_child'); Schema::drop('domains_reference_parent');
    echo "Reference collision preserves the other owner; independent names and partial-DDL cleanup passed.\n";
    $nativeSuccessful = true;
}

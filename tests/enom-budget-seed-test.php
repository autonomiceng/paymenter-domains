<?php
// Exercise the real MariaDB seed script offline at UTC midnight; no database or container.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Illuminate\Support\Facades {
    final class Artisan { public static function call(...$args): void {} }
    final class Schema { public static function clearResolvedInstance(...$args): void {} }
    final class DB {
        public static function purge(...$args): void {}
        public static function table($table): object { return new class {
            public function insert(array $row): void { $GLOBALS['seedNative']=$row; }
        }; }
    }
}
namespace Paymenter\Extensions\Servers\Domains\Models {
    final class Account {
        public static function withVerifiedBindingWrite(\Closure $work): void { $work(); }
        public static function create(...$args): void {}
        public static function where(...$args): object { return new class {
            public function sole(): object { return (object)['account_fingerprint'=>str_repeat('b',64)]; }
        }; }
    }
}
namespace Paymenter\Extensions\Servers\Domains\Services {
    final class Runtime { public static function register(): void {} }
    final class Installation { public function install(): void {} }
}
namespace SeedFixture\App\Models { final class Server { public static function create(...$args): void {} } }
namespace SeedFixture {
    require __DIR__.'/bootstrap.php';
    function config(...$args): void {}
    function getenv(string $key): string { return match($key){'BUDGET_PHASE'=>'seed',default=>''}; }
    function microtime(bool $float): float { $GLOBALS['seedReads']++;return $GLOBALS['seedClock']; }
    function gmdate(string $format,?int $time=null): string { return \gmdate($format,$time??(int)$GLOBALS['seedClock']); }
    function file_put_contents(string $path,string $data): void { $GLOBALS['seedHistory']=json_decode($data,true,32,JSON_THROW_ON_ERROR); }
    $source=file_get_contents(__DIR__.'/Support/mariadb-enom-budget.php');
    $source=str_replace(['<?php',"require __DIR__.'/mariadb-bootstrap.php';"],'',$source);
    $source=str_replace('declare(strict_types=1);','declare(strict_types=1); namespace SeedFixture;',$source);
    foreach(['2026-10-28T00:00:00.000000Z','2026-10-28T00:00:00.500000Z','2026-10-28T00:00:01.000000Z','2026-10-28T12:00:00Z'] as $at){
        $GLOBALS['seedClock']=(float)(new \DateTimeImmutable($at))->format('U.u');$GLOBALS['seedReads']=0;
        eval($source);
        $history=$GLOBALS['seedHistory'];
        same([$GLOBALS['seedNative']],$history['native'],'Seed row matches retained history');
        foreach($history['attempts'] as $time){same($GLOBALS['seedNative']['day'],\gmdate('Y-m-d',intdiv($time,1000000)),'Seed attempt day agrees at '.$at);}
        same(1,$GLOBALS['seedReads'],'Seed uses one clock read');
    }
    echo "MariaDB seed stays on one UTC day at midnight without database access.\n";
}

<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use App\Models\Setting;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{WatchProfile,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,SiteDnsDefault,ComplimentaryAccountSchema};
// Same-version local setup calls Installation, not the uploader's upgraded hook.
// Native settings, schema history and encrypted watch rows cannot be proved by a stub.
$entry=new \Paymenter\Extensions\Servers\Domains\Domains([]);$entry->installed();
same(null,SiteDnsDefault::profile(),'Old installation has no deployment default');
// Pre-F71 retained storage has neither complimentary table nor migration 000055.
// Keep all existing foundational tables/history; the normal installer must add F71 before seeding DNS.
foreach(['domains_complimentary_approvals','domains_complimentary_accounts']as$table){
    same(0,DB::table($table)->count(),'Only empty new tables are removed from the synthetic old installation');
    Schema::drop($table);
}
DB::table('migrations')->where('migration',ComplimentaryAccountSchema::MIGRATION)->delete();

config(['domains.site_dns_default.nameservers'=>['ns1.beco.test','ns2.beco.test']]);
app(Installation::class)->install();
ComplimentaryAccountSchema::verify();
same(true,DB::table('migrations')->where('migration',ComplimentaryAccountSchema::MIGRATION)->exists(),'Normal pre-F71 upgrade records the new migration');
$seeded=SiteDnsDefault::profile();
expect($seeded!==null,'Normal same-version installation seeds the DNS default');
same(['ns1.beco.test','ns2.beco.test'],$seeded->nameservers,'Seed uses configured nameservers');
$count=WatchProfile::count();$audit=WatchAudit::count();
app(Installation::class)->install();
same($seeded->id,SiteDnsDefault::profile()->id,'Repeated upgrade keeps the same reference');
same([$count,$audit],[WatchProfile::count(),WatchAudit::count()],'Repeated upgrade adds no template or audit');
config(['domains.site_dns_default.nameservers'=>['ns1.changed.test','ns2.changed.test']]);
app(Installation::class)->install();
same($seeded->nameservers,SiteDnsDefault::profile()->nameservers,'Configuration never replaces a saved choice');
$nativeSuccessful=true;echo "Normal same-version DNS seeding passed.\n";

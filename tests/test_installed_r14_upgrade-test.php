<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use App\Models\Setting;
use Paymenter\Extensions\Servers\Domains\Models\{WatchProfile,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,SiteDnsDefault};
// Same-version local setup calls Installation, not the uploader's upgraded hook.
// Native settings, schema history and encrypted watch rows cannot be proved by a stub.
$entry=new \Paymenter\Extensions\Servers\Domains\Domains([]);$entry->installed();
same(null,SiteDnsDefault::profile(),'Old installation has no deployment default');
config(['domains.site_dns_default.nameservers'=>['ns1.beco.test','ns2.beco.test']]);
app(Installation::class)->install();
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

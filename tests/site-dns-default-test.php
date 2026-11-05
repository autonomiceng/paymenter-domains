<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,NewDomainDns,WatchDnsChecks};
// Installation has no staff actor yet. Deployment supplies literal public names, never registrar credentials.
config(['domains.site_dns_default.nameservers'=>['ns1.deployment.test','ns2.deployment.test']]);
extract(dnsFixture());
same(1,WatchProfile::where('name','Beco DNS')->where('enabled',true)->count(),'Installation seeds one enabled deployment DNS template');
$default=SiteDnsDefault::profile();
same(['ns1.deployment.test','ns2.deployment.test'],$default->nameservers,'Deployment owns the nameservers');
same($default->id,NewDomainDns::profile($server->id)->id,'Site default works independently of registrar account');
$entry->upgraded();same($default->id,SiteDnsDefault::profile()->id,'Upgrade keeps one stable default reference');
same(1,WatchProfile::where('name','Beco DNS')->count(),'Upgrade does not duplicate the deployment template');
$nativeSuccessful=true;echo "Site DNS installation default passed.\n";

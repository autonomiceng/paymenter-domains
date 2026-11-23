<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real Eloquent casts and retained installation are needed to reproduce the site-zone storage boundary.
require __DIR__.'/native-app.php';
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\DomainAudit;
use Paymenter\Extensions\Servers\Domains\Services\{Installation};
$install=new Installation;$install->install();
$prior=date_default_timezone_get();date_default_timezone_set('America/Los_Angeles');
$attrs=['subject_type'=>'dns_profile','subject_id'=>7,'event'=>'dns_profile_saved','after_revision'=>'1','data'=>[]];
try{
    foreach(['2026-11-01T08:30:00Z','2026-11-01T09:30:00Z','2026-03-08T09:59:59Z','2026-03-08T10:00:00Z'] as $value){
        $at=new DateTimeImmutable($value);$local=CarbonImmutable::instance($at)->setTimezone('America/Los_Angeles');
        $a=DomainAudit::create([...$attrs,'created_at'=>$local]);$raw=DB::table('domains_audit')->find($a->id);
        same($at->format('Y-m-d H:i:s'),$raw->created_at,'UTC wall time survives DST and a non-UTC writer');
        same('UTC',$raw->time_zone,'Every new row declares UTC');
        same($at->getTimestamp(),$a->fresh()->created_at->getTimestamp(),'The stored instant round-trips');
        date_default_timezone_set('Asia/Tokyo');
        same($at->getTimestamp(),$a->fresh()->created_at->getTimestamp(),'A later site zone cannot reinterpret UTC');
        date_default_timezone_set('America/Los_Angeles');
    }
}finally{date_default_timezone_set($prior);}
$nativeSuccessful=true;echo "Watch audit UTC instants, DST, mixed rows and conservative retained upgrade.\n";

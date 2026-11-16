<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline staff capture of a stuck renewal request and its domain, synthetic records.
declare(strict_types=1);
require __DIR__.'/staff-domain-edits.php';
require_once __DIR__.'/panel-render.php';
require_once __DIR__.'/render-artifacts.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
$out=$argv[1]??'';expect(is_dir($out),'Render output exists');
config(['domains.time_zone'=>'America/Los_Angeles']);
$staff=staffUser([...array_keys(Staff::permissions()),'admin.invoices.view']);Auth::setUser($staff);
panelTheme(getenv('PAYMENTER_TEST_FIXTURE'));
$now=$clock->now()->getTimestamp();
// A paid renewal sent a day ago with no confirmed result: past its lease, with the next automatic check scheduled.
$renewal=$d->operations()->firstOrFail()->replicate();
$renewal->fill(['operation_key'=>'request-review-render','action'=>'renew','state'=>'uncertain','funding_basis'=>'paid_invoice',
    'started_at'=>$clock->now()->modify('-1 day'),'lease_expires_at'=>$clock->now()->modify('-23 hours'),
    'attempt'=>['started_at'=>$now-86400,'dispatch_attempted_at'=>gmdate('c',$now-86400)],
    'policy'=>['status_check'=>['outcome'=>'checked','at'=>$now-1800,'next_at'=>$now+1800,'reason'=>null]]])->save();
writePanelPage($out.'/domain-delayed.html',Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id]));
writePanelPage($out.'/request-delayed.html',Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$renewal->id]));
// Checks stopped without a clear answer: the domain waits for staff to record Enom's answer.
$renewal->update(['policy'=>['status_check'=>['outcome'=>'checked','at'=>$now-1800,'next_at'=>null,'reason'=>null]]]);
writePanelPage($out.'/domain-under-review.html',Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id]));
$nativeSuccessful=true;echo "Staff request review pages rendered offline.\n";

<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline staff capture of a delayed renewal's domain with one open account hold, synthetic records.
declare(strict_types=1);
// Look evidence only: skin-render sets both, and no other runner may load this capture.
if(!is_file((string)getenv('DOMAINS_PANEL_SETUP'))||(string)getenv('SKIN_RENDER_MARKER')===''){fwrite(STDERR,"This capture runs only through skin-render.\n");exit(1);}
require __DIR__.'/staff-domain-edits.php';
require_once __DIR__.'/panel-render.php';
require_once __DIR__.'/render-artifacts.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview;
use Paymenter\Extensions\Servers\Domains\Money\Returns\DisputeRecoveries;
$out=$argv[1]??'';expect(is_dir($out),'Render output exists');
config(['domains.time_zone'=>'America/Los_Angeles']);
$staff=staffUser([Staff::VIEW,'admin.domains.manage',Staff::POLICY,'admin.invoices.update',
    ...array_map(fn($a)=>StaffOperationReview::permission($a),['recheck','resume_reads','mark_failed','release'])]);Auth::setUser($staff);
panelTheme(getenv('PAYMENTER_TEST_FIXTURE'));
$now=$clock->now()->getTimestamp();
// The request review page's delayed renewal, whose paid invoice later lost $10.99 to a bank dispute after $6.00 of it was returned as credit, $2.00 of which is unspent.
$renewal=$d->operations()->firstOrFail()->replicate();
$renewal->fill(['operation_key'=>'billing-hold-render','action'=>'renew','state'=>'uncertain','funding_basis'=>'paid_invoice',
    'started_at'=>$clock->now()->modify('-1 day'),'lease_expires_at'=>$clock->now()->modify('-23 hours'),
    'attempt'=>['started_at'=>$now-86400,'dispatch_attempted_at'=>gmdate('c',$now-86400)],
    'funding'=>[...($renewal->funding??[]),'dispute_holds'=>[['dispute_id'=>'synthetic_render_dispute','lost_minor'=>1099,'owed_minor'=>600,'removed_minor'=>200,'remainder_minor'=>400]]],
    'policy'=>['status_check'=>['outcome'=>'checked','at'=>$now-1800,'next_at'=>$now+1800,'reason'=>null]]])->save();
DB::table('domains_obligation_alerts')->insert(['alert_key'=>DisputeRecoveries::key('account_money_hold','synthetic_render_dispute'),'domain_id'=>$d->id,
    'operation_id'=>$renewal->id,'generation'=>$renewal->authority_generation,'code'=>'account_money_hold','first_seen'=>$now-3*86400,'last_seen'=>$now-3*86400]);
writePanelPage($out.'/domain-billing-hold.html',Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id]));
$nativeSuccessful=true;echo "Staff domain page with an account hold rendered offline.\n";

<?php
// Native notification fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/reconciliation-fixture.php';
require_once __DIR__.'/staff-panel.php';
use Illuminate\Support\Facades\{Mail,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Operation,Price};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling,HolderPricing,NativeDomainGuard,ObservationSchema,ReminderSchema,ReminderSchedules,ReminderTemplates,Reminders};
require __DIR__.'/reminder-mail.php';
$guard=new NativeDomainGuard(new HolderPricing($prices),$clock);$guard->boot();$billing=new DomainBilling($bindings,$guard,$clock);
$reminders=new Reminders($clock,$bindings);$staff=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::POLICY]);
foreach($tlds as $t){ReminderSchedules::enroll((int)$t->server_id);}
$enable=static function(int $i=0,string $before='60,30,7,1',string $after='3')use($staff,$tlds):void {
    $r=Illuminate\Support\Facades\DB::table('domains_reminder_schedules')->where('server_id',$tlds[$i]->server_id)->first();
    ReminderSchedules::save($staff,(int)$tlds[$i]->server_id,(int)$r->revision,true,$before,$after,'test_schedule');
};
$make=static function(string $name,bool $invoice=true,int $years=1)use($prepare,$reconcile,$clock,$billing,$product):array {
    foreach(Price::where('action','renew')->get() as $p){$p->update(['revision'=>$p->revision+1,'observed_at'=>new DateTimeImmutable('2026-10-18T12:00:00Z'),'fresh_until'=>new DateTimeImmutable('2026-10-19T12:00:00Z')]);}
    [$source]=$prepare($name);same('succeeded',$reconcile->run($source->id)->state,'Real registration completion anchor');
    $d=$source->domain()->firstOrFail();$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
    $product->server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
    $bill=$invoice?$billing->invoice((int)$d->id,$d->service->user,$years):null;
    // Refresh synthetic saved rates only; reminder processing must never refresh a provider.
    foreach(Price::where('action','renew')->get() as $p){$p->update(['revision'=>$p->revision+1,'observed_at'=>$clock->now(),'fresh_until'=>$clock->now()->modify('+100 days')]);}
    $clock->instant=new DateTimeImmutable('2027-10-11T12:00:00Z');
    return [$d->fresh(),$bill,$bill?Operation::where('invoice_id',$bill->id)->sole():null];
};

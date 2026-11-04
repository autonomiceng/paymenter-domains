<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/offer-activation-test.php';$nativeSuccessful=false;
require_once __DIR__.'/Support/offer-generation.php';
use App\Models\{Role,User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,StaffSettingsReview};
$role=Role::create(['name'=>'Synthetic sync staff','permissions'=>[Staff::VIEW,Staff::POLICY,StaffSettingsReview::permission('import')]]);
$actor=User::create(['first_name'=>'Synthetic','last_name'=>'Staff','email'=>'queued-sync@example.test','password'=>'synthetic-password','role_id'=>$role->id]);
$b=AccountBindings::binding(Account::where('registrar_key','enom')->sole());
$job=new SyncPrices($actor->id,$b->serverId,$b->fingerprint);advanceOfferGeneration();$before=$reads->calls;
$job->handle();same($before,$reads->calls,'Superseded delivery never reads the provider');
same("Couldn't sync prices",App\Models\Notification::where('user_id',$actor->id)->latest('id')->value('title'),'Stale queued work reports refusal');
$job=new SyncPrices($actor->id,$b->serverId,$b->fingerprint);$armed=true;
DB::listen(static function($event)use(&$armed):void {
    if($armed&&str_contains($event->sql,'domains_accounts')&&str_starts_with(strtolower($event->sql),'select')){$armed=false;advanceOfferGeneration();}
});
$job->handle();same(false,$armed,'Race crosses the real delivery account lookup');
same($before,$reads->calls,'Generation change after delivery validation cannot reach provider');
same(UpgradeGate::generation(),(new SyncPrices($actor->id,$b->serverId,$b->fingerprint))->upgradeGeneration,'Next job captures the current generation');
$nativeSuccessful=true;

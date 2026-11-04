<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/installed-module-routing.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Context,OfferReadiness,Query};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,Rdap,TransferPrecheck};
extract(commerceFixture(true,static fn()=>null));
$staff=staffUser(array_keys(\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()));Auth::setUser($staff);
[$prices,$native,$sellers]=installedModuleRouting($bindings,$clock,$ports,$clock,new TransferPrecheck(new Rdap(static fn()=>throw new LogicException('No provider reads.'))));
$account=Account::where('server_id',$tlds[0]->server_id)->sole();$binding=AccountBindings::binding($account);
$bindings->setProcessing($binding->serverId,false);
$ready=OfferReadiness::routed();$features=['registration'=>true,'renewal'=>false,'transfer'=>false];
$review=$ready->review($account->fresh(),$features);
same(null,$review['missing'],'An otherwise ready paused account can review activation before Orders on');
same($review,DB::transaction(fn()=>$ready->review($account->fresh(),$features,true)),'Locked readiness uses the same paused account premise');
$public=CallerPorts::routed()->readOffers(new Query($binding,['com'],'register'),Context::Public)[0];
expect(!$public->decision->available,'Paused readiness cannot grant a public purchase');
\App\Models\Server::whereKey($binding->serverId)->update(['enabled'=>false]);
expect($ready->review($account->fresh(),$features)['missing']!==null,'Readiness still checks the current server and credential prefix');
same(0,$reads->calls,'Readiness checks are local only');
$nativeSuccessful=true;
echo "Installed paused offer readiness: ok\n";

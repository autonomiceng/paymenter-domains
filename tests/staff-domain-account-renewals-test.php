<?php
// A billed domain on an account with Renewals off says Off on its page and in the list, as the account page does.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainDecisions,DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings;
staffPanel();[$held,$active]=staffData();Paymenter\Extensions\Servers\Domains\Services\HoldingProvenanceSchema::install();Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
$viewer=staffUser([Staff::VIEW]);Auth::setUser($viewer);
$switch=function(bool $on)use($active){
    $server=App\Models\Server::findOrFail($active->server_id);
    foreach([RenewalBillingSettings::SCHEDULED,RenewalBillingSettings::MANUAL] as $key){$server->settings()->updateOrCreate(['key'=>$key],['value'=>$on?'1':'0','type'=>'boolean','encrypted'=>false]);}
};
$cell=function()use($viewer,$active){
    $page=Livewire::actingAs($viewer)->test(ListDomains::class)->assertSuccessful()->instance();
    $c=$page->getTable()->getColumn('auto_renew')->record($page->getTableRecords()->firstWhere('id',$active->id));
    return [$c->getState(),$c->getDescriptionBelow()];
};
DB::table('domains_domains')->where('id',$active->id)->update(['billing_managed'=>true]);
$switch(true);
same('On',DomainFacts::for($active->fresh())['Renewal']['value'],'Renewals on: the domain renews');
same(['On',null],$cell(),'Renewals on: the list says On');
// The account Renewals switch off: no renewal invoice goes out, so neither page may say On.
$switch(false);
$row=DomainFacts::for($active->fresh())['Renewal'];
same(['Off',DomainDecisions::ACCOUNT_RENEWALS_OFF],[$row['value'],$row['sub']],'Renewals off on the account: the domain page says Off and why');
expect(!isset(DomainFacts::for($active->fresh())['Next invoice']),'No next invoice date while the account sends none');
same(['Off','Account Renewals off'],$cell(),'Renewals off on the account: the list says Off');
// A domain we do not bill keeps its own state: the switch only stops billed renewals.
DB::table('domains_domains')->where('id',$active->id)->update(['billing_managed'=>false]);
same('On',DomainFacts::for($active->fresh())['Renewal']['value'],'An unbilled domain is not held by the Renewals switch');
echo "Account renewals state passed.\n";
$nativeSuccessful=true;

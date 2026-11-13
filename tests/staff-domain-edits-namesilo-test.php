<?php
// One staff contact save uses the same durable sequence as the owner.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
require __DIR__.'/Support/owner-pages.php';
require __DIR__.'/Support/staff-panel.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\{Auth,Cache};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ManagementForms,OwnedDomains,OwnerPorts};
staffPanel();storefrontViews();
[$d,$driver,$original]=$holding('contacts');$contact=$original->payload['contacts']['registrant'];
$ports=new OwnerPorts(fn($b)=>$driver);
app()->instance(OwnerPorts::class,$ports);app()->instance(OwnedDomains::class,new OwnedDomains($clock,$ports,$management));app()->instance(DomainManagement::class,$management);
$staff=staffUser([Staff::VIEW,'admin.domains.manage']);Auth::setUser($staff);
require __DIR__.'/Support/staff-site-nameservers.php';
$contactCallOffset=count($ms->calls);
$writes=static fn()=>array_values(array_filter(array_slice($ms->calls,$contactCallOffset),fn($row)=>!in_array($row[0],['getDomainInfo','contactList','listRegisteredNameServers'],true)));
$edit=static fn(string $action,array $data=[])=>Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])
    ->callAction(TestAction::make('edit_'.$action)->schemaComponent('relationManagerTabs.sheet','content'),$data);
$last=static fn(string $action)=>$d->operations()->where('action',$action)->orderByDesc('id')->first();
$checkProgress=static function(string $text,bool $warning=false)use(&$d,$ports,$management,$clock,$user,$staff,$ms):void {
    storefrontViews();$before=$ms->calls;$owners=new OwnedDomains($clock,$ports,$management);
    $request=\Illuminate\Http\Request::create('/domains/'.$d->id);$request->setUserResolver(fn()=>$user);$request->setLaravelSession(app('request')->session());
    $controller=new \Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners);
    $row=$controller->status($request,$d->id)->getData(true)['contacts'];same($text,$row['text'],'Owner polling describes retained sequence progress');
    same($warning,($row['tone']??null)==='warning','Owner polling retains the warning tone');
    $owner=storefrontDom($controller->show($request,$d->id)->render());
    $status=$owner->query('//li[@id="contacts"]//*[@role="status"]')->item(0);same($text,$status?->textContent,'Owner page shows the shared words');
    same($warning,str_contains($status->getAttribute('class'),'domains-tone-text-warn'),'Owner support status is amber');
    $page=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id]);same($row,$page->instance()->managementProgress()['contacts'],'Staff and owner share all progress facts');
    expect(str_contains(html_entity_decode($page->html(),ENT_QUOTES|ENT_HTML5),$text),'Staff page shows the shared words');
    if($warning){$warn=storefrontDom($page->html())->query('//div[@data-domains-row][.//*[@data-domains-tone="warn"]]');expect((bool)array_filter(iterator_to_array($warn),fn($node)=>str_contains($node->textContent,$text)),'Staff support status is amber');}
    same($before,$ms->calls,'Neither page nor polling calls the registrar');
};

$edit('contacts',$contact)->assertNotified(ManagementForms::REQUESTED);
$op=$last('contacts');same((int)$staff->id,(int)$op->authorized_by,'One staff save owns the contact change');
$stage=$last('contact_profile');same((int)$staff->id,(int)$stage->authorized_by,'The automatic prerequisite retains the staff actor');
same(['all',$contact],[$op->payload['role'],$op->payload['contact']],'The request freezes the owner contact shape');
same('queued',$management->run($op->id,$driver)->state,'Creation finishes before association');
$partial='Contact details saved. Applying them to the domain.';
same($partial,\Paymenter\Extensions\Servers\Domains\Services\ManagementProgress::for($d)['contacts']['text'],'Shared progress shows the verified prerequisite awaiting association');
$checkProgress($partial);
Cache::flush();
same('succeeded',$management->run($op->id,$driver)->state,'The same queue completes the staff change');
same(['contactAdd','contactDomainAssociate'],array_column($writes(),0),'One grant per automatic step');
$checkProgress('Contact details verified.');
// A modal opened against older stored contacts cannot silently overwrite a later save.
$open=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->mountAction(TestAction::make('edit_contacts')->schemaComponent('relationManagerTabs.sheet','content'));
$edit('contacts',[...$contact,'first_name'=>'Bea'])->assertNotified(ManagementForms::REQUESTED);Cache::flush();
$new=$last('contacts');$management->run($new->id,$driver);Cache::flush();same('succeeded',$management->run($new->id,$driver)->state,'Newer saved contacts complete');
$before=$d->operations()->count();$open->callMountedAction()->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't save this change")->body('This changed after you opened it. Reload the page and try again.'));
same($before,$d->operations()->count(),'A stale contact modal queues no sequence');
// Lost original creation receipt needs a person, with the same amber words on both pages.
[$d,$driver,$original]=$holding('progress-lost');
$unknown=$submit($d,$driver,'contacts',['role'=>'all','contact'=>$original->payload['contacts']['registrant']]);$ms->fault='lost';
$management->run($unknown->id,$driver);
$checkProgress("We couldn't confirm the new contact details. Nothing else was changed. Contact support and we'll sort it out.",true);
echo "Staff automatic contacts passed.\n";$nativeSuccessful=true;

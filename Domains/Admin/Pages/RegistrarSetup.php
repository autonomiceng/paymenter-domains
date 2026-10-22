<?php
// Adapted from Enom 444f31c Admin/Pages/EnomSetup; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use App\Models\Server;
use Filament\Pages\Page;
use Filament\Schemas\{Schema,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts,SettingsActions};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\ContactDetails;
class RegistrarSetup extends Page
{
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-cog-6-tooth';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?string $title='Registrar setup';
    protected static ?string $slug='registrar-setup';
    public static function canAccess(): bool {return Staff::can();}
    public array $reviewStamps=[];
    public function mount(): void
    {Staff::requireView();foreach(Server::where('extension','Domains')->get() as $s){$this->reviewStamps[$s->id]=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::stamp((int)$s->id,null);}}
    public function content(Schema $schema): Schema
    {
        Staff::requireView();
        $site=['Renewal safety'=>'Keep both registrar accounts serviced. Seller selection affects new sales only.',
            'Adoption readiness'=>"Off. Deleting a customer during checkout can remove their new domain's Paymenter order and service, today as well. Affected domains appear under Missing Paymenter records on each account. Adoption stays off until a later release closes this gap. Before switching it on, let in-progress edits finish, then restart web and queue workers. Direct database edits bypass these checks.",
            'Credentials'=>'This page does not display saved credentials. Treat staff with server-edit permission as able to access them. Saving credentials pauses processing until you resume it.',
            ...\Paymenter\Extensions\Servers\Domains\Services\RuntimeHealth::site()];
        $missing=ContactDetails::missingRequiredProperties();
        if($missing){$site['Contact properties missing']='Missing user contact properties: '.implode(', ',$missing).'. Registrations and transfers are held until customers can enter them. Configure these user properties in Paymenter under Configuration → Custom Properties.';}
        $entries=[];foreach($site as $label=>$value){$entries[]=TextEntry::make('site_'.count($entries))->label($label)->state($value);}
        $sections=[Section::make('All registrar accounts')->columns(2)->schema($entries)->columnSpanFull()];
        $servers=Server::where('extension','Domains')->orderBy('id')->get();
        foreach($servers as $s){
            $a=Account::where('server_id',$s->id)->first();
            $facts=['Environment'=>$a?->environment==='live'?'Live':($a?'TEST':'Not configured'),
                'Processing'=>$a?->processing_enabled?(app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class)->configurationAvailable((int)$s->id)?'On':'On hold'):'Off',
                'Catalogue acceptance'=>$a?TldFacts::acceptance($a):'Unavailable · new sales held',
                'Connection'=>app(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites::class)->connectionAvailable($a?->registrar_key)?'Not tested this visit':'Unavailable','Retained domains'=>$a?\Paymenter\Extensions\Servers\Domains\Models\Domain::where('server_id',$s->id)->count():0];
            $facts += $a?->registrar_key==='namesilo'
                ?app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class)->sandboxFacts($a)
                :['Account binding'=>$a?'Verified · immutable':'Not configured',
                    'Verified user ID'=>$a?->registrar_key==='enom'&&config('domains.enom_identity.environment')===$a->environment?$a->stable_identity:'Unavailable'];
            if ($a?->registrar_key==='enom' && $a->environment==='test') { $facts['Enom TEST daily calls']=\Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::facts(); }
            if ($a?->registrar_key==='enom' && $a->environment==='test' && config('domains.enom_writes.registration')===true) {
                $facts['Enom TEST registration']=\Paymenter\Extensions\Servers\Domains\Services\EnomTestRegistration::reason();
            }
            if($a?->registrar_key==='enom'&&$a->environment==='test'&&config('domains.enom_writes.transfer')===true){
                $facts['Enom TEST inbound transfer']=\Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::reason();
            }
            if($a?->registrar_key==='enom'&&$a->environment==='test'&&config('domains.enom_writes.renewal')===true){
                $facts['Enom TEST renewal']=\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::reason();
                try{$facts['TEST renewal acceptance']=\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::accepted(\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($a))?'Accepted':'Not accepted';}
                catch(\Throwable){$facts['TEST renewal acceptance']='Unavailable';}
            }
            if($a?->registrar_key==='enom'&&$a->environment==='test'&&config('domains.enom_writes.management')===true){
                $facts['Enom TEST management']=\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::reason();
                try{$facts['TEST management acceptance']=\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::accepted(\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($a))?'Accepted':'Not accepted';}
                catch(\Throwable){$facts['TEST management acceptance']='Unavailable';}
            }
            $facts += \Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::facts((int)$s->id);
            $facts += \Paymenter\Extensions\Servers\Domains\Services\RuntimeHealth::for((int)$s->id);
            $facts += \Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::schedule((int)$s->id);
            $entries=[];foreach($facts as $label=>$value){$entries[]=TextEntry::make('s'.$s->id.'_'.count($entries))->label($label)->state((string)$value)->badge($label==='Environment');}
            $sections[]=Section::make($s->name)
                ->key('account_'.$s->id)->description(($a?TldFacts::registrar($a->registrar_key):'Registrar').' · Server #'.$s->id.' · '.($s->enabled?'Viewing uses saved local facts.':'This server is off in Paymenter.'))
                ->columns(2)->schema([...$entries,\Filament\Schemas\Components\Actions::make([...SettingsActions::make((int)$s->id,null,$this->reviewStamps[$s->id]??'',true),\Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderAction::make((int)$s->id)])->columnSpanFull()])->columnSpanFull();
        }
        if($servers->isEmpty()){$sections[]=Section::make('No registrar accounts')->description('Create a Domains server before configuring a registrar account.');}
        return $schema->components($sections);
    }
}

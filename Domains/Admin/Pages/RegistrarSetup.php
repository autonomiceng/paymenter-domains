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
        Staff::requireView();$sections=[];
        foreach(Server::where('extension','Domains')->orderBy('id')->get() as $s){
            $a=Account::where('server_id',$s->id)->first();
            $facts=['Environment'=>$a?->environment==='live'?'Live':($a?'Test':'Not configured'),
                'Processing'=>$a?->processing_enabled?(app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class)->configurationAvailable((int)$s->id)?'On':'On hold'):'Off',
                'Account binding'=>$a?'Verified · immutable':'Not configured',
                'Catalogue acceptance'=>$a?TldFacts::acceptance($a):'Unavailable · new sales held',
                'Connection'=>app(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites::class)->connectionAvailable()?'Not tested this visit':'Unavailable','Retained domains'=>$a?\Paymenter\Extensions\Servers\Domains\Models\Domain::where('server_id',$s->id)->count():0,
                'Renewal safety'=>'Keep both registrar accounts serviced. Seller selection affects new sales only.',
                'Adoption readiness'=>'Off. Staff deleting a customer while that customer is checking out can remove the new domain’s Paymenter order and service. This can happen now, with adoption off. Affected domains appear under Missing Paymenter records. Adoption stays off until a later release closes this gap. Before any activation, let in-progress customer, order and service edits finish, then restart web and queue workers. Direct database edits bypass these checks.',
                'Credentials'=>'This page does not display saved credentials. Treat staff with server-edit permission as able to access them. Saving credentials pauses processing until you resume it.'];
            $facts += \Paymenter\Extensions\Servers\Domains\Services\RuntimeHealth::for((int)$s->id);
            $facts += \Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::schedule((int)$s->id);
            $entries=[];foreach($facts as $label=>$value){$entries[]=TextEntry::make('s'.$s->id.'_'.count($entries))->label($label)->state((string)$value);}
            $sections[]=Section::make(($a?TldFacts::registrar($a->registrar_key):'Registrar').' · account #'.$s->id)
                ->key('account_'.$s->id)->description($s->enabled?'Viewing uses saved local facts.':'This server is off in Paymenter.')
                ->columns(2)->schema([...$entries,\Filament\Schemas\Components\Actions::make([...SettingsActions::make((int)$s->id,null,$this->reviewStamps[$s->id]??'',true),\Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderAction::make((int)$s->id)])->columnSpanFull()])->columnSpanFull();
        }
        return $schema->components($sections?:[Section::make('No registrar accounts')->description('Create a Domains server before configuring a registrar account.')]);
    }
}

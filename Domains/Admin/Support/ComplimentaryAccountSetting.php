<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccountChanged,ComplimentaryAccounts,HookRegistration};
/** The domain price line under the native customer editor's title. Public heading hook only; all writes stay extension-owned. */
final class ComplimentaryAccountSetting extends Component implements HasActions,HasSchemas
{
    use InteractsWithActions,InteractsWithSchemas;
    public const NAME='domains-complimentary-account';
    #[Locked] public int $customer;
    /** The setting row the open confirm showed, so its submit only applies over what staff saw. */
    #[Locked] public int $seen=0;
    public static function boot():void
    {
        if(!HookRegistration::claim(FilamentView::getFacadeRoot(),self::class)){return;}
        Livewire::component(self::NAME,self::class);
        $scope=\App\Admin\Resources\UserResource\Pages\EditUser::class;
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_HEADER_HEADING_AFTER,static function()use($scope):string {
            $page=Livewire::current();
            if(!$page instanceof $scope||!self::allowed()){return '';}
            $id=(int)$page->getRecord()->id;
            return Livewire::mount(self::NAME,['customer'=>$id],self::NAME.'-'.$id);
        },scopes:[$scope]);
    }
    public static function allowed():bool {return Auth::user() instanceof User&&(bool)User::with('role')->find(Auth::id())?->hasPermission('admin.domains.fund');}
    /** Permission first, then the customer: staff without it learn nothing about who exists. */
    private function ready():bool {return self::allowed()&&User::whereKey($this->customer)->exists();}
    public function render():View
    {
        $allowed=$this->ready();$setting=$allowed?ComplimentaryAccounts::current($this->customer):null;
        return view('domains::admin.complimentary-account',['allowed'=>$allowed,'on'=>(bool)($setting['enabled']??false),'last'=>$setting?self::lastChange($setting):null]);
    }
    public function turnOnAction():Action
    {
        return $this->change('turnOn',true)->label('Make complimentary')->modalHeading('Make domains complimentary?')
            ->modalDescription(function():string {$name=User::find($this->customer)?->name;
                return self::payer().' for new registrations, transfers and renewals'.($name?' for '.$name:'').'. Hosting is billed as usual.';})
            ->modalSubmitActionLabel('Make complimentary');
    }
    public function turnOffAction():Action
    {
        return $this->change('turnOff',false)->label('Turn off')->modalHeading('Turn off complimentary domains?')
            ->modalDescription('Orders and renewals from before now stay covered. New registrations, transfers and renewals use standard prices.')
            ->modalSubmitActionLabel('Turn off');
    }
    private function change(string $name,bool $enabled):Action
    {
        return Action::make($name)->color('gray')->outlined()->size('sm')->modalWidth('md')->authorize(fn()=>self::allowed())
            ->visible(fn()=>$this->ready()&&(bool)(ComplimentaryAccounts::current($this->customer)['enabled']??false)!==$enabled)
            ->schema([TextInput::make('reason')->label('Reason')->required()->maxLength(500)->helperText(fn()=>ComplimentaryAccounts::nativeAuditAvailable()?'Shown on this page and in the audit log.':'Native audit logging is off. The reason is retained in Domains history on this page.')])
            ->mountUsing(function(?Schema $schema):void {$this->seen=(int)(ComplimentaryAccounts::current($this->customer)['id']??0);$schema?->fill();})
            ->action(function(array $data)use($enabled):void {
                try{ComplimentaryAccounts::set($this->customer,Auth::user(),$enabled,$data['reason'],app(Clock::class),$this->seen);}
                catch(ComplimentaryAccountChanged $e){Notification::make()->danger()->title($e->getMessage())->body('Reload the customer and check the current setting.')->send();}
                catch(\DomainException $e){Notification::make()->danger()->title($e->getMessage())->send();}
            });
    }
    /** "Example Hosting pays", from the store's company name in settings; "We pay" when the store has none. */
    private static function payer():string
    {
        $company=DnsSignalLabels::company();return $company!==''?$company.' pays':'We pay';
    }
    /** "Turned on by X, <time>. Reason: Y"; the actor's current display name, or Staff once they are gone. */
    private static function lastChange(array $setting):HtmlString
    {
        $by=User::find($setting['actor'])?->name?:'Staff';
        $at=view('domains::partials.moment',['at'=>\Carbon\CarbonImmutable::parse((string)$setting['at'],config('app.timezone','UTC'))->utc()])->render();
        return new HtmlString(e(($setting['enabled']?'Turned on by ':'Turned off by ').$by.', ').$at.e('. Reason: '.$setting['reason']));
    }
}

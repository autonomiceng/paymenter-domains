<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Hidden;
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
/**
 * Make domains complimentary / Make domain prices standard, in the native customer editor's header actions, through the
 * public header actions hook only; all writes stay extension-owned. A page that lists actions() in its own header (for
 * example inside a "..." menu) hosts them itself, and the hook then adds nothing.
 */
final class ComplimentaryAccountSetting extends Component implements HasActions,HasSchemas
{
    use InteractsWithActions,InteractsWithSchemas;
    public const NAME='domains-complimentary-account';
    #[Locked] public int $customer;
    public static function boot():void
    {
        if(!HookRegistration::claim(FilamentView::getFacadeRoot(),self::class)){return;}
        Livewire::component(self::NAME,self::class);
        // Scoped to the resource, so a page that replaces the native editor with a subclass still gets the actions.
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE,static function():string {
            $page=Livewire::current();
            if(!$page instanceof \App\Admin\Resources\UserResource\Pages\EditUser||!self::allowed()||self::hostedBy($page)){return '';}
            $id=(int)$page->getRecord()->id;
            return Livewire::mount(self::NAME,['customer'=>$id],self::NAME.'-'.$id);
        },scopes:[\App\Admin\Resources\UserResource::class]);
    }
    public static function allowed():bool {return Auth::user() instanceof User&&(bool)User::with('role')->find(Auth::id())?->hasPermission('admin.domains.fund');}
    /** Permission first, then the customer: staff without it learn nothing about who exists. */
    private static function ready(int $customer):bool {return self::allowed()&&User::whereKey($customer)->exists();}
    /** Whether the page already lists these actions in its own header, directly or in a menu. */
    private static function hostedBy(object $page):bool
    {
        if(!method_exists($page,'getCachedHeaderActions')){return false;}
        foreach($page->getCachedHeaderActions() as $a){foreach($a instanceof ActionGroup?$a->getFlatActions():[$a] as $one){if(in_array($one->getName(),['turnOn','turnOff'],true)){return true;}}}
        return false;
    }
    public function render():View {return view('domains::admin.complimentary-account',['allowed'=>self::ready($this->customer)]);}
    public function turnOnAction():Action {return self::actions(fn()=>$this->customer)[0];}
    public function turnOffAction():Action {return self::actions(fn()=>$this->customer)[1];}
    /**
     * The two actions, one visible at a time, for any host: this component, or a customer page that lists them in its header.
     * @param \Closure():int $customer the customer being edited
     * @return array{Action,Action}
     */
    public static function actions(\Closure $customer):array
    {
        return [
            self::change('turnOn',true,$customer)->label('Make domains complimentary')->modalHeading('Make domains complimentary?')
                ->modalDescription(function()use($customer):HtmlString {$name=User::find($customer())?->name;
                    return self::withLast(self::payer().' for new registrations, transfers and renewals'.($name?' for '.$name:'').'. Hosting is billed as usual.',$customer());})
                ->modalSubmitActionLabel('Make domains complimentary'),
            self::change('turnOff',false,$customer)->label('Make domain prices standard')->modalHeading('Make domain prices standard?')
                ->modalDescription(fn():HtmlString=>self::withLast('Orders and renewals from before now stay covered. New registrations, transfers and renewals use standard prices.',$customer()))
                ->modalSubmitActionLabel('Make domain prices standard'),
        ];
    }
    private static function change(string $name,bool $enabled,\Closure $customer):Action
    {
        return Action::make($name)->color('gray')->modalWidth('md')->authorize(fn()=>self::allowed())
            ->visible(fn()=>self::ready($customer())&&(bool)(ComplimentaryAccounts::current($customer())['enabled']??false)!==$enabled)
            ->schema([TextInput::make('reason')->label('Reason')->required()->maxLength(500)->helperText(fn()=>ComplimentaryAccounts::nativeAuditAvailable()?'Saved in the audit log.':'Native audit logging is off. The reason is kept in Domains history.'),
                // The setting row this confirm showed, so its submit only applies over what staff saw.
                Hidden::make('seen')])
            ->mountUsing(function(?Schema $schema)use($customer):void {$schema?->fill(['seen'=>(int)(ComplimentaryAccounts::current($customer())['id']??0)]);})
            ->action(function(array $data)use($enabled,$customer):void {
                try{ComplimentaryAccounts::set($customer(),Auth::user(),$enabled,$data['reason'],app(Clock::class),(int)($data['seen']??0));if(!ComplimentaryAccounts::nativeAuditAvailable()){Notification::make()->warning()->title('Domain pricing saved')->body('Native audit logging is off. The change and reason are retained in Domains history.')->send();}}
                catch(ComplimentaryAccountChanged $e){Notification::make()->danger()->title($e->getMessage())->body('Reload the customer and check the current setting.')->send();}
                catch(\DomainException $e){Notification::make()->danger()->title($e->getMessage())->send();}
            });
    }
    /** The confirm's sentence, then the last change on its own line when there is one. */
    private static function withLast(string $text,int $customer):HtmlString
    {
        $setting=ComplimentaryAccounts::current($customer);
        return new HtmlString(e($text).($setting?'<br><span class="domains-comp-last">'.self::lastChange($setting)->toHtml().'</span>':''));
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
        $at=view('domains::partials.moment',['at'=>ComplimentaryAccounts::changedAt($setting)])->render();
        return new HtmlString(e(($setting['enabled']?'Turned on by ':'Turned off by ').$by.', ').$at.e('. Reason: '.$setting['reason']));
    }
}

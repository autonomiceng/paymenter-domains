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
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,HookRegistration};
/** The domain price line under the native customer editor's title. Public heading hook only; all writes stay extension-owned. */
final class ComplimentaryAccountSetting extends Component implements HasActions,HasSchemas
{
    use InteractsWithActions,InteractsWithSchemas;
    public const NAME='domains-complimentary-account';
    #[Locked] public int $customer;
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
    public function render():View
    {
        $allowed=self::allowed();$setting=$allowed?ComplimentaryAccounts::current($this->customer):null;
        return view('domains::admin.complimentary-account',['allowed'=>$allowed,'on'=>(bool)($setting['enabled']??false),'last'=>$setting?self::lastChange($setting):null]);
    }
    public function turnOnAction():Action
    {
        $name=User::find($this->customer)?->name;
        return $this->change('turnOn',true)->label('Make complimentary')->modalHeading('Make domains complimentary?')
            ->modalDescription('Beco pays for new registrations, transfers and renewals'.($name?' for '.$name:'').'. Hosting is billed as usual.')
            ->modalSubmitActionLabel('Make complimentary');
    }
    public function turnOffAction():Action
    {
        $last=($s=ComplimentaryAccounts::current($this->customer))?self::lastChange($s):null;
        return $this->change('turnOff',false)->label('Turn off')->modalHeading('Turn off complimentary domains?')
            ->modalDescription(new HtmlString(($last?'<span class="domains-comp-was">'.$last->toHtml().'</span> ':'')
                .e('Beco still pays for what it already covers. New registrations, transfers and renewals use standard prices.')))
            ->modalSubmitActionLabel('Turn off');
    }
    private function change(string $name,bool $enabled):Action
    {
        return Action::make($name)->color('gray')->outlined()->size('sm')->modalWidth('md')->authorize(fn()=>self::allowed())
            ->visible(fn()=>(bool)(ComplimentaryAccounts::current($this->customer)['enabled']??false)!==$enabled)
            ->schema([TextInput::make('reason')->label('Reason')->required()->maxLength(500)])
            ->action(function(array $data)use($enabled):void {
                try{ComplimentaryAccounts::set($this->customer,Auth::user(),$enabled,$data['reason'],app(Clock::class));}
                catch(\DomainException $e){Notification::make()->danger()->title($e->getMessage())->send();}
            });
    }
    /** "Turned on by X, <time>. Reason: Y"; the actor's current display name, or Staff once they are gone. */
    private static function lastChange(array $setting):HtmlString
    {
        $by=User::find($setting['actor'])?->name?:'Staff';
        $at=view('domains::partials.moment',['at'=>\Carbon\CarbonImmutable::parse((string)$setting['at'],'UTC')])->render();
        return new HtmlString(e(($setting['enabled']?'Turned on by ':'Turned off by ').$by.', ').$at.e('. Reason: '.$setting['reason']));
    }
}

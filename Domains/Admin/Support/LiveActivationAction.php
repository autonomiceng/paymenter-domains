<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
use Filament\Actions\Action;
use Filament\Forms\Components\{Checkbox,Hidden};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{EnomLiveActivation,StaffSettingsReview as Review,StaffSettingsWrites};
/** A person must approve the spending summary; stored API keys are not spending approval. */
final class LiveActivationAction
{
    public static function offered(Account $a): bool
    {
        if(!in_array($a->registrar_key,['enom','namesilo'],true)||$a->environment!=='live'||!Staff::can(Staff::POLICY)||!Staff::can(Staff::COSTS)||!Staff::can(Review::permission('activate'))){return false;}
        try{AccountBindings::binding($a);return true;}catch(\Throwable){return false;}
    }
    public static function summary(Account $a,?array $features=null): HtmlString
    {
        try{
            $v=EnomLiveActivation::summary($a,$features);$v['retained_off']=self::retainedOff($a);
            return new HtmlString(view('domains::admin.live-activation',['summary'=>$v])->render());
        }catch(\Throwable $e){
            \Illuminate\Support\Facades\Log::warning('Domains activation summary failed.',['exception_class'=>$e::class]);
            return new HtmlString(e('Live setup can’t be read. Reload this page, then check System checks.'));
        }
    }
    /** Read only saved sales intent under this account binding; activation never clears these stops. */
    public static function retainedOff(Account $a): array
    {
        $defaults=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($a->catalogue_defaults);$off=[];
        foreach(\Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id',$a->server_id)->where('registrar_key',$a->registrar_key)->where('account_fingerprint',$a->account_fingerprint)->orderBy('tld')->cursor() as $t){
            $intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($t->catalogue_intent);
            $seller=\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',$t->tld)->where('server_id',$t->server_id)->where('registrar_key',$t->registrar_key)->where('account_fingerprint',$t->account_fingerprint)->first();
            if($seller&&($intent['sell']??$defaults['defaultSell']??true)&&!AccountChecklist::savedTransfers($t,['transfers'=>$defaults['defaultTransfers']??false])){$off[]='.'. $t->tld;}
        }
        return $off;
    }
    public static function make(Account $a,string $stamp): Action
    {
        $id=(int)$a->server_id;$binding=AccountBindings::binding($a);
        return Action::make('activate_live')->label('Activate')->visible(fn()=>self::offered($a))
            ->modalHeading('Activate this account?')->modalSubmitActionLabel('Activate selected actions')->modalWidth('4xl')
            ->schema(function()use($a,$id,$stamp,$binding):array {
                $fields=[Hidden::make('review_key')->default(fn()=>Review::ticket((int)Auth::id(),$id,null,'activate',$stamp)),Html::make(fn(Get $get)=>self::summary($a,array_combine(EnomLiveActivation::FEATURES,array_map(fn($feature)=>(bool)$get($feature),EnomLiveActivation::FEATURES))))];
                $defaults=EnomLiveActivation::defaults($a);
                foreach(['registration'=>'Registrations','renewal'=>'Renewals and renewal invoices','management'=>'DNS, nameservers and contacts','transfer'=>AccountChecklist::TRANSFER_LABEL] as $feature=>$label){
                    $fields[]=Checkbox::make($feature)->label($label)->live()->default($defaults[$feature])->disabled(!EnomLiveActivation::featureEnabled($binding,$feature))->dehydrated()
                        ->helperText($feature==='transfer'?'Experimental. Readiness checks still apply. Existing account and TLD Off choices stay Off.':null);
                }
                return $fields;
            })->action(function(array $data)use($id):void {
                try{
                    $r=new Review((int)Auth::id(),$id,null,'activate',(string)($data['review_key']??''),'Approved the Live spending summary.');
                    $body=SettingsActions::republished(app(StaffSettingsWrites::class)->run($r,[...$data,'confirmed'=>true]));
                    Notification::make()->status($body===null?'success':'warning')->title('Live features activated')->body($body)->send();
                }catch(\DomainException $e){Notification::make()->danger()->title('Could not activate')->body($e->getMessage())->send();}
                catch(\Throwable $e){\Illuminate\Support\Facades\Log::warning('Domains activation failed.',['exception_class'=>$e::class]);Notification::make()->danger()->title('Could not activate')->body('Reload the registrar account and check the missing setup.')->send();}
            });
    }
}

<?php
// Enom 444f31c pricing/setup action layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\{Action,ActionGroup};
use Filament\Forms\Components\{Checkbox,CheckboxList,Hidden,Select,Textarea,TextInput};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,EnomTestCalls,PricingRules,StaffSettingsReview as Review,StaffSettingsWrites};
final class SettingsActions
{
    public static function description(string $name): string
    {
        return match($name){
            'alert_review'=>'Record an independent check of a notification failure. A continuing failure reopens the alert; this action sends no message.',
            'delivery_review'=>'Record an independently checked notification outcome. This retains the original handoff claim and never sends the notification again.',
            'processing'=>'Registrar purchases and renewals can start for this account. Enable only after reviewing its accepted provider features and worker configuration.',
            'default_rule'=>'Changes the default for this account. Publish each affected TLD next. Existing invoices keep their prices.',
            'override'=>'Changes this TLD only. Publish prices next. Registration and transfer below cost are held; renewals below cost are billed. Both alert staff.',
            'remove_override'=>'Uses this account’s default rule. Publish prices next. Existing invoices keep their prices.',
            'publish'=>'Creates price revisions from saved costs and the current rule. It does not refresh costs or reprice issued invoices.',
            'tld_settings'=>'Narrow term bounds or pause new sales. Widening and restoring sales require explicit verification and current acceptance. Retained holders require annual renewal support. For Enom TEST, ineligible cart lines are removed.',
            'seller'=>'Selects the registrar for new sales only. Existing domains and renewal obligations keep their holding registrar. No automatic fallback. For Enom TEST, previous seller cart lines for this ending are removed.',
            'import'=>'Reads one accepted TEST ending from Enom and imports current costs and registry requirements. Publish prices and select a seller separately. Processing stays off; withdrawn registration, transfer and renewal support stay off. Costs expire 60 minutes after the read; re-import before continuing preview. An annual registration and renewal baseline takes five reads; each additional accepted term or transfer adds up to one. Existing TEST cart lines for this ending are removed.',
            'acceptance'=>'Accept only independently verified exact terms. For opted-in Enom TEST, also review and accept the Purchase route and request interval shown below. Any change holds new sales for every TLD on this account until a new import and removes its TEST cart lines. Retained holders keep accepted annual renewal support.',
            'history'=>'Accept only a verified complete USD history contract for this action. Revocation stops new reconciliation reads; saved attempts and financial evidence stay retained.',
            'configure'=>'Sends one account read to check the supplied credentials, then saves them encrypted. The verified account cannot change. Saving pauses processing until it is re-enabled.',
            'probe'=>'Reads the verified account balance once and saves it to Registrar balances. Does not enable processing, purchases or new sales.',
        };
    }
    public static function make(int $server,?int $tld,string $stamp,bool $setup=false): array
    {
        $out=[];$names=$setup?['configure','probe','acceptance','import','history','processing','delivery_review','alert_review']:['default_rule','override','remove_override','tld_settings','seller','publish'];
        $target=fn()=>\App\Models\Server::find($server)?->name ?? 'Server #'.$server;
        foreach($names as $name){
            $fields=[Hidden::make('review_key')->default(fn()=>Review::ticket((int)Auth::id(),$server,$tld,$name,$stamp))];
            if(in_array($name,['default_rule','override'],true)){
                $rule=static function()use($tld,$name,$server){try{return $tld&&$name==='override'?(new PricingRules)->rule(Tld::findOrFail($tld)):(new PricingRules)->defaultRule($server);}catch(\Throwable){return \Paymenter\Extensions\Servers\Domains\Services\PricingRule::DEFAULT;}};
                $fields[] = Select::make('mode')->label('Pricing mode')->options(['markup_percent'=>'Percentage markup','markup_amount'=>'Amount markup','fixed'=>'Fixed annual retail'])->required()->default(fn()=>$rule()['mode']);
                $fields[] = TextInput::make('markup')->label('Markup')->default(fn()=>$rule()['markup'])->required()->maxLength(12);
                $fields[] = Select::make('round_up')->label('Round up')->options(['none'=>'None','whole'=>'Whole dollar','half'=>'Half dollar','ending_99'=>'Ending .99'])->default(fn()=>$rule()['round_up'])->required();
                foreach(['register'=>'Registration','renew'=>'Renewal','transfer'=>'Transfer'] as $key=>$label){$fields[]=TextInput::make('fixed_'.$key)->label($label.' annual retail (USD)')->default(fn()=>$rule()['fixed'][$key])->maxLength(14);}
            }
            if($name==='tld_settings'){
                foreach(['min_years'=>'Minimum registration years','max_years'=>'Maximum registration years'] as $key=>$label){$fields[]=TextInput::make($key)->label($label)->integer()->minValue(1)->maxValue(10)->required()->default(fn()=>Tld::findOrFail($tld)->$key);}
                foreach(['enabled'=>'Catalogue enabled','register'=>'Registrations enabled','transfer'=>'Transfers enabled','renew'=>'Annual renewal catalogue support'] as $key=>$label){$column=match($key){'enabled'=>'enabled',default=>$key.'_enabled'};$fields[]=Checkbox::make($key)->label($label)->default(fn()=>Tld::findOrFail($tld)->$column);}
                $fields[]=\Filament\Schemas\Components\Text::make('Catalogue support gates new sales. It does not authorize registrar renewals.');
                $fields[]=Checkbox::make('verified')->label('I verified widened terms or restored sales against current acceptance');
            }
            if($name==='seller'){
                $policy=static fn()=>\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',Tld::findOrFail($tld)->tld)->first();
                $fields[]=Select::make('seller')->label('Seller account')->options(fn()=>Account::orderBy('server_id')->get()->mapWithKeys(fn($a)=>[$a->server_id=>TldFacts::registrar($a->registrar_key).' · account #'.$a->server_id])->all())->placeholder('No seller')->default(fn()=>$policy()?->server_id);
                $fields[]=Checkbox::make('register')->label('Allow registrations')->default(fn()=>$policy()?->register_enabled??false);$fields[]=Checkbox::make('transfer')->label('Allow transfers')->default(fn()=>$policy()?->transfer_enabled??false);
            }
            if($name==='import'){$fields[]=TextInput::make('ending')->label('Accepted TLD without leading dot')->required()->maxLength(63);}
            if($name==='acceptance'){
                $fields[]=TextInput::make('ending')->label('TLD without leading dot')->required()->maxLength(63);
                $fields[]=Checkbox::make('accept')->label('Accept this TLD (clear to revoke new sales)');
                $fields[]=CheckboxList::make('terms')->label('Independently verified registration terms')->options(array_combine(range(1,10),array_map(fn($n)=>$n.' '.($n===1?'year':'years'),range(1,10))))->columns(2);
                $fields[]=Checkbox::make('transfer')->label('Annual transfers verified');$fields[]=Checkbox::make('registry')->label('Registry requirements supported');
                $fields[]=Checkbox::make('verified')->label('I independently verified these exact terms and annual renewal support');
                $account=Account::where('server_id',$server)->first();
                if($account?->registrar_key==='enom'&&$account->environment==='test'&&config('domains.enom_writes.registration')===true){
                    $protocol=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance(\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($account));
                    $fields[]=Checkbox::make('test_registration_accepted')->label('I reviewed Enom TEST Purchase at '.$protocol['route'].' and its '.$protocol['interval_seconds'].'-second request interval');
                }
            }
            if($name==='history'){
                $fields[]=Select::make('history_action')->label('History action')->options(['register'=>'Registration','renew'=>'Renewal','transfer'=>'Transfer'])->required();
                $fields[]=Checkbox::make('accept')->label('Accept contract (clear to revoke)');
                $fields[]=TextInput::make('timezone')->label('Verified history timezone')->maxLength(64);
                $fields[]=TextInput::make('lag')->label('Maximum lag (seconds)')->integer()->minValue(0)->maxValue(86400);
                $fields[]=TextInput::make('retention')->label('Retention (seconds)')->integer()->minValue(86400)->maxValue(31536000);
                $fields[]=Checkbox::make('verified')->label('I verified complete, all-page USD history and these limits');
            }
            if($name==='alert_review'){$fields[]=TextInput::make('alert_id')->label('Notification check alert number')->integer()->minValue(1)->required();$fields[]=Checkbox::make('confirmed')->label('I independently checked this notification failure')->accepted()->required();}
            if($name==='delivery_review'){$fields[]=TextInput::make('delivery_id')->label('Notification claim number')->integer()->minValue(1)->required();$fields[]=Checkbox::make('confirmed')->label('I independently checked the outcome; retain this claim without resending')->accepted()->required();}
            if($name==='processing'){
                $fields[]=Hidden::make('enabled')->default(fn()=>!Account::where('server_id',$server)->value('processing_enabled'));
                $fields[]=Checkbox::make('confirmed')->label(fn()=>'I reviewed '.$target().' and confirm this change')->accepted()->required();
            }
            if($name==='configure'){
                $fields[]=Select::make('registrar')->label('Registrar')->options(['enom'=>'Enom','namesilo'=>'NameSilo'])->default(fn()=>Account::where('server_id',$server)->value('registrar_key'))->live()->required();
                $fields[]=Select::make('environment')->label('Environment')->options(['test'=>'TEST','live'=>'Live'])->default(fn()=>Account::where('server_id',$server)->value('environment')??'test')->required();
                foreach(['uid'=>'Enom user ID','token'=>'Enom API token','api_key'=>'NameSilo API key'] as $key=>$label){$fields[]=TextInput::make($key)->label($label)->password()->autocomplete('new-password')->maxLength(4096)->visible(fn($get)=>$get('registrar')===($key==='api_key'?'namesilo':'enom'))->required();}
            }
            $fields[]=Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(2)->rules(['regex:/\A[^\x00-\x1f\x7f]*\z/'])->helperText('Use one line. Do not include credentials.');
            $processing=fn()=>(bool)Account::where('server_id',$server)->value('processing_enabled');
            $cannotEnable=fn()=>$name==='processing'&&!$processing()&&(!Account::where('server_id',$server)->exists()||app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class)->configurationStatus($server)!=='matched');$label=$name==='processing'?fn()=>$processing()?'Pause processing…':'Enable processing…':Review::ACTIONS[$name];
            $out[]=Action::make($name.($setup?'_'.$server:''))->label($label)->color(fn()=>$name==='processing'&&$processing()?'danger':'gray')->modalWidth('lg')
                ->disabled(fn()=>$cannotEnable()||(in_array($name,['configure','probe'],true)&&!app(StaffSettingsWrites::class)->connectionAvailable()))
                ->tooltip(fn()=>$cannotEnable()?'Configure and verify this account first.':null)
                ->visible(fn()=>Staff::can(Staff::POLICY)&&Staff::can(Review::permission($name)))
                ->requiresConfirmation()->modalHeading($name==='processing'?fn()=>($processing()?'Pause':'Enable').' processing for '.$target().'?':$label)
                ->modalDescription($name==='processing'?fn()=>$processing()?'Stops new registrar work for this account. Domains, invoices and queued work are kept; requests already sent keep reconciling.':self::description($name):self::description($name))->modalSubmitActionLabel($name==='processing'?fn()=>$processing()?'Confirm pause':'Confirm enable':'Confirm')->schema($fields)
                ->action(function(#[\SensitiveParameter] array $data,$livewire)use($name,$server,$tld,$target){
                    try{$r=new Review((int)Auth::id(),$server,$tld,$name,(string)($data['review_key']??''),trim((string)($data['reason']??'')));
                        app(StaffSettingsWrites::class)->run($r,$data);$account=Account::where('server_id',$server)->first();
                        Notification::make()->success()->title($name==='processing'?($account->processing_enabled?'Processing enabled':'Processing paused').' for '.$target():'Change recorded')->body($name==='processing'?null:($name==='probe'?'Balance read saved. See Registrar balances.':'Reload this page before another change.'))->send();
                        if($name==='processing'){$livewire->redirect(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::getUrl());}
                    }catch(\Throwable $error){
                        $message=self::refusal($error,$name);
                        if(!$error instanceof \DomainException){\Illuminate\Support\Facades\Log::warning('Domains staff setting failed.',['action'=>$name,'exception_class'=>$error::class]);}
                        Notification::make()->danger()->title('Change refused')->body($message)->send();}
                    finally{$livewire->mountedActions=[];}
                });
        }
        return $out;
    }
    /** Staff text for a refused settings change; never echoes unrecognized exception text. */
    public static function refusal(\Throwable $error,string $name): string
    {
        return $error instanceof EnomTestCallLimit
                ?'Enom TEST request refused before sending. '.($name==='import'?'Earlier reads in this import may have been sent and counted. ':'')
                    .EnomTestCalls::refusedFacts()
                :match($error->getMessage()){'Processing state unchanged.'=>'Processing state is unchanged. Reload this page.',
                'Settings changed. Reload this page.'=>'Settings changed. Reload this page.',
                'Current staff permissions required.'=>'Your permission changed. Reload this page.',
                'Runtime acceptance and shared workers are required.'=>'Provider acceptance and shared cache, sessions and queue workers are required before enabling processing.',
                'Pause processing on every legacy Enom server before installing or enabling Domains.'=>'Pause processing on every legacy Enom server first.',
                'TEST catalogue reads are unavailable.'=>'TEST catalogue import is available only for Enom TEST accounts on a deployment that enables it. No provider call was made.',
                'Catalogue ending is not accepted.'=>'Accept this TLD before importing. No provider call was made.',
                'Catalogue import unavailable.'=>'Import did not complete. No prices changed; Enom TEST reads may have been made. Reload and check acceptance and permissions.',
                'Connection checks are unavailable.'=>'Connection checks are unavailable. No provider call was made.',
                'identity_credentials_refused'=>'Enom refused the user ID or API token. Check both for the selected environment.',
                'identity_unavailable'=>'Enom did not confirm this account. Nothing was saved. Try again later.',
                'Connection not verified.'=>'Enom did not confirm the balance. The last saved balance was kept.',
                'identity_environment_refused'=>'This environment is not enabled for Enom account reads on this site. No provider call was made.',
                'identity_account_changed'=>'These credentials belong to a different account. The saved account was kept.',
                'namesilo_identity_unavailable'=>'NameSilo identity verification is unavailable. This account cannot be connected.',
                'Review key used.'=>'This confirmation was already used. Reload this page.',
                'Review the Enom TEST Purchase route and request interval before accepting registration terms.'=>'Check the box confirming the Enom TEST Purchase route and request interval, then try again. Nothing was saved.',
                'Enom TEST Purchase route acceptance is unavailable.'=>'Enom TEST Purchase acceptance is unavailable on this deployment; contact the deployment administrator. Nothing was saved.',
                default=>'Reload and check current permissions, saved facts and acceptance.'};
    }
}

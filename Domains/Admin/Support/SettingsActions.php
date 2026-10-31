<?php
// Enom 444f31c pricing/setup action layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;
use Filament\Actions\{Action,ActionGroup};
use Filament\Forms\Components\{Checkbox,Hidden,Radio,Select,Textarea,TextInput};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,EnomTestSendLimit,EnomTestTransfer,CardFee,PriceCatalogue,PricingRule,PricingRules,StaffSettingsReview as Review,StaffSettingsWrites};
final class SettingsActions
{
    private const OUTCOME_NEXT='Open the account page before you try again. Orders paused: it saved, so check the label is yours. Not connected and not set up yet: nothing saved. Off in Paymenter: turn it on in Servers first. Anything else: don’t try again here. Check the site logs. Trying again may send another key check.';
    public const SAVED_OUTCOME_UNKNOWN='NameSilo accepted the key, but we can’t tell if the account saved. '.self::OUTCOME_NEXT;
    public const GENERIC_OUTCOME_UNKNOWN='We can’t tell if a key check was sent or if the account saved. '.self::OUTCOME_NEXT;
    private const ADMIN_ORDERS='This account can’t take orders on this site yet. See System checks. Nothing was saved.';
    private const NO_KEY_CHECK='No key check was sent and nothing was saved.';
    private const NOT_SENT='Nothing was sent to the registrar.';
    /** Each switch clicks straight through; the audit keeps who, when, before and after. Popups: spends money or stops sales. */
    private const SWITCHES=['processing','renewals','test_renewal','test_management','test_transfer','live_renewal','live_management','live_transfer','renew_early'];
    private const POPUPS=['processing'=>'orders','renewals'=>'renewals','test_renewal'=>'renewals','live_renewal'=>'renewals','test_transfer'=>'transfers','live_transfer'=>'transfers'];
    private const SWITCH_REASON='Changed with the on/off switch.';
    /** Switch names saved by a writer action with a name of its own. */
    private const SHARED=['renew_early'=>'renewal_billing','renewals'=>'renewal_billing'];
    /** Keep one snapshot per form, including empty arrays and absent rows. */
    private static function cached(\Closure $read): \Closure
    {
        $loaded=false;$value=null;
        return static function()use($read,&$loaded,&$value){
            if(!$loaded){$value=$read();$loaded=true;}
            return $value;
        };
    }
    /** The one plain sentence a settings dialog opens with. Detail lives in details(). */
    public static function description(string $name): string
    {
        return match($name){
            'renewal_billing'=>'Set when renewal invoices go out.',
            'unpaid_hold'=>'Set how long an unpaid new domain order keeps its name.',
            'card_fee'=>'Sets the estimated card fee for this account. Saving publishes new prices for TLDs whose pricing adds a percentage to cost and card fee. Other prices and issued invoices stay the same.',
            'default_rule'=>'Sets the pricing every TLD on this account uses unless it has its own. Saving publishes new prices for those TLDs at once. Issued invoices keep their prices.',
            'override'=>'Saving publishes these prices at once. Registrations and transfers below cost are not sold; renewals below cost are still billed. Both alert staff. Issued invoices keep their prices.',
            'remove_override'=>'Uses this account’s default pricing. Saving publishes the new prices at once. Issued invoices keep their prices.',
            'publish'=>'Publishes prices from saved costs and the current pricing. It does not refresh costs or change issued invoices.',
            'tld_settings'=>'Choose the years customers can buy and what is for sale.',
            'seller'=>'Choose the registrar for new orders. Existing domains stay where they are.',
            'import'=>'Gets current costs from the registrar for the TLDs this account sells.',
            'enroll_sandbox'=>'Checks the key with one balance read, then saves it. A key can only be used once, and you can’t change it later.',
            'configure'=>'Checks these credentials with one read, then saves them. Saving pauses orders until you resume them.',
            'probe'=>'Reads the balance once and shows it on this page.',
        };
    }
    /** The rest of what staff may want to know, behind the dialog's "?" helper. Null when the sentence says it all. */
    public static function details(string $name): ?string
    {
        return match($name){
            'unpaid_hold'=>'After this time the order closes and anyone can buy the name. Orders with a payment in progress stay open.',
            'renewal_billing'=>'Invoices go out from the issue day until the due day for domains with auto-renew on. They need orders on and a confirmed expiry date.',
            'tld_settings'=>'You can always offer fewer years or turn sales off. More years, or turning sales back on, must match this account’s setup. Change years and sales under What you sell. Renewals stay on while customers own domains with this TLD. On some test accounts, cart items that no longer fit are removed.',
            'seller'=>'Renewals stay with the registrar that holds the domain. If this one can’t sell, no other takes over. On some test accounts, this TLD’s cart items from the old registrar are removed.',
            'enroll_sandbox'=>'One read-only balance request goes to the NameSilo test system. The balance is not saved. NameSilo does not confirm who owns the key, so the label is your own note. Orders stay paused and no TLDs are chosen. The next background run may send one scheduled balance read, then at most one a day. If NameSilo refuses that read, it shows as Read failed. Test connection can send one more balance request. To change the key or label, use a different test key on a new registrar account. Domains, What you sell and requests stay with the old one.',
            'configure'=>'Credentials are saved encrypted. Once connected, the account can’t change.',
            'probe'=>'For a NameSilo test account, a good read shows the key still works. It does not show who owns it. This does not turn on orders or sales.',
            default=>null,
        };
    }
    /** One account setup action, keyed by its name (e.g. "processing"). */
    public static function setup(int $server,string $stamp,string $name): Action
    { return self::make($server,null,$stamp,true,[$name])[0]; }
    public static function make(int $server,?int $tld,string $stamp,bool $setup=false,?array $only=null): array
    {
        $out=[];$names=$setup?['enroll_sandbox','configure','probe','renewals','test_renewal','test_management','test_transfer','live_renewal','live_management','live_transfer','processing','renew_early','renewal_billing','unpaid_hold','default_rule','card_fee']:['override','tld_settings','seller'];
        if($only!==null){$names=array_values(array_intersect($names,$only));}
        $target=fn()=>\App\Models\Server::find($server)?->name ?? 'Server #'.$server;
        foreach($names as $id){
            $name=self::SHARED[$id]??$id;$switch=in_array($id,self::SWITCHES,true);$popup=isset(self::POPUPS[$id]);
            $fields=[Hidden::make('review_key')->default(fn()=>Review::ticket((int)Auth::id(),$server,$tld,$name,$stamp))];
            if(in_array($name,['default_rule','override'],true)){
                $rule=self::cached(static function()use($tld,$name,$server){try{return $tld&&$name==='override'?(new PricingRules)->rule(Tld::findOrFail($tld)):(new PricingRules)->defaultRule($server);}catch(\Throwable){return PricingRule::DEFAULT;}});
                $default=self::cached(static function()use($server){try{return (new PricingRules)->defaultRule($server);}catch(\Throwable){return null;}});
                $own=static fn($get)=>$name!=='override'||$get('pricing')!=='default';
                if($name==='override'){
                    // "Use the account default" saves as remove_override, so it keeps that permission and history label.
                    $fields[]=Hidden::make('default_key')->default(fn()=>Review::ticket((int)Auth::id(),$server,$tld,'remove_override',$stamp));
                    $fields[]=Radio::make('pricing')->hiddenLabel()->live()->required()
                        ->options(fn()=>array_filter(['default'=>'Use the account default ('.($default()===null?'not readable':PricingRule::describe($default())).')','custom'=>'Set prices for this TLD only'],
                            fn($key)=>Staff::can(Review::permission($key==='default'?'remove_override':'override')),ARRAY_FILTER_USE_KEY))
                        ->default(fn()=>((Tld::find($tld)?->use_default_rule??true)&&Staff::can(Review::permission('remove_override')))||!Staff::can(Review::permission('override'))?'default':'custom');
                }
                $fields[]=Select::make('mode')->label('Pricing')->options(['markup_percent'=>'Add a percentage to cost','markup_fee_percent'=>'Add a percentage to cost and card fee','markup_amount'=>'Add an amount to cost','fixed'=>'Set my own price'])->required()->live()->default(fn()=>$rule()['mode'])->visible($own);
                $fields[]=TextInput::make('markup')->label(fn($get)=>$get('mode')==='markup_amount'?'Amount to add (USD)':'Percentage to add')->default(fn()=>$rule()['markup'])->required()->maxLength(12)->live(debounce:500)
                    ->visible(fn($get)=>$own($get)&&$get('mode')!=='fixed');
                $fields[]=Select::make('round_up')->label('Round up')->options(PricingRule::ROUNDING_LABELS)->default(fn()=>$rule()['round_up'])->required()->live()
                    ->visible(fn($get)=>$own($get)&&$get('mode')!=='fixed');
                foreach(['register'=>'Register','renew'=>'Renew','transfer'=>'Transfer'] as $key=>$label){$fields[]=TextInput::make('fixed_'.$key)->label($label.' price per year (USD)')->default(fn()=>$rule()['fixed'][$key])->maxLength(14)->live(debounce:500)
                    ->visible(fn($get)=>$own($get)&&$get('mode')==='fixed');}
                $entered=static function($get)use($name,$default,$server):?array{
                    if($name==='override'&&$get('pricing')==='default'){return $default();}
                    try{return PricingRule::validate(['mode'=>$get('mode'),'markup'=>$get('markup')??'0','round_up'=>$get('round_up')??'none',
                        'fixed'=>['register'=>$get('fixed_register'),'renew'=>$get('fixed_renew'),'transfer'=>$get('fixed_transfer')],
                        ...($get('mode')==='markup_fee_percent'?['card_fee'=>CardFee::read($server)]:[])]);}
                    catch(\Throwable){return null;}
                };
                $fields[]=\Filament\Schemas\Components\Text::make(fn($get)=>($r=$entered($get))===null?'Enter prices to see what customers pay.':'Customer pays: '.PricingRule::describe($r))->key('formula');
                if($name==='override'){$fields[]=\Filament\Schemas\Components\Html::make(fn($get)=>self::preview($tld,$entered($get)))->key('preview');}
                else{$fields[]=\Filament\Schemas\Components\Text::make(fn()=>self::followers($server))->key('followers');}
            }
            if($name==='card_fee'){
                $fee=self::cached(static function()use($server):array{try{return CardFee::read($server);}catch(\Throwable){return CardFee::DEFAULT;}});
                $fields[]=TextInput::make('fee_percent')->label('Card fee (%)')->default(fn()=>$fee()['percent'])->required()->maxLength(6);
                $fields[]=TextInput::make('fee_fixed')->label('Plus a fixed amount (USD)')->default(fn()=>$fee()['fixed'])->required()->maxLength(12);
                $fields[]=\Filament\Schemas\Components\Text::make('An estimate per payment. International cards and refunds can cost more, so set it a little high.');
            }
            if($name==='tld_settings'){
                // Offer only what the saved account terms allow; the writer still checks every change.
                $allowed=self::cached(static fn()=>self::allowedTerms($server,$tld));
                $years=static function(string $bound)use($allowed):int{$a=$allowed();return $a===null?($bound==='min'?1:10):$a[$bound];};
                $fields[]=\Filament\Schemas\Components\Text::make(fn()=>($a=$allowed())===null?'':($a['register']===[]?'This account is not set up to sell new .'.$a['ending'].' registrations. Change sales under What you sell.':'This account is set up to sell .'.$a['ending'].' for '.self::years($a['register']).'. Change years under What you sell.'))->key('allowed')->visible(fn()=>$allowed()!==null);
                foreach(['min_years'=>'Minimum registration years','max_years'=>'Maximum registration years'] as $key=>$label){
                    $field=TextInput::make($key)->label($label)->integer()->minValue(fn()=>$years('min'))->maxValue(fn()=>$years('max'))->required()->default(fn()=>Tld::findOrFail($tld)->$key)
                        ->validationMessages(['min'=>fn()=>'Pick from '.$years('min').' to '.$years('max').'.','max'=>fn()=>'Pick from '.$years('min').' to '.$years('max').'.']);
                    // A failed rule keeps the dialog and its input open.
                    if($key==='max_years'){$field->rules([fn(\Filament\Schemas\Components\Utilities\Get $get)=>static function(string $attribute,mixed $value,\Closure $fail)use($get,$years):void{
                        if((int)$get('min_years')>(int)$value){$fail('The minimum is above the maximum. Pick from '.$years('min').' to '.$years('max').'.');}
                    }]);}
                    $fields[]=$field;
                }
                foreach(['enabled'=>'For sale','register'=>'Allow registrations','transfer'=>'Allow transfers','renew'=>'Allow renewals'] as $key=>$label){
                    $column=match($key){'enabled'=>'enabled',default=>$key.'_enabled'};
                    $field=Checkbox::make($key)->label($label)->default(fn()=>Tld::findOrFail($tld)->$column);
                    if(in_array($key,['register','transfer'],true)){
                        $refused=static fn()=>($a=$allowed())!==null&&!$a[$key.'_allowed']&&!Tld::findOrFail($tld)->$column;
                        $field->disabled($refused)->helperText(fn()=>$refused()?($key==='register'?'Registrations':'Transfers').' are not set up for .'.$allowed()['ending'].' on this account. Change sales under What you sell.':null);
                    }
                    $fields[]=$field;
                }
                $fields[]=\Filament\Schemas\Components\Text::make('These switches control new sales here. They do not renew domains at the registrar.');
                $fields[]=Checkbox::make('verified')->label('I checked these against this account’s setup');
            }
            if($name==='seller'){
                $policy=self::cached(static fn()=>\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',Tld::findOrFail($tld)->tld)->first());
                $fields[]=Select::make('seller')->label('Registrar for new orders')->options(fn()=>\App\Models\Server::whereIn('id',Account::pluck('server_id'))->orderBy('id')->pluck('name','id')->all())->placeholder('None')->default(fn()=>$policy()?->server_id);
                $fields[]=Checkbox::make('register')->label('Allow registrations')->default(fn()=>$policy()?->register_enabled??false);$fields[]=Checkbox::make('transfer')->label('Allow transfers')->default(fn()=>$policy()?->transfer_enabled??false);
            }
            if($name==='renewal_billing'){
                $billing=static fn()=>\Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::read($server);
                $fields[]=TextInput::make('issue_days')->label('Issue invoices this many days before expiry')->integer()->minValue(2)->maxValue(365)->required()->default(fn()=>$billing()['issue_days']??21)->helperText('Default 21. Must be more than the due days; at most 365.');
                $fields[]=TextInput::make('due_days')->label('Invoices fall due this many days before expiry')->integer()->minValue(1)->maxValue(364)->required()->default(fn()=>$billing()['due_days']??7)->helperText('Default 7. At least 1.');
            }
            if($name==='unpaid_hold'){
                $hold=\Paymenter\Extensions\Servers\Domains\Services\UnpaidHoldSetting::class;
                $fields[]=TextInput::make('minutes')->label('Minutes an unpaid order keeps its name')->integer()->minValue($hold::MIN)->maxValue($hold::MAX)->required()->default(fn()=>$hold::minutes($server))->helperText('Default '.$hold::DEFAULT.'. Between '.$hold::MIN.' and '.$hold::MAX.' (7 days).');
            }
            if($name==='enroll_sandbox'){
                $fields[]=Hidden::make('environment')->default('test');
                $fields[]=TextInput::make('label')->label('Account label')->helperText('For example, test-west. Do not enter personal data or an API key. You can’t change it later.')->hintAction(HelpHint::make('One line, up to 128 bytes (non-ASCII characters count as more), with no spaces at the start or end. NameSilo does not confirm this label. Staff can see it on this page.'))->required()->maxLength(128);
                $fields[]=TextInput::make('api_key')->label('NameSilo test API key')->password()->autocomplete('new-password')->required()->maxLength(256);
                $fields[]=Checkbox::make('confirmed')->label('I understand this is a test account and NameSilo has not confirmed who owns it')->accepted()->required();
            }
            if($name==='configure'){
                $fields[]=Select::make('registrar')->label('Registrar')->options(['enom'=>'Enom','namesilo'=>'NameSilo'])->default(fn()=>Account::where('server_id',$server)->value('registrar_key'))->live()->required();
                $fields[]=Select::make('environment')->label('Account type')->options(['test'=>'Test','live'=>'Live'])->default(fn()=>Account::where('server_id',$server)->value('environment')??'test')->required();
                foreach(['uid'=>'Enom user ID','token'=>'Enom API token','api_key'=>'NameSilo API key'] as $key=>$label){$fields[]=TextInput::make($key)->label($label)->password()->autocomplete('new-password')->maxLength(4096)->visible(fn($get)=>$get('registrar')===($key==='api_key'?'namesilo':'enom'))->required();}
            }
            if(!in_array($name,Review::NO_REASON,true)){$fields[]=Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(2)->rules(['regex:'.StaffReason::SINGLE_LINE])->helperText('Use one line. Do not include credentials.');}
            if(($help=self::details($name))!==null){(collect($fields)->last(fn($f)=>$f instanceof Checkbox&&in_array($f->getName(),['verified','confirmed'],true))
                ??collect($fields)->first(fn($f)=>$f instanceof Select||$f instanceof Textarea))?->hintAction(HelpHint::make($help));}
            $processing=fn()=>(bool)Account::where('server_id',$server)->value('processing_enabled');
            $cannotEnable=fn()=>$name==='processing'&&!$processing()&&(!Account::where('server_id',$server)->exists()||app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class)->configurationStatus($server)!=='matched');
            $registrar=fn()=>Account::where('server_id',$server)->value('registrar_key');
            // Setup buttons that can't run are hidden, not disabled (layout.md section 6).
            $blocked=fn()=>$cannotEnable()||($name==='enroll_sandbox'&&(!\Paymenter\Extensions\Servers\Domains\Services\NameSiloSandbox::enabled()||Account::where('server_id',$server)->exists()))
                ||($name==='configure'&&!app(StaffSettingsWrites::class)->connectionAvailable($registrar()))||($name==='probe'&&!app(StaffSettingsWrites::class)->connectionAvailable((string)$registrar()));
            $turn=fn()=>self::switchOn($id,$server)?'Turn off':'Turn on';
            $label=$setup?fn()=>self::setupLabel($name,$registrar()!==null):Review::ACTIONS[$name];
            $out[]=Action::make($id.($setup?'_'.$server:''))->label($label)->color(fn()=>$name==='processing'&&$processing()?'danger':'gray')->modalWidth('lg')
                ->visible(fn()=>!($setup&&$blocked())&&(!in_array($name,Review::PUBLISHES,true)||Staff::can(Review::permission('publish')))&&(!str_starts_with($name,'live_')||(config('domains.enom_live.'.substr($name,5))===true&&Account::where('server_id',$server)->where('registrar_key','enom')->where('environment','live')->exists()))&&Staff::can(Staff::POLICY)&&(Staff::can(Review::permission($name))||($name==='override'&&Staff::can(Review::permission('remove_override'))))&&($name!=='test_transfer'||(EnomTestTransfer::deploymentEnabled()&&Account::where('server_id',$server)->where('registrar_key','enom')->where('environment','test')->exists()))&&($name!=='test_management'||(config('domains.enom_writes.management')===true&&Account::where('server_id',$server)->where('registrar_key','enom')->where('environment','test')->exists()))&&($name!=='test_renewal'||(config('domains.enom_writes.renewal')===true&&Account::where('server_id',$server)->where('registrar_key','enom')->where('environment','test')->exists())))
                ->requiresConfirmation(!$switch||$popup)->modalHidden($switch&&!$popup)->modalHeading($setup?fn()=>$popup?$turn().' '.self::POPUPS[$id].'?':self::setupHeading($name,$registrar()!==null):$label)
                ->modalDescription($popup?fn()=>self::switchWarning($id,$server):($switch?null:($name==='configure'?fn(Action $action)=>($action->getRawData()['registrar']??null)==='namesilo'?"NameSilo accounts can't be connected with API credentials.":self::description($name):self::description($name))))
                ->modalSubmitActionLabel($setup?fn()=>$popup?$turn():self::submitLabel($name,$registrar()!==null):($name==='override'?'Save and publish':'Confirm'))->schema($switch?[]:$fields)
                ->action(function(#[\SensitiveParameter] array $data,$livewire)use($id,$name,$server,$tld,$setup,$stamp,$switch){
                    try{$data=[...$data,...self::saved($id,$server)];
                        if($switch){$data+=['review_key'=>Review::ticket((int)Auth::id(),$server,$tld,$name,$stamp),'reason'=>self::SWITCH_REASON];}
                        $default=$name==='override'&&($data['pricing']??null)==='default';
                        $r=new Review((int)Auth::id(),$server,$tld,$default?'remove_override':$name,(string)($data[$default?'default_key':'review_key']??''),trim((string)($data['reason']??'')));
                        $summary=app(StaffSettingsWrites::class)->run($r,$data);$account=Account::where('server_id',$server)->first();
                        Notification::make()->status(($summary['audit_failed']??false)?'warning':'success')->title(match(true){($summary['audit_failed']??false)=>"Saved, but the result wasn't recorded",$name==='processing'=>$account->processing_enabled?'Orders resumed':'Orders paused',$name==='probe'=>'Balance updated',
                                $name==='enroll_sandbox'=>'Account connected',$name==='override'=>'Prices published',$setup=>'Saved',default=>'Change recorded'})
                            ->body($name==='processing'||$name==='probe'?null:(in_array($name,['default_rule','card_fee'],true)?self::published($summary??['published'=>0,'failed'=>[]],$name):($name==='enroll_sandbox'?'NameSilo accepted the key. Orders are paused and no TLDs are chosen yet.':null)))->send();
                        if($name==='processing'){$livewire->redirect(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$server]));}
                    }catch(\Throwable $error){
                        if($error instanceof \DomainException&&$error->getMessage()==='Terms unchanged.'){Notification::make()->info()->title('Nothing to save')->body('These terms are the same as before.')->send();return;}
                        $message=self::refusal($error,$name,$data,$server,$tld);
                        if(!$error instanceof \DomainException){\Illuminate\Support\Facades\Log::warning('Domains staff setting failed.',['action'=>$name,'exception_class'=>$error::class]);}
                        Notification::make()->danger()->title(match(true){$name==='enroll_sandbox'&&in_array($message,[self::SAVED_OUTCOME_UNKNOWN,self::GENERIC_OUTCOME_UNKNOWN],true)=>"Couldn't confirm the connection",
                            $setup=>"Couldn't save",default=>'Change refused'})->body($message)->send();}
                    finally{$livewire->mountedActions=[];}
                });
        }
        return $out;
    }
    /** Sync prices for every accepted TLD of one account, from the TLD list header: one short question, no typed TLD and
     * no reason. The audit records who synced, when and which TLDs. */
    /** The Sync prices question: what it reads, and its share of our own daily call cap. */
    private static function syncDescription(int $server): string|\Illuminate\Support\HtmlString
    {
        $text=self::description('import');
        try{
            $account=Account::where('server_id',$server)->sole();$binding=\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($account);
            $accepted=\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::load($binding);$today=\Paymenter\Extensions\Servers\Domains\Services\PriceSync::callsToday($binding);
            // Sync never adds TLDs, so say which ones it covers and where more are added.
            $endings=array_map(fn($e)=>'.'.$e,array_keys($accepted->endings));$count=count($endings);
            $text=($count===0?'This account sells no TLDs yet, so there is nothing to sync.':'Gets current costs from '.self::seller($account).' for the '.$count.' '.($count===1?'TLD':'TLDs').' this account sells: '.implode(', ',array_slice($endings,0,10)).($count>10?' and '.($count-10).' more':'').'.')
                .' To sell more, add them under What you sell.';
            if($count>0&&$today!==null&&$today['limit']>=1){
                $calls=\Paymenter\Extensions\Servers\Domains\Services\PriceSync::batchedCalls($accepted,array_keys($accepted->endings));
                $text.=' Uses about '.$calls.' of '.$today['limit'].' registrar calls allowed '.($binding->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Test?'in any 24 hours':'today').' (a safety limit you set).';
            }
            if(!\Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::canAccess()){return $text;}
            $url=\Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::getUrl(['account'=>$server]);
            return new \Illuminate\Support\HtmlString(str_replace('What you sell','<a class="fi-link" style="text-decoration:underline" href="'.e($url).'">What you sell</a>',e($text)));
        }catch(\Throwable){return $text;}
    }
    /** One short line for a notification: only what staff must act on is named. The prices are on the page. */
    public static function syncSummary(array $result): string
    {
        $list=static function(array $endings):string{
            $names=implode(', ',array_map(fn($e)=>'.'.$e,array_slice($endings,0,3)));
            return $names.(count($endings)>3?' and '.(count($endings)-3).' more':'');
        };
        $count=count($result['synced']);$failed=array_keys($result['failed']??[]);$later=$result['deferred']??[];
        return implode(' ',array_filter([
            $count.' '.($count===1?'TLD':'TLDs').' synced.',
            $failed===[]?null:'Not synced: '.$list($failed).'. They keep their last cost.',
            $later===[]?null:'Left for the next sync: '.$list($later).'. Sync prices again in a minute.',
        ]));
    }
    /** Sync needs an account whose price list we can read. */
    public static function syncOffered(?Account $account): bool
    {
        try{return $account!==null&&\Paymenter\Extensions\Servers\Domains\Services\TestCatalogue::enabled(\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($account));}
        catch(\Throwable){return false;}
    }
    public static function syncAll(int $server,bool $named=false): Action
    {
        return Action::make('sync_prices_'.$server)->label(fn()=>'Sync prices'.($named?': '.(\App\Models\Server::find($server)?->name ?? 'Server #'.$server):''))->color('gray')
            ->visible(fn()=>Staff::can(Staff::POLICY)&&Staff::can(Review::permission('import'))&&self::syncOffered(Account::where('server_id',$server)->first()))
            ->requiresConfirmation()->modalHeading('Sync prices')->modalDescription(fn()=>self::syncDescription($server))->modalSubmitActionLabel('Sync prices')
            ->fillForm(fn()=>['review_key'=>Review::ticket((int)Auth::id(),$server,null,'import',Review::stamp($server,null))])
            ->schema([Hidden::make('review_key')])
            ->action(function(#[\SensitiveParameter] array $data,$livewire)use($server){
                try{
                    $result=app(StaffSettingsWrites::class)->run(new Review((int)Auth::id(),$server,null,'import',(string)($data['review_key']??''),''),[]);
                    Notification::make()->success()->title('Prices synced')->body(self::syncSummary($result)?:null)->send();
                }catch(\Throwable $error){
                    if(!$error instanceof \DomainException){\Illuminate\Support\Facades\Log::warning('Domains price sync failed.',['exception_class'=>$error::class]);}
                    Notification::make()->danger()->title("Couldn't sync prices")->body(self::refusal($error,'import',[],$server))->send();
                }finally{$livewire->mountedActions=[];}
            });
    }
    /** Live preview: each sold term's cost, current price and the price saving would publish. */
    private static function preview(?int $tld,?array $rule): \Illuminate\Support\HtmlString
    {
        $t=$tld?Tld::find($tld):null;
        if($t===null||$rule===null){return new \Illuminate\Support\HtmlString('');}
        $now=now()->toDateTimeImmutable();
        try{$rows=PriceCatalogue::preview($t,$rule,$now);}catch(\Throwable){$rows=[];}
        if($rows===[]){return new \Illuminate\Support\HtmlString('<p>No costs yet. Use Sync prices on the TLD list first. Saving now changes nothing.</p>');}
        $costs=Staff::can(Staff::COSTS);$money=static fn(?string $v)=>$v===null?'None':'$'.preg_replace('/(\.\d{2}\d*?)0+$/D','$1',$v);
        $cell=static fn(string $html)=>'<td style="padding:6px 10px;vertical-align:top;white-space:nowrap">'.$html.'</td>';
        $html='<div style="max-width:100%;overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.875rem"><thead><tr>';
        foreach(['Price',...($costs?['Cost']:[]),'Now','After saving'] as $label){$html.='<th scope="col" style="padding:6px 10px;text-align:left;white-space:nowrap;font-weight:500">'.e($label).'</th>';}
        $html.='</tr></thead><tbody>';$missing=false;
        foreach($rows as $row){
            $missing=$missing||!$row['synced'];
            $note=match(true){!$row['synced']=>'Cost missing',$row['cost']===null=>TldFacts::NOT_OFFERED,$row['hold']==='below_cost'=>'Below cost: not sold',
                $row['alert']==='below_cost'=>'Below cost: still billed',$row['hold']!==null=>'Not sold',default=>null};
            $html.='<tr style="border-top:1px solid rgb(127 127 127 / .2)">'.$cell(e(ucfirst($row['action']).', '.$row['term'].' '.($row['term']===1?'year':'years')))
                .($costs?$cell(e($money($row['cost']))):'').$cell(e($money($row['current'])))
                .$cell(e(in_array($row['hold'],[null,'below_cost'],true)?$money($row['new']):'Not sold').($note===null?'':'<div style="font-size:.75rem;color:rgb(217 119 6)">'.e($note).'</div>')).'</tr>';
        }
        $html.='</tbody></table></div>';
        if($missing){$html.='<p style="margin-top:.5rem;color:rgb(220 38 38)">Some costs are missing. Use Sync prices on the TLD list first. Saving now changes nothing.</p>';}
        return new \Illuminate\Support\HtmlString($html);
    }
    /** Which TLDs a default pricing change publishes to, and which of them will wait for a price sync. */
    private static function followers(int $server): string
    {
        $tlds=TldFacts::bound(Tld::query())->where('server_id',$server)->where('use_default_rule',true)->orderBy('tld')->get();
        if($tlds->isEmpty()){return 'No TLDs use the account default yet. Saving changes the default only.';}
        $account=$tlds->first();
        $prices=\Paymenter\Extensions\Servers\Domains\Models\Price::where('server_id',$server)
            ->where('registrar_key',$account->registrar_key)->where('account_fingerprint',$account->account_fingerprint)
            ->whereIn('tld',$tlds->pluck('tld'))->where('currency','USD')->orderBy('id')->get()->groupBy('tld');
        $now=now()->toDateTimeImmutable();
        $waiting=$tlds->filter(function(Tld $t)use($now,$prices){try{PriceCatalogue::requireCosts($t,$now,$prices->get($t->tld,collect()));return false;}catch(\Throwable){return true;}});
        $text=$tlds->count().' '.($tlds->count()===1?'TLD uses':'TLDs use').' the account default: '.$tlds->map(fn($t)=>'.'.$t->tld)->implode(', ').'. Saving publishes '.($tlds->count()===1?'its':'their').' new prices.';
        return $text.($waiting->isEmpty()?'':' Costs are missing for '.$waiting->map(fn($t)=>'.'.$t->tld)->implode(', ').'. '.($waiting->count()===1?'Its prices':'Their prices').' stay the same until you sync prices.');
    }
    /** Notification text after a pricing or card fee change. */
    private static function published(array $summary,string $name): string
    {
        $text=$summary['published']===0&&$summary['failed']===[]?($name==='card_fee'?'Card fee saved. No TLDs count the card fee in their prices, so no prices changed.':'No TLDs use the account default.'):'Published new prices for '.$summary['published'].' '.($summary['published']===1?'TLD':'TLDs').'.';
        return $text.($summary['failed']===[]?'':' Could not publish '.implode(', ',$summary['failed']).'. Use Sync prices on the TLD list.')
            .(($summary['audit_failed']??false)?" Nothing was undone: your change and any prices published stay in place. Only the record of this result couldn't be saved.":'');
    }
    /** Renewals, DNS management and transfers are on when their saved acceptance is current. */
    private static function accepted(int $server,string $name): bool
    {
        if(!preg_match('/^(test|live)_(renewal|management|transfer)$/',$name,$m)){return false;}
        try{$b=\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding(Account::where('server_id',$server)->firstOrFail());
            return match($m[2]){'renewal'=>\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::on($b),'transfer'=>EnomTestTransfer::accepted($b),default=>\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::accepted($b)};}
        catch(\Throwable){return false;}
    }
    private static function switchOn(string $id,int $server): bool
    {
        return match($id){'processing'=>(bool)Account::where('server_id',$server)->value('processing_enabled'),
            'renew_early'=>\Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::read($server)['manual'],
            'renewals'=>\Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::anyOn($server),default=>self::accepted($server,$id)};
    }
    /** What the writer needs beyond the form. A switch sends the opposite of what is saved; its ticket refuses a page that showed something else.
     * Only a TEST switch vouches for its own check: no real money moves there. A Live switch can turn off, never on. */
    private static function saved(string $id,int $server): array
    {
        $billing=isset(self::SHARED[$id])||$id==='renewal_billing'?\Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::read($server):[];
        return match(true){$id==='processing'=>['enabled'=>!self::switchOn($id,$server),'confirmed'=>true],
            $id==='renewal_billing'=>['scheduled'=>$billing['scheduled'],'manual'=>$billing['manual'],'confirmed'=>true],
            $id==='unpaid_hold'=>['confirmed'=>true],
            $id==='renew_early'=>[...$billing,'manual'=>!$billing['manual'],'confirmed'=>true],
            $id==='renewals'=>[...$billing,'scheduled'=>!self::switchOn($id,$server),'manual'=>!self::switchOn($id,$server),'confirmed'=>true],
            in_array($id,self::SWITCHES,true)=>['accept'=>!self::switchOn($id,$server),...(str_starts_with($id,'test_')?['verified'=>true]:[])],default=>[]};
    }
    /** The one sentence on a switch that spends money or stops sales. */
    private static function switchWarning(string $id,int $server): string
    {
        $on=self::switchOn($id,$server);
        return match($id){'processing'=>$on?'New orders and renewals wait until you turn this back on.':'New orders and renewals start going to the registrar.',
            'test_transfer','live_transfer'=>$on?'New transfers stop. Transfers already sent keep going.':'Customers can order transfers. Paid transfers go to the registrar, which takes the cost from this account’s balance.',
            'renewals'=>$on?'New renewal invoices stop. Issued invoices stay payable. Paid renewals still go to the registrar.':'Customers start getting renewal invoices and can renew early.',
            default=>$on?'New renewal invoices stop. Issued invoices stay payable. Paid renewals wait until you turn this back on.':'Customers start getting renewal invoices. Paid renewals go to the registrar, which takes the cost from this account’s balance.'};
    }
    private static function setupLabel(string $name,bool $connected): string
    {
        return match(true){$name==='processing'=>'Orders',$name==='configure'=>$connected?'Edit API credentials':'Connect account',
            $name==='enroll_sandbox'=>'Connect NameSilo test account',$name==='probe'=>'Test connection',
            $name==='renewal_billing'=>'Edit schedule',$name==='unpaid_hold'=>'Edit unpaid hold',$name==='default_rule'=>'Edit default pricing',$name==='card_fee'=>'Edit card fee',default=>Review::ACTIONS[$name]??$name};
    }
    /** Dialog title: what the button does, as a question. */
    private static function setupHeading(string $name,bool $connected): string
    {
        return match(true){$name==='renewal_billing'=>'Edit renewal schedule?',$name==='default_rule'=>'Edit default pricing?',$name==='card_fee'=>'Edit card fee?',
            default=>self::setupLabel($name,$connected).'?'};
    }
    private static function submitLabel(string $name,bool $connected): string
    {
        return match(true){$name==='enroll_sandbox'||($name==='configure'&&!$connected)=>'Connect',
            $name==='probe'=>'Test connection',in_array($name,['default_rule','card_fee'],true)=>'Save and publish',default=>'Save'};
    }
    /** "the Enom test account": where Sync prices reads from. */
    private static function seller(Account $account): string
    { return 'the '.TldFacts::registrar($account->registrar_key).($account->environment==='live'?' live':' test').' account'; }
    /** "1 to 3 years" for a run of terms, "1 or 3 years" otherwise. */
    private static function years(array $terms): string
    {
        sort($terms);$last=end($terms);
        $list=$last-$terms[0]===count($terms)-1&&count($terms)>2?$terms[0].' to '.$last:(count($terms)===1?(string)$last:implode(', ',array_slice($terms,0,-1)).' or '.$last);
        return $list.' '.($last===1?'year':'years');
    }
    /** What the saved account terms allow for one TLD, with its current bounds; null when unreadable. */
    private static function allowedTerms(int $server,?int $tld): ?array
    {
        try{
            $account=Account::where('server_id',$server)->sole();$t=Tld::findOrFail($tld);
            $row=\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::load(\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($account))->endings[$t->tld]??[];
            $register=array_values(array_filter($row['register']??[],'is_int'));$renewable=in_array(1,$row['renew']??[],true)&&($row['registry']??false)&&($row['renewal_safe']??false);
            $bounds=[...$register,$t->min_years,$t->max_years];
            return ['ending'=>$t->tld,'register'=>$register,'min'=>min($bounds),'max'=>max($bounds),
                'register_allowed'=>$renewable&&$register!==[],'transfer_allowed'=>$renewable&&in_array(1,$row['transfer']??[],true)];
        }catch(\Throwable){return null;}
    }
    /** The real reason an Edit terms change was refused, in plain words; null leaves the shared guidance. */
    private static function termsRefusal(string $message,array $data,int $server,?int $tld): ?string
    {
        $a=self::allowedTerms($server,$tld);$ending=isset($a['ending'])?'.'.$a['ending']:'this TLD';
        $min=filter_var($data['min_years']??null,FILTER_VALIDATE_INT);$max=filter_var($data['max_years']??null,FILTER_VALIDATE_INT);
        $text=match($message){
            'Valid term bounds required.'=>'Pick from '.($a['min']??1).' to '.($a['max']??10).', with the minimum no more than the maximum.',
            'Explicit verified widening required.'=>match(true){
                $a!==null&&$a['register']===[]=>'This account is not set up to sell new '.$ending.' registrations. Change this under What you sell.',
                $a!==null&&(!in_array($min,$a['register'],true)||!in_array($max,$a['register'],true))=>'This account is set up to sell '.$ending.' for '.self::years($a['register']).'. '.(count($a['register'])===max($a['register'])-min($a['register'])+1?'Pick from '.min($a['register']).' to '.max($a['register']).', or change What you sell.':'Pick only those, or change What you sell.'),
                default=>'To add years, tick the box to confirm they match this account’s setup. Check What you sell.'},
            'Explicit restoration required.'=>'To turn sales back on, tick the box to confirm this account is set up for them. Check What you sell.',
            'Acquisition acceptance unavailable.'=>($data['transfer']??false)&&$a!==null&&!$a['transfer_allowed']?'Transfers are not set up for '.$ending.' on this account. Change sales under What you sell. Turn off Transfers.'
                :'These '.$ending.' sales are not set up on this account. Check What you sell.',
            'Catalogue publication evidence missing.','Catalogue publication evidence changed.'=>'Prices for '.$ending.' are missing or out of date. Use Sync prices on the TLD list, then try again.',
            'Retained holders require annual renewal support.'=>'Customers still own '.$ending.' domains here, so renewals must stay on.',
            default=>null};
        return $text===null?null:$text.' Nothing was saved.';
    }
    /** Says what stopped the send. Only over_limit, or no finer reason, is about the count. */
    private static function testRefusal(EnomTestCallLimit $error,string $name,int $server): string
    {
        $earlier=$name==='import'?'Earlier reads in this sync may have been sent and counted. ':'';
        return match($error->reason){
            'send_guard'=>'This site does not allow that Enom request, so it was not sent. '.$earlier.'Add it to the send guard list to allow it.',
            'bad_setting'=>'Our test send limit setting is missing or not valid, so this Enom request was not sent. '.$earlier,
            'unreadable'=>'We could not read our test send count, so this Enom request was not sent. '.$earlier.'Try again in a minute.',
            default=>'Our test send check stopped this Enom request before sending. '.$earlier.EnomTestSendLimit::facts(($account=Account::where('server_id',$server)->first())?EnomTestSendLimit::login($account):null)};
    }
    /** Staff text for a refused settings change; never echoes unrecognized exception text. */
    public static function refusal(\Throwable $error,string $name,array $data=[],int $server=0,?int $tld=null): string
    {
        if($name==='tld_settings'&&$error instanceof \DomainException&&($text=self::termsRefusal($error->getMessage(),$data,$server,$tld))!==null){return $text;}
        if($name==='acceptance'&&$error->getMessage()==='Audit entry too large. Changes held.'){return 'That is too many changes for one save. Save fewer at a time. Nothing was saved.';}
        if($name==='card_fee'&&$error instanceof \InvalidArgumentException&&!in_array($error->getMessage(),['This percentage cannot cover the card fee. Lower the markup or card fee.','The calculated price is too large.'],true)){
            return 'Enter a card fee from 0% to 99.99% and a fixed amount from $0 to $100,000, with up to two decimal places. Nothing was changed.';
        }
        if($error instanceof EnomTestCallLimit&&(Account::where('server_id',$server)->value('environment')??($data['environment']??null))==='live'){return 'We stopped this before it reached Enom. Earlier reads may have been sent. Check API calls on the account page.';}
        return $error instanceof EnomTestCallLimit?self::testRefusal($error,$name,$server)
                :match($error->getMessage()){'The calculated price is too large.'=>'This makes a price too large to save. Lower the markup or the card fee. Nothing was changed.','Processing state unchanged.'=>'Orders are already set that way. Nothing changed.',
                'This TLD is hidden.'=>"That TLD isn't offered here. Nothing was saved.",'Choose at least one year.'=>'Tick at least one year. Nothing was saved.','Nothing to save.'=>'Nothing changed. Nothing was saved.',
                'Acceptance exceeds encrypted settings capacity.','Invalid catalogue acceptance.'=>'That is more TLDs than one account can save. Turn some off. Nothing was saved.',
                'Settings changed. Reload this page.'=>$name==='enroll_sandbox'?'This expired, or someone else changed this account. '.self::NO_KEY_CHECK.' Try again.':'Someone else changed this. Nothing was saved. Try again.',
                'Current staff permissions required.'=>$name==='enroll_sandbox'?'Your permissions changed. '.self::NO_KEY_CHECK:'Your permissions changed. Nothing was saved.',
                'Runtime acceptance and shared workers are required.'=>'Orders can’t resume yet. See System checks on this page.',
                'Pause processing on every legacy Enom server before installing or enabling Domains.'=>'Pause orders on the old Enom accounts first.',
                'TEST catalogue reads are unavailable.'=>'Sync prices is off for this account on this site. '.self::NOT_SENT,
                'Catalogue ending is not accepted.'=>'Add this TLD under What you sell first. '.self::NOT_SENT,
                'Catalogue import unavailable.'=>'Sync did not finish. Check saved prices and what is for sale. Some registrar reads may have been sent. Check What you sell, then try again.'
                    .($error instanceof \Paymenter\Extensions\Servers\Domains\Services\CatalogueImportFailure?' '.TldFacts::syncCause($error->failureCode).'.':''),
                'Connection checks are unavailable.'=>$name==='configure'&&($data['registrar']??null)==='namesilo'?'NameSilo accounts can’t be connected here.':'Connection checks are off on this site. '.self::NOT_SENT,
                'identity_credentials_refused'=>'Enom refused the user ID or API token. Check both for this account type.',
                'identity_unavailable'=>'Enom did not confirm this account. Nothing was saved. Try again later.',
                'Connection not verified.'=>Account::where('server_id',$server)->value('registrar_key')==='namesilo'?'NameSilo did not confirm the balance. The last saved balance stays. Trying again may send one more balance read.':'Enom did not confirm the balance. The last saved balance stays.',
                'identity_environment_refused'=>'Enom reads for this account type are off on this site. '.self::NOT_SENT,
                'identity_account_changed'=>'These credentials belong to a different account. The saved account was kept.',
                'Sandbox contract confirmation required.'=>'Tick the box to continue. '.self::NO_KEY_CHECK,
                'Sandbox review mismatch.'=>'This doesn’t match the selected account. '.self::NO_KEY_CHECK.' Try again.',
                'Registrar unavailable.'=>$name==='enroll_sandbox'?'This registrar account is off or missing. '.self::NO_KEY_CHECK.' Turn it on in Servers, then try again.':'This registrar account is off or missing. Turn it on in Servers, then try again.',
                'Domains server is unavailable.'=>$name==='enroll_sandbox'?'This registrar account is off or missing. '.self::NO_KEY_CHECK.' Turn it on in Servers, then try again.':'This registrar account is off or missing. Turn it on in Servers, then try again.',
                'Invalid review key.'=>$name==='enroll_sandbox'?'This expired. '.self::NO_KEY_CHECK.' Try again.':'This expired. Nothing was changed. Try again.',
                'Enter a one-line reason between 5 and 500 characters.'=>$name==='enroll_sandbox'?'Enter a one-line reason between 5 and 500 characters. '.self::NO_KEY_CHECK:'Enter a one-line reason between 5 and 500 characters. Nothing was changed.',
                'Sandbox key already enrolled.'=>'This test key is already on another registrar account. Each key can be used once. Keep using that account, or use a different key on a new registrar account. '.self::NOT_SENT,
                'Existing sandbox enrollment unavailable.'=>'Another test account’s saved details can’t be read, so we can’t check this key. Restore those details, then try again. '.self::NOT_SENT,
                'Sandbox enrollment unavailable.'=>'NameSilo test accounts are off on this site, and live NameSilo accounts can’t be connected. '.self::NOT_SENT,
                'Sandbox enrollment is immutable.'=>'A test account can’t be changed. To change the key or label, use a different test key on a new registrar account. Domains, What you sell and requests stay with the old one. '.self::NOT_SENT,
                'Sandbox enrollment requires a fresh server.'=>'This registrar account already has saved settings, records or domains. Use a new registrar account. '.self::NOT_SENT,
                'Invalid sandbox enrollment input.'=>'Check the label and key. The label must be one line of up to 128 bytes with no spaces at the start or end, and can’t contain the key. The key can be up to 256 characters, with no spaces or control characters. '.self::NOT_SENT,
                'Sandbox key check failed.'=>'Nothing was saved. NameSilo did not confirm the key. It may be wrong, or the reply may be missing or unclear. Check the key and try again. Trying again may send another key check.',
                'Sandbox enrollment could not be saved.'=>self::SAVED_OUTCOME_UNKNOWN,
                'namesilo_identity_unavailable'=>'NameSilo accounts can’t be connected here.',
                'Review key used.'=>$name==='enroll_sandbox'?'This was already submitted, so no new key check was sent. An earlier try may still be running, and this page can’t show that. Wait for it to finish. '.self::OUTCOME_NEXT:'This was already submitted. Check this page, then try again if you need to.',
                'This percentage cannot cover the card fee. Lower the markup or card fee.'=>'No price can cover the card fee with this markup. Lower the markup or the card fee. Nothing was changed.',
                'Costs required before publication.'=>'Costs are missing. Use Sync prices on the TLD list, then save again. Nothing was changed.',
                'Explicit renewal billing confirmation required.'=>'Tick the box to continue. Nothing was saved.',
                'Invalid renewal billing input.'=>'Check the fields and try again. Nothing was saved.',
                'Invalid renewal timing.'=>'Enter whole numbers of days. Nothing was saved.',
                'Valid billing calendar required.'=>'Issue days must be more than due days. Issue days can be at most 365 and due days at least 1. Nothing was saved.',
                'Renewal billing unchanged.'=>'Renewal billing is unchanged. Nothing was saved.',
                'Live Enom Purchase route acceptance is unavailable.'=>self::ADMIN_ORDERS,
                'Live renewal deployment is unavailable.'=>'Live renewals are off for this account or site. Check the Renewals row on the account page. Nothing was saved.',
                'Live management deployment is unavailable.'=>'Live changes are off for this account or site. Check the DNS, nameservers and contacts row on the account page. Nothing was saved.',
                'TEST management deployment is unavailable.'=>'Test changes are off for this account or site. Check the DNS, nameservers and contacts row on the account page. Nothing was saved.',
                'TEST renewal deployment is unavailable.'=>'Test renewals are off for this account or site. Check the Renewals row on the account page. Nothing was saved.',
                'Enom TEST acquisition route acceptance is unavailable.'=>self::ADMIN_ORDERS,
                'Enom TEST Purchase route acceptance is unavailable.'=>self::ADMIN_ORDERS,
                default=>$name==='enroll_sandbox'?self::GENERIC_OUTCOME_UNKNOWN:'Check this page, then try again. If it fails again, open System checks on the registrar account.'};
    }
}

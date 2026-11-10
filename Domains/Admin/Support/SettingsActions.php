<?php
// Enom 444f31c pricing/setup action layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue;
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
    private const SWITCHES=['late_renewal','processing','renewals','test_renewal','test_management','test_transfer','live_renewal','live_management','live_transfer','renew_early'];
    private const POPUPS=['late_renewal'=>'late renewal','processing'=>'orders','renewals'=>'renewals','test_renewal'=>'renewals','live_renewal'=>'renewals','test_transfer'=>'transfers','live_transfer'=>'transfers','test_management'=>'DNS, nameservers and contacts','live_management'=>'DNS, nameservers and contacts'];
    /** Switch names saved by a writer action with a name of its own. */
    /** The largest margin the pricing dialogs take, as a percentage or a USD amount per year. Larger is almost always a typo. */
    private const MARGIN_MAX=500;
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
    /** Visibility only: reuse the current actor's role for this request. Writers authorize afresh. */
    private static function canSee(string $permission): bool
    {
        $actor=Auth::user();
        if(!$actor instanceof User){return false;}
        $request=request();$key=self::class.'.staff_visibility';
        $snapshot=$request->attributes->get($key);
        if($snapshot===null||$snapshot['actor']!==$actor->getKey()){
            $snapshot=['actor'=>$actor->getKey(),'user'=>User::with('role')->find($actor->getKey())];
            $request->attributes->set($key,$snapshot);
        }
        $current=$snapshot['user'];
        return $current!==null&&$current->hasPermission(Staff::VIEW)&&$current->hasPermission($permission);
    }
    /** Why a margin can't be used, or null when it can. Matches the field's own rules so the preview never shows a price for it.
     * The server takes the exact text, so a sign or spaces around the number are refused here too. The limit applies to a new
     * margin only: a larger saved one, which the server takes, stays usable for other edits in its own mode (800% is not $800). */
    private static function margin(mixed $value, mixed $saved = null, ?string $mode = null, ?string $savedMode = null): ?string
    {
        if($value===null||is_string($value)&&trim($value)===''){return 'Enter a margin.';}
        if(!is_scalar($value)){return 'Enter a number.';}
        $value=(string)$value;
        if(str_starts_with(trim($value),'-')){return 'Enter 0 or more.';}
        if(!preg_match('/^[0-9]+(\.[0-9]+)?$/D',$value)){return 'Enter a number.';}
        $kept=is_string($saved)&&is_numeric($saved)&&$mode===$savedMode&&bccomp($value,$saved,2)===0;
        if(!$kept&&bccomp($value,(string)self::MARGIN_MAX,2)>0){return 'Enter '.self::MARGIN_MAX.' or less.';}
        return preg_match('/\.[0-9]{3,}$/',$value)?'Use at most two decimal places.':null;
    }
    /** The one plain sentence a settings dialog opens with. Detail lives in details(). */
    public static function description(string $name): string
    {
        return match($name){
            'renewal_billing'=>'Set when renewal invoices go out.',
            'unpaid_hold'=>'Set how long an unpaid new domain order keeps its name.',
            'default_pricing'=>'Sets the pricing every TLD uses unless it has its own, and the estimated card fee. Saving publishes new prices at once. Issued invoices keep their prices.',
            'override'=>'Saving publishes these prices at once. Registrations and transfers below cost are not sold, and Edit prices and the price list show why for each term. Renewals below cost are still billed, and each one adds an alert to its domain. Issued invoices keep their prices.',
            'remove_override'=>'Uses Default pricing. Saving publishes the new prices at once. Issued invoices keep their prices.',
            'publish'=>'Publishes prices from saved costs and the current pricing. It does not refresh costs or change issued invoices.',
            'tld_settings'=>'Choose the years customers can buy and what is for sale.',
            'seller'=>'Choose the registrar for new orders. Existing domains stay where they are.',
            'import'=>'Gets current costs from the registrar for the TLDs this account sells.',
            'enroll_sandbox'=>'Checks the key with one balance read, then saves it. A key can only be used once, and you can’t change it later.',
            'configure'=>'Checks these credentials with one read, then saves them. Saving pauses orders until you resume them.',
            'probe'=>'Reads the balance once and shows it on this page.',
        };
    }
    /** Change registrar on a TLD that already has one says what the change does to its sales before it is saved. */
    private static function sellerDescription(int $tld): string
    {
        $t=\Paymenter\Extensions\Servers\Domains\Models\Tld::find($tld);
        $p=$t?\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',$t->tld)->first():null;
        if($p===null||$p->server_id===null){return self::description('seller');}
        return 'Choose the registrar for new orders. If you pick another account, new orders for .'.$t->tld.' stop until that account\'s terms and prices are checked, and open carts drop .'.$t->tld.'. Existing domains stay where they are.';
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
            'configure'=>'Credentials are saved encrypted. The registrar and Test or Live mode stay fixed. You can rotate the key on this account.',
            'probe'=>'For NameSilo, a successful balance read confirms the key works. It does not confirm who owns the account. Orders and sales stay unchanged.',
            default=>null,
        };
    }
    /** One account setup action, keyed by its name (e.g. "processing"). */
    public static function setup(int $server,string $stamp,string $name): Action
    { return self::make($server,null,$stamp,true,[$name])[0]; }
    /** Default pricing and the card fee, shared by every account. Its stamp reads every account, so it is taken when the dialog opens. */
    public static function defaultPricing(): Action
    { return self::make(Review::ALL_ACCOUNTS,null,fn()=>Review::stamp(Review::ALL_ACCOUNTS,null),true,['default_pricing'])[0]; }
    public static function make(int $server,?int $tld,string|\Closure $stamp,bool $setup=false,?array $only=null): array
    {
        $out=[];$names=$setup?['enroll_sandbox','configure','probe','renewals','test_renewal','test_management','test_transfer','live_renewal','live_management','live_transfer','late_renewal','processing','renew_early','renewal_billing','unpaid_hold','default_pricing']:['override','tld_settings','seller'];
        if($only!==null){$names=array_values(array_intersect($names,$only));}
        $target=fn()=>\App\Models\Server::find($server)?->name ?? 'Server #'.$server;
        foreach($names as $id){
            $name=self::SHARED[$id]??$id;$switch=in_array($id,self::SWITCHES,true);$popup=isset(self::POPUPS[$id]);
            $fields=[Hidden::make('review_key')->default(fn()=>Review::ticket((int)Auth::id(),$server,$tld,$name,value($stamp)))];
            if(in_array($name,['default_pricing','override'],true)){
                // Saving writes rule and fee together. The form shows only the rule's own fields, so no fee may swap them for the built-in rule.
                $savedRule=self::cached(static function()use($tld,$name,$server):array{
                    $kind='default';
                    try{
                        $t=$tld&&$name==='override'?Tld::findOrFail($tld):null;
                        if($t){$kind='pricing';$intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($t->catalogue_intent);$kind=!\Paymenter\Extensions\Servers\Domains\Catalogue\Pricing::hasOwn($t)?'default':match($intent['pricing_rule']['mode']??$t->pricing_mode){'fixed'=>'fixed','markup_percent','markup_amount','markup_fee_percent'=>'markup',default=>'pricing'};}
                        $rule=$t?(StaffCatalogue::routed()?->pricing($server,$t)??(new PricingRules)->rule($t)):(new PricingRules)->defaultRule(fee:CardFee::NONE);
                        return ['rule'=>$rule,'error'=>null];
                    }catch(\Throwable){return ['rule'=>null,'error'=>match($kind){'fixed'=>'Invalid fixed price rule','markup'=>'Invalid markup rule','default'=>'Invalid default pricing rule',default=>'Invalid pricing rule'}];}
                });
                $rule=static fn()=>$savedRule()['rule'];
                $fields[]=\Filament\Schemas\Components\Text::make(fn()=>'Missing: '.$savedRule()['error'].'. Enter valid prices below or choose Default pricing.')
                    ->key('invalid_saved_rule')->visible(fn()=>$savedRule()['error']!==null);
                $default=self::cached(static function(){try{return (new PricingRules)->defaultRule();}catch(\Throwable){return null;}});
                $own=static fn($get)=>$name!=='override'||$get('pricing')!=='default';
                if($name==='override'){
                    // "Use Default pricing" saves as remove_override, so it keeps that permission and history label.
                    $fields[]=Hidden::make('default_key')->default(fn()=>Review::ticket((int)Auth::id(),$server,$tld,'remove_override',value($stamp)));
                    $fields[]=Radio::make('pricing')->hiddenLabel()->live()->required()
                        ->options(fn()=>array_filter(['default'=>'Use Default pricing ('.($default()===null?'not readable':PricingRule::describe($default())).')','custom'=>'Set prices for this TLD only'],
                            fn($key)=>self::canSee(Review::permission($key==='default'?'remove_override':'override')),ARRAY_FILTER_USE_KEY))
                        ->default(fn()=>((StaffCatalogue::routed()!==null?!StaffCatalogue::routed()->own(Tld::findOrFail($tld)):(Tld::find($tld)?->use_default_rule??true))&&self::canSee(Review::permission('remove_override')))||!self::canSee(Review::permission('override'))?'default':'custom');
                }
                $fields[]=Select::make('mode')->label('Pricing')->options(['markup_percent'=>'Add a percentage to cost','markup_fee_percent'=>'Add a percentage to cost and card fee','markup_amount'=>'Add an amount to cost',
                    // One own price for every TLD ignores each TLD's cost, so own prices are set per TLD only.
                    ...($name==='default_pricing'?[]:['fixed'=>'Set my own price'])])->required()->live()->default(fn()=>$rule()['mode']??null)->visible($own);
                $fields[]=TextInput::make('markup')->label(fn($get)=>$get('mode')==='markup_amount'?'Amount to add (USD)':'Percentage to add')->default(fn()=>$rule()['markup']??null)->required()->maxLength(12)->live(debounce:500)
                    ->rule(static fn($get):\Closure=>static function(string $attribute,mixed $value,\Closure $fail)use($rule,$get):void{if(($error=self::margin($value,$rule()['markup']??null,$get('mode'),$rule()['mode']??null))!==null){$fail($error);}})
                    ->visible(fn($get)=>$own($get)&&$get('mode')!=='fixed');
                $fields[]=Select::make('round_up')->label('Round up')->options(PricingRule::ROUNDING_LABELS)->default(fn()=>$rule()['round_up']??null)->required()->live()
                    ->visible(fn($get)=>$own($get)&&$get('mode')!=='fixed');
                foreach(['register'=>'Register','renew'=>'Renew','transfer'=>'Transfer'] as $key=>$label){$fields[]=TextInput::make('fixed_'.$key)->label($key==='register'?'First registration year (USD)':$label.' price per year (USD)')->default(fn()=>$rule()['fixed'][$key]??null)->maxLength(14)->live(debounce:500)
                    ->visible(fn($get)=>$own($get)&&$get('mode')==='fixed');}
                $saved=self::cached(static function():?array{try{return CardFee::read();}catch(\Throwable){return null;}});
                if($name==='default_pricing'){
                    $fields[]=TextInput::make('fee_percent')->label('Card fee (%)')->default(fn()=>$saved()['percent']??null)->required()->maxLength(6)->live(debounce:500);
                    $fields[]=TextInput::make('fee_fixed')->label('Plus a fixed amount (USD)')->default(fn()=>$saved()['fixed']??null)->required()->maxLength(12)->live(debounce:500);
                    $fields[]=\Filament\Schemas\Components\Text::make('An estimate per payment. International cards and refunds can cost more, so set it a little high.')->key('fee_note');
                }
                $newFee=static fn($get)=>CardFee::validate(['percent'=>$get('fee_percent'),'fixed'=>$get('fee_fixed')]);
                $entered=static function($get)use($name,$default,$saved,$newFee,$rule):?array{
                    if($name==='override'&&$get('pricing')==='default'){return $default();}
                    if($get('mode')!=='fixed'&&self::margin($get('markup'),$rule()['markup']??null,$get('mode'),$rule()['mode']??null)!==null){return null;}
                    try{return PricingRule::validate(['mode'=>$get('mode'),'markup'=>$get('markup')??'0','round_up'=>$get('round_up')??'none',
                        'fixed'=>['register'=>$get('fixed_register'),'renew'=>$get('fixed_renew'),'transfer'=>$get('fixed_transfer')],
                        ...($get('mode')==='markup_fee_percent'?['card_fee'=>$name==='override'?$saved():$newFee($get)]:[])]);}
                    catch(\Throwable){return null;}
                };
                $fields[]=\Filament\Schemas\Components\Text::make(fn($get)=>($r=$entered($get))!==null?'Customer pays: '.PricingRule::describe($r)
                    :($get('mode')!=='fixed'&&($bad=self::margin($get('markup'),$rule()['markup']??null,$get('mode'),$rule()['mode']??null))!==null?$bad:'Enter prices to see what customers pay.'))->key('formula');
                if($name==='override'){$fields[]=\Filament\Schemas\Components\Html::make(fn($get)=>self::preview($tld,$entered($get)))->key('preview');}
                else{
                    $reach=self::cached(static fn()=>self::reach());
                    $feeMoves=static function($get)use($saved,$newFee):bool{try{return $newFee($get)!==$saved();}catch(\Throwable){return false;}};
                    $fields[]=\Filament\Schemas\Components\Text::make(fn()=>self::reachDefault($reach()))->key('reach');
                    $fields[]=\Filament\Schemas\Components\Text::make(fn()=>$reach()['own'].' '.($reach()['own']===1?'TLD':'TLDs').' with '.($reach()['own']===1?'its':'their').' own pricing '.($reach()['own']===1?'counts':'count').' the card fee and also '.($reach()['own']===1?'changes':'change').'.')->key('reach_own')->visible(fn($get)=>$reach()['own']>0&&$feeMoves($get));
                    $fields[]=\Filament\Schemas\Components\Text::make(fn()=>'Off in Paymenter, so skipped: '.implode(', ',$reach()['off']).'. Prices there catch up once it is back on.')->key('reach_off')->visible(fn()=>$reach()['off']!==[]);
                }
            }
                if($name==='tld_settings'){
                // Offer only what the saved account terms allow; the writer still checks every change.
                $allowed=self::cached(static fn()=>self::allowedTerms($server,$tld));
                $years=static function(string $bound)use($allowed):int{$a=$allowed();return $a===null?($bound==='min'?1:10):$a[$bound];};
                $fields[]=\Filament\Schemas\Components\Text::make(fn()=>($a=$allowed())===null?'':($a['register']===[]?'This account is not set up to sell new .'.$a['ending'].' registrations. Change sales under What you sell.':'This account is set up to sell .'.$a['ending'].' for '.self::years($a['register']).'. Change years under What you sell.'))->key('allowed')->visible(fn()=>$allowed()!==null);
                foreach(['min_years'=>'Minimum registration years','max_years'=>'Maximum registration years'] as $key=>$label){
                    $field=TextInput::make($key)->label($label)->integer()->minValue(fn()=>$years('min'))->maxValue(fn()=>$years('max'))->required()->default(fn()=>StaffCatalogue::routed()?->setting(Tld::findOrFail($tld),$key)??Tld::findOrFail($tld)->$key)
                        ->validationMessages(['min'=>fn()=>'Pick from '.$years('min').' to '.$years('max').'.','max'=>fn()=>'Pick from '.$years('min').' to '.$years('max').'.']);
                    // A failed rule keeps the dialog and its input open.
                    if($key==='max_years'){$field->rules([fn(\Filament\Schemas\Components\Utilities\Get $get)=>static function(string $attribute,mixed $value,\Closure $fail)use($get,$years):void{
                        if((int)$get('min_years')>(int)$value){$fail('The minimum is above the maximum. Pick from '.$years('min').' to '.$years('max').'.');}
                    }]);}
                    $fields[]=$field;
                }
                foreach(['enabled'=>'For sale','register'=>'Allow registrations','transfer'=>'Allow transfers','renew'=>'Allow renewals'] as $key=>$label){
                    $column=match($key){'enabled'=>'enabled',default=>$key.'_enabled'};
                    $field=Checkbox::make($key)->label($label)->default(fn()=>StaffCatalogue::routed()?->setting(Tld::findOrFail($tld),$key)??Tld::findOrFail($tld)->$column);
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
                $choices=self::cached(static fn()=>TldRegistrar::choices($tld));
                $fields[]=Select::make('seller')->label('Registrar for new orders')
                    ->options(fn()=>array_map(static fn($row)=>$row['label'],$choices()))
                    ->disableOptionWhen(fn($value)=>$choices()[(int)$value]['disabled']??true)
                    ->helperText(fn()=>new \Illuminate\Support\HtmlString(implode('<br>',array_map(static fn($row)=>e($row['label'].': '.$row['reason']),array_filter($choices(),static fn($row)=>$row['reason']!==null)))))
                    ->placeholder('None')->default(fn()=>$policy()?->server_id);
                $fields[]=Checkbox::make('register')->label('Allow registrations')->default(fn()=>$policy()?->register_enabled??false);$fields[]=Checkbox::make('transfer')->label('Allow transfers')->default(fn()=>$policy()?->transfer_enabled??false);
            }
            if($name==='renewal_billing'){
                $billing=static fn()=>\Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::read($server);
                $fields[]=TextInput::make('issue_days')->label('Issue invoices this many days before expiry')->integer()->minValue(2)->maxValue(365)->required()->default(fn()=>$billing()['issue_days']??21)->helperText('Default 21. Must be more than the due days; at most 365.')
                    ->rule(fn(\Filament\Schemas\Components\Utilities\Get $get)=>FieldRules::rule(static function(mixed $value,\Closure $fail)use($get):void {$due=filter_var($get('due_days'),FILTER_VALIDATE_INT);if($due!==false&&filter_var($value,FILTER_VALIDATE_INT)!==false&&(int)$value<=$due){$fail('Must be more than the due days ('.$due.').');}}));
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
            $out[]=Action::make($id.($setup&&$server!==Review::ALL_ACCOUNTS?'_'.$server:''))->label($label)->color(fn()=>$name==='processing'&&$processing()?'danger':'gray')->modalWidth('lg')
                ->visible(fn()=>!($setup&&$blocked())&&(!in_array($name,Review::PUBLISHES,true)||self::canSee(Review::permission('publish')))&&self::canSee(Staff::POLICY)&&(collect(Review::rights($name))->every(fn($right)=>self::canSee($right))||($name==='override'&&self::canSee(Review::permission('remove_override'))))&&self::featureVisible($server,$name))
                ->requiresConfirmation(!$switch||$popup)->modalHidden($switch&&!$popup)->modalHeading($setup?fn()=>$popup?$turn().' '.self::POPUPS[$id].'?':self::setupHeading($name,$registrar()!==null):$label)
                ->modalDescription($popup?fn()=>self::switchWarning($id,$server):($switch?null:($name==='probe'?fn()=>self::description($name).($registrar()==='namesilo'?' '.self::details($name):''):($name==='configure'?fn(Action $action)=>($action->getRawData()['registrar']??null)==='namesilo'?'Checks the key with one balance read, then saves it. Orders stay paused. This does not confirm who owns the account.':self::description($name):($name==='seller'&&$tld!==null?fn()=>self::sellerDescription((int)$tld):self::description($name))))))
                ->modalSubmitActionLabel($setup?fn()=>$popup?$turn():self::submitLabel($name,$registrar()!==null):($name==='override'?'Save and publish':'Confirm'))->schema($switch?[]:$fields)
                ->action(function(#[\SensitiveParameter] array $data,$livewire)use($id,$name,$server,$tld,$setup,$stamp,$switch){
                    try{$data=[...$data,...self::saved($id,$server)];
                        if($switch){$data+=['review_key'=>Review::ticket((int)Auth::id(),$server,$tld,$name,value($stamp)),'reason'=>self::switchOn($id,$server)?'Turned off':'Turned on'];}

                        $default=$name==='override'&&($data['pricing']??null)==='default';
                        $r=new Review((int)Auth::id(),$server,$tld,$default?'remove_override':$name,(string)($data[$default?'default_key':'review_key']??''),trim((string)($data['reason']??'')));
                        $summary=app(StaffSettingsWrites::class)->run($r,$data);$account=Account::where('server_id',$server)->first();
                        Notification::make()->status(($summary['audit_failed']??false)||($summary['failed']??[])!==[]||($summary['pending']??false)?'warning':'success')->title(match(true){($summary['audit_failed']??false)=>"Saved, but the result wasn't recorded",$name==='processing'=>$account->processing_enabled?'Orders resumed':'Orders paused',$name==='probe'=>'Balance updated',
                                $name==='enroll_sandbox'=>'Account connected',$name==='override'=>'Prices published',$setup=>'Saved',default=>'Change recorded'})
                            ->body($name==='processing'?self::republished($summary):($name==='probe'?null:($name==='default_pricing'?self::published($summary):($name==='enroll_sandbox'?'NameSilo accepted the key. Orders are paused and no TLDs are chosen yet.':null))))->send();
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
    private static function syncDescription(int $server): string|\Illuminate\Support\HtmlString { return self::description('import'); }
    /** One short line for a notification: only what staff must act on is named. The prices are on the page. */
    public static function syncSummary(array $result): string
    {
        if(($result['queued']??false)===true){return 'Price sync queued. Check sync progress on What you sell.';}
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
        try{if(($catalogue=StaffCatalogue::routed())!==null){return $account!==null&&$catalogue->readable(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account));}return $account!==null&&\Paymenter\Extensions\Servers\Domains\Services\TestCatalogue::enabled(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account));}
        catch(\Throwable){return false;}
    }
    /** Sync reads every TLD on the account, so a TLD page's button says so: it never looks like a one-row sync. The result
     * lands on the page staff are on, so the toast points there instead of promising a notice the worker doesn't send. */
    public static function syncAll(int $server,bool $named=false,?string $tld=null): Action
    {
        return Action::make('sync_prices_'.$server)->label(fn()=>'Sync prices'.($named?': '.(\App\Models\Server::find($server)?->name ?? 'Server #'.$server):''))->color('gray')
            ->visible(fn()=>self::canSee(Staff::POLICY)&&self::canSee(Review::permission('import'))&&self::syncOffered(Account::where('server_id',$server)->first()))
            ->requiresConfirmation()->modalHeading($tld===null?'Sync prices':'Sync prices for every TLD on this account?')
            ->modalDescription(fn()=>$tld===null?self::syncDescription($server):'Gets current costs from the registrar for every TLD this account sells, not only .'.$tld.'. It runs in the background.')
            ->modalSubmitActionLabel('Sync prices')
            ->fillForm(fn()=>['review_key'=>Review::ticket((int)Auth::id(),$server,null,'import',Review::stamp($server,null))])
            ->schema([Hidden::make('review_key')])
            ->action(function(#[\SensitiveParameter] array $data,$livewire)use($server,$tld){
                try{
                    if(StaffCatalogue::routed()!==null){app(StaffSettingsWrites::class)->run(new Review((int)Auth::id(),$server,null,'import',(string)($data['review_key']??''),''),[]);}else{\Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices::enqueue((int)Auth::id(),$server,(string)($data['review_key']??''));}
                    Notification::make()->success()->title('Price sync queued')->body(self::syncQueued($tld))->send();
                }catch(\Throwable $error){
                    if(!$error instanceof \DomainException){\Illuminate\Support\Facades\Log::warning('Domains price sync failed.',['exception_class'=>$error::class]);}
                    Notification::make()->danger()->title("Couldn't sync prices")->body(\Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices::isQueueRefusal($error)?$error->getMessage():self::refusal($error,'import',[],$server))->send();
                }finally{$livewire->mountedActions=[];}
            });
    }
    /** The queued toast: where the result shows. The worker sends no result notice, so it never promises one. */
    public static function syncQueued(?string $tld): string
    { return 'You can keep working. '.($tld===null?'Check sync progress on What you sell.':'Last updated on this page changes when it finishes.'); }
    /** Live preview: each sold term's cost, current price and the price saving would publish. */
    private static function preview(?int $tld,?array $rule): \Illuminate\Support\HtmlString
    {
        $t=$tld?Tld::find($tld):null;
        if($t===null||$rule===null){return new \Illuminate\Support\HtmlString('');}
        $now=now()->toDateTimeImmutable();
        try{$rows=(StaffCatalogue::routed() ?? throw new \DomainException(\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE))->preview($t,$rule);}catch(\Throwable){$rows=[];}
        if($rows===[]){return new \Illuminate\Support\HtmlString('<p>Exact registrar costs missing. '.e(TldFacts::refreshText(TldFacts::snapshot($t)['refresh']??[])).'</p>');}
        $costs=Staff::can(Staff::COSTS);$money=static fn(?string $v)=>$v===null?'None':'$'.preg_replace('/(\.\d{2}\d*?)0+$/D','$1',$v);
        $cell=static fn(string $html)=>'<td>'.$html.'</td>';
        $html='<div class="domains-preview"><table cellpadding="6"><thead><tr>';
        foreach(['Price',...($costs?['Cost']:[]),'Now','After saving'] as $label){$html.='<th scope="col">'.e($label).'</th>';}
        $html.='</tr></thead><tbody>';$outdated=false;
        foreach($rows as $row){
            $outdated=$outdated||(!$row['synced']&&$row['cost']!==null);
            $note=match(true){$row['cost']===null=>TldFacts::NOT_OFFERED,!$row['synced']=>'Cost out of date',$row['hold']==='below_cost'=>'Below cost: not sold',
                $row['alert']==='below_cost'=>'Below cost: still billed',$row['hold']!==null=>'Not sold',default=>null};
            $html.='<tr>'.$cell(e(ucfirst($row['action']).', '.$row['term'].' '.($row['term']===1?'year':'years')))
                .($costs?$cell(e($money($row['cost']))):'').$cell(e($money($row['current'])))
                .$cell(e(in_array($row['hold'],[null,'below_cost'],true)?$money($row['new']):'Not sold').($note===null?'':'<div class="domains-warn">'.e($note).'</div>')).'</tr>';
        }
        $html.='</tbody></table></div>';
        if($outdated){$html.='<p class="domains-warn">Saving publishes prices using saved costs. New sales with out-of-date costs stay off sale until Sync prices completes.</p>';}
        return new \Illuminate\Support\HtmlString($html);
    }
    /** What saving Default pricing publishes: TLDs on enabled accounts, and who waits for a price sync. */
    private static function reach(): array
    {
        $off=\App\Models\Server::where('extension','Domains')->where('enabled',false)->whereIn('id',Account::pluck('server_id'))->orderBy('id')->pluck('name','id');
        $tlds=TldFacts::bound(Tld::query())->whereNotIn('server_id',$off->keys())->orderBy('tld')->get();
        $own=static fn(Tld $t)=>\Paymenter\Extensions\Servers\Domains\Catalogue\Pricing::own($t);
        $tlds=$tlds->filter(function(Tld $t)use($own):bool{
            try{$rule=$own($t);return $rule===null||$rule['mode']==='markup_fee_percent';}
            // The table's sell-note names unreadable own rules and their fix; they cannot follow this save.
            catch(\DomainException|\InvalidArgumentException){return false;}
        });
        $followers=$tlds->filter(fn($t)=>$own($t)===null);
        $prices=\Paymenter\Extensions\Servers\Domains\Models\Price::whereIn('server_id',$followers->pluck('server_id')->unique())->whereIn('tld',$followers->pluck('tld')->unique())->where('currency','USD')->orderBy('id')->get()
            ->groupBy(fn($p)=>$p->server_id.':'.$p->registrar_key.':'.$p->account_fingerprint.':'.$p->tld);
        $now=now()->toDateTimeImmutable();
        $waiting=$followers->filter(function(Tld $t)use($now,$prices){
            $rows=$prices->get($t->server_id.':'.$t->registrar_key.':'.$t->account_fingerprint.':'.$t->tld,collect());
            if($rows->isEmpty()){return true;}
            foreach($rows as $row){try{if($row->catalogue_observation===null||!\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::cost(\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($row->catalogue_observation))->fresh($now)){return true;}}catch(\Throwable){return true;}}
            return false;
        });
        return ['default'=>$followers->count(),'own'=>$tlds->count()-$followers->count(),'waiting'=>$waiting->pluck('tld')->unique()->values()->all(),'off'=>$off->values()->all()];
    }
    private static function reachDefault(array $reach): string
    {
        $count=$reach['default'];$waiting=$reach['waiting'];
        if($count===0){return 'No TLDs use Default pricing yet.';}
        return $count.' '.($count===1?'TLD uses':'TLDs use').' Default pricing.'.($waiting===[]?'':' Costs are missing for '.\Paymenter\Extensions\Servers\Domains\Presentation\TldNames::summary($waiting).'. '.(count($waiting)===1?'Its prices stay':'Their prices stay').' the same until you sync prices.');
    }
    /** Notification text after resuming Orders or changing price freshness: the TLDs whose prices are not on sale yet. */
    public static function republished(?array $summary): ?string
    {
        $failed=$summary['failed']??[];$left=(int)($summary['left']??0);
        $later=$left>0?$left.' more '.($left===1?'TLD goes':'TLDs go').' back on sale in the background, about '.\Paymenter\Extensions\Servers\Domains\Catalogue\OfferWork::BATCH.' a minute.':null;
        if($failed!==[]){return implode(' ',array_filter(['Prices for '.\Paymenter\Extensions\Servers\Domains\Presentation\TldNames::summary($failed).' are not published, so '.(count($failed)===1?'it is':'they are').' not for sale. Open Edit prices on the TLD and save.',$later]));}
        return ($summary['pending']??false)?'Prices are not published yet, so TLDs are not for sale until the next background run. Open Edit prices on a TLD and save to publish it now.':$later;
    }
    /** Notification text after a Default pricing save. Each account that failed is named. */
    private static function published(array $summary): string
    {
        $count=$summary['published'];$failed=[];
        foreach($summary['failed'] as $account=>$tlds){$failed[]=$account.($tlds===[]?'':' ('.implode(', ',$tlds).')');}
        return implode(' ',array_filter([
            $count===0?'No prices changed.':'Published new prices for '.$count.' '.($count===1?'TLD':'TLDs').'.',
            $failed===[]?null:'Could not publish '.implode(', ',$failed).'. Check the exact missing cost or pricing rule on the TLD page. Sync prices is an optional retry.',
            $summary['skipped']===[]?null:'Off in Paymenter, so skipped: '.implode(', ',$summary['skipped']).'.',
            ($summary['audit_failed']??false)?"Nothing was undone: your change and any prices published stay in place. Only the record of this result couldn't be saved.":null,
        ]));
    }
    /** Keep Off reachable after deployment evidence is withdrawn. Writers recheck current permissions. */
    private static function featureVisible(int $server,string $name): bool
    {
        if(!preg_match('/^(test|live)_(renewal|management|transfer)$/',$name,$m)){return true;}
        try{
            $a=Account::where('server_id',$server)->sole();
            if($a->environment!==$m[1]){return false;}
            $b=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($a);
            $class=match($m[2]){'renewal'=>\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::class,'management'=>\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::class,default=>EnomTestTransfer::class};
            return $class::enabled($b)||$class::stored($b)||($m[2]==='renewal'&&\Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::anyOn($server));
        }catch(\Throwable){return false;}
    }
    /** Renewals, DNS management and transfers are on when their saved acceptance is current. */
    private static function accepted(int $server,string $name): bool
    {
        if(!preg_match('/^(test|live)_(renewal|management|transfer)$/',$name,$m)){return false;}
        try{$b=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id',$server)->firstOrFail());
            return match($m[2]){'renewal'=>\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::on($b),'transfer'=>EnomTestTransfer::stored($b),default=>\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::stored($b)};}
        catch(\Throwable){return false;}
    }
    private static function switchOn(string $id,int $server): bool
    {
        return match($id){'processing'=>(bool)Account::where('server_id',$server)->value('processing_enabled'),
            'late_renewal'=>\Paymenter\Extensions\Servers\Domains\Services\EnomLateRenewal::stored(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id',$server)->sole())),
            'renew_early'=>\Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::read($server)['manual'],
            'renewals'=>\Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::anyOn($server),default=>self::accepted($server,$id)};
    }
    /** What the writer needs beyond the form. A switch sends the opposite of what is saved; its ticket refuses a page that showed something else.
     * Only a TEST switch vouches for its own check: no real money moves there. A Live switch rechecks activation and setup before turning on. */
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
        return match($id){'late_renewal'=>$on?'Late renewal pauses until you turn this back on; unsent paid renewals return their payment at the deadline.':'Expired domains can renew for one year after fresh registrar checks.', 'processing'=>$on?'New orders and renewals wait until you turn this back on.':'New orders and renewals start going to the registrar.',
            'test_transfer','live_transfer'=>$on?'New transfers stop. Transfers already sent keep going.':'Customers can order transfers. Paid transfers go to the registrar, which takes the cost from this account’s balance.',
            'test_management','live_management'=>$on?'Customers can’t change DNS records, nameservers or contacts on this account’s domains until you turn this back on. Changes already sent keep going.':'Customers can change DNS records, nameservers and contacts. Their changes go to the registrar.',
            'renewals'=>$on?'New renewal invoices stop. Issued invoices stay payable. Paid renewals still go to the registrar.':'Customers start getting renewal invoices and can renew early.',
            default=>$on?'New renewal invoices stop. Issued invoices stay payable. Paid renewals wait until you turn this back on.':'Customers start getting renewal invoices. Paid renewals go to the registrar, which takes the cost from this account’s balance.'};
    }
    private static function setupLabel(string $name,bool $connected): string
    {
        return match(true){$name==='late_renewal'=>'Late renewal',$name==='processing'=>'Orders',$name==='configure'=>$connected?'Edit API credentials':'Connect account',
            $name==='enroll_sandbox'=>'Connect NameSilo test account',$name==='probe'=>'Test connection',
            $name==='renewal_billing'=>'Edit schedule',$name==='unpaid_hold'=>'Edit unpaid hold',$name==='default_pricing'=>'Default pricing',default=>Review::ACTIONS[$name]??$name};
    }
    /** Dialog title: what the button does, as a question. */
    private static function setupHeading(string $name,bool $connected): string
    {
        return match(true){$name==='renewal_billing'=>'Edit renewal schedule?',$name==='default_pricing'=>'Edit default pricing?',
            default=>self::setupLabel($name,$connected).'?'};
    }
    private static function submitLabel(string $name,bool $connected): string
    {
        return match(true){$name==='enroll_sandbox'||($name==='configure'&&!$connected)=>'Connect',
            $name==='probe'=>'Test connection',$name==='default_pricing'=>'Save and publish',default=>'Save'};
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
            $catalogue=StaffCatalogue::routed();
            if($catalogue!==null){$facts=$catalogue->page(collect([$t]))[$t->id]['facts'];$safe=$catalogue->restorable($t);$register=$safe['register']?$facts->terms['register']:[];
                $bounds=[...$register,$catalogue->setting($t,'min_years'),$catalogue->setting($t,'max_years')];
                return ['ending'=>$t->tld,'register'=>$register,'min'=>min($bounds),'max'=>max($bounds),'register_allowed'=>$safe['register'],'transfer_allowed'=>$safe['transfer']];}
            return null;
        }catch(\Throwable){return null;}
    }
    /** The real reason a change to a TLD's terms was refused, in plain words; null leaves the shared guidance. */
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
            'Catalogue publication evidence missing.','Catalogue publication evidence changed.'=>'Prices for '.$ending.' are missing or out of date. See the last successful refresh and next due on the TLD page. Sync prices is an optional retry.',
            'Retained holders require annual renewal support.'=>'Customers still own '.$ending.' domains here, so renewals must stay on.',
            default=>null};
        return $text===null?null:$text.' Nothing was saved.';
    }
    /** Says what stopped the send. Only over_limit, or no finer reason, is about the count. */
    private static function testRefusal(EnomTestCallLimit $error,string $name,int $server,array $data): string
    {
        $earlier=$name==='import'?'Earlier reads in this sync may have been sent and counted. ':'';
        return match($error->reason){
            'send_guard'=>'This site does not allow that Enom request, so it was not sent. '.$earlier.'Add it to the send guard list to allow it.',
            'bad_setting'=>'Our test send limit setting is missing or not valid, so this Enom request was not sent. '.$earlier,
            'unreadable'=>'We could not read our test send count, so this Enom request was not sent. '.$earlier.'Try again in a minute.',
            default=>'Our test send check stopped this Enom request before sending. '.$earlier.EnomTestSendLimit::facts(($account=Account::where('server_id',$server)->first())?EnomTestSendLimit::login($account):($data['uid']??null))};
    }
    private static function registrarName(array $data): string { return ['enom'=>'Enom','namesilo'=>'NameSilo'][$data['registrar']??'']??'The registrar'; }
    /** Staff text for a refused settings change; never echoes unrecognized exception text. */
    public static function refusal(\Throwable $error,string $name,array $data=[],int $server=0,?int $tld=null): string
    {
        if($name==='seller'&&($message=TldRegistrar::refusal($error,$data,$tld))!==null){return $message;}
        if($name==='tld_settings'&&$error instanceof \DomainException&&($text=self::termsRefusal($error->getMessage(),$data,$server,$tld))!==null){return $text;}
        if($name==='acceptance'&&$error->getMessage()==='Audit entry too large. Changes held.'){return 'That is too many changes for one save. Save fewer at a time. Nothing was saved.';}
        if($error instanceof EnomTestCallLimit&&(Account::where('server_id',$server)->value('environment')??($data['environment']??null))==='live'){return 'We stopped this before it reached Enom. Earlier reads may have been sent. Check API calls on the account page.';}
        return $error instanceof EnomTestCallLimit?self::testRefusal($error,$name,$server,$data)
                :match($error->getMessage()){\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE=>\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::STAFF,'The calculated price is too large.'=>'This makes a price too large to save. Lower the markup or the card fee. Nothing was changed.','Processing state unchanged.'=>'Orders are already set that way. Nothing changed.',
                CardFee::INVALID=>'Enter a card fee from 0% to 99.99% and a fixed amount from $0 to $100,000, with up to two decimal places. Nothing was changed.',
                'This TLD is hidden.'=>"That TLD isn't offered here. Nothing was saved.",
                \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands::NO_SELLER=>'No registrar takes its new orders yet, so Sell would do nothing. Use Start selling on its TLD page to choose one. Nothing was saved.',
                \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands::OTHER_SELLER=>'Another registrar account takes its new orders, so Sell here would do nothing. Use Change registrar on its TLD page to move it. Nothing was saved.','Choose at least one year.'=>'Tick at least one year. Nothing was saved.','Nothing to save.'=>'Nothing changed. Nothing was saved.',
                'Acceptance exceeds encrypted settings capacity.','Invalid catalogue acceptance.'=>'That is more TLDs than one account can save. Turn some off. Nothing was saved.',
                'Settings changed. Reload this page.'=>$name==='enroll_sandbox'?'This expired, or someone else changed this account. '.self::NO_KEY_CHECK.' Try again.':'Someone else changed this. Nothing was saved. Try again.',
                'Current staff permissions required.'=>$name==='enroll_sandbox'?'Your permissions changed. '.self::NO_KEY_CHECK:'Your permissions changed. Nothing was saved.',
                'Runtime acceptance and shared workers are required.'=>($account=Account::where('server_id',$server)->first())?StaffSettingsWrites::processingBlocker($account)??'Orders setup changed. Reopen Orders and try again.':'This registrar account was removed. Choose another registrar.',
                'Pause processing on every legacy Enom server before installing or enabling Domains.'=>'Pause orders on the old Enom accounts first.',
                'TEST catalogue reads are unavailable.'=>'Sync prices is off for this account on this site. '.self::NOT_SENT,
                'Catalogue ending is not accepted.'=>'Add this TLD under What you sell first. '.self::NOT_SENT,
                'Catalogue import unavailable.'=>'Sync did not finish. Check saved prices and what is for sale. Some registrar reads may have been sent. Check What you sell, then try again.'
                    .($error instanceof \Paymenter\Extensions\Servers\Domains\Services\CatalogueImportFailure?' '.TldFacts::syncCause($error->failureCode).'.':''),
                'Connection checks are unavailable.'=>'Connection checks are off on this site. '.self::NOT_SENT,
                'identity_credentials_refused'=>'Enom refused the user ID or API token. Check both for this account type.',
                'identity_unavailable'=>'Enom did not confirm this account. Nothing was saved. Try again later.',
                \Paymenter\Extensions\Servers\Domains\Contracts\CredentialAuthenticator::UNCONFIRMED=>'Registrar key could not be confirmed. Nothing was saved. Check the API details and try again.',
                \Paymenter\Extensions\Servers\Domains\Contracts\CredentialAuthenticator::UNANSWERED=>self::registrarName($data)." didn't answer. Nothing was saved. Try again in a few minutes.",
                \Paymenter\Extensions\Servers\Domains\Contracts\CredentialAuthenticator::REFUSED=>self::registrarName($data).' refused this API key. Nothing was saved. Check the key, its account type and any IP limit set on it.',
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
                'Sandbox enrollment is immutable.'=>'This older test enrollment cannot rotate its key. Connect a new registrar account to use a different key. '.self::NOT_SENT,
                \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::FRESH_SERVER=>'This registrar account already has saved settings, records or domains. Use a new registrar account. '.self::NO_KEY_CHECK,
                'Invalid sandbox enrollment input.'=>'Check the label and key. The label must be one line of up to 128 bytes with no spaces at the start or end, and can’t contain the key. The key can be up to 256 characters, with no spaces or control characters. '.self::NOT_SENT,
                'Sandbox key check failed.'=>'Nothing was saved. NameSilo did not confirm the key. It may be wrong, or the reply may be missing or unclear. Check the key and try again. Trying again may send another key check.',
                'Sandbox enrollment could not be saved.'=>self::SAVED_OUTCOME_UNKNOWN,
                'namesilo_identity_unavailable'=>'NameSilo did not confirm the key. Check the API key and account mode. Nothing was saved.',
                'Review key used.'=>$name==='enroll_sandbox'?'This was already submitted, so no new key check was sent. An earlier try may still be running, and this page can’t show that. Wait for it to finish. '.self::OUTCOME_NEXT:'This was already submitted. Check this page, then try again if you need to.',
                'This percentage cannot cover the card fee. Lower the markup or card fee.'=>'No price can cover the card fee with this markup. Lower the markup or the card fee. Nothing was changed.',
                'Costs required before publication.'=>'Exact registrar costs are missing. See the last successful refresh and next due on the TLD page. Sync prices is an optional retry. Nothing was changed.',
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
                default=>match($name){'enroll_sandbox'=>self::GENERIC_OUTCOME_UNKNOWN,'seller'=>'The registrar change could not be saved. Reopen Change registrar and try again.',default=>'Check this page, then try again. If it fails again, open System checks on the registrar account.'}};
    }
}

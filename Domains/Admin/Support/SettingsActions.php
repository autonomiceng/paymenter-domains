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
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,EnomTestSendLimit,CardFee,PriceCatalogue,PricingRule,PricingRules,StaffSettingsReview as Review,StaffSettingsWrites};
final class SettingsActions
{
    private const OUTCOME_NEXT='Open the account page before you try again. Orders paused: it saved, so check the label is yours. Not connected and not set up yet: nothing saved. Off in Paymenter: turn it on in Servers first. Anything else: don’t try again here. Ask your site admin. Trying again may send another key check.';
    public const SAVED_OUTCOME_UNKNOWN='NameSilo accepted the key, but we can’t tell if the account saved. '.self::OUTCOME_NEXT;
    public const GENERIC_OUTCOME_UNKNOWN='We can’t tell if a key check was sent or if the account saved. '.self::OUTCOME_NEXT;
    private const ADMIN_ORDERS='This account can’t take orders on this site yet. Ask your site admin. Nothing was saved.';
    private const NO_KEY_CHECK='No key check was sent and nothing was saved.';
    private const NOT_SENT='Nothing was sent to the registrar.';
    private const LIFECYCLE_DAYS='Enter whole numbers from 0 to 365 for grace and restore days. Nothing was saved.';
    /** Keep one snapshot per form, including empty arrays and absent rows. */
    private static function cached(\Closure $read): \Closure
    {
        $loaded=false;$value=null;
        return static function()use($read,&$loaded,&$value){
            if(!$loaded){$value=$read();$loaded=true;}
            return $value;
        };
    }
    private static function missingIdentity(): \Filament\Schemas\Components\Text
    { return \Filament\Schemas\Components\Text::make('Missing: saved account identity. Restore it before you turn this on.'); }
    /** The one plain sentence a settings dialog opens with. Detail lives in details(). */
    public static function description(string $name): string
    {
        return match($name){
            'lifecycle'=>'Set how long after expiry a domain can still be renewed or restored, and what the registrar charges.',
            'live_management'=>'Lets DNS, nameserver and contact changes go to the registrar for this live account.',
            'live_renewal'=>'Lets paid renewals go to the registrar for this live account.',
            'test_management'=>'Lets DNS, nameserver and contact changes go to the registrar for this test account.',
            'test_renewal'=>'Lets paid renewals go to the registrar for this test account.',
            'alert_review'=>'Mark this as checked. Nothing is sent. If it fails again, the alert comes back.',
            'delivery_review'=>'Mark this notification as checked. It is not sent again.',
            'processing'=>'Lets orders and renewals start for this account.',
            'renewal_billing'=>'Set when renewal invoices go out and if owners can ask for one.',
            'card_fee'=>'Sets the estimated card fee for this account. Saving publishes new prices for TLDs whose pricing adds a percentage to cost and card fee. Other prices and issued invoices stay the same.',
            'default_rule'=>'Sets the pricing every TLD on this account uses unless it has its own. Saving publishes new prices for those TLDs at once. Issued invoices keep their prices.',
            'override'=>'Saving publishes these prices at once. Registrations and transfers below cost are not sold; renewals below cost are still billed. Both alert staff. Issued invoices keep their prices.',
            'remove_override'=>'Uses this account’s default pricing. Saving publishes the new prices at once. Issued invoices keep their prices.',
            'publish'=>'Publishes prices from saved costs and the current pricing. It does not refresh costs or change issued invoices.',
            'tld_settings'=>'Choose the years customers can buy and what is for sale.',
            'seller'=>'Choose the registrar for new orders. Existing domains stay where they are.',
            'import'=>'Gets current costs from the registrar for the TLDs this account sells.',
            'history'=>'Use this account’s transaction history to match charges. Clear this to stop. Saved records stay.',
            'enroll_sandbox'=>'Checks the key with one balance read, then saves it. A key can only be used once, and you can’t change it later.',
            'configure'=>'Checks these credentials with one read, then saves them. Saving pauses orders until you resume them.',
            'probe'=>'Reads the balance once and shows it on this page.',
        };
    }
    /** The rest of what staff may want to know, behind the dialog's "?" helper. Null when the sentence says it all. */
    public static function details(string $name): ?string
    {
        $live=static fn(array $p)=>$p['route'].', the '.\Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::REQUEST_SECONDS.'-second gap between requests';
        $liveRoute=['route'=>\Paymenter\Extensions\Servers\Domains\Services\EnomLive::route()];
        return match($name){
            'lifecycle'=>'Check these with the registrar first and note where you checked. They stay until you change them. If a value is missing or your sources disagree, late renewals and restores stay off. Grace renewal is off for TLDs that only renew automatically. Customers pay the registrar charge plus the normal renewal markup. Saving does not turn on owner checkout or restores.',
            'live_management'=>'Check the commands at '.$live($liveRoute).' and that changes read back correctly. This is separate from test accounts. DNS tools have their own setting. Changes already sent are checked at the registrar, not sent again.',
            'live_renewal'=>'Check Extend at '.$live($liveRoute).' and that this account’s balance is in USD. This covers normal yearly renewals only. Renewal invoices, owner requests and orders have their own settings.',
            'test_management'=>'Check '.implode(', ',($p=\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::protocol())['commands']).' at '.$p['route'].' and the '.$p['interval'].'-second gap between requests. A change finishes only when a read confirms it. If no read confirms it, it is not sent again. DNS tools need a setting on this site and a full, current read of the records. Turning this off holds changes not yet sent; they go out if you turn it back on. Turning this on also starts background reads of domains and records. These use our test send limit for this registrar login, shared with Purchase and Extend, which caps sends over any 24 hours and per minute. Changes already sent keep being checked while this site’s setting stays on.',
            'test_renewal'=>'Check Extend at '.($p=\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::protocol())['route'].', the '.$p['interval'].'-second gap between requests, and that this account’s balance is in USD. When this site allows it, each paid yearly renewal can send one Extend. This does not turn on renewal invoices, owner requests or orders. Turning it off stops new Extend requests. Renewals already sent keep being checked while this site’s setting stays on.',
            'processing'=>'Check What you sell and System checks on this page first.',
            'renewal_billing'=>'Scheduled invoices go out from the issue day until the due day for domains with auto-renew on. Owner requests let a customer ask for a renewal invoice for an active domain at any time before the confirmed expiry date. Both need orders on and a confirmed expiry date. Turning either off stops new invoices only. Issued invoices stay payable and keep their dates, term and amount.',
            'tld_settings'=>'You can always offer fewer years or turn sales off. More years, or turning sales back on, must match this account’s setup. Change years and sales under What you sell. Renewals stay on while customers own domains with this TLD. On some test accounts, cart items that no longer fit are removed.',
            'seller'=>'Renewals stay with the registrar that holds the domain. If this one can’t sell, no other takes over. On some test accounts, this TLD’s cart items from the old registrar are removed.',
            'history'=>'Check that the history is complete across all pages and in USD. Use it for this request type only. Clearing this stops new history reads. Saved requests and money records stay.',
            'enroll_sandbox'=>'One read-only balance request goes to the NameSilo test system. The balance is not saved. NameSilo does not confirm who owns the key, so the label is your own note. Orders stay paused and no TLDs are chosen. The next background run may send one scheduled balance read, then at most one a day. If NameSilo refuses that read, it shows as Read failed. Test connection can send one more balance request. To change the key or label, use a different test key on a new registrar account. Domains, What you sell and requests stay with the old one.',
            'configure'=>'Credentials are saved encrypted. Once connected, the account can’t change.',
            'probe'=>'For a NameSilo test account, a good read shows the key still works. It does not show who owns it. This does not turn on orders or sales.',
            default=>null,
        };
    }
    /** The alerts Mark alert checked can close, newest first. A search reaches past the first 100; $id looks up one choice. @return array<int,string> */
    public static function openAlerts(int $server,string $search='',?int $id=null): array
    {
        $types=array_intersect_key(\Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::LABELS,array_flip(['template_failed','reminder_failed']));
        return self::openNotices('domains_obligation_alerts',$server,'code',$types,$search,$id)->whereIn('n.code',array_keys($types))->whereNull('n.resolved_at')
            ->whereColumn('d.authority_generation','n.generation')->get(['n.id','n.code','n.first_seen','d.domain'])
            ->mapWithKeys(fn(object $a)=>[$a->id=>self::noticeLabel($a->domain,$types[$a->code],(int)$a->first_seen,$a->id)])->all();
    }
    /** The notifications Mark notification checked can close, as openAlerts. @return array<int,string> */
    public static function openDeliveries(int $server,string $search='',?int $id=null): array
    {
        $types=\Paymenter\Extensions\Servers\Domains\Services\ReminderTemplates::names();
        return self::openNotices('domains_reminder_deliveries',$server,'template',$types,$search,$id)->whereIn('n.state',['handoff','uncertain'])->get(['n.id','n.template','n.created_at','d.domain'])
            ->mapWithKeys(fn(object $n)=>[$n->id=>self::noticeLabel($n->domain,$types[$n->template]??'Notification',(int)$n->created_at,$n->id)])->all();
    }
    private static function noticeLabel(string $domain,string $type,int $at,int $id): string
    { return $domain.' · '.$type.' · '.gmdate('M j, Y H:i',$at).' UTC · #'.$id; }
    /** @param array<string,string> $types Stored value => the words staff see, so those words can be searched. */
    private static function openNotices(string $table,int $server,string $typeColumn,array $types,string $search,?int $id): \Illuminate\Database\Query\Builder
    {
        $query=\Illuminate\Support\Facades\DB::table($table.' as n')->join('domains_domains as d','d.id','=','n.domain_id')->where('d.server_id',$server)
            ->where('d.account_fingerprint',Account::where('server_id',$server)->value('account_fingerprint'))->orderByDesc('n.id')->limit(100);
        if($id!==null){$query->where('n.id',$id);}
        $search=trim($search);if($search===''){return $query;}
        $reference=ltrim($search,'#');$like='%'.addcslashes($search,'%_\\').'%';
        $matching=array_keys(array_filter($types,fn(string $words)=>stripos($words,$search)!==false));
        return $query->where(fn($q)=>$q->where('d.domain','like',$like)->orWhereIn('n.'.$typeColumn,$matching)->when(ctype_digit($reference),fn($q)=>$q->orWhere('n.id',(int)$reference)));
    }
    /** Lists from the database, so a choice can sit outside the first 100. Filament also accepts a saved choice only if its label resolves. */
    private static function noticePicker(string $field,string $label,\Closure $open): Select
    {
        return Select::make($field)->label($label)->options(fn()=>$open())->searchable()->optionsLimit(100)->required()
            ->getSearchResultsUsing(fn(string $search)=>$open($search))->getOptionLabelUsing(fn($value)=>$open('',(int)$value)[(int)$value]??null);
    }
    /** One account setup action, keyed by its name (e.g. "processing"). */
    public static function setup(int $server,string $stamp,string $name): Action
    { return self::make($server,null,$stamp,true,[$name])[0]; }
    public static function make(int $server,?int $tld,string $stamp,bool $setup=false,?array $only=null): array
    {
        $out=[];$names=$setup?['enroll_sandbox','configure','probe','history','test_renewal','test_management','live_renewal','live_management','processing','renewal_billing','default_rule','card_fee','delivery_review','alert_review']:['override','tld_settings','lifecycle','seller'];
        if($only!==null){$names=array_values(array_intersect($names,$only));}
        $target=fn()=>\App\Models\Server::find($server)?->name ?? 'Server #'.$server;
        foreach($names as $name){
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
            if($name==='lifecycle'){
                $saved=self::cached(static function()use($server,$tld){
                    try{$a=Account::where('server_id',$server)->firstOrFail();$t=Tld::findOrFail($tld);
                    return \Paymenter\Extensions\Servers\Domains\Services\LifecyclePolicies::read(\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($a),$t->tld)??[];}
                    catch(\Throwable){return [];}
                });
                foreach(['grace_days'=>'Grace days','restore_days'=>'Restore days'] as $key=>$label){$fields[]=TextInput::make($key)->label($label)->integer()->minValue(0)->maxValue(365)->validationMessages(['integer'=>self::LIFECYCLE_DAYS,'min'=>self::LIFECYCLE_DAYS,'max'=>self::LIFECYCLE_DAYS])->required()->default(fn()=>$saved()[$key]??null);}
                foreach(['grace_supported'=>'Grace renewal offered','restore_supported'=>'Restore offered','auto_renew_only'=>'Auto-renew only','conflicting'=>'Sources disagree'] as $key=>$label){$fields[]=Checkbox::make($key)->label($label)->default(fn()=>$saved()[$key]??false);}
                foreach([''=>'Policy','grace_'=>'Extra grace charge','restore_'=>'Restore fee'] as $prefix=>$label){
                    if($prefix!==''){
                        $amount=$prefix==='grace_'?'grace_extra':'restore_fee';
                        $fields[]=TextInput::make($amount)->label($label.' amount')->required()->maxLength(14)->default(fn()=>$saved()[$amount]??null)->helperText('What the registrar charges. Enter 0 if it’s free.');
                        $fields[]=TextInput::make($prefix.'currency')->label($label.' currency')->required()->maxLength(3)->default(fn()=>$saved()[$prefix.'currency']??null);
                    }
                    $fields[]=TextInput::make($prefix.'verified_at')->label($label.' date checked')->required()->maxLength(25)->default(fn()=>$saved()[$prefix.'verified_at']??null)->helperText('Date you checked this, with a UTC offset. For example, 2026-10-22T12:00:00Z.');
                    $fields[]=TextInput::make($prefix.'source')->label($label.' source')->required()->minLength(5)->maxLength(500)->default(fn()=>$saved()[$prefix.'source']??null)->helperText('Where you checked this. Do not include credentials.');
                }
                $fields[]=Checkbox::make('verified')->label('I checked these with the registrar')->accepted()->required();
            }
            if($name==='seller'){
                $policy=self::cached(static fn()=>\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',Tld::findOrFail($tld)->tld)->first());
                $fields[]=Select::make('seller')->label('Registrar for new orders')->options(fn()=>\App\Models\Server::whereIn('id',Account::pluck('server_id'))->orderBy('id')->pluck('name','id')->all())->placeholder('None')->default(fn()=>$policy()?->server_id);
                $fields[]=Checkbox::make('register')->label('Allow registrations')->default(fn()=>$policy()?->register_enabled??false);$fields[]=Checkbox::make('transfer')->label('Allow transfers')->default(fn()=>$policy()?->transfer_enabled??false);
            }
            if(in_array($name,['live_renewal','live_management'],true)){
                $liveAccount=Account::where('server_id',$server)->first();
                try{$b=$liveAccount?\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($liveAccount):null;}
                catch(\Throwable){$b=null;}
                if($b===null){$fields[]=self::missingIdentity();}
                else{
                    $fields[]=Checkbox::make('accept')->label($name==='live_renewal'?'Allow live renewals on this account':'Allow live DNS, nameserver and contact changes')->helperText('Leave this clear to turn it off.');
                    $fields[]=Checkbox::make('verified')->label('I checked this on the live account');
                }
            }
            if($name==='test_management'){
                $fields[]=Checkbox::make('accept')->label('Allow test DNS, nameserver and contact changes')->helperText('Leave this clear to turn it off.');
                $fields[]=Checkbox::make('verified')->label('I checked this on the test account');
            }
            if($name==='test_renewal'){
                $fields[]=Checkbox::make('accept')->label('Allow test renewals')->helperText('Not during grace or redemption. Leave this clear to turn it off.');
                $fields[]=Checkbox::make('verified')->label('I checked this on the test account');
            }
            if($name==='history'){
                $fields[]=Select::make('history_action')->label('Request type')->options(['register'=>'Registration','renew'=>'Renewal','transfer'=>'Transfer'])->required();
                $fields[]=Checkbox::make('accept')->label('Use this history');
                $fields[]=TextInput::make('timezone')->label('History timezone')->maxLength(64);
                $fields[]=TextInput::make('lag')->label('Longest delay (seconds)')->integer()->minValue(0)->maxValue(86400);
                $fields[]=TextInput::make('retention')->label('History kept for (seconds)')->integer()->minValue(86400)->maxValue(31536000);
                $fields[]=Checkbox::make('verified')->label('I checked the history and these limits');
            }
            if($name==='alert_review'){$fields[]=self::noticePicker('alert_id','Alert',fn(string $search='',?int $id=null)=>self::openAlerts($server,$search,$id));$fields[]=Checkbox::make('confirmed')->label('I checked this notification failure')->accepted()->required();}
            if($name==='delivery_review'){$fields[]=self::noticePicker('delivery_id','Notification',fn(string $search='',?int $id=null)=>self::openDeliveries($server,$search,$id));$fields[]=Checkbox::make('confirmed')->label('I checked the outcome. Do not send it again.')->accepted()->required();}
            if($name==='processing'){
                $fields[]=Hidden::make('enabled')->default(fn()=>!Account::where('server_id',$server)->value('processing_enabled'));
                $fields[]=Checkbox::make('confirmed')->label(fn()=>'I checked '.$target())->accepted()->required();
            }
            if($name==='renewal_billing'){
                $billing=static fn()=>\Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::read($server);
                $fields[]=Checkbox::make('scheduled')->label('Issue scheduled renewal invoices')->default(fn()=>$billing()['scheduled']);
                $fields[]=Checkbox::make('manual')->label('Let owners request renewal invoices')->default(fn()=>$billing()['manual']);
                $fields[]=TextInput::make('issue_days')->label('Issue invoices this many days before expiry')->integer()->minValue(2)->maxValue(365)->required()->default(fn()=>$billing()['issue_days']??21)->helperText('Default 21. Must be more than the due days; at most 365.');
                $fields[]=TextInput::make('due_days')->label('Invoices fall due this many days before expiry')->integer()->minValue(1)->maxValue(364)->required()->default(fn()=>$billing()['due_days']??7)->helperText('Default 7. At least 1.');
                $fields[]=Checkbox::make('confirmed')->label(fn()=>'I checked renewal invoices for '.$target())->accepted()->required();
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
            $on=fn()=>self::accepted($server,$name);
            $label=$setup?fn()=>self::setupLabel($name,$processing(),$registrar()!==null,$on()):Review::ACTIONS[$name];
            $out[]=Action::make($name.($setup?'_'.$server:''))->label($label)->color(fn()=>$name==='processing'&&$processing()?'danger':'gray')->modalWidth('lg')
                ->visible(fn()=>!($setup&&$blocked())&&(!in_array($name,Review::PUBLISHES,true)||Staff::can(Review::permission('publish')))&&($name!=='lifecycle'||Staff::can(Staff::COSTS))&&(!str_starts_with($name,'live_')||(config('domains.enom_live.'.substr($name,5))===true&&Account::where('server_id',$server)->where('registrar_key','enom')->where('environment','live')->exists()))&&Staff::can(Staff::POLICY)&&(Staff::can(Review::permission($name))||($name==='override'&&Staff::can(Review::permission('remove_override'))))&&($name!=='test_management'||(config('domains.enom_writes.management')===true&&Account::where('server_id',$server)->where('registrar_key','enom')->where('environment','test')->exists()))&&($name!=='test_renewal'||(config('domains.enom_writes.renewal')===true&&Account::where('server_id',$server)->where('registrar_key','enom')->where('environment','test')->exists())))
                ->requiresConfirmation()->modalHeading($setup?fn()=>self::setupHeading($name,$processing(),$registrar()!==null,$on()):$label)
                ->modalDescription($name==='processing'?fn()=>$processing()?'New registrar requests on this account wait until you resume.':'Registrar requests on this account start sending again.':($name==='configure'?fn(Action $action)=>($action->getRawData()['registrar']??null)==='namesilo'?"NameSilo accounts can't be connected with API credentials.":self::description($name):self::description($name)))
                ->modalSubmitActionLabel($setup?fn()=>self::submitLabel($name,$processing(),$registrar()!==null,$on()):($name==='override'?'Save and publish':'Confirm'))->schema($fields)
                ->action(function(#[\SensitiveParameter] array $data,$livewire)use($name,$server,$tld,$setup){
                    try{$default=$name==='override'&&($data['pricing']??null)==='default';
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
            $text=($count===0?'This account sells no TLDs yet, so there is nothing to sync.':'Gets current costs from '.self::seller($account).' for the '.$count.' '.($count===1?'TLD':'TLDs').' this account sells: '.implode(', ',$endings).'.')
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
        return $text.($waiting->isEmpty()?'':' Costs are missing for '.$waiting->map(fn($t)=>'.'.$t->tld)->implode(', ').'. '.($waiting->count()===1?'Its prices':'Their prices').' stay the same until you sync prices and save again.');
    }
    /** Notification text after a pricing or card fee change. */
    private static function published(array $summary,string $name): string
    {
        $text=$summary['published']===0&&$summary['failed']===[]?($name==='card_fee'?'Card fee saved. No TLDs count the card fee in their prices, so no prices changed.':'No TLDs use the account default.'):'Published new prices for '.$summary['published'].' '.($summary['published']===1?'TLD':'TLDs').'.';
        return $text.($summary['failed']===[]?'':' Could not publish '.implode(', ',$summary['failed']).'. Use Sync prices on the TLD list, then save again.')
            .(($summary['audit_failed']??false)?" Nothing was undone: your change and any prices published stay in place. Only the record of this result couldn't be saved.":'');
    }
    /** Renewals and DNS management are on when their saved acceptance is current. */
    private static function accepted(int $server,string $name): bool
    {
        if(!preg_match('/^(test|live)_(renewal|management)$/',$name,$m)){return false;}
        try{$b=\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding(Account::where('server_id',$server)->firstOrFail());
            return $m[2]==='renewal'?\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::accepted($b):\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::accepted($b);}
        catch(\Throwable){return false;}
    }
    private static function setupLabel(string $name,bool $processing,bool $connected,bool $on): string
    {
        return match(true){$name==='processing'=>$processing?'Pause orders':'Resume orders',$name==='configure'=>$connected?'Edit API credentials':'Connect account',
            $name==='enroll_sandbox'=>'Connect NameSilo test account',$name==='probe'=>'Test connection',
            $name==='renewal_billing'=>'Edit invoices',$name==='default_rule'=>'Edit default pricing',$name==='card_fee'=>'Edit card fee',str_ends_with($name,'_renewal')||str_ends_with($name,'_management')=>$on?'Turn off':'Turn on',
            $name==='history'=>'Set up transaction history',$name==='delivery_review'=>'Mark notification checked',$name==='alert_review'=>'Mark alert checked',default=>Review::ACTIONS[$name]};
    }
    /** Dialog title: what the button does, as a question. */
    private static function setupHeading(string $name,bool $processing,bool $connected,bool $on): string
    {
        $feature=str_ends_with($name,'_renewal')?'renewals':(str_ends_with($name,'_management')?'DNS, nameservers and contacts':null);
        return match(true){$feature!==null=>($on?'Turn off ':'Turn on ').$feature.'?',$name==='renewal_billing'=>'Edit renewal invoices?',$name==='default_rule'=>'Edit default pricing?',$name==='card_fee'=>'Edit card fee?',
            default=>self::setupLabel($name,$processing,$connected,$on).'?'};
    }
    private static function submitLabel(string $name,bool $processing,bool $connected,bool $on): string
    {
        return match(true){$name==='processing'=>$processing?'Pause orders':'Resume orders',$name==='enroll_sandbox'||($name==='configure'&&!$connected)=>'Connect',
            $name==='probe'=>'Test connection',in_array($name,['default_rule','card_fee'],true)=>'Save and publish',str_ends_with($name,'_renewal')||str_ends_with($name,'_management')=>$on?'Turn off':'Turn on',default=>'Save'};
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
    /** Staff text for a refused settings change; never echoes unrecognized exception text. */
    public static function refusal(\Throwable $error,string $name,array $data=[],int $server=0,?int $tld=null): string
    {
        if($name==='tld_settings'&&$error instanceof \DomainException&&($text=self::termsRefusal($error->getMessage(),$data,$server,$tld))!==null){return $text;}
        if($name==='acceptance'&&$error->getMessage()==='Audit entry too large. Changes held.'){return 'That is too many changes for one save. Save fewer at a time. Nothing was saved.';}
        if($name==='card_fee'&&$error instanceof \InvalidArgumentException&&!in_array($error->getMessage(),['This percentage cannot cover the card fee. Lower the markup or card fee.','The calculated price is too large.'],true)){
            return 'Enter a card fee from 0% to 99.99% and a fixed amount from $0 to $100,000, with up to two decimal places. Nothing was changed.';
        }
        if($error instanceof EnomTestCallLimit&&Account::where('server_id',$server)->value('environment')==='live'){return 'We stopped this before it reached Enom. Earlier reads may have been sent. Check API calls on the account page.';}
        return $error instanceof EnomTestCallLimit
                ?'Our test send check stopped this Enom request before sending. '.($name==='import'?'Earlier reads in this sync may have been sent and counted. ':'')
                    .EnomTestSendLimit::facts(($account=Account::where('server_id',$server)->first())?EnomTestSendLimit::login($account):null)
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
                'Complete lifecycle facts required.'=>'Fill in every field. Nothing was saved.',
                'Invalid lifecycle input.'=>'Check the fields and try again. Nothing was saved.',
                'Invalid lifecycle support.'=>'Check the grace, restore, auto-renew and sources boxes. Nothing was saved.',
                'Lifecycle days must be between 0 and 365.'=>self::LIFECYCLE_DAYS,
                'Invalid lifecycle charge.'=>'Enter valid non-negative grace and restore charges with at most four decimal places. Nothing was saved.',
                'Invalid lifecycle currency.'=>'Enter three uppercase letters for grace and restore currencies, for example USD. Nothing was saved.',
                'Lifecycle verification requires a date and time with UTC offset.'=>'Include a date, time and UTC offset in each date checked. Nothing was saved.',
                'Invalid lifecycle verification time.'=>'Enter a valid date and time in each date checked. Nothing was saved.',
                'Enter a one-line lifecycle source between 5 and 500 characters.'=>'Enter one-line policy, grace and restore sources from 5 to 500 characters. Nothing was saved.',
                'Conflicting lifecycle settings require repair.'=>'Ask your site admin to fix the duplicate grace and restore settings. Nothing was saved.',
                'Independent lifecycle verification required.'=>'Check these with the registrar, then tick the box. Nothing was saved.',
                'Lifecycle verification cannot be in the future.'=>'Enter dates at or before now. Nothing was saved.',
                'Explicit renewal billing confirmation required.'=>'Tick the box to continue. Nothing was saved.',
                'Invalid renewal billing input.'=>'Check the fields and try again. Nothing was saved.',
                'Invalid renewal timing.'=>'Enter whole numbers of days. Nothing was saved.',
                'Valid billing calendar required.'=>'Issue days must be more than due days. Issue days can be at most 365 and due days at least 1. Nothing was saved.',
                'Renewal billing unchanged.'=>'Renewal billing is unchanged. Nothing was saved.',
                'Live Enom Purchase route acceptance is unavailable.'=>self::ADMIN_ORDERS,
                'Independent Live renewal review required.'=>'Tick the box to show you checked this on the live account. Nothing was saved.',
                'Live renewal deployment is unavailable.'=>'Live renewals are off for this account or site. Check the Renewals row on the account page. Nothing was saved.',
                'Independent Live management review required.'=>'Tick the box to show you checked this on the live account. Nothing was saved.',
                'Live management deployment is unavailable.'=>'Live changes are off for this account or site. Check the DNS, nameservers and contacts row on the account page. Nothing was saved.',
                'Independent TEST management review required.'=>'Tick the box to show you checked this on the test account. Nothing was saved.',
                'TEST management deployment is unavailable.'=>'Test changes are off for this account or site. Check the DNS, nameservers and contacts row on the account page. Nothing was saved.',
                'Independent TEST renewal review required.'=>'Tick the box to show you checked this on the test account. Nothing was saved.',
                'TEST renewal deployment is unavailable.'=>'Test renewals are off for this account or site. Check the Renewals row on the account page. Nothing was saved.',
                'Enom TEST acquisition route acceptance is unavailable.'=>self::ADMIN_ORDERS,
                'Enom TEST Purchase route acceptance is unavailable.'=>self::ADMIN_ORDERS,
                default=>$name==='enroll_sandbox'?self::GENERIC_OUTCOME_UNKNOWN:'Check this page, then try again. If it fails again, open System checks on the registrar account.'};
    }
}

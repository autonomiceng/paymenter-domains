<?php
// Enom 444f31c pricing/setup action layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;
use Filament\Actions\{Action,ActionGroup};
use Filament\Forms\Components\{Checkbox,CheckboxList,Hidden,Radio,Select,Textarea,TextInput};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,EnomTestCalls,PriceCatalogue,PricingRule,PricingRules,StaffSettingsReview as Review,StaffSettingsWrites};
final class SettingsActions
{
    private const NOT_ENROLLED='If no Enrollment row appears and Account binding reads “Not configured”, nothing was enrolled.';
    private const UNAVAILABLE_RECOVERY='If Enrollment reads “Unavailable”, an account record exists, but its current OTE enrollment cannot be confirmed. Do not make another enrollment attempt on this server. If it is off, re-enable it in Servers; ask the installation administrator to check that OTE enrollment is enabled on this site, then reload. If it remains unavailable and this account was enrolled, ask them to restore the original saved enrollment settings. If it was never enrolled, use a fresh Domains server for a new key.';
    private const RETRY_GUIDANCE='For “Not configured”, confirm the server is enabled and fresh, OTE enrollment is enabled, your permissions are current, and the key is not already enrolled on this site before submitting another review. Submitting another review may send another OTE key check.';
    public const SAVED_OUTCOME_UNKNOWN='One OTE key check was sent and NameSilo accepted the key; its balance was not saved. Enrollment may have been saved, but its outcome could not be confirmed. Reload the account page. If Enrollment reads “OTE only · immutable”, an enrollment was saved; check its label before treating it as yours. '.self::NOT_ENROLLED.' '.self::UNAVAILABLE_RECOVERY.' '.self::RETRY_GUIDANCE;
    public const GENERIC_OUTCOME_UNKNOWN='Enrollment did not finish normally, and its outcome could not be confirmed. An OTE key check may or may not have been sent. If it was sent, NameSilo may or may not have accepted the key. Reload the account page. If Enrollment reads “OTE only · immutable”, an enrollment was saved; check its label before treating it as yours. '.self::NOT_ENROLLED.' '.self::UNAVAILABLE_RECOVERY.' '.self::RETRY_GUIDANCE;
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
    { return \Filament\Schemas\Components\Text::make('Missing: saved account identity cannot be read. Restore the saved identity before accepting provider actions.'); }
    public static function description(string $name): string
    {
        if(config('domains.enom_identity.environment')==='live'&&in_array($name,['acceptance','import'],true)){
            return $name==='acceptance'?'Accept independently verified exact terms and annual renewal support for this live account. Review live Purchase below. TEST acceptance grants no live authority. Changes pause new sales until a new import.':'Reads one accepted ending from the live Enom account. Each request uses its daily allowance. Costs expire after 60 minutes. To publish prices, save Edit prices on the TLD page; select a seller separately. Processing remains separate.';
        }
        return match($name){
            'lifecycle'=>'Set how long after expiry a domain can still be renewed (grace) or restored, and what the registrar charges for each. Check these with the registrar first and note where you checked. They stay until you change them. If they are not set, or your sources disagree, late renewals and restores stay off. Grace renewal is off for TLDs that only renew automatically. Customers pay the registrar charge plus the normal renewal markup. Saving does not turn on owner checkout or restores.',
            'live_management'=>'Independently check the supported management commands at '.\Paymenter\Extensions\Servers\Domains\Services\EnomLive::route().', the '.\Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::REQUEST_SECONDS.'-second interval and current readback. Live acceptance is separate from TEST. DNS tools retain their separate setting. Sent changes reconcile without another write.',
            'live_renewal'=>'Independently check Extend at '.\Paymenter\Extensions\Servers\Domains\Services\EnomLive::route().', the '.\Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::REQUEST_SECONDS.'-second interval and this live account’s USD balance. Accept ordinary annual renewal only. Billing, owner requests and processing remain separate.',
            'test_management'=>'Record an independent check of '.implode(', ',\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::protocol()['commands']).' at '.\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::protocol()['route'].' and its '.\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::protocol()['interval'].'-second request interval. A change completes only after readback confirms it. If readback cannot confirm it, the change is not resent; only confirmation reads continue. DNS tools require a server setting set by the deployment and a complete, current read of existing records for whole-list editors. Clearing acceptance pauses changes not yet sent; they stay queued and send if acceptance is restored. Accepting also starts background domain and record reads for this account, which use Enom TEST daily calls shared with Purchase and Extend. Already sent changes keep read-only reconciliation while the deployment opt-in stays on.',
            'test_renewal'=>'Record that you checked Enom TEST Extend at '.\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::protocol()['route'].', its '.\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::protocol()['interval'].'-second request interval, and that this account’s balance is in USD. With the deployment opt-in, each paid annual renewal can send one Extend. This does not turn on renewal billing, owner renewal requests or processing. Clearing it stops new Extend requests; renewals already sent keep reconciling while the deployment opt-in stays on.',
            'alert_review'=>'Record an independent check of a notification failure. A continuing failure reopens the alert; this action sends no message.',
            'delivery_review'=>'Record an independently checked notification outcome. This retains the original handoff claim and never sends the notification again.',
            'processing'=>'Registrar purchases and renewals can start for this account. Enable only after reviewing its accepted provider features and worker configuration.',
            'renewal_billing'=>'Scheduled renewal invoices are issued from the issue day until the due day for domains with renewal on. Owner requests let a customer ask for a renewal invoice for an active domain at any time before the confirmed expiry date. Both still need processing on and a confirmed registration expiry. Turning either off stops new invoices only; issued invoices stay payable. Calendar changes apply to invoices issued later; issued invoices keep their dates, term and amount.',
            'default_rule'=>'Sets the pricing every TLD on this account uses unless it has its own. Saving publishes new prices for those TLDs at once. Issued invoices keep their prices.',
            'override'=>'Saving publishes these prices at once. Registrations and transfers below cost are not sold; renewals below cost are still billed. Both alert staff. Issued invoices keep their prices.',
            'remove_override'=>'Uses this account’s default pricing. Saving publishes the new prices at once. Issued invoices keep their prices.',
            'publish'=>'Publishes prices from saved costs and the current pricing. It does not refresh costs or change issued invoices.',
            'tld_settings'=>'Narrow term bounds or pause new sales. Widening and restoring sales require explicit verification and current acceptance. Retained holders require annual renewal support. For Enom TEST, ineligible cart lines are removed.',
            'seller'=>'Selects the registrar for new sales only. Existing domains and renewal obligations keep their holding registrar. No automatic fallback. For Enom TEST, previous seller cart lines for this ending are removed.',
            'import'=>'Reads one accepted TEST ending from Enom and imports current costs and registry requirements. To publish prices, save Edit prices on the TLD page; select a seller separately. Processing stays off; withdrawn registration, transfer and renewal support stay off. Costs expire 60 minutes after the read; re-import before continuing preview. An annual registration and renewal baseline takes five reads; each additional accepted term or transfer adds up to one. Existing TEST cart lines for this ending are removed.',
            'acceptance'=>config('domains.enom_writes.transfer')===true?'Accept only independently verified exact terms. For Enom TEST, review Purchase / TP_CreateOrder at the fixed TEST route and request interval below. Annual transfers also need accepted registry details, the transfer ending policy, processing and a recent successful USD balance read. Any change holds new sales on this account until a new import and removes its TEST cart lines. Retained holders keep accepted annual renewal support.':'Accept only independently verified exact terms. For opted-in Enom TEST, also review and accept the Purchase route and request interval shown below. Any change holds new sales for every TLD on this account until a new import and removes its TEST cart lines. Retained holders keep accepted annual renewal support.',
            'history'=>'Accept only a verified complete USD history contract for this action. Revocation stops new reconciliation reads; saved attempts and financial evidence stay retained.',
            'enroll_sandbox'=>'Sends one read-only balance request to NameSilo OTE to check whether the key works. The balance is not saved. NameSilo does not confirm which account owns the key; the account label is entered by staff and remains unconfirmed. If the check succeeds, the key is saved encrypted as an immutable OTE enrollment on this server. Processing starts off and no catalogue terms are accepted. The next worker tick may send one scheduled daily read through /apibatch/getAccountBalance; NameSilo has not confirmed OTE support for this route. An unsupported reply appears as Read failed; another daily attempt is no sooner than the next day. Later scheduled reads are limited to one per day. Check balance can make one separate OTE balance request and show the result on the account page. Each key can be enrolled once on this site. To change the key or label, use a different OTE key on a fresh Domains server. Holdings, acceptances and operations stay with the old enrollment.',
            'configure'=>'Sends one account read to check the supplied credentials, then saves them encrypted. The verified account cannot change. Saving pauses processing until it is re-enabled.',
            'probe'=>'Reads the balance once with saved credentials and shows it on the account page. For NameSilo OTE, a successful read shows the key still authenticates; it does not confirm account ownership. This does not enable processing, purchases or new sales.',
        };
    }
    /** One account setup action, keyed by its name (e.g. "processing"). */
    public static function setup(int $server,string $stamp,string $name): Action
    { return self::make($server,null,$stamp,true,[$name])[0]; }
    public static function make(int $server,?int $tld,string $stamp,bool $setup=false,?array $only=null): array
    {
        $out=[];$names=$setup?['enroll_sandbox','configure','probe','acceptance','import','history','test_renewal','test_management','live_renewal','live_management','processing','renewal_billing','default_rule','delivery_review','alert_review']:['override','tld_settings','lifecycle','seller'];
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
                $fields[]=Select::make('mode')->label('Pricing')->options(['markup_percent'=>'Add a percentage to cost','markup_amount'=>'Add an amount to cost','fixed'=>'Set my own price'])->required()->live()->default(fn()=>$rule()['mode'])->visible($own);
                $fields[]=TextInput::make('markup')->label(fn($get)=>$get('mode')==='markup_amount'?'Amount to add (USD)':'Percentage to add')->default(fn()=>$rule()['markup'])->required()->maxLength(12)->live(debounce:500)
                    ->visible(fn($get)=>$own($get)&&$get('mode')!=='fixed');
                $fields[]=Select::make('round_up')->label('Round up')->options(PricingRule::ROUNDING_LABELS)->default(fn()=>$rule()['round_up'])->required()->live()
                    ->visible(fn($get)=>$own($get)&&$get('mode')!=='fixed');
                foreach(['register'=>'Register','renew'=>'Renew','transfer'=>'Transfer'] as $key=>$label){$fields[]=TextInput::make('fixed_'.$key)->label($label.' price per year (USD)')->default(fn()=>$rule()['fixed'][$key])->maxLength(14)->live(debounce:500)
                    ->visible(fn($get)=>$own($get)&&$get('mode')==='fixed');}
                $entered=static function($get)use($name,$default):?array{
                    if($name==='override'&&$get('pricing')==='default'){return $default();}
                    try{return PricingRule::validate(['mode'=>$get('mode'),'markup'=>$get('markup')??'0','round_up'=>$get('round_up')??'none',
                        'fixed'=>['register'=>$get('fixed_register'),'renew'=>$get('fixed_renew'),'transfer'=>$get('fixed_transfer')]]);}
                    catch(\Throwable){return null;}
                };
                $fields[]=\Filament\Schemas\Components\Text::make(fn($get)=>($r=$entered($get))===null?'Enter prices to see what customers pay.':'Customer pays: '.PricingRule::describe($r))->key('formula');
                if($name==='override'){$fields[]=\Filament\Schemas\Components\Html::make(fn($get)=>self::preview($tld,$entered($get)))->key('preview');}
                else{$fields[]=\Filament\Schemas\Components\Text::make(fn()=>self::followers($server))->key('followers');}
            }
            if($name==='tld_settings'){
                foreach(['min_years'=>'Minimum registration years','max_years'=>'Maximum registration years'] as $key=>$label){$fields[]=TextInput::make($key)->label($label)->integer()->minValue(1)->maxValue(10)->required()->default(fn()=>Tld::findOrFail($tld)->$key);}
                foreach(['enabled'=>'Catalogue enabled','register'=>'Registrations enabled','transfer'=>'Transfers enabled','renew'=>'Annual renewal catalogue support'] as $key=>$label){$column=match($key){'enabled'=>'enabled',default=>$key.'_enabled'};$fields[]=Checkbox::make($key)->label($label)->default(fn()=>Tld::findOrFail($tld)->$column);}
                $fields[]=\Filament\Schemas\Components\Text::make('Catalogue support gates new sales. It does not authorize registrar renewals.');
                $fields[]=Checkbox::make('verified')->label('I verified widened terms or restored sales against current acceptance');
            }
            if($name==='lifecycle'){
                $saved=self::cached(static function()use($server,$tld){
                    try{$a=Account::where('server_id',$server)->firstOrFail();$t=Tld::findOrFail($tld);
                    return \Paymenter\Extensions\Servers\Domains\Services\LifecyclePolicies::read(\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($a),$t->tld)??[];}
                    catch(\Throwable){return [];}
                });
                foreach(['grace_days'=>'Post-expiry grace days','restore_days'=>'Restore days'] as $key=>$label){$fields[]=TextInput::make($key)->label($label)->integer()->minValue(0)->maxValue(365)->validationMessages(['integer'=>self::LIFECYCLE_DAYS,'min'=>self::LIFECYCLE_DAYS,'max'=>self::LIFECYCLE_DAYS])->required()->default(fn()=>$saved()[$key]??null);}
                foreach(['grace_supported'=>'Grace renewal supported','restore_supported'=>'Restore supported','auto_renew_only'=>'Auto-renew only','conflicting'=>'Sources conflict'] as $key=>$label){$fields[]=Checkbox::make($key)->label($label)->default(fn()=>$saved()[$key]??false);}
                foreach([''=>'Policy','grace_'=>'Extra grace charge','restore_'=>'Restore fee'] as $prefix=>$label){
                    if($prefix!==''){
                        $amount=$prefix==='grace_'?'grace_extra':'restore_fee';
                        $fields[]=TextInput::make($amount)->label($label.' amount')->required()->maxLength(14)->default(fn()=>$saved()[$amount]??null)->helperText('Registrar charge only. Enter 0 for a verified absence of a charge. No service fee.');
                        $fields[]=TextInput::make($prefix.'currency')->label($label.' currency')->required()->maxLength(3)->default(fn()=>$saved()[$prefix.'currency']??null);
                    }
                    $fields[]=TextInput::make($prefix.'verified_at')->label($label.' verified at')->required()->maxLength(25)->default(fn()=>$saved()[$prefix.'verified_at']??null)->helperText('Include UTC offset, for example 2026-10-22T12:00:00Z. Reopening this form never refreshes verification.');
                    $fields[]=TextInput::make($prefix.'source')->label($label.' source')->required()->minLength(5)->maxLength(500)->default(fn()=>$saved()[$prefix.'source']??null)->helperText('Policy document or account cost evidence. Do not include credentials.');
                }
                $fields[]=Checkbox::make('verified')->label('I independently verified these windows, support flags and registrar charges')->accepted()->required();
            }
            if($name==='seller'){
                $policy=self::cached(static fn()=>\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',Tld::findOrFail($tld)->tld)->first());
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
                if($account?->registrar_key==='enom'&&($account->environment==='live'||config('domains.enom_writes.registration')===true||config('domains.enom_writes.transfer')===true)){
                    try{$binding=\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($account);}
                    catch(\Throwable){$binding=null;$fields[]=self::missingIdentity();}
                    if($binding!==null){
                        $protocol=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding);
                        $fields[]=$account->environment==='live'
                            ?Checkbox::make('live_registration_accepted')->label('I independently reviewed live Enom Purchase at '.$protocol['route'].' and its '.$protocol['interval_seconds'].'-second request interval')
                            :Checkbox::make('test_registration_accepted')->label('I reviewed Enom TEST '.(config('domains.enom_writes.transfer')===true?'Purchase / TP_CreateOrder':'Purchase').' at '.$protocol['route'].' and its '.$protocol['interval_seconds'].'-second request interval');
                    }
                }
            }
            if(in_array($name,['live_renewal','live_management'],true)){
                $liveAccount=Account::where('server_id',$server)->first();
                try{$b=$liveAccount?\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($liveAccount):null;}
                catch(\Throwable){$b=null;}
                if($b===null){$fields[]=self::missingIdentity();}
                else{
                    $fields[]=Checkbox::make('accept')->label('Accept live '.($name==='live_renewal'?'ordinary annual renewal':'supported management actions').' (clear to revoke)');
                    $protocol=$name==='live_renewal'?\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::protocol($b):\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::protocol($b);
                    $fields[]=Checkbox::make('verified')->label('I independently checked this live action at '.$protocol['route'].', its '.$protocol['interval'].'-second interval and USD account');
                }
            }
            if($name==='test_management'){
                $fields[]=Checkbox::make('accept')->label('Accept the supported TEST management actions (clear to revoke)');
                $fields[]=Checkbox::make('verified')->label('I independently checked '.implode(', ',\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::protocol()['commands']).' at '.\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::protocol()['route'].' and its '.\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::protocol()['interval'].'-second request interval');
            }
            if($name==='test_renewal'){
                $fields[]=Checkbox::make('accept')->label('Accept TEST annual renewal, excluding grace and redemption (clear to revoke)');
                $fields[]=Checkbox::make('verified')->label('I checked Enom TEST Extend, its request interval shown above, and this account’s USD balance');
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
            if($name==='renewal_billing'){
                $billing=static fn()=>\Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::read($server);
                $fields[]=Checkbox::make('scheduled')->label('Issue scheduled renewal invoices')->default(fn()=>$billing()['scheduled']);
                $fields[]=Checkbox::make('manual')->label('Let owners request renewal invoices')->default(fn()=>$billing()['manual']);
                $fields[]=TextInput::make('issue_days')->label('Issue invoices this many days before expiry')->integer()->minValue(2)->maxValue(365)->required()->default(fn()=>$billing()['issue_days']??21)->helperText('Default 21. Must be more than the due days; at most 365.');
                $fields[]=TextInput::make('due_days')->label('Invoices fall due this many days before expiry')->integer()->minValue(1)->maxValue(364)->required()->default(fn()=>$billing()['due_days']??7)->helperText('Default 7. At least 1.');
                $fields[]=Checkbox::make('confirmed')->label(fn()=>'I reviewed renewal billing for '.$target())->accepted()->required();
            }
            if($name==='enroll_sandbox'){
                $fields[]=Hidden::make('environment')->default('test');
                $fields[]=TextInput::make('label')->label('Account label (staff-entered, unconfirmed)')->helperText('For example, sandbox-west. Do not enter personal data or an API key. One line, up to 128 bytes (non-ASCII characters count as more), with no leading or trailing spaces. NameSilo does not confirm this label. Staff can see it on this page, and it cannot be changed later.')->required()->maxLength(128);
                $fields[]=TextInput::make('api_key')->label('NameSilo OTE API key')->password()->autocomplete('new-password')->required()->maxLength(256);
                $fields[]=Checkbox::make('confirmed')->label('I accept OTE-only enrollment without provider-confirmed account ownership')->accepted()->required();
            }
            if($name==='configure'){
                $fields[]=Select::make('registrar')->label('Registrar')->options(['enom'=>'Enom','namesilo'=>'NameSilo'])->default(fn()=>Account::where('server_id',$server)->value('registrar_key'))->live()->required();
                $fields[]=Select::make('environment')->label('Environment')->options(['test'=>'TEST','live'=>'Live'])->default(fn()=>Account::where('server_id',$server)->value('environment')??'test')->required();
                foreach(['uid'=>'Enom user ID','token'=>'Enom API token','api_key'=>'NameSilo API key'] as $key=>$label){$fields[]=TextInput::make($key)->label($label)->password()->autocomplete('new-password')->maxLength(4096)->visible(fn($get)=>$get('registrar')===($key==='api_key'?'namesilo':'enom'))->required();}
            }
            $fields[]=Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(2)->rules(['regex:'.StaffReason::SINGLE_LINE])->helperText('Use one line. Do not include credentials.');
            $processing=fn()=>(bool)Account::where('server_id',$server)->value('processing_enabled');
            $cannotEnable=fn()=>$name==='processing'&&!$processing()&&(!Account::where('server_id',$server)->exists()||app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class)->configurationStatus($server)!=='matched');
            $registrar=fn()=>Account::where('server_id',$server)->value('registrar_key');
            // Setup buttons that can't run are hidden, not disabled (layout.md section 6).
            $blocked=fn()=>$cannotEnable()||($name==='enroll_sandbox'&&(!\Paymenter\Extensions\Servers\Domains\Services\NameSiloSandbox::enabled()||Account::where('server_id',$server)->exists()))
                ||($name==='configure'&&!app(StaffSettingsWrites::class)->connectionAvailable($registrar()))||($name==='probe'&&!app(StaffSettingsWrites::class)->connectionAvailable((string)$registrar()));
            $on=fn()=>self::accepted($server,$name);
            $label=$setup?fn()=>self::setupLabel($name,$processing(),$registrar()!==null,$on()):Review::ACTIONS[$name];
            $out[]=Action::make($name.($setup?'_'.$server:''))->label($label)->color(fn()=>$name==='processing'&&$processing()?'danger':($setup&&in_array($name,['import'],true)?'primary':'gray'))->modalWidth('lg')
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
                            ->body($name==='processing'||$name==='probe'?null:($name==='default_rule'?self::published($summary??['published'=>0,'failed'=>[]]):($name==='enroll_sandbox'?'NameSilo OTE accepted the key in one read-only balance request. That balance was not saved; account ownership is unconfirmed, orders are paused and no TLDs are chosen. The next worker run may read the balance once; check Balance on this page for its result. Later scheduled reads are limited to one per day. Reload this page before another change.':'Reload this page before another change.')))->send();
                        if($name==='processing'){$livewire->redirect(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$server]));}
                    }catch(\Throwable $error){
                        $message=self::refusal($error,$name,$data,$server);
                        if(!$error instanceof \DomainException){\Illuminate\Support\Facades\Log::warning('Domains staff setting failed.',['action'=>$name,'exception_class'=>$error::class]);}
                        Notification::make()->danger()->title(match(true){$name==='enroll_sandbox'&&in_array($message,[self::SAVED_OUTCOME_UNKNOWN,self::GENERIC_OUTCOME_UNKNOWN],true)=>"Couldn't confirm the connection",
                            $setup=>"Couldn't save",default=>'Change refused'})->body($message)->send();}
                    finally{$livewire->mountedActions=[];}
                });
        }
        return $out;
    }
    /** Live preview: each sold term's cost, current price and the price saving would publish. */
    private static function preview(?int $tld,?array $rule): \Illuminate\Support\HtmlString
    {
        $t=$tld?Tld::find($tld):null;
        if($t===null||$rule===null){return new \Illuminate\Support\HtmlString('');}
        $now=now()->toDateTimeImmutable();
        try{$rows=PriceCatalogue::preview($t,$rule,$now);}catch(\Throwable){$rows=[];}
        if($rows===[]){return new \Illuminate\Support\HtmlString('<p>No costs yet. Sync prices on the account page before saving. Saving now changes nothing.</p>');}
        $costs=Staff::can(Staff::COSTS);$money=static fn(?string $v)=>$v===null?'None':'$'.preg_replace('/(\.\d{2}\d*?)0+$/D','$1',$v);
        $cell=static fn(string $html)=>'<td style="padding:6px 10px;vertical-align:top;white-space:nowrap">'.$html.'</td>';
        $html='<div style="max-width:100%;overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.875rem"><thead><tr>';
        foreach(['Price',...($costs?['Cost']:[]),'Now','After saving'] as $label){$html.='<th scope="col" style="padding:6px 10px;text-align:left;white-space:nowrap;font-weight:500">'.e($label).'</th>';}
        $html.='</tr></thead><tbody>';$stale=false;
        foreach($rows as $row){
            $stale=$stale||!$row['fresh'];
            $note=match(true){!$row['fresh']=>'Cost out of date',$row['cost']===null=>'Not offered by the registrar',$row['hold']==='below_cost'=>'Below cost: not sold',
                $row['alert']==='below_cost'=>'Below cost: still billed',$row['hold']!==null=>'Not sold',default=>null};
            $html.='<tr style="border-top:1px solid rgb(127 127 127 / .2)">'.$cell(e(ucfirst($row['action']).', '.$row['term'].' '.($row['term']===1?'year':'years')))
                .($costs?$cell(e($money($row['cost']))):'').$cell(e($money($row['current'])))
                .$cell(e(in_array($row['hold'],[null,'below_cost'],true)?$money($row['new']):'Not sold').($note===null?'':'<div style="font-size:.75rem;color:rgb(217 119 6)">'.e($note).'</div>')).'</tr>';
        }
        $html.='</tbody></table></div>';
        if($stale){$html.='<p style="margin-top:.5rem;color:rgb(220 38 38)">Some costs are out of date. Sync prices on the account page before saving. Saving now changes nothing.</p>';}
        return new \Illuminate\Support\HtmlString($html);
    }
    /** Which TLDs a default pricing change publishes to, and which of them will wait for a price sync. */
    private static function followers(int $server): string
    {
        $tlds=TldFacts::bound(Tld::query())->where('server_id',$server)->where('use_default_rule',true)->orderBy('tld')->get();
        if($tlds->isEmpty()){return 'No TLDs use the account default yet. Saving changes the default only.';}
        $now=now()->toDateTimeImmutable();
        $waiting=$tlds->filter(function(Tld $t)use($now){try{PriceCatalogue::requireFreshCosts($t,$now);return false;}catch(\Throwable){return true;}});
        $text=$tlds->count().' '.($tlds->count()===1?'TLD uses':'TLDs use').' the account default: '.$tlds->map(fn($t)=>'.'.$t->tld)->implode(', ').'. Saving publishes '.($tlds->count()===1?'its':'their').' new prices.';
        return $text.($waiting->isEmpty()?'':' Costs are out of date for '.$waiting->map(fn($t)=>'.'.$t->tld)->implode(', ').'. '.($waiting->count()===1?'Its prices':'Their prices').' stay the same until you sync prices and save again.');
    }
    /** Notification text after a default pricing change. */
    private static function published(array $summary): string
    {
        $text=$summary['published']===0&&$summary['failed']===[]?'No TLDs use the account default.':'Published new prices for '.$summary['published'].' '.($summary['published']===1?'TLD':'TLDs').'.';
        return $text.($summary['failed']===[]?'':' Could not publish '.implode(', ',$summary['failed']).'. Sync prices on the account page, then save again.')
            .(($summary['audit_failed']??false)?" Nothing was undone: the new default and any prices published stay in place. Only the record of this result couldn't be saved.":'');
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
            $name==='enroll_sandbox'=>'Connect NameSilo test account',$name==='probe'=>'Test connection',$name==='acceptance'=>'Edit',$name==='import'=>'Sync TLD prices',
            $name==='renewal_billing'=>'Edit invoices',$name==='default_rule'=>'Edit default pricing',str_ends_with($name,'_renewal')||str_ends_with($name,'_management')=>$on?'Turn off':'Turn on',default=>Review::ACTIONS[$name]};
    }
    /** Dialog title: what the button does, as a question. */
    private static function setupHeading(string $name,bool $processing,bool $connected,bool $on): string
    {
        $feature=str_ends_with($name,'_renewal')?'renewals':(str_ends_with($name,'_management')?'DNS, nameservers and contacts':null);
        return match(true){$feature!==null=>($on?'Turn off ':'Turn on ').$feature.'?',$name==='acceptance'=>'Edit supported TLDs?',$name==='renewal_billing'=>'Edit renewal invoices?',$name==='default_rule'=>'Edit default pricing?',
            $name==='history'||str_ends_with($name,'_review')=>Review::ACTIONS[$name].'?',default=>self::setupLabel($name,$processing,$connected,$on).'?'};
    }
    private static function submitLabel(string $name,bool $processing,bool $connected,bool $on): string
    {
        return match(true){$name==='processing'=>$processing?'Pause orders':'Resume orders',$name==='enroll_sandbox'||($name==='configure'&&!$connected)=>'Connect',
            $name==='probe'=>'Test connection',$name==='import'=>'Sync',$name==='default_rule'=>'Save and publish',str_ends_with($name,'_renewal')||str_ends_with($name,'_management')=>$on?'Turn off':'Turn on',default=>'Save'};
    }
    /** Staff text for a refused settings change; never echoes unrecognized exception text. */
    public static function refusal(\Throwable $error,string $name,array $data=[],int $server=0): string
    {
        if ($name==='seller' && $error instanceof \Paymenter\Extensions\Servers\Domains\Services\ExpiredCostEvidence) {
            $action=match($error->action){'register'=>'registration','renew'=>'renewal','transfer'=>'transfer',default=>'saved'};
            $term=$error->term.' '.($error->term===1?'year':'years');
            $expiry=$error->freshUntil->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC';
            $account=Account::where('server_id',$error->serverId)->first();
            $binding=$account ? \Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($account) : null;
            $registrar=TldFacts::registrar((string)$account?->registrar_key);
            $imports=$binding && \Paymenter\Extensions\Servers\Domains\Services\TestCatalogue::enabled($binding);
            $test=$binding && \Paymenter\Extensions\Servers\Domains\Services\TestCatalogue::testDeployment($binding);
            $remedy=$imports ? "On the account page for account #{$error->serverId}, use Sync TLD prices to refresh {$error->tld} costs, then try again."
                : ($test ? 'Sync TLD prices is unavailable for this account; ask the installation administrator to restore TEST catalogue import and refresh costs before trying again.'
                    : "Check Cost refresh on .{$error->tld} for account #{$error->serverId}; wait for fresh costs from its scheduled catalogue read, or ask the installation administrator to restore reads if refresh failed or was interrupted.");
            return "Cannot select {$registrar} account #{$error->serverId} for .{$error->tld}: its {$action} cost for {$term} expired at {$expiry}. ".$remedy;
        }
        if($error instanceof EnomTestCallLimit&&Account::where('server_id',$server)->value('environment')==='live'){return 'Live Enom request refused before sending. Earlier reads may have been sent. Check the live site, account binding and daily limits on the account page.';}
        return $error instanceof EnomTestCallLimit
                ?'Enom TEST request refused before sending. '.($name==='import'?'Earlier reads in this import may have been sent and counted. ':'')
                    .EnomTestCalls::refusedFacts()
                :match($error->getMessage()){'Processing state unchanged.'=>'Processing state is unchanged. Reload this page.',
                'Settings changed. Reload this page.'=>$name==='enroll_sandbox'?'This review expired, or this server changed after you opened it. No OTE key check was sent and nothing was saved. Reload this page and review the server before trying again.':'Settings changed. Reload this page.',
                'Current staff permissions required.'=>$name==='enroll_sandbox'?'Your staff permission changed. No OTE key check was sent and nothing was saved. Reload this page before trying again.':'Your permission changed. Reload this page.',
                'Runtime acceptance and shared workers are required.'=>'Provider acceptance and shared cache, sessions and queue workers are required before enabling processing.',
                'Pause processing on every legacy Enom server before installing or enabling Domains.'=>'Pause processing on every legacy Enom server first.',
                'TEST catalogue reads are unavailable.'=>'TEST catalogue import is available only for Enom TEST accounts on a deployment that enables it. No provider call was made.',
                'Catalogue ending is not accepted.'=>'Accept this TLD before importing. No provider call was made.',
                'Catalogue import unavailable.'=>'Import did not complete. Check saved prices and sale availability. Enom TEST reads may have been made. Reload and check acceptance and permissions.'
                    .($error instanceof \Paymenter\Extensions\Servers\Domains\Services\CatalogueImportFailure?' · '.$error->failureCode:''),
                'Connection checks are unavailable.'=>$name==='configure'&&($data['registrar']??null)==='namesilo'?'NameSilo identity verification is unavailable. This account cannot be connected.':'Connection checks are unavailable. No provider call was made.',
                'identity_credentials_refused'=>'Enom refused the user ID or API token. Check both for the selected environment.',
                'identity_unavailable'=>'Enom did not confirm this account. Nothing was saved. Try again later.',
                'Connection not verified.'=>Account::where('server_id',$server)->value('registrar_key')==='namesilo'?'NameSilo did not confirm the balance. At most one OTE balance read may have been sent; the last saved balance was kept. Retrying may send another OTE balance read.':'Enom did not confirm the balance. The last saved balance was kept.',
                'identity_environment_refused'=>'This environment is not enabled for Enom account reads on this site. No provider call was made.',
                'identity_account_changed'=>'These credentials belong to a different account. The saved account was kept.',
                'Sandbox contract confirmation required.'=>'Accept the OTE-only enrollment terms to continue. No OTE key check was sent and nothing was saved.',
                'Sandbox review mismatch.'=>'This review does not match the selected server or action. No OTE key check was sent and nothing was saved. Reload this page and try again.',
                'Registrar unavailable.'=>$name==='enroll_sandbox'?'This Domains server is off or unavailable. No OTE key check was sent and nothing was saved. Enable the server in Servers, then try again.':'This Domains server is off or unavailable. Enable it in Servers, then reload this page and check saved facts before trying again.',
                'Domains server is unavailable.'=>$name==='enroll_sandbox'?'This Domains server is off or unavailable. No OTE key check was sent and nothing was saved. Enable the server in Servers, then try again.':'This Domains server is off or unavailable. Enable it in Servers, then reload this page and check saved facts before trying again.',
                'Invalid review key.'=>$name==='enroll_sandbox'?'This review is invalid. No OTE key check was sent and nothing was saved. Reload this page and try again.':'This review is invalid. Nothing was changed. Reload this page and try again.',
                'Enter a one-line reason between 5 and 500 characters.'=>$name==='enroll_sandbox'?'Enter a one-line reason between 5 and 500 characters. No OTE key check was sent and nothing was saved.':'Enter a one-line reason between 5 and 500 characters. Nothing was changed.',
                'Sandbox key already enrolled.'=>'This OTE key is already enrolled on another Domains server. Each key can be enrolled once on this site; keep using that server or use a different OTE key on a fresh Domains server. No provider call was made.',
                'Existing sandbox enrollment unavailable.'=>'An existing OTE enrollment’s saved facts cannot be read, so this key cannot be checked for reuse. Restore its original saved facts before retrying. No provider call was made.',
                'Sandbox enrollment unavailable.'=>'OTE enrollment is disabled on this site or unavailable for this environment. Live NameSilo enrollment is not allowed. No provider call was made.',
                'Sandbox enrollment is immutable.'=>'This OTE enrollment is immutable. To change the key or label, use a different OTE key on a fresh Domains server. Holdings, acceptances and operations stay with the old enrollment. No provider call was made.',
                'Sandbox enrollment requires a fresh server.'=>'This server already has saved Domains settings, records or holdings, so it cannot be enrolled. Use a fresh Domains server; enrollment never inherits holdings, acceptances or operations. No provider call was made.',
                'Invalid sandbox enrollment input.'=>'Check the label and OTE key. The label must be one line of up to 128 bytes with no leading or trailing spaces, and must not contain the key. The key must be up to 256 characters with no spaces or control characters. No provider call was made.',
                'Sandbox key check failed.'=>'Nothing was enrolled or saved. At most one OTE key check may have been sent, and NameSilo did not confirm the key: it may be wrong or unusable in OTE, or the reply may be missing or unclear. No balance was saved. Check the OTE key and try again; another submission may send another OTE key check.',
                'Sandbox enrollment could not be saved.'=>self::SAVED_OUTCOME_UNKNOWN,
                'namesilo_identity_unavailable'=>'NameSilo identity verification is unavailable. This account cannot be connected.',
                'Review key used.'=>$name==='enroll_sandbox'?'This enrollment confirmation was already used, so this submission sent no new OTE key check. An earlier submission may still be finishing. Wait for it to finish, then reload the account page. This page cannot show whether that submission is still running. If Enrollment reads “OTE only · immutable”, an enrollment was saved; check its label before treating it as yours. If no Enrollment row appears and Account binding reads “Not configured” after the earlier submission finishes, nothing was enrolled. '.self::UNAVAILABLE_RECOVERY.' '.self::RETRY_GUIDANCE:'This confirmation was already used. Reload this page.',
                'Fresh costs required before publication.'=>'Costs are missing or out of date. Sync prices on the account page, then save again. Nothing was changed.',
                'Complete lifecycle facts required.'=>'Complete all lifecycle fields. Nothing was saved.',
                'Invalid lifecycle input.'=>'Reload the lifecycle form and check its fields. Nothing was saved.',
                'Invalid lifecycle support.'=>'Check the grace, restore, auto-renew and conflicting-source flags. Nothing was saved.',
                'Lifecycle days must be between 0 and 365.'=>self::LIFECYCLE_DAYS,
                'Invalid lifecycle charge.'=>'Enter valid non-negative grace and restore charges with at most four decimal places. Nothing was saved.',
                'Invalid lifecycle currency.'=>'Enter three uppercase letters for grace and restore currencies, for example USD. Nothing was saved.',
                'Lifecycle verification requires a date and time with UTC offset.'=>'Include a date, time and UTC offset in policy, grace and restore verification times. Nothing was saved.',
                'Invalid lifecycle verification time.'=>'Enter valid policy, grace and restore verification dates and times. Nothing was saved.',
                'Enter a one-line lifecycle source between 5 and 500 characters.'=>'Enter one-line policy, grace and restore sources from 5 to 500 characters. Nothing was saved.',
                'Conflicting lifecycle settings require repair.'=>'Ask an administrator to repair duplicate lifecycle settings before retrying. Nothing was saved.',
                'Independent lifecycle verification required.'=>'Verify the lifecycle facts and check the confirmation box. Nothing was saved.',
                'Lifecycle verification cannot be in the future.'=>'Enter verification times at or before now. Nothing was saved.',
                'Explicit renewal billing confirmation required.'=>'Check the review box to continue. Nothing was saved.',
                'Invalid renewal billing input.'=>'Reload this page and try again. Nothing was saved.',
                'Invalid renewal timing.'=>'Enter whole numbers of days. Nothing was saved.',
                'Valid billing calendar required.'=>'Issue days must be more than due days. Issue days can be at most 365 and due days at least 1. Nothing was saved.',
                'Renewal billing unchanged.'=>'Renewal billing is unchanged. Nothing was saved.',
                'Review the live Enom Purchase route and request interval.'=>'Check the box confirming the live Enom Purchase route and request interval, then try again. Nothing was saved.',
                'Live Enom Purchase route acceptance is unavailable.'=>'Live Enom Purchase acceptance is unavailable on this deployment; contact the deployment administrator. Nothing was saved.',
                'Independent Live renewal review required.'=>'Check the box confirming the live Extend route, request interval and USD balance. Nothing was saved.',
                'Live renewal deployment is unavailable.'=>'Live renewal is unavailable for this account or deployment. Reload the account page and check the Renewals row. Nothing was saved.',
                'Independent Live management review required.'=>'Check the box confirming the supported live management actions, endpoint and request interval. Nothing was saved.',
                'Live management deployment is unavailable.'=>'Live management is unavailable for this account or deployment. Reload the account page and check the DNS, nameservers and contacts row. Nothing was saved.',
                'Independent TEST management review required.'=>'Check the box confirming the supported TEST management actions, endpoint and request interval. Nothing was saved.',
                'TEST management deployment is unavailable.'=>'TEST management is unavailable for this account or deployment. Reload the account page and check the DNS, nameservers and contacts row. Nothing was saved.',
                'Independent TEST renewal review required.'=>'Check the box confirming the TEST Extend route, request interval and USD balance. Nothing was saved.',
                'TEST renewal deployment is unavailable.'=>'TEST renewal is unavailable for this account or deployment. Reload the account page and check the Renewals row. Nothing was saved.',
                'Review the Enom TEST Purchase / TP_CreateOrder route and request interval before accepting acquisition terms.'=>'Check the box confirming the Enom TEST Purchase / TP_CreateOrder route and request interval, then try again. Nothing was saved.',
                'Enom TEST acquisition route acceptance is unavailable.'=>'Enom TEST acquisition acceptance is unavailable on this deployment; contact the deployment administrator. Nothing was saved.',
                'Review the Enom TEST Purchase route and request interval before accepting registration terms.'=>'Check the box confirming the Enom TEST Purchase route and request interval, then try again. Nothing was saved.',
                'Enom TEST Purchase route acceptance is unavailable.'=>'Enom TEST Purchase acceptance is unavailable on this deployment; contact the deployment administrator. Nothing was saved.',
                default=>$name==='enroll_sandbox'?self::GENERIC_OUTCOME_UNKNOWN:'Reload and check current permissions, saved facts and acceptance.'};
    }
}

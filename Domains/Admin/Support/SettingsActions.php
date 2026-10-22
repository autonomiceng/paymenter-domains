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
    private const NOT_ENROLLED='If no Enrollment row appears and Account binding reads “Not configured”, nothing was enrolled.';
    private const UNAVAILABLE_RECOVERY='If Enrollment reads “Unavailable”, an account record exists, but its current OTE enrollment cannot be confirmed. Do not make another enrollment attempt on this server. If it is off, re-enable it in Servers; ask the installation administrator to check that OTE enrollment is enabled on this site, then reload. If it remains unavailable and this account was enrolled, ask them to restore the original saved enrollment settings. If it was never enrolled, use a fresh Domains server for a new key.';
    private const RETRY_GUIDANCE='For “Not configured”, confirm the server is enabled and fresh, OTE enrollment is enabled, your permissions are current, and the key is not already enrolled on this site before submitting another review. Submitting another review may send another OTE key check.';
    public const SAVED_OUTCOME_UNKNOWN='One OTE key check was sent and NameSilo accepted the key; its balance was not saved. Enrollment may have been saved, but its outcome could not be confirmed. Reload Registrar setup. If Enrollment reads “OTE only · immutable”, an enrollment was saved; check its label before treating it as yours. '.self::NOT_ENROLLED.' '.self::UNAVAILABLE_RECOVERY.' '.self::RETRY_GUIDANCE;
    public const GENERIC_OUTCOME_UNKNOWN='Enrollment did not finish normally, and its outcome could not be confirmed. An OTE key check may or may not have been sent. If it was sent, NameSilo may or may not have accepted the key. Reload Registrar setup. If Enrollment reads “OTE only · immutable”, an enrollment was saved; check its label before treating it as yours. '.self::NOT_ENROLLED.' '.self::UNAVAILABLE_RECOVERY.' '.self::RETRY_GUIDANCE;
    public static function description(string $name): string
    {
        return match($name){
            'test_management'=>'Record an independent check of '.implode(', ',\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::protocol()['commands']).' at '.\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::protocol()['route'].' and its '.\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::protocol()['interval'].'-second request interval. A change completes only after readback confirms it. If readback cannot confirm it, the change is not resent; only confirmation reads continue. DNS tools require a server setting set by the deployment and a complete, current read of existing records for whole-list editors. Clearing acceptance pauses changes not yet sent; they stay queued and send if acceptance is restored. Accepting also starts background domain and record reads for this account, which use Enom TEST daily calls shared with Purchase and Extend. Already sent changes keep read-only reconciliation while the deployment opt-in stays on.',
            'test_renewal'=>'Record that you checked Enom TEST Extend at '.\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::protocol()['route'].', its '.\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::protocol()['interval'].'-second request interval, and that this account’s balance is in USD. With the deployment opt-in, each paid annual renewal can send one Extend. This does not turn on renewal billing, owner renewal requests or processing. Clearing it stops new Extend requests; renewals already sent keep reconciling while the deployment opt-in stays on.',
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
            'enroll_sandbox'=>'Sends one read-only balance request to NameSilo OTE to check whether the key works. The balance is not saved. NameSilo does not confirm which account owns the key; the account label is entered by staff and remains unconfirmed. If the check succeeds, the key is saved encrypted as an immutable OTE enrollment on this server. Processing starts off and no catalogue terms are accepted. The next worker tick may send one scheduled daily read through /apibatch/getAccountBalance; NameSilo has not confirmed OTE support for this route. An unsupported reply appears as Read failed; another daily attempt is no sooner than the next day. Later scheduled reads are limited to one per day. Read balance and Registrar balances → Refresh now can each make one separate OTE balance request and save the result in Registrar balances. Each key can be enrolled once on this site. To change the key or label, use a different OTE key on a fresh Domains server. Holdings, acceptances and operations stay with the old enrollment.',
            'configure'=>'Sends one account read to check the supplied credentials, then saves them encrypted. The verified account cannot change. Saving pauses processing until it is re-enabled.',
            'probe'=>'Reads the balance once with saved credentials and saves it to Registrar balances. For NameSilo OTE, a successful read shows the key still authenticates; it does not confirm account ownership. This does not enable processing, purchases or new sales.',
        };
    }
    public static function make(int $server,?int $tld,string $stamp,bool $setup=false): array
    {
        $out=[];$names=$setup?['enroll_sandbox','configure','probe','acceptance','import','history','test_renewal','test_management','processing','delivery_review','alert_review']:['default_rule','override','remove_override','tld_settings','seller','publish'];
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
            if($name==='enroll_sandbox'){
                $fields[]=Hidden::make('environment')->default('test');
                $fields[]=TextInput::make('label')->label('Account label (staff-entered, unconfirmed)')->helperText('For example, the OTE login email. One line, up to 128 bytes (non-ASCII characters count as more), with no leading or trailing spaces. Never enter an API key. NameSilo does not confirm this label. Staff can see it on this page, and it cannot be changed later.')->required()->maxLength(128);
                $fields[]=TextInput::make('api_key')->label('NameSilo OTE API key')->password()->autocomplete('new-password')->required()->maxLength(256);
                $fields[]=Checkbox::make('confirmed')->label('I accept OTE-only enrollment without provider-confirmed account ownership')->accepted()->required();
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
                ->disabled(fn(Action $action)=>$cannotEnable()||($name==='enroll_sandbox'&&(!\Paymenter\Extensions\Servers\Domains\Services\NameSiloSandbox::enabled()||Account::where('server_id',$server)->exists()))||($name==='configure'&&($selected=(string)($action->getRawData()['registrar']??''))!=='namesilo'&&!app(StaffSettingsWrites::class)->connectionAvailable($selected!==''?$selected:null))||($name==='probe'&&!app(StaffSettingsWrites::class)->connectionAvailable((string)Account::where('server_id',$server)->value('registrar_key'))))
                ->tooltip(fn()=>$cannotEnable()?(Account::where('server_id',$server)->value('registrar_key')==='namesilo'?'Unavailable: this server may be off, OTE enrollment may be off on this site, or saved enrollment settings may have changed. If the server is off, re-enable it in Servers. Ask the installation administrator to check the site setting. If this account was enrolled, ask them to restore the original saved enrollment settings; otherwise use a fresh Domains server for a new key.':'Configure and verify this account first.'):($name==='enroll_sandbox'?'Available only on a fresh Domains server when OTE enrollment is enabled on this site.':null))
                ->visible(fn()=>Staff::can(Staff::POLICY)&&Staff::can(Review::permission($name))&&($name!=='test_management'||(config('domains.enom_writes.management')===true&&Account::where('server_id',$server)->where('registrar_key','enom')->where('environment','test')->exists()))&&($name!=='test_renewal'||(config('domains.enom_writes.renewal')===true&&Account::where('server_id',$server)->where('registrar_key','enom')->where('environment','test')->exists())))
                ->requiresConfirmation()->modalHeading($name==='processing'?fn()=>($processing()?'Pause':'Enable').' processing for '.$target().'?':$label)
                ->modalDescription($name==='processing'?fn()=>$processing()?'Stops new registrar work for this account. Domains, invoices and queued work are kept; requests already sent keep reconciling.':self::description($name):($name==='configure'?fn(Action $action)=>($action->getRawData()['registrar']??null)==='namesilo'?'NameSilo identity verification is unavailable. This account cannot be connected.':self::description($name):self::description($name)))->modalSubmitActionLabel($name==='processing'?fn()=>$processing()?'Confirm pause':'Confirm enable':'Confirm')->schema($fields)
                ->action(function(#[\SensitiveParameter] array $data,$livewire)use($name,$server,$tld,$target){
                    try{$r=new Review((int)Auth::id(),$server,$tld,$name,(string)($data['review_key']??''),trim((string)($data['reason']??'')));
                        app(StaffSettingsWrites::class)->run($r,$data);$account=Account::where('server_id',$server)->first();
                        Notification::make()->success()->title($name==='processing'?($account->processing_enabled?'Processing enabled':'Processing paused').' for '.$target():($name==='enroll_sandbox'?'OTE key enrolled':'Change recorded'))
                            ->body($name==='processing'?null:($name==='probe'?'Balance read saved. See Registrar balances.':($name==='enroll_sandbox'?'NameSilo OTE accepted the key in one read-only balance request. That balance was not saved; account ownership is unconfirmed, processing is off and no catalogue terms are accepted. The next worker tick may send one scheduled daily balance read; check Registrar balances for its result. Later scheduled reads are limited to one per day. Reload this page before another change.':'Reload this page before another change.')))->send();
                        if($name==='processing'){$livewire->redirect(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::getUrl());}
                    }catch(\Throwable $error){
                        $message=self::refusal($error,$name,$data,$server);
                        if(!$error instanceof \DomainException){\Illuminate\Support\Facades\Log::warning('Domains staff setting failed.',['action'=>$name,'exception_class'=>$error::class]);}
                        Notification::make()->danger()->title($name==='enroll_sandbox'&&in_array($message,[self::SAVED_OUTCOME_UNKNOWN,self::GENERIC_OUTCOME_UNKNOWN],true)?'Enrollment outcome unknown':'Change refused')->body($message)->send();}
                    finally{$livewire->mountedActions=[];}
                });
        }
        return $out;
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
            $remedy=$imports ? "In Registrar setup for account #{$error->serverId}, use Import accepted ending for {$error->tld} to refresh costs, then try again."
                : ($test ? 'Import accepted ending is unavailable for this account; ask the installation administrator to restore TEST catalogue import and refresh costs before trying again.'
                    : "Check Cost refresh on .{$error->tld} for account #{$error->serverId}; wait for fresh costs from its scheduled catalogue read, or ask the installation administrator to restore reads if refresh failed or was interrupted.");
            return "Cannot select {$registrar} account #{$error->serverId} for .{$error->tld}: its {$action} cost for {$term} expired at {$expiry}. ".$remedy;
        }
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
                'Catalogue import unavailable.'=>'Import did not complete. No prices changed; Enom TEST reads may have been made. Reload and check acceptance and permissions.',
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
                'Review key used.'=>$name==='enroll_sandbox'?'This enrollment confirmation was already used, so this submission sent no new OTE key check. An earlier submission may still be finishing. Wait for it to finish, then reload Registrar setup. This page cannot show whether that submission is still running. If Enrollment reads “OTE only · immutable”, an enrollment was saved; check its label before treating it as yours. If no Enrollment row appears and Account binding reads “Not configured” after the earlier submission finishes, nothing was enrolled. '.self::UNAVAILABLE_RECOVERY.' '.self::RETRY_GUIDANCE:'This confirmation was already used. Reload this page.',
                'Independent TEST management review required.'=>'Check the box confirming the supported TEST management actions, endpoint and request interval. Nothing was saved.',
                'TEST management deployment is unavailable.'=>'TEST management is unavailable for this account or deployment. Reload Registrar setup and check the Enom TEST management row. Nothing was saved.',
                'Independent TEST renewal review required.'=>'Check the box confirming the TEST Extend route, request interval and USD balance. Nothing was saved.',
                'TEST renewal deployment is unavailable.'=>'TEST renewal is unavailable for this account or deployment. Reload Registrar setup and check the TEST renewal row. Nothing was saved.',
                'Review the Enom TEST Purchase route and request interval before accepting registration terms.'=>'Check the box confirming the Enom TEST Purchase route and request interval, then try again. Nothing was saved.',
                'Enom TEST Purchase route acceptance is unavailable.'=>'Enom TEST Purchase acceptance is unavailable on this deployment; contact the deployment administrator. Nothing was saved.',
                default=>$name==='enroll_sandbox'?self::GENERIC_OUTCOME_UNKNOWN:'Reload and check current permissions, saved facts and acceptance.'};
    }
}

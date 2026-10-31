<?php
// Enom 444f31c DomainController/DomainManager owner projection; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice, User};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{PrivacyMode, Support};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, NativeIntent, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\{OwnedDomain, RequestStatus, StatusPill};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\ManagementProfiles;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
final readonly class OwnedDomains
{
    /** Enom's default nameservers, shown when an owner chooses them. The request itself sends UseDNS=default. */
    public const DEFAULT_NAMESERVERS=['dns1.name-services.com','dns2.name-services.com','dns3.name-services.com','dns4.name-services.com','dns5.name-services.com'];
    /** History change label for each management request action. */
    private const CHANGES = ['nameservers'=>'Nameservers','contacts'=>'Contacts','contact_profile'=>'Contacts','lock'=>'Transfer lock',
        'privacy_enable'=>'WHOIS privacy','privacy_disable'=>'WHOIS privacy','privacy_purchase'=>'WHOIS privacy','privacy_renew'=>'WHOIS privacy',
        'auto_renew_off'=>'Auto-renew','auth_code_email'=>'Auth code','dns'=>'DNS records','srv'=>'DNS records',
        'ds_add'=>'DNSSEC','ds_delete'=>'DNSSEC','glue_add'=>'Glue records','glue_update'=>'Glue records','glue_delete'=>'Glue records'];
    /** Holds only the owner can clear, so "nothing you need to do" would be false. */
    private const OWNER_HOLDS = ['contact_details_required','nameservers_required','transfer_setup_review_required'];
    public function __construct(private Clock $clock, private OwnerPorts $ports, private ?DomainManagement $engine = null, private ?DomainBilling $billing = null) {}
    /** Always query current native AND ledger ownership; IDs are locators, never authority. */
    public function owned(User $user, int $id): Domain
    {
        return Domain::whereKey($id)->where('user_id',$user->id)
            ->whereHas('service',fn($q)=>$q->where('user_id',$user->id)->whereHas('order',fn($q)=>$q->where('user_id',$user->id)))->firstOrFail();
    }
    public function operation(Domain $domain): ?Operation
    {
        return self::acquisition($domain->operations()->whereIn('action',['register','transfer'])->where('authority_generation',$domain->authority_generation)->where('account_fingerprint',$domain->account_fingerprint)->orderByDesc('id')->limit(1)->get(),$domain);
    }
    public static function acquisition($operations, Domain $d): ?Operation
    { return $operations->filter(fn($op)=>in_array($op->action,['register','transfer'],true)&&$op->authority_generation===$d->authority_generation&&$op->account_fingerprint===$d->account_fingerprint)->sortByDesc('id')->first(); }
    /** Where a pending order stands for its owner: unpaid with the invoice link, paid, cancelled ('credit' once credit is approved), or failed. Empty otherwise. Paid says what's still the owner's in next: setup_required, awaiting_code, or null for nothing.
     * $closed: the service is cancelled, so payment can no longer start an order that hasn't been sent (InvoiceFunding::requireInitialFacts). */
    public static function order(Domain $d, ?Operation $op, ?Invoice $invoice, bool $closed=false): array
    {
        if (!$op) { return []; }
        $kind=$op->action==='transfer'?'transfer':'registration';
        // Applying the credit closes the domain row too. The order still reads Cancelled, as its request does.
        if ($op->state==='rejected'&&($op->safe_code==='refund_approved'||isset($op->funding['refund'])&&in_array($op->safe_code,WriteHeld::FINAL_REFUSALS,true))&&in_array($d->status,['registration_pending','transfer_pending',$kind.'_rejected'],true)) { return ['state'=>'cancelled','kind'=>$kind,'credit'=>true]; }
        if ($op->safe_code===UnpaidAcquisitionProof::CODE&&isset($op->funding['late_credits'])) { return ['state'=>'cancelled','kind'=>$kind,'credit'=>true]; }
        if (!in_array($d->status,['registration_pending','transfer_pending'],true)) { return []; }
        if ($op->state==='rejected') { return RequestStatus::closed($op->state,$op->safe_code)?[]:['state'=>'failed','kind'=>$kind]; }
        if ($closed && in_array($op->state,['awaiting_payment','awaiting_code','queued'],true)) { return ['state'=>'cancelled','kind'=>$kind]; }
        if ($op->state==='awaiting_payment' && $invoice?->status==='pending') { return ['state'=>'unpaid','kind'=>$kind,'invoice'=>route('invoices.show',$invoice)]; }
        if ($op->state==='awaiting_payment' && $invoice?->status==='cancelled') { return ['state'=>'cancelled','kind'=>$kind]; }
        $next=match (true) {
            $op->state==='held'&&in_array($op->safe_code,self::OWNER_HOLDS,true) => 'setup_required',
            $op->action==='transfer'&&!TransferInputs::validCode($op->payload['epp']??null) => 'awaiting_code',
            $d->registrar_key!=='enom'&&!isset($op->policy['owner_setup'])&&in_array($op->state,['awaiting_payment','queued'],true)&&($op->attempt??[])===[] => 'setup_required',
            default => null,
        };
        return $invoice?->status==='paid'&&in_array($op->state,['awaiting_payment','awaiting_code','queued','running','pending','held','uncertain'],true)?['state'=>'paid','kind'=>$kind,'next'=>$next]:[];
    }
    /** The service is gone, or a cancellation or native intent stops it. */
    public static function closed(?string $serviceStatus, bool $stopped): bool
    { return !in_array($serviceStatus,['pending','active'],true)||$stopped; }
    /** The status once the invoice is known: the request alone still says awaiting_payment until it is picked up. */
    public static function orderStatus(array $state, Domain $d, array $order): array
    {
        return match (true) {
            ($order['state']??null)==='cancelled' => [isset($order['credit'])?'cancelled':'needs_review','Order cancelled'],
            ($order['state']??null)==='failed' => [$order['kind']==='transfer'?'transfer_rejected':'registration_rejected',null],
            ($order['state']??null)==='paid'&&$state[0]==='awaiting_payment' => [$order['next']??$d->status,null],
            default => $state,
        };
    }
    /** Why a History change failed, in owner words. A test call cap stops the change before it is sent. */
    private static function failure(Operation $entry): string
    {
        return match ($entry->safe_code) {
            'enom_test_management_call_cap' => 'We didn’t send this change, so nothing changed.',
            'management_provider_refused' => RequestStatus::REFUSED,
            default => 'We couldn’t make this change.',
        };
    }
    public function present(Domain $d, bool $forms = true): OwnedDomain
    {
        if ($d->adoptionPending()) {
            $pill=StatusPill::of('adoption_pending');
            return new OwnedDomain($d->domain, $pill['label'], $pill['tone'], null, 'Paused during setup', null,
                ['show'=>route('domains.domains.show',$d->id)], [], ['required'=>false,'contacts'=>false], [], [],
                Account::where('server_id',$d->server_id)->value('environment')==='test', setupPending:true, statusReason:$pill['reason'], demo:DemoData::is($d));
        }
        $op=$this->operation($d); $obs=$d->observation??[]; $service=$d->service;
        $renewalState=RenewalStop::read($d);
        $actions=['show'=>route('domains.domains.show',$d->id),'setupSave'=>route('domains.domains.setup-save',$d->id),'contactsSave'=>route('domains.domains.contacts-save',$d->id),'codeSave'=>route('domains.domains.code-save',$d->id)]; $management=[]; $caps=null;
        try { $caps=$this->ports->forDomain($d)->capabilities(); } catch (\Throwable) {}
        $closed=self::closed($service?->status,$service&&($service->cancellation||NativeIntent::where('service_id',$service->id)->exists()));
        $order=$op?self::order($d,$op,Invoice::whereKey($op->invoice_id)->where('user_id',$d->user_id)->first(),$closed):[];
        $state=self::orderStatus(DomainStates::managed($d,$op,$this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),RenewalStop::stopped($renewalState)),$d,$order);
        $pill=StatusPill::of(...$state);
        $setup=['contacts'=>false,'required'=>false,'nameservers'=>false,'privacy'=>false,'defaults'=>$d->registrar_key==='enom'];
        if ($caps && $op && ($op->state!=='held'||in_array($op->safe_code,['contact_details_required','transfer_setup_review_required','nameservers_required'],true))
            && in_array($op->state,['awaiting_payment','awaiting_code','queued','held'],true) && ($op->attempt??[])===[]
            && $op->started_at===null && $op->request_fingerprint===null && $op->completed_at===null
            && in_array($service?->status,['pending','active'],true) && !$service->cancellation
            && in_array($d->status,['registration_pending','transfer_pending'],true)) {
            $setup['nameservers']=$op->action==='register'||$d->registrar_key==='namesilo';
            $setup['privacy']=$caps?->privacy===PrivacyMode::Free;
            $setup['required']=!isset($op->policy['owner_setup']) && ($setup['nameservers'] || $setup['privacy']);
        }
        if ($caps && $op?->state==='held' && $op->safe_code==='contact_details_required' && ContactRecovery::provenUnsent($op)
            && in_array($service?->status,['pending','active'],true) && !$service->cancellation
            && in_array($d->status,['registration_pending','transfer_pending'],true)) { $setup['contacts']=true; }
        if ($setup['required']||$setup['contacts']) { $actions['setup']=route('domains.domains.setup',$d->id); }
        $transfer=['open'=>false,'received'=>false,'paid'=>false,'deadline'=>null,'held'=>false,'unchecked'=>false,'phase'=>'held'];
        if ($op?->action==='transfer' && $d->status==='transfer_pending') {
            $transfer['notice']=\Paymenter\Extensions\Servers\Domains\Presentation\TransferNotice::for($op,$this->clock->now()->getTimestamp());
            $transfer['received']=TransferInputs::validCode($op->payload['epp']??null);
            $transfer['paid']=Invoice::whereKey($op->invoice_id)->where('user_id',$d->user_id)->value('status')==='paid';
            $deadline=TransferInputs::deadline($op,$this->clock->now());
            $transfer['deadline']=$deadline?$deadline->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y, H:i').' UTC':null;
            $transfer['open']=in_array($op->state,['awaiting_payment','awaiting_code','queued','held'],true)
                && ($op->attempt??[])===[] && $op->started_at===null && $op->request_fingerprint===null
                && (!$transfer['paid']||$deadline!==null&&$this->clock->now()<$deadline)
                && ($op->state!=='held'||TransferInputs::holdTakesCode($op));
            $transfer['held']=!$transfer['open'];
            $transfer['phase']=$transfer['open']?'editing':match(true) {
                in_array($op->state,['running','pending'],true)=>'pending', $op->state==='uncertain'=>'review',
                $deadline!==null&&$this->clock->now()>=$deadline=>'cutoff',
                $transfer['paid']&&$deadline===null=>'payment',default=>'held' };
            if (!$transfer['open']) {
                $setup['required']=false;
                $setup['contacts']=$setup['contacts'] && $transfer['paid'] && TransferInputs::contactsEditable($op,$this->clock->now());
                if (!$setup['contacts']) { unset($actions['setup']); }
            }
            if ($op->state==='held' && $op->safe_code==='transfer_setup_review_required') { $transfer['phase']='setup_review'; }
            $transfer['unchecked']=($op->attempt['transfer_admission']['rdap_unchecked']??null)===true;
            $actions['code']=route('domains.domains.code',$d->id);
        }
        $progress=$d->provenance === 'adopted' ? [['label' => 'Imported', 'state' => 'Completed']] : [];
        $entries=$d->operations()->where('authority_generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint)->orderByDesc('id')->limit(100)->get();
        foreach ($entries as $entry) {
            if (($late=RenewalCycles::lateNote($entry))!==null) { $progress[]=['label'=>'Renewal','state'=>'Cancelled','date'=>$entry->updated_at?->format('Y-m-d'),'note'=>$late]; continue; }
            if (!isset($entry->policy['management']) || isset($entry->policy['management']['correction'])) { continue; }
            $label=self::CHANGES[$entry->action]??null;
            if (!$label) { continue; }
            // Request status labels; a result counts only once the registrar's readback confirms it.
            $resolved=ManagementEvidence::resolved($entry,$d);
            $state=match (true) {
                in_array($entry->state,['succeeded','rejected'],true) && !$resolved => 'Under review',
                $entry->state==='held' && $entry->safe_code==='enom_test_management_call_cap' && $resolved => 'Failed',
                default => RequestStatus::for(RequestStatus::closed($entry->state,$entry->safe_code)?'disposed':$entry->state)['label'],
            };
            $progress[]=['label'=>$label,'state'=>$state,'date'=>$entry->updated_at?->format('Y-m-d'),'note'=>$state==='Failed'?self::failure($entry):null,
                'by'=>(int)$entry->authorized_by!==(int)$d->user_id?'Staff':null];
        }
        foreach (array_slice($obs['corrections']??[],0,100) as $row) {
            $label=['contacts'=>'Contacts','nameservers'=>'Nameservers','privacy'=>'WHOIS privacy','auto_renew'=>'Auto-renew'][$row['section']??'']??null;
            if ($label) { $progress[]=['label'=>$label,'state'=>'Under review','date'=>$entries->firstWhere('operation_key',$row['operation_key']??null)?->updated_at?->format('Y-m-d')]; }
        }
        if ($caps) { $setup['maxNameservers']=$d->registrar_key==='enom'?12:13; }
        try { $contacts=$forms?self::contacts($d):[]; } catch (\Throwable) { $contacts=[]; }
        if ($forms) {
            $labels=['nameservers'=>'Nameservers','contacts'=>'Contacts','lock'=>'Transfer lock','privacy'=>'WHOIS privacy','auto_renew_off'=>'Renewal settings','auth_code_email'=>'Auth code'];
            if ($d->registrar_key==='enom') { $labels+=['dns'=>'DNS records','srv'=>'SRV records','ds'=>'DNSSEC','glue'=>'Glue records']; }
            foreach ($labels as $cap=>$label) {
                if ($d->status==='active' && $service?->status==='active' && !RenewalStop::blocksExisting($renewalState) && !($d->renewal_policy['automatic_spend_paused']??false) && $caps?->get($cap==='srv'?'dns':$cap)->support===Support::Supported) { $management[$cap]=['label'=>$label]; }
            }
            if (isset($management['privacy'])) { $management['privacy']['free']=$caps->privacy===PrivacyMode::Free;
                $retail=\App\Models\Server::find($d->server_id)?->settings()->where('key','domains_privacy_price')->value('value');
                try { $management['privacy']['price']=PricingRule::minor($retail)>0?Storefront::money(RetailLine::decimal($retail)):null; } catch(\Throwable) { $management['privacy']['price']=null; } }
            if ($caps) {
                if (isset($management['nameservers'])) { $management['nameservers']['max']=$setup['maxNameservers']; $management['nameservers']['defaults']=$d->registrar_key==='enom';
                    $management['nameservers']['defaultServers']=$d->registrar_key==='enom'?self::DEFAULT_NAMESERVERS:[];
                    // Only a confirmed readback supplies current nameservers; otherwise the form starts empty.
                    $management['nameservers']['current']=self::storedNameservers($d); }
                if (isset($management['contacts'])) { $management['contacts']+=self::contactForm($d,$contacts); }
                if (isset($management['auth_code_email'])) { $management['auth_code_email']['recipient']=$d->registrar_key==='namesilo'?'admin contact':'owner'; }
                // Lock and privacy as last read from the registrar (a confirmed change first, then the sync); null until read.
                foreach (['lock'=>'locked','privacy'=>'privacy'] as $cap=>$fact) { if (isset($management[$cap])) { $management[$cap]['state']=self::storedFlag($d,$cap,$fact); } }
            }
            $records=$forms&&array_intersect(array_keys($management),['dns','srv','ds','glue'])?OwnerRecords::forDomain($d):[];
            $recordCheckHeld=$forms&&$management&&ObservationRecords::stopped($d);
            foreach ($management as &$section) { $section['recordCheckHeld']=$recordCheckHeld; } unset($section);
            foreach (['dns','srv','ds','glue'] as $kind) {
                if (isset($management[$kind])) { $management[$kind]['records']=$records[$kind]??null; }
                if (isset($management[$kind])&&in_array($kind,['dns','srv'],true)&&isset($records[$kind])) { $management[$kind]['digest']=ManagementPayload::digest($records[$kind],$kind); }
            }
        }
        $renewal=$d->status==='active'?(RenewalStop::stopped($renewalState)?'Off':'On'):''; $price=null;
        try { if($service?->currency_code==='USD'){$price=Storefront::money(RetailLine::decimal($service->price));} } catch(\Throwable){}
        foreach ($management as $key=>&$section) {
            $section['url']=route('domains.domains.manage',[$d->id,$key]); $section['actions']=[];
            $intents=match($key) { 'contacts'=>$section['staged']?['contact_profile','contacts']:['contacts'],
                'ds'=>['ds_add','ds_delete'],'glue'=>['glue_add','glue_update','glue_delete'],
                'privacy'=>['privacy_enable','privacy_disable',...(!$section['free']&&$section['price']?['privacy_purchase','privacy_renew']:[])],default=>[$key] };
            foreach ($intents as $form) { $section['actions'][$form]=route('domains.domains.manage',[$d->id,$form]); }
        } unset($section);
        $unavailable=$forms && $d->status==='active' && $service?->status==='active';
        if ($management && $forms) {
            $all=array_merge(...array_values(array_map(fn($section)=>array_keys($section['actions']),$management)));
            try { $allowed=Runtime::management($d,$this->engine)?->available($d->service->user,$d,$this->ports->forDomain($d),$all)??[]; } catch (\Throwable) { $allowed=[]; }
            foreach ($management as $key=>&$section) { $section['actions']=array_intersect_key($section['actions'],array_flip($allowed)); if (!$section['actions']) { unset($management[$key]); } } unset($section);
            $unavailable=$management===[];
        }
        // Only the exact retained issued renewal invoice supplies a due date.
        $renewalDue=null; $earlyRenewal=false;
        $debt=$d->operations()->where('action','renew')->where('authority_generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint)->whereIn('state',['awaiting_payment','queued','pending','uncertain'])->orderByDesc('id')->first();
        if ($debt) {
            $ids = [$debt->invoice_id];
            try {
                $invoice = DomainBilling::existingInvoice($debt, ['domain' => $d, 'invoices' => Invoice::whereIn('id', $ids)->get(),
                    'items' => \App\Models\InvoiceItem::whereIn('invoice_id', $ids)->get()]);
                if ($invoice->status === 'pending') { $renewalDue = $invoice->due_at->format('Y-m-d'); $earlyRenewal = RenewalCycles::early($debt, $this->clock->now()) !== null; }
            } catch (\Throwable) {}
        }
        $adopted = $d->provenance === 'adopted'; $plannedDue = null; $history = []; $renewalInvoice = [];
        $renewNow = $this->renewNow($d, $forms);
        if ($adopted) {
            if (isset($renewNow['invoice'])) { $renewalInvoice = ['state' => 'pending', 'due' => $renewalDue]; }
            elseif (isset($renewNow['status'])) { $renewalInvoice = ['state' => $renewNow['status']]; }
        }
        if ($adopted && $renewal === 'On' && $renewalInvoice === [] && $d->provider_expires_at) {
            try {
                $timing = BillingCalendar::timing(\App\Models\Server::findOrFail($d->server_id)->settings);
                $due = BillingCalendar::dates($d->provider_expires_at->toDateTimeImmutable(), $timing['issue'], $timing['due'])['due'];
                if ($this->clock->now()->format('Y-m-d') >= $due) { $renewalInvoice = ['state' => 'review']; }
                else { $plannedDue = $due; }
            } catch (\Throwable) { $renewalInvoice = ['state' => 'review']; }
        }
        if ($adopted) {
            $invoiceIds = \Paymenter\Extensions\Servers\Domains\Models\HistoryLink::where('domain_id', $d->id)->where('user_id', $d->user_id)->pluck('invoice_id');
            // Dated by the last successful payment for a paid invoice, else by its issue date.
            $history = Invoice::whereKey($invoiceIds)->where('user_id', $d->user_id)->withMax(['transactions as succeeded_at' => fn ($q) => $q->where('status', 'succeeded')], 'created_at')->orderBy('id')->get()
                ->map(fn ($i) => ['number' => $i->number ?? (string) $i->id, 'status' => ucfirst($i->status), 'url' => route('invoices.show', $i),
                    'date' => substr((string) ($i->status === 'paid' && $i->succeeded_at ? $i->succeeded_at : $i->created_at), 0, 10)])->all();
        }
        $test=Account::where('server_id',$d->server_id)->value('environment')==='test';
        if ($service) { $actions['autoPay']=route('services.show',$service->id).'#auto-pay'; }
        return new OwnedDomain($d->domain,$pill['label'],$pill['tone'],$d->provider_expires_at?->format('Y-m-d'),$renewal,$price,
            $actions,$progress,$setup,$transfer,$management,$test,$unavailable,$renewalDue, adopted:$adopted, plannedRenewalDue:$plannedDue, history:$history, renewalInvoice:$renewalInvoice, originalDoNotRenew:$adopted && RenewalStop::adopted($renewalState),
            order:$order, autoPay:RenewalAutoPay::ready($service), renewNow:$renewNow,
            renewalOff:$renewal==='Off'?RenewalStop::offReason($renewalState):null,
            autoRenew:$forms&&OwnerAutoRenew::available($renewalState)?['url'=>route('domains.domains.auto-renew',$d->id),'enabled'=>!RenewalStop::ownerOff($renewalState)]:[],
            statusReason:$pill['reason'], earlyRenewal:$earlyRenewal, demo:DemoData::is($d), syncPaused:DemoData::syncPaused($d), owner:self::owner($contacts),
            daysLeft:$d->provider_expires_at?(int)\Carbon\CarbonImmutable::parse($this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),'UTC')->diffInDays(\Carbon\CarbonImmutable::parse($d->provider_expires_at->format('Y-m-d'),'UTC'),false):null);
    }
    /** Details by role as last stored: the registration request, then confirmed contact changes in order. Throws on unreadable data. Shared by the customer and staff domain pages. */
    public static function contacts(Domain $d): array
    {
        $ops = $d->operations()->whereIn('action', ['register', 'transfer', 'contacts'])->where('authority_generation', $d->authority_generation)->where('account_fingerprint', $d->account_fingerprint)->orderByDesc('id')->limit(100)->get();
        $saved = array_intersect_key(self::acquisition($ops, $d)?->payload['contacts'] ?? [], array_flip(ContactDetails::ROLES));
        foreach ($ops->where('action', 'contacts')->where('state', 'succeeded')->sortBy('id') as $change) {
            $role = $change->payload['role'] ?? null;
            foreach ($role === 'all' ? ContactDetails::ROLES : array_intersect([$role], ContactDetails::ROLES) as $target) { $saved[$target] = $change->payload['contact'] ?? null; }
        }
        return array_filter($saved, 'is_array');
    }
    /** The editor: one form for the whole domain, prefilled from the owner, the role every registrar reads back. */
    private static function contactForm(Domain $d, array $saved): array
    {
        $staged = $d->registrar_key === 'namesilo';
        try { $approved = $staged ? ManagementProfiles::approved($d) : null; } catch (\Throwable) { $approved = null; }
        return ['staged' => $staged, 'limits' => ContactDetails::limits($d->registrar_key), 'saved' => $saved['registrant'] ?? [], 'approved' => $approved];
    }
    /** The owner's name and email from contacts(), or null. */
    public static function owner(array $contacts): ?array
    {
        $contact = $contacts['registrant'] ?? null;
        if (!is_array($contact) || !filled($contact['email_address'] ?? null)) { return null; }
        $name = trim(($contact['first_name'] ?? '').' '.($contact['last_name'] ?? ''));
        return ['name' => $name !== '' ? $name : (string) ($contact['organization_name'] ?? ''), 'email' => (string) $contact['email_address']];
    }
    /** Existing cycle visibility is independent of eligibility to request a new invoice. */
    private function renewNow(Domain $d, bool $forms): array
    {
        if (!$d->provider_expires_at) {
            return $d->operations()->where('action', 'renew')->where('authority_generation', $d->authority_generation)->limit(1)->exists() ? ['status' => 'review'] : [];
        }
        try {
            if ($cycle = self::renewalCycle($d, now: $this->clock->now())) { return $cycle; }
            $retained = self::renewalCycle($d, true, $this->clock->now()) ?? [];
            if (!$forms) { return $retained; }
            try {
                $options = ($this->billing ?? app(DomainBilling::class))->requestOptions($d);
                return ['url' => route('domains.domains.renew', $d->id), ...$options];
            } catch (\Throwable) { return $retained; }
        } catch (\Throwable) { return ['status' => 'review']; }
    }
    /** Nameservers from the last confirmed registrar readback, in order, or null. Shared by the customer and staff domain pages. */
    public static function storedNameservers(Domain $d): ?array
    {
        $evidence=ObservationProjection::nameservers($d);
        return $evidence?->state==='observed'?$evidence->nameservers:null;
    }
    /** Confirmed changes take precedence over the last domain observation. Registration supplies no flags. */
    public static function storedFlag(Domain $d, string $section, string $fact): ?bool
    {
        $read=$d->observation['sections'][$section]??null;
        $value=null;
        if (is_array($read) && ObservationProjection::matches($d,$read)) {
            $facts=$read['facts']??[];
            $value=$section==='privacy'?match($d->registrar_key) {
                'enom'=>$facts['enabled']??null,
                'namesilo'=>$facts['privacy']['enabled']??null,
                default=>null,
            }:($facts[$fact]??null);
        }
        $snapshot=$d->observation['facts']??null;
        if ($value===null && is_array($snapshot) && ObservationProjection::matches($d,$snapshot)) { $value=$snapshot[$fact]??null; }
        return is_bool($value)?$value:null;
    }
    /** This cycle as billing sees it: null while a new request is possible, else its pending invoice or an owner status. */
    public static function renewalCycle(Domain $d, bool $retained = false, ?\DateTimeImmutable $now = null): ?array
    {
        $key = RenewalCycles::key($d);
        $cycle = $d->operations()->where('action', 'renew')
            ->where(fn ($q) => $q->whereLike('operation_key', $key, caseSensitive: true)->orWhereLike('operation_key', $key.':replacement:%', caseSensitive: true))
            ->orderByDesc('id')->first();
        if (!$cycle) { return null; }
        $l = self::renewalLedger($d, collect([$cycle]));
        if ($cycle->safe_code !== 'renewal_cancelled_unpaid' && !RenewalReplacements::reviewed($cycle, $d)) {
            $invoice = $l['invoices']->firstWhere('id', $cycle->invoice_id);
            if ($invoice?->status === 'cancelled' && (int) $invoice->user_id === (int) $d->user_id) { return ['status' => 'cancelled']; }
            try { $invoice = DomainBilling::existingInvoice($cycle, $l); } catch (\Throwable) { return ['status' => 'review']; }
            if ($invoice->status === 'pending' && DomainBilling::expired($d, $now ?? app(Clock::class)->now())) {
                return ['status' => RenewalCycles::canRetireUnpaid($cycle, $l) ? 'cancelled' : 'review'];
            }
            return match (true) {
                $cycle->state === 'awaiting_payment' && $invoice->status === 'pending' => ['invoice' => route('invoices.show', $invoice)],
                $invoice->status === 'paid' && in_array($cycle->state, ['awaiting_payment', 'queued', 'running', 'pending', 'succeeded'], true) => ['status' => 'paid'],
                default => ['status' => 'review'] };
        }
        // A retired or refunded debt permits a replacement only when billing's cycle proof holds.
        try {
            // Keep date validation in the shared proof, including malformed dates and unexpected keys.
            $d->operations()->where('action', 'renew')->where('authority_generation', $d->authority_generation)
                ->chunkById(100, function ($ops) use ($d): void {
                    // Only the proof is needed here; the writer computes the replacement number under locks.
                    RenewalCycles::next(self::renewalLedger($d, $ops));
                });
        } catch (\Throwable) { return ['status' => 'review']; }
        return $retained && !RenewalCycles::closedEarly($cycle) ? ['status' => $cycle->safe_code === 'renewal_cancelled_unpaid' ? 'cancelled' : 'review'] : null;
    }
    private static function renewalLedger(Domain $d, \Illuminate\Support\Collection $ops): array
    {
        $ids = $ops->pluck('invoice_id')->filter()->all();
        return ['domain' => $d, 'service' => $d->service, 'operations' => $ops, 'invoices' => Invoice::whereIn('id', $ids)->get(),
            'items' => \App\Models\InvoiceItem::whereIn('invoice_id', $ids)->get(), 'transactions' => \App\Models\InvoiceTransaction::whereIn('invoice_id', $ids)->get(),
            'credit' => $d->service?->user?->credits()->where('currency_code', 'USD')->first()];
    }
}

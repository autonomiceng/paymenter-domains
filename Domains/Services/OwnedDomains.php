<?php
// Enom 444f31c DomainController/DomainManager owner projection; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice, User};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Capabilities, PrivacyMode, Support};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, NativeIntent, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\{OwnedDomain, RequestStatus, StatusPill};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Dns;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
final readonly class OwnedDomains
{
    /** Owner and staff see the same reason and next step while records can't be changed here. */
    public const DNS_OFF='You can’t change DNS records here yet. Contact support for a change.';
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
    /** Where a pending order stands for its owner: unpaid with the invoice link, paid, cancelled ('credit' once the money is credited), or failed. Empty otherwise. Paid says what's still the owner's in next: setup_required, awaiting_code, or null for nothing.
     * $closed: the service is cancelled, so payment can no longer start an order that hasn't been sent (InvoiceFunding::requireInitialFacts). */
    public static function order(Domain $d, ?Operation $op, ?Invoice $invoice, bool $closed=false): array
    {
        if (!$op) { return []; }
        $kind=$op->action==='transfer'?'transfer':'registration';
        if($op->state==='rejected'&&($return=ReceiptReturns::summary($op))){return ['state'=>'cancelled','kind'=>$kind,'return'=>$return[1],'returned'=>ReceiptReturns::outstanding($op)===0];}
        // Applying the credit closes the domain row too. The order still reads Cancelled, as its request does. Approval alone adds no money: the credit can fail and retry.
        if ($op->state==='rejected'&&($op->safe_code==='refund_approved'||isset($op->funding['refund'])&&in_array($op->safe_code,[...WriteHeld::FINAL_REFUSALS,TransferRejections::CODE],true))&&in_array($d->status,['registration_pending','transfer_pending',$kind.'_rejected'],true)) { return ['state'=>'cancelled','kind'=>$kind,...(isset($op->funding['refund'])?['credit'=>true]:[])]; }
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
        return $invoice?->status==='paid'&&in_array($op->state,['awaiting_payment','awaiting_code','queued','running','pending','held','uncertain'],true)?['state'=>'paid','kind'=>$kind,'next'=>$next,...($op->funding_basis==='complimentary'?['complimentary'=>true]:[]),...(isset($op->policy['status_check'])&&!isset($op->policy['status_check']['next_at'])?['check'=>RegistrarCheck::forCustomer($op)]:[])]:[];
    }
    /** The service is gone, or a cancellation or native intent stops it. */
    public static function closed(?string $serviceStatus, bool $stopped): bool
    { return !in_array($serviceStatus,['pending','active'],true)||$stopped; }
    /** The status once the invoice is known: the request alone still says awaiting_payment until it is picked up. */
    public static function orderStatus(array $state, Domain $d, array $order): array
    {
        return match (true) {
            ($order['state']??null)==='cancelled' => [isset($order['credit'])||($order['returned']??false)?'cancelled':'needs_review','Order cancelled'],
            ($order['state']??null)==='failed' => [$order['kind']==='transfer'?'transfer_rejected':'registration_rejected',null],
            ($order['state']??null)==='paid'&&$state[0]==='awaiting_payment' => [$order['next']??$d->status,null],
            default => $state,
        };
    }
    /** Why a History change failed, in owner words. A test call cap stops the change before it is sent. */
    public const ASK_US=' Contact support to make this change.';
    /** A failed change the customer can't redo asks them to contact us, not to try again. */
    public static function askUs(?array $row, bool $canEdit): ?array
    {
        if ($row===null || $canEdit || !str_ends_with($row['text'],ManagementProgress::TRY_AGAIN)) { return $row; }
        return ['text'=>substr($row['text'],0,-strlen(ManagementProgress::TRY_AGAIN)).self::ASK_US]+$row;
    }
    private static function failure(Operation $entry): string
    {
        return ManagementEvidence::outcome($entry) ?? RequestStatus::NOT_MADE;
    }
    /** Who gets the auth code email. */
    public static function authCodeRecipient(Domain $d): string
    { return $d->registrar_key==='namesilo'?'admin contact email':'registrant email'; }
    /** The domain and registrar take this change at all, whatever a switch says right now. */
    private static function supported(Domain $d, ?Capabilities $caps, string $cap): bool
    { return $d->status==='active' && $d->service?->status==='active' && $caps?->get($cap==='srv'?'dns':$cap)->support===Support::Supported; }
    public function offersAuthCode(Domain $d): bool
    { try { return self::supported($d,$this->ports->forDomain($d)->capabilities(),'auth_code_email'); } catch (\Throwable) { return false; } }
    public function present(Domain $d, bool $forms = true): OwnedDomain
    {
        if ($d->adoptionPending()) {
            $pill=StatusPill::of('adoption_pending');
            return new OwnedDomain($d->domain, $pill['label'], $pill['tone'], null, 'Paused during setup', null,
                ['show'=>route('domains.domains.show',$d->id)], [], ['required'=>false,'contacts'=>false], [], [],
                Account::where('server_id',$d->server_id)->value('environment')==='test', setupPending:true, statusReason:$pill['reason'], demo:DemoData::is($d));
        }
        $op=$this->operation($d); $obs=$d->observation??[]; $service=$d->service;
        $renewalState=RenewalStop::read($d);$policy=$renewalState['policy'];
        $actions=['show'=>route('domains.domains.show',$d->id),'setupSave'=>route('domains.domains.setup-save',$d->id),'contactsSave'=>route('domains.domains.contacts-save',$d->id),'codeSave'=>route('domains.domains.code-save',$d->id)]; $management=[]; $caps=null;
        try { $caps=$this->ports->forDomain($d)->capabilities(); } catch (\Throwable) {}
        $closed=self::closed($service?->status,$service&&($service->cancellation||NativeIntent::where('service_id',$service->id)->exists()));
        $order=$op?self::order($d,$op,Invoice::whereKey($op->invoice_id)->where('user_id',$d->user_id)->first(),$closed):[];
        $state=self::orderStatus(DomainStates::managed($d,$op,$this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),RenewalStop::stopped($renewalState)),$d,$order);
        $pill=StatusPill::of(...($policy===null?['needs_review',null]:$state));
        $setup=['contacts'=>false,'required'=>false,'nameservers'=>false,'privacy'=>false,'defaults'=>$d->registrar_key==='enom','defaultNameservers'=>[]];
        if ($caps && $op && ($op->state!=='held'||in_array($op->safe_code,['contact_details_required','transfer_setup_review_required','nameservers_required'],true))
            && in_array($op->state,['awaiting_payment','awaiting_code','queued','held'],true) && ($op->attempt??[])===[]
            && $op->started_at===null && $op->request_fingerprint===null && $op->completed_at===null
            && in_array($service?->status,['pending','active'],true) && !$service->cancellation
            && in_array($d->status,['registration_pending','transfer_pending'],true)) {
            try { $defaults=NewDomainDns::forOperation($op); } catch (\Throwable) { $defaults=[]; }
            $setup['defaults']=$setup['defaults']||$defaults!==[];$setup['defaultNameservers']=$defaults;
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
        $entries=$d->operations()->where('authority_generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint)->orderByDesc('updated_at')->orderByDesc('id')->limit(100)->get();
        foreach ($entries as $entry) {
            try {
                if (($late=RenewalCycles::lateNote($entry))!==null) { $progress[]=['label'=>'Renewal','state'=>'Cancelled','date'=>$entry->updated_at?->toIso8601String(),'note'=>$late]; continue; }
                $key=ManagementProgress::statusKey($entry,$d);
                $label=self::CHANGES[$entry->action]??null;
                if ($key===null || !$label) { continue; }
                $locked=$entry->policy['management']['input']['locked']??null;
                if ($entry->action==='lock' && is_bool($locked)) { $label.=$locked?' On':' Off'; }
                // The same word staff read on the domain's Activity rail.
                $state=RequestStatus::SET[$key][0];
                $progress[]=['label'=>$label,'state'=>$state,'date'=>$entry->updated_at?->toIso8601String(),'note'=>$state==='Failed'?self::failure($entry):null,
                    'noteComplete'=>$entry->action==='nameservers' && $entry->safe_code==='management_provider_refused',
                    'by'=>(int)$entry->authorized_by!==(int)$d->user_id?'Staff':null,'setting'=>ManagementProgress::setting($entry->action)];
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                if ($label=self::CHANGES[$entry->action]??null) {
                    $progress[]=['label'=>$label,'state'=>'Unreadable','date'=>$entry->updated_at?->toIso8601String(),'note'=>ManagementProgress::UNREADABLE];
                }
            }
        }
        foreach (array_slice($obs['corrections']??[],0,100) as $row) {
            if(($row['section']??null)==='nameservers' && $op?->action==='register' && $op->state==='succeeded' && ($row['original_key']??null)===$op->operation_key){continue;}
            $label=['contacts'=>'Contacts','nameservers'=>'Nameservers','privacy'=>'WHOIS privacy','auto_renew'=>'Auto-renew'][$row['section']??'']??null;
            if ($label) { $progress[]=['label'=>$label,'state'=>'Under review','date'=>$entries->firstWhere('operation_key',$row['operation_key']??null)?->updated_at?->toIso8601String()]; }
        }
        if ($caps) { $setup['maxNameservers']=$d->registrar_key==='enom'?12:13; }
        try { $contacts=$forms?self::contacts($d):[]; } catch (\Throwable) { $contacts=null; }
        if ($forms) {
            $labels=['glue'=>'Glue records','nameservers'=>'Nameservers','contacts'=>'Contacts','lock'=>'Transfer lock','privacy'=>'WHOIS privacy','auto_renew_off'=>'Renewal settings','auth_code_email'=>'Auth code'];
            if ($d->registrar_key==='enom') { $labels+=['dns'=>'DNS records','srv'=>'SRV records','ds'=>'DNSSEC','glue'=>'Glue records']; }
            if ($d->registrar_key==='namesilo') { $labels+=['dns'=>'DNS records']; }
            foreach ($labels as $cap=>$label) {
                $supported=self::supported($d,$caps,$cap);
                if ($policy!==null && ($cap!=='contacts'||$contacts!==null) && $supported && !RenewalStop::blocksExisting($renewalState) && !($policy['automatic_spend_paused']??false)) { $management[$cap]=['label'=>$label]; }
            }
            if (isset($management['privacy'])) { $management['privacy']['free']=$caps->privacy===PrivacyMode::Free;
                $retail=\App\Models\Server::find($d->server_id)?->settings()->where('key','domains_privacy_price')->value('value');
                try { $management['privacy']['price']=PricingRule::minor($retail)>0?Storefront::money(RetailLine::decimal($retail)):null; } catch(\Throwable) { $management['privacy']['price']=null; } }
            if ($caps) {
                if (isset($management['nameservers'])) { $management['nameservers']['max']=$setup['maxNameservers']; $management['nameservers']['defaultServers']=ManagementNameservers::defaults($d,$this->ports);
                    $management['nameservers']['defaults']=$management['nameservers']['defaultServers']!==[];
                    $management['nameservers']['registrar']=ManagementNameservers::registrar($d);
                    // Only a confirmed readback supplies current nameservers; otherwise the form starts empty.
                    $management['nameservers']['current']=self::storedNameservers($d); }
                if (isset($management['contacts'])) { $management['contacts']+=self::contactForm($d,$contacts)+['digest'=>hash('sha256',json_encode($contacts,JSON_THROW_ON_ERROR))]; }
                if (isset($management['auth_code_email'])) { $management['auth_code_email']['recipient']=self::authCodeRecipient($d); }
                // Lock and privacy as last read from the registrar (a confirmed change first, then the sync); null until read.
                foreach (['lock'=>'locked','privacy'=>'privacy'] as $cap=>$fact) { if (isset($management[$cap])) { $management[$cap]['state']=self::storedFlag($d,$cap,$fact); } }
            }
            if(!RegistrationDns::registrarDns($d)){unset($management['dns'],$management['srv']);}
            try {
                $sections=$forms&&array_intersect(array_keys($management),['dns','srv','ds','glue'])?ObservationProjection::sections($d):[];
                $records=$sections?OwnerRecords::forDomain($d,$sections):[];
            } catch (\Illuminate\Contracts\Encryption\DecryptException) { $sections=$records=[]; }
            $recordCheckHeld=$forms&&$management&&ObservationRecords::stopped($d);
            foreach ($management as &$section) { $section['recordCheckHeld']=$recordCheckHeld; } unset($section);
            foreach (['dns','srv','ds','glue'] as $kind) {
                if (isset($management[$kind])) { $management[$kind]['records']=$records[$kind]??null; $management[$kind]['asOf']=$kind==='glue'?GlueRecords::asOf($d,$sections):($sections[$kind]['at']??null); if ($kind==='glue') { $management[$kind]['ipv4Only']=$d->registrar_key==='namesilo'; $management[$kind]['warnings']=GlueDrift::warnings($d,known:$sections['known_glue']??[]); } }
                if (isset($management[$kind])&&in_array($kind,['dns','srv'],true)&&isset($records[$kind])&&$d->registrar_key==='enom') { $management[$kind]['digest']=ManagementPayload::digest($records[$kind],$kind); }
            }
            // NameSilo: standard rows are editable, others are shown as read; the digest covers the whole saved list.
            if (isset($management['dns'])&&$d->registrar_key==='namesilo') {
                $management['dns']=['fixed'=>$records['dns_fixed']??[],'digest'=>$records['dns_digest']??null,'types'=>Dns::STANDARD,'sources'=>true]+$management['dns'];
            }
        }
        $renewal=$policy!==null&&$d->status==='active'?(RenewalStop::stopped($renewalState)?'Off':'On'):''; $price=null;
        try { if($service?->currency_code==='USD'){$price=Storefront::money(RetailLine::decimal($service->price));} } catch(\Throwable){}
        foreach ($management as $key=>&$section) {
            $section['url']=route('domains.domains.manage',[$d->id,$key]); $section['actions']=[];
            $intents=match($key) { 'contacts'=>['contacts'],
                'ds'=>['ds_add','ds_delete'],'glue'=>['glue_add','glue_update','glue_delete'],
                'privacy'=>['privacy_enable','privacy_disable',...(!$section['free']&&$section['price']?['privacy_purchase','privacy_renew']:[])],default=>[$key] };
            foreach ($intents as $form) { $section['actions'][$form]=route('domains.domains.manage',[$d->id,$form]); }
        } unset($section);
        $progressBySetting=$forms?ManagementProgress::for($d):[];
        $unavailable=$forms && $d->status==='active' && $service?->status==='active'; $refused=null;
        if ($management && $forms) {
            $all=array_merge(...array_values(array_map(fn($section)=>array_keys($section['actions']),$management)));
            try { $allowed=Runtime::management($d,$this->engine)?->available($d->service->user,$d,$this->ports->forDomain($d),$all,$refused)??[]; } catch (\Throwable) { $allowed=[]; }
            foreach ($management as $key=>&$section) { $section['actions']=array_intersect_key($section['actions'],array_flip($allowed)); if (!$section['actions'] && !isset($progressBySetting[ManagementProgress::setting($key)])) { unset($management[$key]); } } unset($section);
            $unavailable=!array_filter($management,fn($section)=>$section['actions']) && !array_filter($progressBySetting,fn($row)=>$row['pending']);
        }
        if ($forms && $caps && $d->registrar_key==='namesilo' && $d->status==='active' && !isset($management['dns']) && $caps->get('dns')->support!==Support::Supported) {
            try { $read=ObservationProjection::sections($d);$saved=OwnerRecords::forDomain($d,$read); } catch (\Throwable) { $read=$saved=[]; }
            $management['dns']=['label'=>'DNS records','unavailable'=>true,'actions'=>[],'url'=>null,'records'=>$saved['dns']??null,'fixed'=>$saved['dns_fixed']??[],'asOf'=>isset($saved['dns'])?($read['dns']['at']??null):null];
        }
        foreach ($management as $key=>&$section) { $row=$progressBySetting[ManagementProgress::setting($key)]??null; $section['progress']=($row['settled']??false)?null:self::askUs($row,(bool)$section['actions']); } unset($section);
        // Try again only where the customer can make the change; otherwise they ask us.
        $editable=[]; foreach ($management as $key=>$section) { $editable[ManagementProgress::setting($key)]=($editable[ManagementProgress::setting($key)]??false)||$section['actions']; }
        foreach ($progress as &$row) { if (isset($row['setting'])) { $row['retry']=$editable[$row['setting']]??false; unset($row['setting']); } } unset($row);
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
                ->map(fn ($i) => ['number' => $i->number ?? (string) $i->id, 'status' => $i->status === 'pending' ? 'Unpaid' : ucfirst($i->status), 'url' => route('invoices.show', $i),
                    'date' => substr((string) ($i->status === 'paid' && $i->succeeded_at ? $i->succeeded_at : $i->created_at), 0, 10)])->all();
        }
        $test=Account::where('server_id',$d->server_id)->value('environment')==='test';
        if ($service) { $actions['autoPay']=route('services.show',$service->id).'#auto-pay'; }
        try { $dnsCorrection=RegistrationDns::pending($op,$d); } catch (\Throwable) { $dnsCorrection=false; }
        if($d->price_mode==='complimentary'){$price=ComplimentaryAccounts::LABEL;}
        $page=new OwnedDomain($d->domain,$pill['label'],$pill['tone'],$d->provider_expires_at?->format('Y-m-d'),$renewal,$price,
            $actions,$progress,$setup,$transfer,$management,$test,$unavailable,$renewalDue, changesRefused:$unavailable?$refused:null, adopted:$adopted, plannedRenewalDue:$plannedDue, history:$history, renewalInvoice:$renewalInvoice, originalDoNotRenew:$adopted && RenewalStop::adopted($renewalState),
            order:$order, autoPay:$policy!==null&&RenewalAutoPay::ready($service), renewNow:$policy===null?[]:$renewNow,
            renewalOff:$renewal==='Off'?RenewalStop::offReason($renewalState):null,
            autoRenew:$forms&&OwnerAutoRenew::available($renewalState)?['url'=>route('domains.domains.auto-renew',$d->id),'enabled'=>!RenewalStop::ownerOff($renewalState)]:[],
            dnsCorrection:$dnsCorrection, statusReason:$pill['reason'], earlyRenewal:$earlyRenewal, demo:DemoData::is($d), syncPaused:DemoData::syncPaused($d), owner:self::owner($contacts??[]),
            daysLeft:$d->provider_expires_at?(int)\Carbon\CarbonImmutable::parse($this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),'UTC')->diffInDays(\Carbon\CarbonImmutable::parse($d->provider_expires_at->format('Y-m-d'),'UTC'),false):null);
        return \Paymenter\Extensions\Servers\Domains\Presentation\DsState::owner($page,$d,$forms&&$caps!==null);
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
        return ['staged' => $staged, 'limits' => ContactDetails::limits($d->registrar_key), 'saved' => $saved['registrant'] ?? []];
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
            if ($invoice->status === 'pending' && RenewalCycles::expiredInvoice($cycle, $now ?? app(Clock::class)->now())) {
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

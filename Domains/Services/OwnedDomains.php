<?php
// Enom 444f31c DomainController/DomainManager owner projection; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice, User};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{PrivacyMode, Support};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
final readonly class OwnedDomains
{
    public const STATUSES = ['active'=>'Active','registration_pending'=>'Registration in progress','transfer_pending'=>'Transfer in progress',
        'registration_rejected'=>'Needs attention','transfer_rejected'=>'Needs attention','needs_review'=>'Needs attention',
        'expired'=>'Expired','grace'=>'Expired','redemption'=>'In redemption','cancelled'=>'Order cancelled'];
    public function __construct(private Clock $clock, private OwnerPorts $ports, private ?DomainManagement $engine = null) {}
    /** Always query current native AND ledger ownership; IDs are locators, never authority. */
    public function owned(User $user, int $id): Domain
    {
        return Domain::whereKey($id)->where('user_id',$user->id)
            ->whereHas('service',fn($q)=>$q->where('user_id',$user->id))->firstOrFail();
    }
    public function operation(Domain $domain): ?Operation
    {
        return self::acquisition($domain->operations()->whereIn('action',['register','transfer'])->where('authority_generation',$domain->authority_generation)->where('account_fingerprint',$domain->account_fingerprint)->orderByDesc('id')->limit(1)->get(),$domain);
    }
    public static function acquisition($operations, Domain $d): ?Operation
    { return $operations->filter(fn($op)=>in_array($op->action,['register','transfer'],true)&&$op->authority_generation===$d->authority_generation&&$op->account_fingerprint===$d->account_fingerprint)->sortByDesc('id')->first(); }
    public function present(Domain $d, bool $forms = true): OwnedDomain
    {
        $op=$this->operation($d); $obs=$d->observation??[]; $service=$d->service;
        $actions=['show'=>route('domains.domains.show',$d->id),'setupSave'=>route('domains.domains.setup-save',$d->id),'contactsSave'=>route('domains.domains.contacts-save',$d->id),'codeSave'=>route('domains.domains.code-save',$d->id)]; $management=[]; $caps=null;
        try { $caps=$this->ports->forDomain($d)->capabilities(); } catch (\Throwable) {}
        $status=self::STATUSES[$d->status]??'Needs attention'; $tone=$d->status==='active'?'ok':'muted';
        if ($op && in_array($op->state,['held','uncertain'],true)) { $status='Needs attention'; $tone='muted'; }
        if ($op?->state==='awaiting_payment') { $status='Awaiting payment'; }
        $setup=['contacts'=>false,'required'=>false,'nameservers'=>false,'privacy'=>false,'defaults'=>$d->registrar_key==='enom'];
        if ($caps && $op && ($op->state!=='held'||in_array($op->safe_code,['contact_details_required','transfer_setup_review_required','nameservers_required'],true))
            && in_array($op->state,['awaiting_payment','awaiting_code','queued','held'],true) && ($op->attempt??[])===[]
            && $op->started_at===null && $op->request_fingerprint===null && $op->completed_at===null
            && in_array($service?->status,['pending','active'],true) && !$service->cancellation
            && in_array($d->status,['registration_pending','transfer_pending'],true)) {
            $setup['contacts']=$op->safe_code==='contact_details_required';
            $setup['nameservers']=$op->action==='register'||$d->registrar_key==='namesilo';
            $setup['privacy']=$caps?->privacy===PrivacyMode::Free;
            $setup['required']=!isset($op->policy['owner_setup']) && ($setup['nameservers'] || $setup['privacy']);
        }
        if ($setup['required']||$setup['contacts']) { $actions['setup']=route('domains.domains.setup',$d->id); }
        $transfer=['open'=>false,'received'=>false,'paid'=>false,'deadline'=>null,'held'=>false,'unchecked'=>false,'phase'=>'held'];
        if ($op?->action==='transfer' && $d->status==='transfer_pending') {
            $transfer['received']=TransferInputs::validCode($op->payload['epp']??null);
            $transfer['paid']=Invoice::whereKey($op->invoice_id)->where('user_id',$d->user_id)->value('status')==='paid';
            $deadline=TransferInputs::deadline($op,$this->clock->now());
            $transfer['deadline']=$deadline?$deadline->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y, H:i').' UTC':null;
            $transfer['open']=in_array($op->state,['awaiting_payment','awaiting_code','queued','held'],true)
                && ($op->attempt??[])===[] && $op->started_at===null && $op->request_fingerprint===null
                && (!$transfer['paid']||$deadline!==null&&$this->clock->now()<$deadline)
                && ($op->state!=='held'||in_array($op->safe_code,['contact_details_required','transfer_setup_review_required'],true));
            $transfer['held']=!$transfer['open'];
            $transfer['phase']=$transfer['open']?'editing':match(true) {
                in_array($op->state,['running','pending'],true)=>'pending', $op->state==='uncertain'=>'review',
                $deadline!==null&&$this->clock->now()>=$deadline=>'cutoff',
                $transfer['paid']&&$deadline===null=>'payment',default=>'held' };
            if (!$transfer['open']) { $setup['required']=false; $setup['contacts']=false; unset($actions['setup']); }
            $transfer['unchecked']=($op->attempt['transfer_admission']['rdap_unchecked']??null)===true;
            $actions['code']=route('domains.domains.code',$d->id);
        }
        $progress=[];
        foreach ($d->operations()->where('authority_generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint)->orderByDesc('id')->limit(100)->get() as $entry) {
            if (!isset($entry->policy['management']) || isset($entry->policy['management']['correction'])) { continue; }
            $label=['nameservers'=>'Nameservers','contacts'=>'Domain contacts','contact_profile'=>'Contact details','lock'=>'Transfer lock',
                'privacy_enable'=>'Domain privacy','privacy_disable'=>'Domain privacy','privacy_purchase'=>'Domain privacy','privacy_renew'=>'Domain privacy',
                'auto_renew_off'=>'Renewal settings','auth_code_email'=>'Transfer code','dns'=>'DNS records','srv'=>'SRV records',
                'ds_add'=>'DNSSEC','ds_delete'=>'DNSSEC','glue_add'=>'Child nameservers','glue_update'=>'Child nameservers','glue_delete'=>'Child nameservers'][$entry->action]??null;
            if (!$label) { continue; }
            $state=match($entry->state) { 'awaiting_payment'=>'Awaiting payment','queued'=>'Queued — not sent yet','running','pending'=>'In progress',default=>'Unresolved' };
            if ($entry->state==='succeeded' && ManagementEvidence::resolved($entry,$d)) { $state=$entry->action==='auth_code_email'?'Email requested':'Confirmed'; }
            if ($entry->state==='rejected' && ManagementEvidence::resolved($entry,$d)) { $state='Not applied'; }
            $progress[]=['label'=>$label,'state'=>$state];
        }
        foreach (array_slice($obs['corrections']??[],0,100) as $row) {
            $label=['contacts'=>'Domain contacts','nameservers'=>'Nameservers','privacy'=>'Domain privacy','auto_renew'=>'Renewal settings'][$row['section']??'']??null;
            if ($label) { $progress[]=['label'=>$label,'state'=>($row['state']??null)==='readback_confirmed_awaiting_review'?'Awaiting review':'Unresolved']; }
        }
        $labels=['nameservers'=>'Nameservers','contacts'=>'Contacts','lock'=>'Transfer lock','privacy'=>'Domain privacy','auto_renew_off'=>'Renewal settings','auth_code_email'=>'Transfer code'];
        if ($d->registrar_key==='enom') { $labels+=['dns'=>'DNS records','srv'=>'SRV records','ds'=>'DNSSEC','glue'=>'Child nameservers']; }
        foreach ($labels as $cap=>$label) {
            if ($d->status==='active' && $service?->status==='active' && !$service->cancellation && !($d->renewal_policy['automatic_spend_paused']??false) && $caps?->get($cap==='srv'?'dns':$cap)->support===Support::Supported) { $management[$cap]=['label'=>$label]; }
        }
        if (isset($management['privacy'])) { $management['privacy']['free']=$caps->privacy===PrivacyMode::Free;
            $retail=\App\Models\Server::find($d->server_id)?->settings()->where('key','domains_privacy_price')->value('value');
            try { $management['privacy']['price']=PricingRule::minor($retail)>0?Storefront::money(RetailLine::decimal($retail)):null; } catch(\Throwable) { $management['privacy']['price']=null; } }
        if ($caps) {
            $setup['maxNameservers']=$d->registrar_key==='enom'?12:13;
            if (isset($management['nameservers'])) { $management['nameservers']['max']=$setup['maxNameservers']; $management['nameservers']['defaults']=$d->registrar_key==='enom'; }
            if (isset($management['contacts'])) { $management['contacts']['staged']=$d->registrar_key==='namesilo'; $management['contacts']['limits']=ContactDetails::limits($d->registrar_key); }
            if (isset($management['auth_code_email'])) { $management['auth_code_email']['recipient']=$d->registrar_key==='namesilo'?'domain’s administrative contact':'domain owner'; }
        }
        $records=$forms&&array_intersect(array_keys($management),['dns','srv','ds','glue'])?OwnerRecords::forDomain($d):[];
        foreach (['dns','srv','ds','glue'] as $kind) {
            if (isset($management[$kind])) { $management[$kind]['records']=$records[$kind]??null; }
            if (isset($management[$kind])&&in_array($kind,['dns','srv'],true)&&isset($records[$kind])) { $management[$kind]['digest']=ManagementPayload::digest($records[$kind],$kind); }
        }
        $renewal=$d->status==='active'?($service?->cancellation?'Off':'On'):'—'; $price=null;
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
            try { $allowed=$this->engine?->available($d->service->user,$d,$this->ports->forDomain($d),$all)??[]; } catch (\Throwable) { $allowed=[]; }
            foreach ($management as $key=>&$section) { $section['actions']=array_intersect_key($section['actions'],array_flip($allowed)); if (!$section['actions']) { unset($management[$key]); } } unset($section);
            $unavailable=$management===[];
        } elseif (!$forms) { $management=[]; }
        $test=Account::where('server_id',$d->server_id)->value('environment')==='test';
        return new OwnedDomain($d->domain,$status,$tone,$d->provider_expires_at?->format('Y-m-d'),$renewal,$price,
            $actions,$progress,$setup,$transfer,$management,$test,$unavailable);
    }
}

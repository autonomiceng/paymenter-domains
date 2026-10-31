<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** The one path from a change form to the request queue, for owner and staff alike. */
final readonly class ManagementForms
{
    public const REQUESTED='Change requested. Check History for updates.';
    public const NAMESERVERS_RISK='If the new nameservers aren’t set up, your website and email stop working.';
    public const CONTACTS_RISK='Changing the owner’s name, organization or email can block transfers for 60 days.';
    public const RECORDS_RISK='Anything that uses these records, like your website or email, can stop working.';
    public function __construct(private DomainManagement $management, private OwnerPorts $ports) {}
    public static function lockRisk(string $domain): string { return 'Anyone with the auth code can then move '.$domain.' to another registrar.'; }
    /** Whether this exact form save is already a request. */
    public static function accepted(User $actor, Domain $d, string $action, string $nonce): bool
    { return $d->operations()->where('operation_key','manage:'.$d->id.':'.$nonce)->where('action',$action)->where('authorized_by',$actor->id)->exists(); }
    public function submit(User $actor, Domain $d, string $action, #[\SensitiveParameter] array $p, string $nonce): void
    {
        $records=in_array($action,['dns','srv'],true);
        $current=$records?(OwnerRecords::forDomain($d)[$action]??throw new \DomainException('Current records required.')):null;
        $payload=match($action) {
            'nameservers'=>($p['use_default']??null)==='1' && $d->registrar_key==='enom'
                ? ['nameservers'=>[],'use_default'=>true] : ['nameservers'=>OwnerNameservers::parse($p['nameservers']??null,false,$d->registrar_key)],
            'lock'=>['locked'=>match($p['locked']??null){'1'=>true,'0'=>false,default=>throw new \DomainException}],
            'contacts','contact_profile'=>['role'=>$p['role']??'all','contact'=>$p['contact']??[]],
            'dns','srv','ds_add','ds_delete','glue_add','glue_update','glue_delete'=>OwnerRecordInput::payload($action,$p,$d->domain),
            default=>[],
        };
        if ($action==='srv') { $payload=OwnerRecords::restoreSrv($d,$payload); }
        if ($action==='nameservers') {
            try { $this->ports->forDomain($d)->validate($action,$payload,$d->domain); }
            catch (\Throwable) { throw new OwnerFormValidation(['nameservers']); }
        }
        // Actions that can take a site offline or move the domain need the confirm step's field; plain edits do not.
        $risky=match($action) {
            'nameservers','auth_code_email','auto_renew_off','ds_delete','glue_delete'=>true,
            'lock'=>$payload['locked']===false,
            'dns','srv'=>count($payload['records'])<count($current) || (bool) array_filter($p['records'],
                static fn($row,$index)=>isset($current[$index]) && OwnerRecordInput::removed($row),ARRAY_FILTER_USE_BOTH),
            default=>false,
        };
        if ($risky&&($p['confirmed']??null)!=='1') { throw new OwnerConfirmRequired; }
        Runtime::management($d,$this->management)->request($actor,(int)$d->id,$action,$payload,$nonce,$this->ports->forDomain($d),recordIdentity:$records?ObservationRecords::identity($d):null);
    }
}

<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Dns\RoutingProfile;
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{WatchAudit,WatchProfile};
/** Revision-checked DNS template edits and replay protection. */
final class StaffWatchWrites
{
    public const VIEW='admin.domains.watch.view',MANAGE='admin.domains.watch.manage';
    public const UNREADABLE='Saved DNS records cannot be read. Ask your site admin to restore this template.';
    public static function ticket(int $actor,int $id,int $revision,string $action,?int $defaultId=null): string
    { return Crypt::encryptString(json_encode(compact('actor','id','revision','action','defaultId')+['until'=>time()+600,'key'=>bin2hex(random_bytes(16))],JSON_THROW_ON_ERROR)); }
    public static function authorize(User $actor): void
    { abort_unless(Staff::allows($actor,self::VIEW) && Staff::allows($actor,self::MANAGE),403); }
    public function run(User $actor,int $id,string $action,string $ticket,string $reason,array $input=[]): void
    {
        self::authorize($actor);$reason=trim($reason);
        StaffReason::require($reason);
        try{$t=json_decode(Crypt::decryptString($ticket),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \DomainException('Reload and try again.');}
        if(($t['actor']??null)!==(int)$actor->id || ($t['id']??null)!==$id || ($t['action']??null)!==$action || ($t['until']??0)<=time()){throw new \DomainException('Reload and try again.');}
        DB::transaction(function()use($actor,$id,$action,$ticket,$reason,$input,$t):void {
            self::authorize($actor);
            $claim=hash('sha256',$ticket);
            if(!DB::table('domains_watch_staff_claims')->insertOrIgnore(['claim'=>$claim,'subject_id'=>$id,'action'=>$action,'created_at'=>time()])){throw new \DomainException('Reload and try again.');}
            BusinessSetting::singleton(true);
            $row=$id?WatchProfile::whereKey($id)->lockForUpdate()->firstOrFail():new WatchProfile;
            if(($row->exists?(int)$row->revision:0)!==($t['revision']??null)){throw new \DomainException('The saved details changed. Reload and try again.');}
            if($action==='save'){
                if(($t['defaultId']??null)!==null && (SiteDnsDefault::uses($id,true) ? $id : 0) !== $t['defaultId']){throw new \DomainException('The default nameservers changed. Reload the page and try again.');}
                if(SiteDnsDefault::uses($id,true)){SiteDnsDefault::authorize($actor);}
            }
            if($row->exists&&$row->readProfile()===null&&$action!=='delete'){throw new \DomainException(self::UNREADABLE);}
            $before=$row->exists?$row->revision:0;
            if($action==='enable'){
                if(!$row->exists || $row->enabled){throw new \DomainException('Reload the disabled profile first.');}$row->enabled=true;$row->edited_by=$actor->id;$row->reason=$reason;
            }elseif(in_array($action,['disable','delete'],true)){
                if(!$row->exists){throw new \DomainException('Reload and try again.');}
                if($reasonBlocked=SiteDnsDefault::usesRefusal((int)$row->id,true)){throw new \DomainException($reasonBlocked);}
                if($action==='delete'){
                    // Delete only an unreferenced template here; the audit remains append-only.
                    WatchAudit::create(['subject_type'=>'dns_profile','subject_id'=>$row->id,'actor_id'=>$actor->id,
                        'event'=>'dns_profile_deleted','reason'=>$reason,'before_revision'=>(string)$before,
                        'after_revision'=>(string)($before+1),'data'=>['name'=>$row->name],'created_at'=>now()]);
                    $row->newQuery()->whereKey($row->id)->delete();self::authorize($actor);return;
                }
                if(!$row->enabled){throw new \DomainException('The saved details changed. Reload and try again.');}
                $row->enabled=false;$row->edited_by=$actor->id;$row->reason=$reason;
            }elseif($action==='save'){
                $row->edited_by=$actor->id;$row->reason=$reason;
                $row->name=(string)($input['name']??'');self::requireUniqueName($row->name,$row->exists?(int)$row->id:null);
                $records = self::routingProfile($input);
                $row->nameservers = $records->nameservers;
            }else{throw new \DomainException('Reload and try again.');}
            $row->revision=$before+1;
            $row->save();
            self::authorize($actor);
        });
    }
    /** Templates are told apart by name in every list and choice, so two may not share one that looks the same: ignoring
     * case, spaces, invisible format characters (zero-width space, soft hyphen) and compatibility forms (fullwidth letters). */
    public static function requireUniqueName(string $name,?int $except=null): void
    {
        $key=self::nameKey($name);
        foreach(WatchProfile::when($except,fn($q)=>$q->whereKeyNot($except))->pluck('name') as $other){
            if(self::nameKey((string)$other)===$key){throw new \DomainException('A DNS template named "'.trim($name).'" already exists. Choose another name.');}
        }
    }
    private static function nameKey(string $name): string
    {
        $folded=class_exists(\Normalizer::class)?(\Normalizer::normalize($name,\Normalizer::FORM_KC)?:$name):$name;
        $visible=preg_replace('/\p{Cf}+/u','',$folded)??$folded;
        return mb_strtolower(trim(preg_replace('/\s+/u',' ',$visible)??$visible));
    }
    public static function routingProfile(array $input): RoutingProfile
    {
        return new RoutingProfile(self::lines($input['nameservers'] ?? ''));
    }
    private static function lines(mixed $value): array
    { if(!is_string($value) || strlen($value)>8192){throw new \DomainException('Enter one DNS target per line.');}return array_values(array_filter(array_map('trim',preg_split('/\R/',$value)))); }
}

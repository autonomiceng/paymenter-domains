<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchAudit,WatchProfile,WatchProductMap,WatchSource};
/** Local staff edits only. A request advances ordinary work without overriding leases/backoff or feature gates. */
final class StaffWatchWrites
{
    public const VIEW='admin.domains.watch.view',MANAGE='admin.domains.watch.manage';
    public static function ticket(int $actor,string $kind,int $id,int $revision,string $action): string
    { return Crypt::encryptString(json_encode(compact('actor','kind','id','revision','action')+['until'=>time()+600,'key'=>bin2hex(random_bytes(16))],JSON_THROW_ON_ERROR)); }
    public static function authorize(User $actor): void
    { abort_unless(Staff::allows($actor,self::VIEW) && Staff::allows($actor,self::MANAGE),403); }
    public function run(User $actor,string $kind,int $id,string $action,string $ticket,string $reason,array $input=[]): void
    {
        self::authorize($actor);$reason=trim($reason);
        StaffReason::require($reason);
        try{$t=json_decode(Crypt::decryptString($ticket),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \DomainException('Reload and try again.');}
        if(($t['actor']??null)!==(int)$actor->id || ($t['kind']??null)!==$kind || ($t['id']??null)!==$id || ($t['action']??null)!==$action || ($t['until']??0)<=time()){throw new \DomainException('Reload and try again.');}
        DB::transaction(function()use($actor,$kind,$id,$action,$ticket,$reason,$input,$t):void {
            self::authorize($actor);
            $claim=hash('sha256',$ticket);
            if(!DB::table('domains_watch_staff_claims')->insertOrIgnore(['claim'=>$claim,'subject_type'=>$kind,'subject_id'=>$id,'action'=>$action,'created_at'=>time()])){throw new \DomainException('Reload and try again.');}
            if($kind==='mapping'){$product=$id?WatchProductMap::findOrFail($id)->product_id:(int)($input['product_id']??0);\App\Models\Product::whereKey($product)->lockForUpdate()->firstOrFail();}
            $class=match($kind){'watch'=>WatchName::class,'profile'=>WatchProfile::class,'mapping'=>WatchProductMap::class,default=>throw new \DomainException('Reload and try again.')};
            $row=$id?$class::whereKey($id)->lockForUpdate()->firstOrFail():new $class;
            if(($row->exists?(int)$row->revision:0)!==($t['revision']??null)){throw new \DomainException('The saved details changed. Reload and try again.');}
            if($kind==='profile'&&$row->exists&&$row->readProfile()===null){throw new \DomainException('Reload and try again.');}
            $before=$row->exists?$row->revision:0;$data=[];
            if($kind==='watch'){
                if(!in_array($action,['pause','resume','profile','retire','read','note'],true) || $row->managed_domain_id!==null || $row->state==='linked'){throw new \DomainException('This watch cannot be changed.');}
                if($action==='pause' && $row->state==='active'){$row->state='paused';}
                elseif($action==='resume' && $row->state==='paused'){$row->state='active';}
                elseif($action==='retire' && in_array($row->state,['active','paused'],true)){$row->sources()->where('state','watch')->orderBy('id')->lockForUpdate()->get()->each(fn($s)=>$s->retire($reason));$row->state='retired';}
                elseif($action==='profile' && in_array($row->state,['active','paused'],true)){
                    $profile=WatchProfile::whereKey((int)($input['profile']??0))->where('enabled',true)->lockForUpdate()->firstOrFail();
                    $conflict=DB::table('domains_watch_sources as s')->join('domains_watch_product_maps as m','m.id','=','s.map_id')->where('s.watch_id',$id)->where('s.state','watch')->whereNotNull('m.expected_ns_profile_id')->where('m.expected_ns_profile_id','<>',$profile->id)->exists();
                    if($conflict){throw new \DomainException('A source mapping pins a different profile. Edit that mapping first.');}
                    $row->ns_profile_id=$profile->id;$row->profile_revision=$profile->revision;
                    $row->expected_dns=in_array($input['expected_dns']??null,['platform','external'],true)?$input['expected_dns']:null;
                }elseif($action==='read' && $row->state==='active'){
                    $latest=DB::table('domains_watch_staff_claims')->where('subject_type','watch')->where('subject_id',$id)->where('action','read')->where('claim','<>',$claim)->orderByDesc('created_at')->lockForUpdate()->first()?->created_at;
                    if($latest && $latest>time()-300){throw new \DomainException('A check was recently requested. Try again later.');}
                    if(!app(WatchWork::class)->active() || !app(WatchWork::class)->queueReady()){throw new \DomainException('Public checks are disabled or the asynchronous queue is unavailable.');}
                    // Enqueue eligibility only: ordinary dispatcher performs the read outside this transaction.
                    $q=DB::table('domains_watch_work')->where('watch_id',$id);
                    if(!(clone$q)->exists()){throw new \DomainException('This watch has no scheduled checks yet.');}
                    $count=$q->whereNull('claim')->where(fn($q)=>$q->whereNull('backoff_until')->orWhere('backoff_until','<=',time()))->where(fn($q)=>$q->whereNull('safe_code')->orWhereIn('safe_code',['ready','rotation']))->update(['due_at'=>time()]);$data=['eligible_rows'=>$count];
                }elseif($action!=='note'){throw new \DomainException('The watch state changed. Reload and try again.');}
            }elseif($kind==='mapping' && $action==='enable'){
                $row=(new WatchMappings())->enable($actor,$id,$before,$reason);
            }elseif($kind==='profile' && $action==='enable'){
                if(!$row->exists || $row->enabled){throw new \DomainException('Reload the disabled profile first.');}$row->enabled=true;$row->edited_by=$actor->id;$row->reason=$reason;
            }elseif($action==='save'){
                $row->edited_by=$actor->id;$row->reason=$reason;
                if($kind==='profile'){
                    $row->name=(string)($input['name']??'');$row->enabled=false;
                    if($row->exists && WatchProductMap::where('expected_ns_profile_id',$row->id)->where('enabled',true)->lockForUpdate()->get()->isNotEmpty()){throw new \DomainException('Disable the referencing mappings before editing this profile.');}
                    $row->nameservers=self::lines($input['nameservers']??'');
                    foreach(['website_endpoints','email_endpoints'] as $field){$row->$field=['exact'=>self::lines($input[$field]??''),'ips'=>self::lines($input[$field.'_ips']??''),'suffix'=>self::lines($input[$field.'_suffix']??'')];}
                }else{
                    if(!$row->exists){$row->product_id=(int)($input['product_id']??0);}
                    $row->property_key=(string)($input['property_key']??'');$row->enabled=false;
                    $pid=(int)($input['expected_ns_profile_id']??0);$row->expected_ns_profile_id=$pid?:null;
                    if($pid && !WatchProfile::whereKey($pid)->where('enabled',true)->lockForUpdate()->exists()){throw new \DomainException('Choose an enabled routing profile.');}
                }
            }else{throw new \DomainException('Reload and try again.');}
            if($kind==='mapping' && $action==='save'){$row=(new WatchMappings())->save($actor,(int)$row->product_id,$row->property_key,$before,$reason,$row->expected_ns_profile_id);}
            elseif(!($kind==='watch' && in_array($action,['read','note'],true)) && !($kind==='mapping' && $action==='enable')){$row->revision=$before+1;$row->save();}self::authorize($actor);
            if($kind==='watch'){WatchAudit::create(['subject_type'=>$kind,'subject_id'=>$row->id,'user_id'=>$kind==='watch'?$row->user_id:null,'actor_id'=>$actor->id,
                'event'=>$action==='read'?'staff_read_requested':'staff_'.$action,'reason'=>$reason,'before_revision'=>(string)$before,'after_revision'=>(string)$row->revision,'data'=>$data,'created_at'=>now()]);}

        });
    }
    private static function lines(mixed $value): array
    { if(!is_string($value) || strlen($value)>8192){throw new \DomainException('Enter one DNS target per line.');}return array_values(array_filter(array_map('trim',preg_split('/\R/',$value)))); }
}

<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption,WatchAudit,WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionEnrollment,ManagedPublicSchema,ManagedPublicWorkSchema,StaffWatchSchema,StaffWatchWrites,WatchDnsSchema};
extract(adoptionCommitFixture());
WatchDnsSchema::install();ManagedPublicWorkSchema::install();ManagedPublicSchema::install();StaffWatchSchema::install();
DB::table('roles')->where('id',$staff->role_id)->update(['permissions'=>json_encode([...$permissions,StaffWatchWrites::VIEW,StaffWatchWrites::MANAGE])]);
$staff=$staff->fresh();
$profile=DB::transaction(fn()=>WatchProfile::create(['name'=>'Retired watch DNS','enabled'=>true,
    'nameservers'=>['ns1.example.com','ns2.example.com'],'website_endpoints'=>[],'email_endpoints'=>[],
    'edited_by'=>$staff->id,'reason'=>'Synthetic former monitoring template']));
$watch=DB::transaction(fn()=>WatchName::create(['user_id'=>$owner->id,'canonical_name'=>'example.com',
    'psl_revision'=>str_repeat('a',64),'state'=>'retired','expected_dns'=>'platform',
    'ns_profile_id'=>$profile->id,'profile_revision'=>$profile->revision,'notice_preferences'=>['dns'=>false]]));
$history=$watch->fresh()->getRawOriginal();
(new StaffWatchWrites())->run($staff,'profile',(int)$profile->id,'delete',
    StaffWatchWrites::ticket((int)$staff->id,'profile',(int)$profile->id,(int)$profile->revision,'delete'),'Remove unused DNS template');
same(null,WatchProfile::find($profile->id),'Template used only by a retired watch can be deleted');
same($history,$watch->fresh()->getRawOriginal(),'Deleting the template preserves the stopped watch history');
same(1,WatchAudit::where('subject_type','dns_profile')->where('subject_id',$profile->id)->where('event','dns_profile_deleted')->count(),'Template deletion remains audited');
$preview=$reader->preview($target,$context);same('ready',$preview->code,'The stopped watch does not block adoption');
$adoption=Adoption::findOrFail($preview->previewId);
$domain=$commit->confirm($adoption->request_key,$adoption->request_digest,$staff);
$enrollment=new AdoptionEnrollment($clock);
$enrollment->repair((int)$domain->id);
$marker=$domain->fresh()->observation['adoption_enrollment']??null;
expect($marker!==null,'Adoption after template deletion enrolls without a permanent retry');
same((int)$adoption->id,$marker['adoption_id'],'Enrollment belongs to this adoption');
same('enrolled',$marker['registrar_observation'],'Registrar observation is enrolled');
same(1,DB::table('domains_observation_work')->where('domain_id',$domain->id)->where('phase','holding')->count(),'The adopted domain has its ordinary observation work');
$work=DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$domain->id)->orderBy('kind')->get();
same(['delegation','points'],$work->pluck('kind')->all(),'Managed DNS work is enrolled after deletion');
same([null,null],$work->pluck('profile_id')->all(),'A deleted historical template is not used for new work');
same($history,$watch->fresh()->getRawOriginal(),'Adoption leaves the stopped watch and its historical profile reference unchanged');
$enrollment->repair((int)$domain->id);
same(2,DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$domain->id)->count(),'A repeated repair does not duplicate managed work');
$nativeSuccessful=true;echo "Adoption after deleting a retired watch DNS template preserves history and enrolls work.\n";

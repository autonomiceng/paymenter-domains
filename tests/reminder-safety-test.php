<?php
// Native renewal authority, preferences, retention and bounded cursors. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reminders.php';
use App\Models\{NotificationTemplate,ServiceCancellation};
use Illuminate\Support\Facades\{Auth,DB,Mail};
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Services\{ReminderSchedules,ReminderSchema};
// Expiry-day zero and an empty schedule have distinct staff-facing labels.
Auth::login($staff);$enable(0,'0','');
$facts=\Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::schedule($tlds[0]->server_id);
same('0',$facts['Days before expiry'],'Expiry-day schedule displays zero');
same('None',$facts['Days after expiry'],'Empty schedule displays None');
$enable(0,'','0');$facts=\Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::schedule($tlds[0]->server_id);
same('None',$facts['Days before expiry'],'Empty before schedule displays None');
same('0',$facts['Days after expiry'],'Expiry-day after schedule displays zero');
Auth::logout();$enable();
[$d,$invoice,$op]=$make('changed-invoice.com');DB::table('invoice_items')->where('id',$invoice->items()->sole()->id)->update(['price'=>'99.00']);
same(false,$reminders->remind($d->id),'Changed invoice is never a payment instruction');
expect(DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','invalid_invoice')->exists(),'Invalid invoice staff alert');
[$d,$invoice,$op]=$make('paid-renewal.com');$op->update(['state'=>'queued']);
same(false,$reminders->remind($d->id),'Paid queued renewal needs no reminder');
[$d]=$make('planned-invoice.com',false);$clock->instant=new DateTimeImmutable('2027-09-18T12:00:00Z');
\Paymenter\Extensions\Servers\Domains\Models\Price::where('action','renew')->update(['observed_at'=>$clock->now()->format('Y-m-d H:i:s')]);
$d->service->product->server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
expect($reminders->remind($d->id),'Source month-out scheduled invoice reminder');
same('September 27, 2027',Mail::queued(DomainNotice::class)->last()->data['invoice_on'],'Known saved issue date');
[$d]=$make('renewal-off.com',false);ServiceCancellation::create(['service_id'=>$d->service_id,'type'=>'end_of_period','reason'=>'Synthetic request']);
$cancelCounts=[App\Models\Invoice::count(),Paymenter\Extensions\Servers\Domains\Models\Operation::count()];
same(null,$billing->invoice($d->id),'Scheduled billing quietly skips a native cancellation before the notice');
same($cancelCounts,[App\Models\Invoice::count(),Paymenter\Extensions\Servers\Domains\Models\Operation::count()],'Cancellation notice flow creates no renewal debt');

expect($reminders->remind($d->id),'Renewal-off notice');
$offMail=Mail::queued(DomainNotice::class)->last();
same(null,$offMail->data['invoice'],'Cancelled renewal notice offers no payment instruction (absent invoice is null for retained templates)');
same(false,$offMail->data['complimentary'],'Native cancellation is not complimentary renewal');
expect(str_contains($offMail->render(),'Renewal is turned off'),'Actual cancelled-service mail says Renewal off');
expect(str_contains($offMail->render(),'contact support before') && !str_contains($offMail->render(),'unless you renew it'),'Renewal-off mail points to support without promising self-service renewal');
expect(str_contains(view('domains::mail-text',$offMail->content()->with)->render(),'Renewal is turned off'),'Cancelled-service text alternative says Renewal off');
expect(!DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->whereIn('code',['missing_obligation','held_obligation','invalid_invoice'])->exists(),'Native cancellation alone creates no renewal billing review alert');
$offNotice=App\Models\Notification::where('user_id',$d->user_id)->latest('id')->firstOrFail();
expect(str_contains($offNotice->title,$d->domain)&&str_contains($offNotice->body,'Renewal is turned off. To keep this domain, contact support before it expires.'),'Actual native in-app reminder says renewal is off for the cancelled domain');

[$d]=$make('expired-off.com',false);ServiceCancellation::create(['service_id'=>$d->service_id,'type'=>'end_of_period','reason'=>'Synthetic request']);
$d->update(['status'=>'expired']);$clock->instant=new DateTimeImmutable('2027-10-21T12:00:00Z');
expect($reminders->remind($d->id),'Expired notice at configured three-day window');
// Native user preferences suppress a whole window without repeated attempts.
[$d]=$make('preference-off.com');$template=NotificationTemplate::where('key','domains_domain_expiring')->sole();
$d->service->user->notificationsPreferences()->create(['notification_template_id'=>$template->id,'mail_enabled'=>false,'in_app_enabled'=>false]);
$before=Mail::queued(DomainNotice::class)->count();same(false,$reminders->remind($d->id),'Native opt-out respected');
same($before,Mail::queued(DomainNotice::class)->count(),'Opt-out queues no mail');
same('suppressed',DB::table('domains_reminder_deliveries')->where('domain_id',$d->id)->sole()->state,'Preference outcome retained');
// Mail-disable is email-only; native in-app preferences still apply.
$d->service->user->notificationsPreferences()->delete();[$appOnly]=$make('in-app-only.com');
config(['settings.mail_disable'=>true]);$count=\App\Models\Notification::where('user_id',$appOnly->user_id)->count();
expect($reminders->remind($appOnly->id),'Mail-disable preserves in-app notice');
same($count+1,\App\Models\Notification::where('user_id',$appOnly->user_id)->count(),'Native app channel delivered');
same($before,Mail::queued(DomainNotice::class)->count(),'Global mail-disable queues no mail');config(['settings.mail_disable'=>false]);
// Deliberately customized schedules and templates survive retained migration rollback/reinstall.
$m=require dirname(__DIR__).'/Domains/database/migrations/2026_10_18_000005_create_reminder_tables.php';$m->down();ReminderSchema::install();
$row=DB::table('domains_reminder_schedules')->where('server_id',$d->server_id)->sole();same(true,(bool)$row->enabled,'Downgrade retains customized schedule');
$enable(1);$staff->role->update(['permissions'=>[\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW]]);
commerceReject(fn()=>ReminderSchedules::save($staff,$d->server_id,$row->revision,true,'1','3','revoked_role'));
// A durable bounded cursor progresses; repeated fixed-time invocation cannot storm an account.
$batch=ReminderSchedules::next($clock->now()->getTimestamp(),2);same(2,count($batch),'Per-account limit');
$next=ReminderSchedules::next($clock->now()->getTimestamp(),2);expect(!array_intersect($batch,$next),'Other account turn is independent');
$again=ReminderSchedules::next($clock->now()->getTimestamp());same([],$again,'Cadence prevents fixed-time repeat work');
commerceReject(fn()=>ReminderSchedules::next($clock->now()->getTimestamp(),26));
// Real scheduled selection must expose failures without leaking the exception message.
[$broken]=$make('throwing-reminder.com');[$healthy]=$make('following-reminder.com');
$throwOnRead=[];
\Paymenter\Extensions\Servers\Domains\Models\Domain::retrieved(static function($domain)use(&$throwOnRead):void {
    if(isset($throwOnRead[$domain->id])&&$domain->getRawOriginal('domain')!==null){unset($throwOnRead[$domain->id]);throw new RuntimeException('private-reminder-provider-contact-credential');}
});
$handler=new class extends \Monolog\Handler\AbstractHandler {
    public array $warnings=[];public bool $fail=false;
    public function handle(\Monolog\LogRecord $record):bool
    {
        if($record->message!=='Domains reminder check failed.'){return false;}
        $this->warnings[]=['level'=>$record->level,'message'=>$record->message,'context'=>$record->context];
        if($this->fail){throw new RuntimeException('private-logger-credential');}return true;
    }
};
$logger=logger()->getLogger();$logger->pushHandler($handler);
try {
    foreach([false,true]as$loggerFails){
        DB::table('domains_reminder_schedules')->update(['due_at'=>$clock->now()->getTimestamp()+300]);
        DB::table('domains_reminder_schedules')->where('server_id',$broken->server_id)->update(['due_at'=>0,'cursor'=>$broken->id-1]);
        $throwOnRead=[$broken->id=>true];$handler->fail=$loggerFails;
        same($loggerFails?0:1,$reminders->sweep(1,2),'Sweep continues to healthy next domain even if warning handler throws; no duplicate notice');
        expect(DB::table('domains_obligation_alerts')->where('domain_id',$broken->id)->where('code','reminder_failed')->exists(),'Throwing reminder retains staff evidence');
    }
}finally{$logger->popHandler();}
same(array_fill(0,2,['level'=>\Monolog\Level::Warning,'message'=>'Domains reminder check failed.',
    'context'=>['domain_id'=>$broken->id,'exception_class'=>RuntimeException::class]]),$handler->warnings,'Warnings contain only class and domain ID, never private exception text or objects');
expect(DB::table('domains_reminder_deliveries')->where('domain_id',$healthy->id)->where('state','accepted')->exists(),'Later holding still receives its notice');
[$unmanaged]=$make('unmanaged-reminder.com');
DB::table('domains_domains')->where('id',$unmanaged->id)->update(['billing_managed'=>false]);
same(false,$reminders->remind($unmanaged->id),'Unmanaged holding quietly skips before managed authority locks');
expect(!DB::table('domains_reminder_deliveries')->where('domain_id',$unmanaged->id)->exists(),'Unmanaged holding sends no notice');
expect(!DB::table('domains_obligation_alerts')->where('domain_id',$unmanaged->id)->exists(),'Unmanaged holding creates no reminder failure alert');
echo "Native invoice authority, scheduled/off/expired notices, preferences, retained migration and bounded cursor checks passed.\n";$nativeSuccessful=true;

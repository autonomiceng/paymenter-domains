<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview as Review,StaffSettingsWrites};
/** One click on a domain's open reminder problems. Failed checks retry on every sweep, so an open one has already failed again.
 * An uncertain email is never resent (it could arrive twice), so only a person can say it was handled. */
final class NoticeReview
{
    private const REASON='Checked from the domain page.';
    public static function action(Domain $d): Action
    {
        return Action::make('notice_review')->label('Mark reminder checked')->size('sm')->color('gray')
            ->visible(fn()=>self::open($d)!==[])
            ->action(function()use($d):void {
                try{foreach(self::open($d) as [$action,$field,$id]){
                        $server=(int)$d->server_id;
                        app(StaffSettingsWrites::class)->run(new Review((int)Auth::id(),$server,null,$action,Review::ticket((int)Auth::id(),$server,null,$action,Review::stamp($server,null)),self::REASON),[$field=>$id,'confirmed'=>true]);
                    }
                    Notification::make()->success()->title('Saved')->send();
                }catch(\Throwable){Notification::make()->danger()->title("Couldn't save")->body('Reload this page, then try again.')->send();}
            });
    }
    /** @return list<array{string,string,int}> The writer call for each open item this person may clear. */
    private static function open(Domain $d): array
    {
        $open=[];if(!Staff::can(Staff::POLICY)){return $open;}
        if(Staff::can(Review::permission('alert_review'))){
            foreach(DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('generation',$d->authority_generation)->whereIn('code',['template_failed','reminder_failed'])->whereNull('resolved_at')->pluck('id') as $id){$open[]=['alert_review','alert_id',(int)$id];}
        }
        if(Staff::can(Review::permission('delivery_review'))){
            foreach(DB::table('domains_reminder_deliveries')->where('domain_id',$d->id)->whereIn('state',['handoff','uncertain'])->pluck('id') as $id){$open[]=['delivery_review','delivery_id',(int)$id];}
        }
        return $open;
    }
}

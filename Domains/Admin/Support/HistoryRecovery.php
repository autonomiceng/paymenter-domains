<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence,HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,ReconciliationEvidence,StaffSettingsReview as Review,StaffSettingsWrites};
/** One click on a TEST Enom renewal held by a lost reply: the system can't prove from a lost reply whether Enom finished, so a person
 * lets it read Enom's order history. The saved values then cover this account's later renewals too. Enom registrations can't use it:
 * their read needs a captured order ID (EnomTestReconciliationReader). */
final class HistoryRecovery
{
    public const NAME='history_recovery';
    public const CAUSE='Enom’s reply was lost. Its order history can show if this renewal went through.';
    public const TOO_OLD='Enom’s reply was lost and this is too old to check in its order history. Ask Enom if this renewal went through.';
    private const REASON='Turned on from the held request.';
    /** Fixture values from the synthetic TEST renewal scenario (tests/Support/enom-test-renewal.php), not measured from Enom, so Live never gets this action. */
    private const TIMEZONE='America/Los_Angeles',LAG=60,RETENTION=86400;
    public static function action(Operation $op): Action
    {
        return Action::make(self::NAME)->label('Check Enom order history')->visible(fn()=>self::offered($op))
            ->action(function()use($op):void {
                try{
                    $server=(int)$op->server_id;
                    app(StaffSettingsWrites::class)->run(new Review((int)Auth::id(),$server,null,'history',Review::ticket((int)Auth::id(),$server,null,'history',Review::stamp($server,null)),self::REASON),
                        ['history_action'=>'renew','accept'=>true,'verified'=>true,'timezone'=>self::TIMEZONE,'lag'=>self::LAG,'retention'=>self::RETENTION]);
                    Notification::make()->success()->title('Saved')->body('Enom’s order history gets checked within a few minutes.')->send();
                }catch(\Throwable){Notification::make()->danger()->title("Couldn't save")->body('Reload this page, then try again.')->send();}
            });
    }
    /** Only when this hold is the reason: a lost reply, no saved history, and these values can read this attempt now. */
    public static function offered(Operation $op): bool
    {
        if(!Staff::can(Staff::POLICY)||!Staff::can(Review::permission('history'))){return false;}
        $attempt=self::lost($op);
        return $attempt!==null&&$attempt->historyAcceptance===null
            &&(new HistoryAcceptance($attempt->binding,self::TIMEZONE,self::LAG,self::RETENTION,'enom_renewal_v1'))->covers($attempt,app(Clock::class)->now());
    }
    /** Past the read window, with or without saved history, no read can settle this hold, so Check status has nothing to do. */
    public static function tooOld(Operation $op): bool
    {
        $attempt=self::lost($op);
        return $attempt!==null&&app(Clock::class)->now()->getTimestamp()-$attempt->windowStart->getTimestamp()>=($attempt->historyAcceptance?->retentionSeconds??self::RETENTION);
    }
    private static function lost(Operation $op): ?AttemptEvidence
    {
        if($op->action!=='renew'||$op->registrar_key!=='enom'||!in_array($op->state,['pending','uncertain'],true)){return null;}
        try{
            $a=Account::where('server_id',$op->server_id)->sole();
            if($a->environment!=='test'){return null;}
            $attempt=ReconciliationEvidence::attempt($op,$op->domain,CatalogueAcceptances::binding($a));
            return $attempt->replyLost()?$attempt:null;
        }catch(\Throwable){return null;}
    }
}

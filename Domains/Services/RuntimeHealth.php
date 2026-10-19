<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\Account;
/** Local operational facts only. No credential, queue payload or provider response is exposed. */
final class RuntimeHealth
{
    public static function for(int $server): array
    {
        if(!Schema::hasTable('domains_runtime_turns')){return ['Runtime'=>'Installation verification required'];}
        $row=DB::table('domains_runtime_turns')->where('server_id',$server)->first();
        $binding=Account::where('server_id',$server)->value('account_fingerprint');
        $budget=DB::table('domains_read_budgets')->where('server_id',$server)->first();
        $claims=DB::table('domains_reminder_deliveries as n')->join('domains_domains as d','d.id','=','n.domain_id')
            ->where('d.server_id',$server)->whereIn('n.state',['handoff','uncertain'])->orderBy('n.id')->limit(10)->get(['n.id','n.state','d.domain']);
        $failures=DB::table('domains_obligation_alerts as a')->join('domains_domains as d','d.id','=','a.domain_id')->where('d.server_id',$server)->whereNull('a.resolved_at')->whereIn('a.code',['template_failed','reminder_failed'])->orderBy('a.id')->limit(10)->get(['a.id','a.code','d.domain']);
        $unsafe=collect(app('router')->getRoutes()->getRoutes())->contains(fn($r)=>in_array(explode('@',$r->getActionName())[0],[\App\Admin\Resources\ServerResource\Pages\CreateServer::class,\App\Admin\Resources\ServerResource\Pages\EditServer::class,\App\Admin\Resources\ServiceResource\Pages\EditService::class],true));
        return ['Native editors'=>$unsafe?'Refresh the native route cache; unsafe editors are refused':'Protected',
            'Runtime account'=>$row?($row->account_fingerprint===$binding?'Current binding':'On hold · restore the original account binding'):'Waiting for first scheduler enrollment',
            'Last worker turn'=>$row&&$row->finished_at?gmdate('M j, Y H:i \U\T\C',(int)$row->finished_at):'Not completed',
            'Worker cadence'=>'Up to 8 accounts per minute, 25 requests and 25 domains per account turn. Check rotation lag against account count.',
            'Read capacity'=>$budget?((int)$budget->used.' wire starts in the last recorded minute; background ceiling 48, shared ceiling 60'):'No recorded wire starts',
            'Notification claims to review'=>$claims->map(fn($r)=>'#'.$r->id.' · '.$r->domain.' · '.($r->state==='handoff'?'Handoff not confirmed':'Outcome unconfirmed'))->implode('; ')?:'None recorded',
            'Notification checks to review'=>$failures->map(fn($r)=>'#'.$r->id.' · '.$r->domain.' · '.ObligationAlerts::LABELS[$r->code])->implode('; ')?:'None recorded',
            'Worker recovery'=>'An interrupted turn resumes after its due time. Review failed queue jobs before retrying; unresolved registrar outcomes remain reserved.',
            'Binding recovery'=>'Do not rebind retained accounts. Restore the original verified identity; processing requires a fresh staff confirmation.'];
    }
}

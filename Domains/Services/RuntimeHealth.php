<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\Account;
/** Local operational facts only. No credential, queue payload or provider response is exposed.
 * Worker cadence and recovery notes are in docs/runtime.md. */
final class RuntimeHealth
{
    public static function site(): array
    {
        return [...NativePaidHooks::notices(),
            ...(NativeServerGuard::deletionNoticeMissing()===null?[]:['Server delete notice'=>'Unavailable · missing '.NativeServerGuard::deletionNoticeMissing().'. Deletion is still refused.']),
            'Domain editor notice'=>($missing=\Paymenter\Extensions\Servers\Domains\Admin\Support\NativeEditorNotice::missing())===null?'Available':'Paused · missing '.$missing,
            ...(NativeServiceRefusal::missing()===null?[]:['Service refusal notice'=>'Unavailable · missing '.NativeServiceRefusal::missing().'. Unsafe changes are still refused.'])];
    }
    public static function for(int $server): array
    {
        if(!Schema::hasTable('domains_runtime_turns')){return ['Runtime'=>'Installation verification required'];}
        $row=DB::table('domains_runtime_turns')->where('server_id',$server)->first();
        $account=Account::where('server_id',$server)->first();$namesilo=$account?->registrar_key==='namesilo';$sandbox=$account&&NameSiloSandbox::enrolled($account);
        $binding=$account?->account_fingerprint;
        $budget=DB::table('domains_read_budgets')->where('server_id',$server)->first();
        $claims=DB::table('domains_reminder_deliveries as n')->join('domains_domains as d','d.id','=','n.domain_id')
            ->where('d.server_id',$server)->whereIn('n.state',['handoff','uncertain'])->orderBy('n.id')->limit(10)->get(['n.id','n.state','d.domain']);
        $failures=DB::table('domains_obligation_alerts as a')->join('domains_domains as d','d.id','=','a.domain_id')->where('d.server_id',$server)->whereNull('a.resolved_at')->whereIn('a.code',['template_failed','reminder_failed'])->orderBy('a.id')->limit(10)->get(['a.id','a.code','d.domain']);
        $identity=DB::table('domains_obligation_alerts as a')->join('domains_domains as d','d.id','=','a.domain_id')->where('d.server_id',$server)->where('a.code','native_identity_missing')->whereNull('a.resolved_at')->orderBy('a.id')->limit(10)->get(['a.id','d.domain']);
        $relinks=DB::table('domains_obligation_alerts as a')->join('domains_domains as d','d.id','=','a.domain_id')->where('d.server_id',$server)->where('a.code','transfer_watch_relink')->whereNull('a.resolved_at')->orderBy('a.id')->limit(10)->pluck('d.domain');
        return ['Transfer watch relinks'=>$relinks->isEmpty()?'None recorded':$relinks->implode(', ').' ('.ObligationAlerts::LABELS['transfer_watch_relink'].')',
            'Missing Paymenter records'=>$identity->isEmpty()?'None recorded':$identity->pluck('domain')->implode(', ').' ('.ObligationAlerts::LABELS['native_identity_missing'].')',
            'Registrar configuration'=>match(app(AccountBindings::class)->configurationStatus($server)){
                'matched'=>$sandbox?'Matches immutable OTE enrollment; account ownership unconfirmed':($namesilo?'NameSilo account ownership unconfirmed · no OTE enrollment':'Matches verified account'),
                'not_configured'=>'Not configured',
                'server_disabled'=>'Off · server disabled. Re-enable it in Servers, then resume orders on the account page.',
                'server_unavailable'=>'On hold · Domains server unavailable. Review the server in Servers.',
                'site_invalid'=>'On hold · live mode requires a canonical HTTPS site address. Ask the installation administrator to review the application URL.',
                'site_unbound'=>'On hold · live mode is not bound to this site address. This release has no staff action to bind it; processing stays off.',
                'site_mismatch'=>'On hold · live mode is bound to a different site address. Ask the installation administrator to restore the original site configuration.',
                default=>$sandbox?'On hold · OTE enrollment is off on this site, or saved enrollment settings changed. Ask the installation administrator about OTE enrollment, or restore the original saved enrollment settings; a new key requires a fresh Domains server.':($namesilo?'On hold · saved settings no longer match this NameSilo binding, and NameSilo did not confirm which account the key belongs to. Restore the original saved settings. To use NameSilo OTE, enroll on a fresh Domains server; this server’s records do not carry over.':'On hold · saved settings no longer match the verified account. Use Edit API credentials to re-enter credentials for the original account before resuming processing.')},
            'Runtime account'=>$row?($row->account_fingerprint===$binding?'Current binding':'On hold · restore the original account binding'):'Waiting for the first scheduled run',
            'Last worker turn'=>$row&&$row->finished_at?gmdate('M j, Y H:i \U\T\C',(int)$row->finished_at):'Not completed',
            'Read capacity'=>($budget?((int)$budget->used.' registrar requests in the last recorded minute (limit 48 background, 60 total)'):'No registrar requests recorded').'. Shared by interactive and background reads and changes; identity verification and the Enom TEST daily limit are separate.',
            'Notification claims to review'=>$claims->map(fn($r)=>'#'.$r->id.' · '.$r->domain.' · '.($r->state==='handoff'?'Handoff not confirmed':'Outcome unconfirmed'))->implode('; ')?:'None recorded',
            'Notification checks to review'=>$failures->map(fn($r)=>'#'.$r->id.' · '.$r->domain.' · '.ObligationAlerts::LABELS[$r->code])->implode('; ')?:'None recorded'];
    }
}

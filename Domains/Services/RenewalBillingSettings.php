<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
/** Staff-reviewed renewal billing switches and calendar. Native server settings stay the only source; issued debts keep their own calendar. */
final class RenewalBillingSettings
{
    public const SCHEDULED='domains_billing_enabled';
    public const MANUAL='domains_manual_renewals_enabled';
    public const ISSUE='domains_issue_days';
    public const DUE='domains_due_days';
    public const KEYS=[self::SCHEDULED,self::MANUAL,self::ISSUE,self::DUE];
    /** Effective values with the billing defaults; an unreadable calendar is reported as null, never repaired. */
    public static function read(int $serverId,bool $lock=false): array
    {
        $server=Server::find($serverId);$query=$server?->settings()->whereIn('key',self::KEYS)->orderBy('id');
        $rows=$query?($lock?$query->lockForUpdate():$query)->get():collect();$values=$rows->pluck('value','key');
        try{$timing=BillingCalendar::timing($rows);}catch(\DomainException){$timing=null;}
        return ['scheduled'=>self::on($values->get(self::SCHEDULED)),'manual'=>self::on($values->get(self::MANUAL)),
            'issue_days'=>$timing['issue']??null,'due_days'=>$timing['due']??null,'calendar_saved'=>$values->has(self::ISSUE)||$values->has(self::DUE)];
    }
    public static function facts(int $serverId): array
    {
        $v=self::read($serverId);
        return ['Scheduled renewal invoices'=>$v['scheduled']?'On':'Off','Owner renewal requests'=>$v['manual']?'On':'Off',
            'Renewal invoice calendar'=>$v['issue_days']===null?'Saved calendar invalid · new renewal invoices refused'
                :'Issued '.$v['issue_days'].' days before expiry · due '.$v['due_days'].' days before'.($v['calendar_saved']?'':' (defaults)')];
    }
    public static function save(StaffSettingsReview $r,#[\SensitiveParameter] array $data): void
    {
        $r->local(function()use($r,$data):void {
            if(($data['confirmed']??false)!==true){throw new \DomainException('Explicit renewal billing confirmation required.');}
            if(!is_bool($data['scheduled']??null)||!is_bool($data['manual']??null)){throw new \DomainException('Invalid renewal billing input.');}
            $issue=filter_var($data['issue_days']??null,FILTER_VALIDATE_INT);$due=filter_var($data['due_days']??null,FILTER_VALIDATE_INT);
            if($issue===false||$due===false){throw new \DomainException('Invalid renewal timing.');}
            BillingCalendar::requireTiming($issue,$due);
            $s=Server::whereKey($r->serverId)->lockForUpdate()->firstOrFail();
            $rows=$s->settings()->whereIn('key',self::KEYS)->orderBy('id')->lockForUpdate()->get();
            $before=self::read($r->serverId,true);
            if([$before['scheduled'],$before['manual'],$before['issue_days'],$before['due_days']]===[$data['scheduled'],$data['manual'],$issue,$due]){throw new \DomainException('Renewal billing unchanged.');}
            foreach([self::SCHEDULED=>[$data['scheduled']?'1':'0','boolean'],self::MANUAL=>[$data['manual']?'1':'0','boolean'],self::ISSUE=>[(string)$issue,'integer'],self::DUE=>[(string)$due,'integer']] as $key=>[$value,$type]){
                $row=$rows->firstWhere('key',$key)??$s->settings()->make(['key'=>$key]);
                $row->value=$value;$row->type=$type;$row->encrypted=false;$row->save();
            }
        });
    }
    /** Either flag lets renewal invoices go out, so the Renewals switch reads On. */
    public static function anyOn(int $serverId): bool
    {$billing=self::read($serverId);return $billing['scheduled']||$billing['manual'];}
    /** The Enom Renewals switch writes this with its send permission, inside the caller's one transaction. Off clears every half. */
    public static function setSwitch(int $serverId,bool $on): void
    {
        if($on&&self::read($serverId,true)['issue_days']===null){throw new \DomainException('Fix the invoice days first.');}
        $s=Server::whereKey($serverId)->lockForUpdate()->firstOrFail();
        foreach($on?[self::SCHEDULED]:[self::SCHEDULED,self::MANUAL] as $key){
            $row=$s->settings()->where('key',$key)->lockForUpdate()->first()??$s->settings()->make(['key'=>$key]);
            $row->value=$on?'1':'0';$row->type='boolean';$row->encrypted=false;$row->save();
        }
    }
    private static function on(mixed $value): bool
    {return filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)===true;}
}

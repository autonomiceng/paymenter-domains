<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
final class EvidenceIncomplete extends \DomainException
{
    public function __construct(public readonly array $summary){parent::__construct('Account evidence incomplete; no file published.');}
}
final class EvidenceCollector
{
    public function __construct(private EvidenceClient $client) {}
    public function collect(string $out,int $actor,string $reason,array $ids=[],?int $limit=null,bool $live=false,bool $replace=false): array
    {
        EvidenceFile::target($out,$replace);SourceAccount::actor($actor,$reason);
        if(($limit!==null&&$limit<1)||count(array_filter($ids,static fn($id)=>!is_int($id)||$id<1))){throw new \DomainException('Positive scope required.');}
        $domains=DB::table('enom_domains')->orderBy('id')->get();
        if(array_diff($ids,$domains->pluck('id')->all())){throw new \DomainException('Unknown source ledger ID.');}
        $sources=[];$scope=[];$planner=new Planner([]);
        foreach(Server::where('extension','Enom')->orderBy('id')->get()as$server){
            $s=SourceAccount::read($server);$a=['uid'=>strtolower($s['uid']),'old'=>$s['old']];$sources[$server->id]=$s;
            foreach($domains as$d){$d=(array)$d;if((int)$d['server_id']!==(int)$server->id||($ids&&!in_array((int)$d['id'],$ids,true))){continue;}
                if($planner->purchaseCandidate($d,$a)){$scope[]=$d;}}
        }
        usort($scope,static fn($a,$b)=>$a['id']<=>$b['id']);if($limit!==null){$scope=array_slice($scope,0,$limit);}
        // Check the whole selected scope before the first request: a mixed TEST/live run cannot partially escape the guard.
        foreach($scope as$d){if($sources[$d['server_id']]['environment']==='live'&&(!$live||!$ids)){throw new \DomainException('Live collection requires --live and explicit --domain IDs.');}}
        $evidence=['accounts'=>[],'domains'=>[]];$omitted=[];
        foreach($scope as$d){$s=$sources[$d['server_id']];$a=['uid'=>strtolower($s['uid']),'old'=>$s['old']];
            try {
                $xml=$this->client->read('GetDomainInfo',(int)$d['server_id'],$s['environment'],$s['uid'],$s['token'],$d);
                $entry=['account_fingerprint'=>$s['old'],'xml'=>$xml];$read=OwnershipRead::parse($d,$a,$entry);
                if($read['id']!==$d['provider_domain_id']||$read['expiry']!==$d['provider_expires_at']){throw new \RuntimeException;}
                // Retain only the accepted ownership fields, excluding contacts, extra XML and credential echoes.
                $escape=static fn($v)=>htmlspecialchars($v,ENT_XML1|ENT_QUOTES,'UTF-8');
                $entry['xml']='<interface-response><Command>GetDomainInfo</Command><Done>true</Done><ErrCount>0</ErrCount><GetDomainInfo><DomainName DomainNameID="'.$read['id'].'">'.$escape($d['domain']).'</DomainName><status><belongs-to>'.$escape($a['uid']).'</belongs-to><expiration>'.$read['expiry'].'</expiration></status></GetDomainInfo></interface-response>';
                OwnershipRead::parse($d,$a,$entry);$evidence['domains'][(string)$d['id']]=$entry;
                $evidence['accounts'][(string)$d['server_id']]??=['source'=>'authenticated_provider_read','uid'=>$a['uid'],'environment'=>$s['environment'],
                    'credential_digest'=>Inputs::digest([$s['uid'],$s['token']]),'accepted_by'=>$actor,'reason'=>$reason,'accepted_at'=>time(),'domain_id'=>(int)$d['id']];
            }catch(\Throwable){$omitted[]=(int)$d['id'];}
        }
        $summary=['collected'=>count($evidence['domains']),'omitted'=>count($omitted),'source_ledger_ids'=>$omitted];
        // The converter requires an independently authenticated anchor for every retained account.
        // Never publish an apparently usable partial file or read outside explicit live scope to fill gaps.
        if(array_diff(array_keys($sources),array_keys($evidence['accounts']))){throw new EvidenceIncomplete($summary);}
        EvidenceFile::write($out,$evidence,$replace,array_column($sources,'token'));
        return $summary;
    }
}

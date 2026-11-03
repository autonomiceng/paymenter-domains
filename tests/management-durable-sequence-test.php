<?php
// Synthetic storage/authority integration. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{Capabilities,Capability,ManagementRequest,ManagementResult,RequestContext,RequestOrigin,Support};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{ManagementProgress,ManagementSequence};
final class SyntheticSequence extends ManagementProtocol {
    public function capabilities():Capabilities {return new Capabilities($this->binding,['dns'=>new Capability(Support::Supported)]);}
    public function validate(string $a,array $p,string $n):array {return $p;}
    public function sequences(string $action):bool {return true;}
    public function plan(ManagementRequest $r,RequestContext $c):array {
        $before=json_decode($this->read($r,$c,'snapshot',[]),true,flags:JSON_THROW_ON_ERROR);
        return ['preimage'=>$before,'steps'=>array_map(fn($i)=>['action'=>'dns','input'=>['delta'=>$i]],$r->payload()['changes'])];
    }
    public function prepare(ManagementRequest $r,RequestContext $c):array {
        same($r->sequence()['preimage'],json_decode($this->read($r,$c,'snapshot',[]),true,flags:JSON_THROW_ON_ERROR),'Each child rechecks the retained complete preimage');return $r->payload();
    }
    protected function apply(ManagementRequest $r,RequestContext $c):ManagementResult {$this->write($r,$c,'change',$r->payload());return $this->observe($r,$c);}
    public function observe(ManagementRequest $r,RequestContext $c):ManagementResult {
        $v=json_decode($this->read($r,$c,'snapshot',[]),true,flags:JSON_THROW_ON_ERROR);$old=$r->sequence()['preimage'];
        return new ManagementResult($v===[...$old,$r->payload()['delta']],false,['sequence_preimage'=>$v]);
    }
    protected function readCommands():array {return ['snapshot'];} protected function validateRead(string $c,array $p):void {}
    protected function writeCommand(string $a):string {return 'change';} protected function origin(RequestContext $c):string {return 'synthetic';}
}
$wire=(object)['state'=>[],'writes'=>[],'lost'=>false,'during'=>null,'reads'=>0];
$exchangeSequence=static function($o,$cmd,$p,$timeout)use($wire):string {
    same(0,DB::transactionLevel(),'Sequence I/O remains outside native locks');
    if($cmd==='snapshot'){$wire->reads++;if($wire->during){$f=$wire->during;$wire->during=null;$f();}return json_encode($wire->state);}
    $wire->writes[]=$p['delta'];$wire->state[]=$p['delta'];if($wire->lost){throw new RuntimeException('Synthetic lost response');}return '{}';
};
$setup=static function($label)use($holding,&$binding,$wire,$exchangeSequence){[$d]=$holding($label);\App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>true]);$wire->state=[];$wire->writes=[];$wire->lost=false;$wire->during=null;return [$d,new SyntheticSequence($binding,$exchangeSequence)];};
[$d,$driver]=$setup('durable-sequence');$intent=['changes'=>[1,2,3]];$nonce=hash('sha256','durable-sequence');
$op=$management->request($user,$d->id,'dns',$intent,$nonce,$driver);
$steps=$d->operations()->where('action','dns')->orderBy('id')->get();
same(3,$steps->count(),'One save freezes all child grants in the existing ledger');
same($intent,$op->policy['management']['intent'],'Customer intent remains frozen independently of the final delta');
same([], $op->policy['management']['sequence']['preimage'],'The initial preimage is retained');
$reads=$wire->reads;same($op->id,$management->request($user,$d->id,'dns',$intent,$nonce,$driver)->id,'Same nonce returns the frozen sequence');same($reads,$wire->reads,'Idempotent save performs no new inventory read');
$management->run($steps[1]->id,$driver);same([],$wire->writes,'A later child cannot bypass its predecessor');
$management->run($op->id,$driver);same([1],$wire->writes,'One entry consumes only one child grant');
same('Request #'.$op->id.': 1 of 3 changes verified. Saving the remaining changes.',ManagementProgress::for($d)['dns']['text'],'Staff and owner share retained partial progress');
$wire->lost=true;Cache::flush();$management->run($op->id,$driver);same([1,2],$wire->writes,'Second delta is sent once despite a lost reply');
$child=$steps[1]->fresh();same('uncertain',$child->state,'The lost child retains uncertainty');
$oldAttempt=$child->attempt;$management->run($child->id,$driver);same([1,2],$wire->writes,'Direct child entry cannot replay a sent delta');
$wire->lost=false;$driver=new SyntheticSequence($binding,$exchangeSequence);Cache::flush();$management->run($op->id,$driver);
same('succeeded',$child->fresh()->state,'A reconstructed driver recovers only from independent readback');same([1,2],$wire->writes,'Readback cannot mutate');
Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'Final delta completes the original request');same([1,2,3],$wire->writes,'Exactly one grant per intended delta');
same('Request #'.$op->id.': all 3 changes verified.',ManagementProgress::for($d)['dns']['text'],'Only complete retained proof reports full success');
foreach($steps as $s){expect(isset($s->fresh()->attempt['dispatch_attempted_at']),'Each mutation retains its own seal');}
$management->run($op->id,$driver);same([1,2,3],$wire->writes,'Completed sequence cannot replay');
[$d,$driver]=$setup('stale-config');$op=$submit($d,$driver,'dns',$intent);$management->run($op->id,$driver);
\App\Models\Server::findOrFail($d->server_id)->settings()->create(['key'=>'synthetic_revision','value'=>'changed']);Cache::flush();$management->run($op->id,$driver);
same([1],$wire->writes,'Config drift between children cannot run the frozen sequence');
expect(str_contains(ManagementProgress::for($d)['dns']['text'],'Contact support'),'A stuck sequence gives a concrete next step');
[$d,$driver]=$setup('owner-drift');$op=$submit($d,$driver,'dns',$intent);$management->run($op->id,$driver);
DB::table('services')->where('id',$d->service_id)->update(['user_id'=>commerceUser()->id]);Cache::flush();try{$management->run($op->id,$driver);}catch(DomainException){}
same([1],$wire->writes,'Stale native owner cannot execute remaining children');
[$d,$driver]=$setup('preimage-drift');$op=$submit($d,$driver,'dns',$intent);$wire->state=[99];$management->run($op->id,$driver);same([],$wire->writes,'Preimage drift prevents the first mutation');
[$d,$driver]=$setup('consume-drift');$op=$submit($d,$driver,'dns',$intent);$wire->during=fn()=>\App\Models\Server::findOrFail($d->server_id)->settings()->create(['key'=>'synthetic_race','value'=>'changed']);$management->run($op->id,$driver);same([],$wire->writes,'Configuration drift during preparation prevents sealing');
[$d,$driver]=$setup('retire-unstarted');$op=$submit($d,$driver,'dns',$intent);$management->retire($user,$op->id);
same(3,$d->operations()->where('action','dns')->where('state','rejected')->count(),'Retirement closes every untouched child');
foreach($d->operations()->where('action','dns')->get() as $s){$management->run($s->id,$driver);}same([],$wire->writes,'Retired child descriptors cannot run');
[$d,$driver]=$setup('replace-unstarted');$op=$submit($d,$driver,'dns',$intent);$replacement=$submit($d,$driver,'dns',['changes'=>[4,5]]);
same('rejected',$op->fresh()->state,'A fresh save replaces the untouched sequence');same(3,$d->operations()->where('action','dns')->where('state','rejected')->count(),'Replacement closes all old children');
// A possibly-sent unknown child owns the setting until its exact change resolves.
[$d,$driver]=$setup('ambiguous');$op=$submit($d,$driver,'dns',$intent);$wire->lost=true;$management->run($op->id,$driver);$wire->state=[1,99];
$management->run($op->id,$driver);same([1],$wire->writes,'Ambiguous independent state never advances');
same($op->id,$submit($d,$driver,'dns',['changes'=>[4]])->id,'New intent cannot replace unknown sent work');
$progress=ManagementProgress::for($d)['dns'];expect(str_contains($progress['text'],'Contact support'),'Unknown child has a support next step');same('warning',$progress['tone'],'Unknown child uses shared warning tone');
// Freeze evidence cannot be edited after a save.
try{$p=$op->policy;$p['management']['sequence']['preimage']=[99];$op->update(['policy'=>$p]);throw new LogicException('Mutable preimage');}catch(DomainException){}
[$d,$driver]=$setup('slow-unclaimed');$op=$submit($d,$driver,'dns',$intent);$op->update(['created_at'=>$op->created_at->copy()->subSeconds(31)]);
$reads=$wire->reads;$progress=ManagementProgress::for($d)['dns'];
expect(str_contains($progress['text'],'Contact support'),'Slow unclaimed sequence has a next step even when a write gate prevents claiming');
expect($progress['pending'],'Slow unclaimed sequence retains the setting');same($reads,$wire->reads,'Progress reads only retained facts');
$nativeSuccessful=true;echo "Shared durable management sequence passed\n";

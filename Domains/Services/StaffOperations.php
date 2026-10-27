<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
final readonly class StaffOperations
{
    public function __construct(private Clock $clock) {}
    public function run(User $actor,int $domain,int $operation,string $action,string $ticket,string $reason): void
    {
        $r=new StaffOperationReview((int)$actor->id,$domain,$operation,$action,$ticket,$reason,$this->clock);
        switch($action){
            case 'hold': $r->local(fn($op,$l)=>StaffOperationWrites::hold($op,$l));break;
            case 'release':
                $op=\Paymenter\Extensions\Servers\Domains\Models\Operation::findOrFail($operation);
                if($op->safe_code==='contact_details_required'){
                    try{ContactRecovery::replace($actor,$operation,$reason,$r,$this->clock);}
                    catch(ContactValidation $error){
                        throw new StaffReviewRefused($error->missingMessage());
                    }
                }else{$r->local(fn($op,$l)=>StaffOperationWrites::release($op,$l,$this->clock->now()));}break;
            case 'disposition': $r->local(fn($op,$l)=>StaffOperationWrites::disposition($op,$l));break;
            case 'replacement': $r->local(fn($op,$l)=>RenewalReplacements::authorize($op,$l,$r,$this->clock));break;
            case 'refund': app(Refunds::class)->refundHeld($actor,$operation,'staff_review',$r);break;
            case 'credit': app(Refunds::class)->creditRejected($operation,$r);break;
            case 'resume_reads':
                $op=\Paymenter\Extensions\Servers\Domains\Models\Operation::findOrFail($operation);
                if($op->action==='renew'){app(RenewalCompletion::class)->resume($operation,$r);}
                else{app(OperationReconciliation::class)->resume($operation,'staff_review',$r);}break;
            case 'resume_observation':
                $id=ObservationWork::currentId(Domain::findOrFail($domain));
                if(!$id){throw new \DomainException('No scheduled read work matches the current domain account, generation and phase.');}
                app(ObservationWork::class)->resume((int)$id,'staff_review',$r);break;
            case 'recheck': $r->read(function()use($operation){$op=\Paymenter\Extensions\Servers\Domains\Models\Operation::findOrFail($operation);
                if($op->action==='renew'){app(RenewalCompletion::class)->run($operation);}
                elseif(in_array($op->action,['register','transfer'],true)){app(OperationReconciliation::class)->run($operation);}
                else {app(DomainManagement::class)->reconcile($operation,app(OwnerPorts::class)->forDomain($op->domain));}
            });break;
            default: throw new \DomainException('Unknown staff action.');
        }
    }
}

<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Checked selector; renewal never enters either acquisition engine. */
final readonly class AcquisitionPorts
{
    public function __construct(private AccountBinding $binding, private AcquisitionPort $registration, private AcquisitionPort $transfer)
    {
        $registration->requireRoute($binding,Action::Register);$transfer->requireRoute($binding,Action::Transfer);
        $registration->requireSharedPolicy($transfer);
    }
    public function requireBinding(AccountBinding $binding): void { $this->binding->requireMatch($binding); }
    public function execute(int $id): Operation
    {
        $action=Operation::findOrFail($id)->action;
        return (match($action){'register'=>$this->registration,'transfer'=>$this->transfer,
            default=>throw new \DomainException('Acquisition action unavailable.')})->execute($id);
    }
    public function gates(): array
    { return ['register'=>$this->registration->enabled(),'transfer'=>$this->transfer->enabled()]; }
}

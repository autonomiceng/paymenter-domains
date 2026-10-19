<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
/** Native create must surface installation refusal before persisting a server. */
class SecureCreateServer extends \App\Admin\Resources\ServerResource\Pages\CreateServer
{
    protected function handleRecordCreation(array $data): Model
    {
        if(($data['extension']??null)!=='Domains'){return parent::handleRecordCreation($data);}
        try{app(\Paymenter\Extensions\Servers\Domains\Services\Installation::class)->install();return parent::handleRecordCreation($data);}
        catch(\DomainException|\RuntimeException|\Illuminate\Contracts\Cache\LockTimeoutException $e){throw ValidationException::withMessages(['data.extension'=>$e->getMessage()]);}
    }
}

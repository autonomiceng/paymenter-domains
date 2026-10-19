<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin;
/** Native route replacement: generic EditServer must never serialize internal account settings. */
class SecureEditServer extends \App\Admin\Resources\ServerResource\Pages\EditServer
{
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if($this->record->extension!=='Domains'){return parent::mutateFormDataBeforeFill($data);}
        $data['settings']=[];return $data;
    }
    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record,array $data): \Illuminate\Database\Eloquent\Model
    {
        if($record->extension!=='Domains'){return parent::handleRecordUpdate($record,$data);}
        // Settings and extension identity are exclusively controlled by Registrar setup.
        $record->update(['name'=>$data['name'],'enabled'=>$data['enabled']??$record->enabled]);return $record->refresh();
    }
}

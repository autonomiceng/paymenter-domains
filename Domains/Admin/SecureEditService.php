<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin;
use Filament\Schemas\Schema;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;
/** Native save still uses the sole financial guard, with its required outer transaction. */
class SecureEditService extends \App\Admin\Resources\ServiceResource\Pages\EditService
{
    protected ?bool $hasDatabaseTransactions=true;
    private function managed(): bool {return $this->record&&Domain::where('service_id',$this->record->id)->exists();}
    public function form(Schema $schema): Schema
    {
        $schema=parent::form($schema);
        if($this->managed()){foreach($schema->getFlatComponents(withHidden:true) as $field){
            if($field instanceof \Filament\Forms\Components\DatePicker&&$field->getName()==='expires_at'){
                $field->required(false)->disabled()->dehydrated(false)->helperText('Domain expiry is maintained from confirmed registrar observations.');
            }
        }}return $schema;
    }
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if($this->managed()){
            $data['expires_at']=null;
            if(isset($data['price'])&&PricingRule::minor((string)$data['price'])===PricingRule::minor((string)$this->record->price)){$data['price']=$this->record->getRawOriginal('price');}
        }return $data;
    }
}

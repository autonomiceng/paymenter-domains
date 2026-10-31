<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Repeater,Select,TextInput,Textarea,Toggle};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\{Auth,Log};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Services\{ContactValidation,ManagementForms,OwnedDomains,OwnerFormValidation};
/** Staff edits on the domain page: the owner's form path and every check it runs, credited to the staff member. */
final class DomainEditActions
{
    private const CONTACT=['first_name'=>'First name','last_name'=>'Last name','organization_name'=>'Organization','address1'=>'Address','address2'=>'Address line 2','city'=>'City',
        'state_province'=>'State or province','postal_code'=>'Postal code','country'=>'Country code','email_address'=>'Email','phone'=>'Phone'];
    private const DNS_TYPES=['A'=>'A (IPv4)','AAAA'=>'AAAA (IPv6)','CNAME'=>'CNAME (alias)','MX'=>'MX (mail)','MXE'=>'MXE (mail IP)','TXT'=>'TXT (text)','URL'=>'URL (redirect)','FRAME'=>'FRAME (masked redirect)'];
    /** Engine refusals => why, and what to do. Unknown refusals get the generic line. */
    private const FAILURES=[
        'Prior operation unresolved.'=>'Another change is still running. Check History, then try again.',
        'Management lifecycle held.'=>'This domain or its service is not active, or orders are paused. Fix that, then try again.',
        'Management permission required.'=>"You don't have permission to change domains.",
        'Current records required.'=>'This changed after you opened it. Reload the page and try again.',
        'DNS tools disabled.'=>'DNS tools are off for this registrar account. Turn them on in Registrars, then try again.',
        'Management capability unsupported.'=>"This registrar doesn't support this change.",
    ];
    /** @return array{rows:array<string,list<Action>>,held:?string} Edits by Registration row, and why none can run now. */
    public static function for(Domain $d): array
    {
        if (!Staff::can('admin.domains.manage')) { return ['rows'=>[],'held'=>null]; }
        try { $page=app(OwnedDomains::class)->present($d); }
        catch (\Throwable $error) { Log::warning('Domains staff edit forms failed.',['domain_id'=>$d->id,'exception_class'=>$error::class]);
            return ['rows'=>[],'held'=>"Changes can't load right now. Reload this page. If it keeps happening, check the alerts on this page."]; }
        $m=$page->management; $rows=[];
        if (isset($m['contacts'])) { $rows['Contacts']=self::contacts($d,$m['contacts']); }
        if (isset($m['nameservers'])) { $rows['Nameservers']=[self::nameservers($d,$m['nameservers'])]; }
        if (isset($m['lock'])) { $rows['Transfer lock']=[self::lock($d,$m['lock']['state'])]; }
        foreach (['dns'=>'Edit','srv'=>'Edit SRV'] as $kind=>$label) {
            if (isset($m[$kind]['records'])) { $rows['DNS records'][]=self::records($d,$kind,$label,$m[$kind]); }
        }
        return ['rows'=>$rows,'held'=>$page->changesUnavailable?self::held($d):null];
    }
    private static function held(Domain $d): string
    {
        return match(true) {
            !Account::where('server_id',$d->server_id)->value('processing_enabled')=>'Changes are on hold: Orders are off for this registrar account. Turn Orders on in Registrars, then reload this page.',
            (bool)($d->renewal_policy['automatic_spend_paused']??false)=>'Changes are on hold: orders are paused for this domain. Check the alerts on this page.',
            default=>'Changes are on hold until the last request finishes. Check History, then reload this page.',
        };
    }
    private static function nameservers(Domain $d, array $m): Action
    {
        return self::edit($d,'nameservers','Change','Change nameservers?',[
            ...($m['defaults']?[Toggle::make('use_default')->label('Use the default nameservers')->live()]:[]),
            Textarea::make('nameservers')->label('Nameservers')->rows(4)->helperText('2 to '.$m['max'].', like ns1.example.com.')->hidden(fn(Get $get)=>(bool)$get('use_default'))],
            fn(array $data)=>['nameservers'=>(string)($data['nameservers']??''),'use_default'=>($data['use_default']??false)?'1':'0'],
            ManagementForms::NAMESERVERS_RISK,['nameservers'=>implode("\n",$m['current']??[])]);
    }
    /** An unknown state needs a choice; a known one has only one next step. */
    private static function lock(Domain $d, ?bool $state): Action
    {
        $form=[$state===null?Select::make('locked')->label('Transfer lock')->options(['1'=>'On','0'=>'Off'])->required():Hidden::make('locked'),Hidden::make('opened')];
        return self::edit($d,'lock',$state===null?'Change':($state?'Turn off':'Turn on'),$state===false?'Turn on transfer lock?':'Turn off transfer lock?',$form,
            fn(array $data)=>['locked'=>(string)($data['locked']??'')],$state===false?null:ManagementForms::lockRisk($d->domain),$state===null?[]:['locked'=>$state?'0':'1'],bound:['opened'=>json_encode($state)]);
    }
    /** @return list<Action> Staged registrars take new details first, then use them once approved. */
    private static function contacts(Domain $d, array $m): array
    {
        $form=array_map(fn(string $field)=>TextInput::make($field)->label(self::CONTACT[$field])->maxLength($m['limits'][$field]),array_keys($m['limits']));
        $staged=$m['staged']; $approved=$staged?$m['approved']:null; $keep=[];
        if ($approved) { $keep[]=self::edit($d,'contacts','Use the approved details','Use the approved details?',[Hidden::make('approved')],fn(array $data)=>(array)json_decode((string)($data['approved']??''),true),
            'Step 2 of 2: apply the approved details for '.trim(($approved['contact']['first_name']??'').' '.($approved['contact']['last_name']??'')).'.',bound:['approved'=>json_encode($approved)]); }
        return [...$keep,self::edit($d,$staged?'contact_profile':'contacts','Edit','Edit contacts',$form,
            fn(array $data)=>['role'=>'all','contact'=>array_map(fn($value)=>(string)($value??''),$data)],
            ($staged?'Step 1 of 2: we send the new details for approval. ':'').'We use these details for every contact on this domain. '.ManagementForms::CONTACTS_RISK,
            array_intersect_key($m['saved'],$m['limits']),$staged?'Send for approval':'Save')];
    }
    private static function records(Domain $d, string $kind, string $label, array $m): Action
    {
        $fields=$kind==='dns'?[TextInput::make('name')->label('Host'),Select::make('type')->label('Type')->options(self::DNS_TYPES),TextInput::make('address')->label('Value'),TextInput::make('mx_pref')->label('MX priority')]
            :[TextInput::make('service')->label('Service'),TextInput::make('protocol')->label('Protocol'),TextInput::make('priority')->label('Priority'),TextInput::make('weight')->label('Weight'),TextInput::make('port')->label('Port'),TextInput::make('target')->label('Target')];
        // A row's source is its place in the saved list, so the registrar's record ids stay server-side.
        $rows=array_map(fn(array $row,int $i)=>[...$row,'_source'=>(string)$i],$m['records'],array_keys($m['records']));
        return self::edit($d,$kind,$label,$m['label'],[Repeater::make('records')->hiddenLabel()->schema([...$fields,Hidden::make('_source')])->columns(count($fields))->defaultItems(0)->maxItems(50)->addActionLabel('Add record'),Hidden::make('preimage_digest')],
            fn(array $data)=>['records'=>array_map(fn(array $row)=>array_map(fn($value)=>(string)($value??''),$row),array_values($data['records']??[])),'preimage_digest'=>$data['preimage_digest']],
            // Queued, a stale list would only fail later at the registrar's read. Staff can reload now.
            ManagementForms::RECORDS_RISK.' Saving replaces the list.',['records'=>$rows],modal:'5xl',bound:['preimage_digest'=>$m['digest']]);
    }
    /** The form holds everything fixed at open, because this action rebuilds from current state at save. Bound fields must still match at save. A replayed save finds its request first, so a finished form returns it instead of a stale refusal. */
    private static function edit(Domain $d, string $name, string $label, string $heading, array $form, \Closure $input, ?string $risk, array $fill=[], string $submit='Save', string $modal='lg', array $bound=[]): Action
    {
        return Action::make('edit_'.$name)->label($label)->link()->modalHeading($heading)->modalDescription($risk)->modalWidth($modal)->modalSubmitActionLabel($submit)
            ->fillForm(fn()=>[...$fill,...$bound,'_nonce'=>bin2hex(random_bytes(32))])->schema([...$form,Hidden::make('_nonce')])->action(function(array $data,Action $action)use($d,$name,$input,$bound):void {
                $p=[];
                try {
                    // The key covers what was typed, so a retry of this exact save finds its request and an edited form is a new one.
                    $key=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR)); unset($data['_nonce']);
                    $actor=Auth::user(); if (!$actor instanceof \App\Models\User) { throw new \DomainException('Management permission required.'); }
                    if (!ManagementForms::accepted($actor,$d,$name,$key)) {
                        if (array_diff_assoc($bound,$data)) { throw new \DomainException('Current records required.'); }
                        $p=$input($data);
                        app(ManagementForms::class)->submit($actor,$d->fresh(),$name,[...$p,'confirmed'=>'1'],$key);
                    }
                    Notification::make()->success()->title(ManagementForms::REQUESTED)->send();
                } catch (\Throwable $error) {
                    Notification::make()->danger()->title("Couldn't save this change")->body(self::failure($d,$name,$p,$error))->send();
                    $action->halt();
                }
            });
    }
    private static function failure(Domain $d, string $name, #[\SensitiveParameter] array $p, \Throwable $error): string
    {
        if ($error instanceof ContactValidation) { $error=OwnerFormValidation::contact($error); }
        if ($error instanceof OwnerFormValidation) { return implode(' ',array_unique($error->messages($d->domain,$p,$d->registrar_key))); }
        if ($error instanceof \DomainException&&isset(self::FAILURES[$error->getMessage()])) { return self::FAILURES[$error->getMessage()]; }
        Log::warning('Domains staff edit failed.',['domain_id'=>$d->id,'action'=>$name,'exception_class'=>$error::class]);
        return 'Reload the page and try again. If it keeps happening, check History for this request.';
    }
}

<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Repeater,Select,TextInput,Textarea};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\{Get,Set};
use Illuminate\Support\Facades\{Auth,Log};
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsForm;
use Paymenter\Extensions\Servers\Domains\Services\{ContactDetails,ContactValidation,DomainManagement,ManagementForms,OwnedDomains,OwnerFormValidation,ManagementProgress};
/** Staff edits on the domain page: the owner's form path and every check it runs, credited to the staff member. */
final class DomainEditActions
{
    private const CONTACT=['first_name'=>'First name','last_name'=>'Last name','organization_name'=>'Organization','address1'=>'Address','address2'=>'Address line 2','city'=>'City',
        'state_province'=>'State or province','postal_code'=>'Postal code','country'=>'Country code','email_address'=>'Email','phone'=>'Phone'];
    private const DNS_TYPES=['A'=>'A (IPv4)','AAAA'=>'AAAA (IPv6)','CNAME'=>'CNAME (alias)','MX'=>'MX (mail)','MXE'=>'MXE (mail IP)','TXT'=>'TXT (text)','URL'=>'URL (redirect)','FRAME'=>'FRAME (masked redirect)'];
    /** The hold with no known cause. */
    public const HELD="Changes are on hold right now. Reload this page. If it keeps happening, check the alerts on this page.";
    /** Engine refusals => why, and what to do. Unknown refusals get the generic line. */
    private const FAILURES=[
        'Prior operation unresolved.'=>'Another change is still running. Check History, then try again.',
        'Management lifecycle held.'=>'This domain or its service is not active, or orders are paused. Fix that, then try again.',
        'Management permission required.'=>"You don't have permission to change domains.",
        'Current records required.'=>'This changed after you opened it. Reload the page and try again.',
        'Opened details changed.'=>'This changed after you opened it. Reload the page and try again.',
        'DNS records changed.'=>'The records changed at the registrar, so nothing was sent. Reload the page and try again.',
        'DNS tools disabled.'=>'DNS tools are off for this registrar account. Turn them on in Registrars, then try again.',
        'Management capability unsupported.'=>"This registrar doesn't support this change.",
    ];
    /** @return array{rows:array<string,list<Action>>,authCode:bool,held:?string,note:string|HtmlString|null,request:?int} Edits by Registration row, why none can run now as plain words and as the section note, and the request behind that when one is. */
    public static function for(Domain $d): array
    {
        if (!Staff::can('admin.domains.manage')) { return ['rows'=>[],'authCode'=>false,'held'=>null,'note'=>null,'request'=>null]; }
        try { $page=app(OwnedDomains::class)->present($d); }
        catch (\Throwable $error) { Log::warning('Domains staff edit forms failed.',['domain_id'=>$d->id,'exception_class'=>$error::class]);
            $held="Changes can't load right now. Reload this page. If it keeps happening, check the alerts on this page."; return ['rows'=>[],'authCode'=>false,'held'=>$held,'note'=>$held,'request'=>null]; }
        $m=$page->management; $rows=[];
        if (!empty($m['contacts']['actions'])) { $rows['Contacts']=self::contacts($d,$m['contacts']); }
        if (isset($m['nameservers']['actions']['nameservers'])) { $rows['Nameservers']=[self::nameservers($d,$m['nameservers'])]; }
        if (isset($m['lock']['actions']['lock'])) { $rows['Transfer lock']=[self::lock($d,$m['lock']['state'])]; }
        foreach (['dns'=>'Edit','srv'=>'Edit SRV'] as $kind=>$label) {
            if (isset($m[$kind]['records'],$m[$kind]['actions'][$kind])) { $rows['DNS records'][]=self::records($d,$kind,$label,$m[$kind]); }
        }
        if (isset($m['auth_code_email']) && $m['auth_code_email']['actions']) { $rows['Email auth code']=[self::edit($d,'auth_code_email','Email code','Email the auth code?',[],fn(array $data)=>[],"We'll email it to the ".$m['auth_code_email']['recipient'].'.',submit:'Email auth code')]; }
        $progress=ManagementProgress::for($d);
        foreach (['Contacts'=>'contacts','Nameservers'=>'nameservers','Transfer lock'=>'lock','DNS records'=>'dns','Email auth code'=>'auth_code_email'] as $label=>$setting) {
            if (($progress[$setting]['pending']??false)) { unset($rows[$label]); }
        }
        return ['rows'=>$rows,'authCode'=>app(OwnedDomains::class)->offersAuthCode($d),...($page->changesUnavailable?self::held($d,$page->changesRefused):['held'=>null,'note'=>null,'request'=>null])];
    }
    /** @return array{held:string,note:string|HtmlString,request:?int,first:?string} what is really in the way, from the engine's own refusal; a request is named only when one is.
     * first: the step that comes before any edit, for rows whose own words point at a blocked button. */
    public static function held(Domain $d, int|string|null $refused): array
    {
        $plain=static fn(string $held,?int $request=null,?string $first=null)=>['held'=>$held,'note'=>$held,'request'=>$request,'first'=>$first];
        return match(true) {
            \Paymenter\Extensions\Servers\Domains\Services\RenewalStop::policy($d)===null=>$plain(DomainFacts::UNREADABLE_RENEWAL),
            !Account::where('server_id',$d->server_id)->value('processing_enabled')=>$plain('Changes are on hold: Orders are off for this registrar account. Turn Orders on in Registrars, then reload this page.',first:'Orders are off. Turn them on in Registrars'),
            (bool)($d->renewal_policy['automatic_spend_paused']??false)=>$plain('Changes are on hold: orders are paused for this domain. Check the alerts on this page.'),
            $refused===DomainManagement::SWITCH_OFF=>self::switchOff($d),
            is_int($refused)=>$plain('On hold until request #'.$refused.' is checked.',$refused,'Wait until request #'.$refused.' is checked'),
            default=>$plain(self::FAILURES[$refused]??self::HELD),
        };
    }
    private static function switchOff(Domain $d): array
    {
        $off='Changes are on hold: DNS, nameservers and contacts are Off for this registrar account.';
        $url=RegistrarFacts::accountUrl((int)$d->server_id,'dns');
        $first='DNS, nameservers and contacts are Off. '.($url===null?'Ask staff who can change registrar accounts to turn them on':'Turn them on');
        if ($url===null) { $held=$off.' Ask staff who can change registrar accounts.'; return ['held'=>$held,'note'=>$held,'request'=>null,'first'=>$first]; }
        return ['held'=>$off.' Turn them on, then reload this page.','note'=>new HtmlString(e($off).' '.DomainFacts::requestLink($url,'Turn them on').', then reload this page.'),'request'=>null,'first'=>$first];
    }
    private static function nameservers(Domain $d, array $m): Action
    {
        return self::edit($d,'nameservers','Change','Change nameservers?',[
            Textarea::make('nameservers')->label('Nameservers')->rows(4)
                ->helperText('2 to '.$m['max'].', like ns1.example.com.'.($m['defaults']?'':' '.\Paymenter\Extensions\Servers\Domains\Services\ManagementNameservers::MISSING))
                ->hintActions($m['defaults']?[Action::make('fill_site_default_nameservers')->label('Use site default nameservers')
                    ->action(fn(Set $set)=>$set('nameservers',implode("\n",$m['defaultServers'])))]:[])],
            fn(array $data)=>['nameservers'=>(string)($data['nameservers']??'')],
            'Registrar: '.$m['registrar'].'. '.ManagementForms::NAMESERVERS_RISK,['nameservers'=>implode("\n",$m['current']??[])]);
    }
    /** An unknown state needs a choice; a known one has only one next step. */
    /** The lock button names its one step; with the state unread it is Change. A blocked button reads the same. */
    public static function lockLabel(?bool $state): string
    { return $state===null?'Change':($state?'Turn off':'Turn on'); }
    private static function lock(Domain $d, ?bool $state): Action
    {
        $form=[$state===null?Select::make('locked')->label('Transfer lock')->options(['1'=>'On','0'=>'Off'])->required():Hidden::make('locked'),Hidden::make('opened')];
        return self::edit($d,'lock',self::lockLabel($state),$state===false?'Turn on transfer lock?':'Turn off transfer lock?',$form,
            fn(array $data)=>['locked'=>(string)($data['locked']??'')],$state===false?null:ManagementForms::lockRisk($d->domain),$state===null?[]:['locked'=>$state?'0':'1'],bound:['opened'=>json_encode($state)]);
    }
    /** @return list<Action> One save freezes all contact steps for either registrar. */
    private static function contacts(Domain $d, array $m): array
    {
        if (!isset($m['actions']['contacts'])) { return []; }
        $form=array_map(fn(string $field)=>TextInput::make($field)->label(self::CONTACT[$field])->maxLength($m['limits'][$field]),array_keys($m['limits']));
        return [self::edit($d,'contacts','Edit','Edit contacts',[...$form,Hidden::make('opened_contacts_digest')],
            fn(array $data)=>['role'=>'all','contact'=>array_map(fn($value)=>(string)($value??''),array_intersect_key($data,$m['limits']))],
            'We use these details for every contact on this domain. '.ManagementForms::CONTACTS_RISK,
            self::contactFill($m['saved'],$m['limits']),'Save',
            bound:['opened_contacts_digest'=>$m['digest']])];
    }
    /** The saved details, with the phone as people write it, as on the customer form. Saving it stores the same value. */
    private static function contactFill(array $saved, array $limits): array
    {
        $fill=array_intersect_key($saved,$limits);
        if (is_string($fill['phone']??null)) { $fill['phone']=ContactDetails::readablePhone($fill['phone'],is_string($saved['country']??null)?$saved['country']:''); }
        return $fill;
    }
    private static function records(Domain $d, string $kind, string $label, array $m): Action
    {
        $fields=$kind==='dns'?[TextInput::make('name')->label('Host'),Select::make('type')->label('Type')->options(isset($m['types'])?array_intersect_key(self::DNS_TYPES,array_flip($m['types'])):self::DNS_TYPES),TextInput::make('address')->label('Value'),TextInput::make('mx_pref')->label('MX priority')]
            :[TextInput::make('service')->label('Service'),TextInput::make('protocol')->label('Protocol'),TextInput::make('priority')->label('Priority'),TextInput::make('weight')->label('Weight'),TextInput::make('port')->label('Port'),TextInput::make('target')->label('Target')];
        // A row's source is its place in the saved list, so the registrar's record ids stay server-side.
        $rows=array_map(fn(array $row,int $i)=>[...$row,'_source'=>(string)$i],$m['records'],array_keys($m['records']));
        return self::edit($d,$kind,$label,$m['label'],[Repeater::make('records')->hiddenLabel()->schema([...$fields,Hidden::make('_source')])->columns(count($fields))->defaultItems(0)->maxItems(50)->addActionLabel('Add record'),Hidden::make('preimage_digest')],
            fn(array $data)=>['records'=>array_map(fn(array $row)=>array_map(fn($value)=>(string)($value??''),$row),array_values($data['records']??[])),'preimage_digest'=>$data['preimage_digest']],
            // Queued, a stale list would only fail later at the registrar's read. Staff can reload now.
            implode(' ',[ManagementForms::RECORDS_RISK.' Saving replaces the list.',...DnsForm::notes($m['fixed']??[])]),['records'=>$rows],modal:'5xl',bound:['preimage_digest'=>$m['digest']]);
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
                        app(ManagementForms::class)->submit($actor,$d->fresh(),$name,[...$p,'confirmed'=>'1'],$key,contactsDigest:$name==='contacts'?$data['opened_contacts_digest']:null);
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

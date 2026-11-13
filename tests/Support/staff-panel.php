<?php
// Native Filament fixture adapted from Enom444f31c tests/enom-admin-panel.php.
// MIT, Copyright (c) 2026 Autonomic Engineering. Fixture registration only.
declare(strict_types=1);
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth, Route};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,OperationResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
function staffPanel(): void
{
    Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__,2).'/Domains/resources/views');
    if (!app()->bound(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)) { app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$GLOBALS['clock']??new Paymenter\Extensions\Servers\Domains\Data\SystemClock); }
    PHPUnit\TextUI\Configuration\Registry::init((new PHPUnit\TextUI\CliArguments\Builder)->fromParameters([]),
        PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create());
    $panel=Filament::getPanel('admin'); $panel->resources([DomainResource::class,OperationResource::class]);
    Route::name('filament.admin.')->prefix($panel->getPath())->middleware($panel->getMiddleware())->group(function() use($panel) {
        Route::middleware($panel->getAuthMiddleware())->group(function()use($panel){DomainResource::registerRoutes($panel);OperationResource::registerRoutes($panel);});
    });
    app('router')->getRoutes()->refreshNameLookups(); app('router')->getRoutes()->refreshActionLookups();
    Filament::setCurrentPanel($panel);
    Qirolab\Theme\Theme::set('default','default');
}
function staffUser(array $permissions): App\Models\User
{
    $role=App\Models\Role::create(['name'=>'Synthetic role '.App\Models\Role::count(),'permissions'=>$permissions]);
    return App\Models\User::create(['first_name'=>'Synthetic','last_name'=>'Staff','email'=>'staff-'.App\Models\User::count().'@example.test',
        'password'=>'synthetic-password','role_id'=>$role->id]);
}
/** The table standard: one pager of 25 (50 or 100), a search that says what it searches, one-line cells, money on the right. */
function staffTableStandard(mixed $page, ?string $search, array $oneLine=[], array $money=[]): void
{
    $table=$page->instance()->getTable(); $name=$page->instance()::class;
    same([25,50,100],array_values($table->getPaginationPageOptions()),$name.': paging 25, 50, 100');
    same(25,(int)$table->getDefaultPaginationPageOption(),$name.': 25 per page');
    if ($search!==null) { same($search,$table->getSearchPlaceholder(),$name.': search says what it searches'); }
    foreach ($oneLine as $column) { expect(($c=$table->getColumn($column))!==null&&!$c->canWrap(),$name.': '.$column.' stays on one line'); }
    foreach ($money as $column) { $a=$table->getColumn($column)?->getAlignment(); same('end',$a instanceof BackedEnum?$a->value:$a,$name.': '.$column.' is right aligned'); }
}
/** Staff rows on an owned table: no line under a value (a description sits beside it), at most 2 row actions, money never cut.
 * Over 4 columns, a Columns button where every column but the first can be hidden; $hidden start hidden. */
function staffRowsStandard(mixed $page, array $hidden=[], array $money=[]): void
{
    $instance=$page->instance(); $table=$instance->getTable(); $name=$instance::class; $columns=$table->getColumns();
    if (count($columns)>4) {
        $identity=array_key_first($columns);
        expect(!$columns[$identity]->isToggleable(),$name.': '.$identity.' names the row, so Columns keeps it');
        foreach (array_slice($columns,1,preserve_keys:true) as $key=>$column) { expect($column->isToggleable(),$name.': Columns can hide '.$key); }
        same($hidden,array_keys(array_filter($columns,fn($c)=>$c->isToggleable()&&$c->isToggledHiddenByDefault())),$name.': hidden at first');
        same('Columns',$table->getColumnManagerTriggerAction()->getLabel(),$name.': the button says Columns');
    } else { expect(!$table->hasToggleableColumns(),$name.': 4 columns or fewer need no Columns button'); }
    expect(count($table->getRecordActions())<=2,$name.': at most 2 row actions');
    $records=$instance->getTableRecords(); expect(count($records)>0,$name.': rows to check');
    foreach ($records as $record) {
        foreach ($columns as $key=>$column) {
            $column->record($record); $class=(string)($column->getExtraAttributes()['class']??'');
            // The domain's approved phone summary is separate from ordinary inline descriptions.
            $description=$column->getDescriptionBelow();
            $phone=$key==='domain'&&$description instanceof Illuminate\Support\HtmlString&&str_starts_with($description->toHtml(),'<span class="domains-phone-note">');
            if ((!$phone&&$description!==null)||$column->getDescriptionAbove()!==null) { expect(str_contains($class,'domains-line'),$name.': '.$key.' keeps its description on the same line'); }
        }
    }
    foreach ($money as $key) {
        $column=$table->getColumn($key); $class=(string)($column->getExtraAttributes()['class']??'');
        expect(str_contains($class,'domains-money')&&!str_contains($class,'domains-line')&&!str_contains($class,'domains-cut'),$name.': '.$key.' money is never cut');
    }
}
/** Turns the account Renewals switch on for these domains' servers, so a billed domain renews as the domain page says. */
function staffRenewalsOn(\Paymenter\Extensions\Servers\Domains\Models\Domain ...$domains): void
{
    foreach (array_unique(array_map(fn($d) => (int) $d->server_id, $domains)) as $server) {
        foreach ([\Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::SCHEDULED, \Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::MANUAL] as $key) {
            \App\Models\Server::findOrFail($server)->settings()->updateOrCreate(['key' => $key], ['value' => '1', 'type' => 'boolean', 'encrypted' => false]);
        }
    }
}
function staffData(bool $ordinaryCompleted = false): array
{
    \Paymenter\Extensions\Servers\Domains\Services\FoundationSchema::install();
    \Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
    \Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();
    require_once __DIR__.'/accounts.php';
    $a=fixtureAccount(1,'namesilo','PRIVATE-ACCOUNT-IDENTITY'); $b=fixtureAccount(2,'enom','PRIVATE-OTHER-IDENTITY');
    $domains=[];
    foreach (['review.example.com','active.example.com',str_repeat('long-domain-',5).'end.example.com'] as $i=>$name) {
        // Compound synthetic ending permits long ASCII labels within the real canonical validator.
        $domain=Domain::create([...($i===1?$b:$a),'domain'=>$name,'sld'=>substr($name,0,-12),'tld'=>'example.com',
            'user_id'=>$i+301,'service_id'=>$i+401,'status'=>$i===1?'active':'transfer_pending',
            'provider_expires_at'=>today()->addDays($i===1?120:15)->format('Y-m-d'),
            'snapshot'=>['private'=>'PRIVATE-SNAPSHOT'], 'observation'=>['facts'=>['owned'=>true,'auto_renew'=>false,'locked'=>true,'privacy'=>false],
                'drift'=>false,'followup'=>['state'=>'pending']], 'renewal_policy'=>['automatic_spend_paused'=>false]]);
        $observed=$domain->observation;
        $observed['facts']+=['name'=>$domain->domain,'generation'=>$domain->authority_generation,'account_fingerprint'=>$domain->account_fingerprint];
        $domain->update(['observation'=>$observed]);
        $op=Operation::create([...($i===1?$b:$a),'domain_id'=>$domain->id,'service_id'=>$domain->service_id,'authority_generation'=>1,
            'operation_key'=>'private-operation-'.$i,'action'=>'transfer','currency'=>'USD','amount'=>'18.00','state'=>$i===1?'succeeded':'held',
            'safe_code'=>$i===1?null:'transfer_needs_action','payload'=>['epp'=>'PRIVATE-EPP','contacts'=>['email'=>'PRIVATE-CONTACT']],
            'attempt'=>$ordinaryCompleted&&$i===1 ? [] : ['seal'=>'sealed','execution'=>null,'corrections'=>['contacts'=>true]],
            'policy'=>$ordinaryCompleted&&$i===1 ? [] : ['provider_message'=>'PRIVATE-PROVIDER-MESSAGE','contact_readmissions'=>[['reason'=>'PRIVATE-REASON']]],
            'cost'=>['estimated'=>'827.43','actual'=>['amount'=>'839.47','currency'=>'USD'],'currency'=>'USD'], 'funding'=>[]]);
        $domains[]=$domain;
    }
    return $domains;
}

/** A registrar account setup action: header actions by name, the rest inside the account's sections. */
function setupAction(string $action,int $server): string|\Filament\Actions\Testing\TestAction
{
    return in_array($action,['configure','enroll_sandbox'],true)?$action.'_'.$server
        :\Filament\Actions\Testing\TestAction::make($action.'_'.$server)->schemaComponent('account_'.$server,'content');
}

/** Read one rendered settings section without confusing it with another row's state. */
function resolverSectionText(string $html): string
{
    $dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    $sections=$xpath->query('//section[.//*[self::h2 or self::h3][normalize-space(.)="DNS resolver"]]');
    same(1,$sections->length,'The rendered page contains one DNS resolver section');
    return $sections->item(0)->textContent;
}
/** The word or fact a panel row shows after its label (a state word, a plain word or a fact), without the line under it.
 * Null when the page has no such row. */
function panelRowValue(string $html, string $label): ?string
{
    $dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);$xpath=new DOMXPath($dom);
    $rows=$xpath->query('//div[@data-domains-row][starts-with(normalize-space(div[contains(concat(" ",@class," ")," domains-panel-row-k ")]),"'.$label.'")]');
    if($rows->length!==1){return null;}
    $value=$xpath->query('.//div[contains(concat(" ",@class," ")," domains-panel-val ")]/*[1]',$rows->item(0))->item(0);
    return $value===null?null:trim((string)preg_replace('/\s+/u',' ',$value->textContent));
}
/** The text of the page's first notice row, or an empty string when it has none. */
function panelNoticeText(string $html): string
{
    $dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $notice=(new DOMXPath($dom))->query('//div[@data-domains-row="notice"]')->item(0);
    return $notice===null?'':trim((string)preg_replace('/\s+/u',' ',$notice->textContent));
}
/** A panel settings row found by its label: its state word and tone, or its plain word, read from the rendered page.
 * @return ?array{word:?string,tone:?string,text:string} */
function settingsRowValue(string $html, string $label): ?array
{
    $dom=new DOMDocument(); @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html); $x=new DOMXPath($dom);
    foreach ($x->query('//*[@data-piece="settings-row"]') as $row) {
        if (!str_contains($row->textContent,$label)) { continue; }
        $word=$x->query('.//*[@data-piece="value"]/*[1]',$row)->item(0); $tone=$x->query('.//*[@data-domains-tone]',$row)->item(0);
        return ['word'=>$word?trim($word->textContent):null,'tone'=>$tone?->getAttribute('data-domains-tone'),'text'=>trim(preg_replace('/\s+/',' ',$row->textContent))];
    }
    return null;
}
/** The text of every notice row on the page, in order, joined by spaces. */
function panelNoticesText(string $html): string
{
    $dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);$text=[];
    foreach((new DOMXPath($dom))->query('//div[@data-domains-row="notice"]') as $n){$text[]=trim((string)preg_replace('/\s+/u',' ',$n->textContent));}
    return implode(' ',$text);
}
/** The whole text of a panel row (its label, value, the line under it, a change or alert, and its actions), or null. */
function panelRowText(string $html, string $label): ?string
{
    $dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);$xpath=new DOMXPath($dom);
    $rows=$xpath->query('//div[@data-domains-row][starts-with(normalize-space(div[contains(concat(" ",@class," ")," domains-panel-row-k ")]),"'.$label.'")]');
    return $rows->length!==1?null:trim((string)preg_replace('/\s+/u',' ',$rows->item(0)->textContent));
}
/** The links in the page's notice rows. @return list<string> */
function panelNoticeLinks(string $html): array
{
    $dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);$links=[];
    foreach((new DOMXPath($dom))->query('//div[@data-domains-row="notice"]//a[@href]') as $a){$links[]=$a->getAttribute('href');}
    return $links;
}

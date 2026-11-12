<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. The client domain page: header, one notice, DNS / Security and contacts / History, Renewal card.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';
use Carbon\CarbonImmutable;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainPage, DomainSummary, OwnedDomain};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaryStrings, OwnerHelp};

config(['settings.company_name'=>'Example Hosting']);
storefrontViews();CarbonImmutable::setTestNow('2026-10-23 12:00:00');\Carbon\Carbon::setTestNow('2026-10-23 12:00:00');
app('view')->share('keys',array_fill_keys(['setup','recovery','code','renew','auto-renew',...OwnerActionController::ACTIONS],str_repeat('a',64)));
app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$name=placeholderComFixtureName('domain-page-placeholder.com');
$management=static function(array $more=[]):array {
    $m=[];
    foreach(['nameservers'=>'Nameservers','dns'=>'DNS records','glue'=>'Glue records','ds'=>'DNSSEC','lock'=>'Transfer lock','privacy'=>'WHOIS privacy','contacts'=>'Contacts','auth_code_email'=>'Auth code','auto_renew_off'=>'Renewal settings']as$cap=>$label){
        $m[$cap]=['label'=>$label,'url'=>'/domains/1/manage/'.$cap,'actions'=>[$cap=>'/domains/1/manage/'.$cap]];
    }
    $m['nameservers']+=['registrar'=>'Enom','max'=>12,'defaults'=>true,'defaultServers'=>['ns1.hosting.example','ns2.hosting.example'],'current'=>['ns1.host.example','ns2.host.example']];
    $m['dns']+=['records'=>[['name'=>'@','type'=>'A','address'=>'192.0.2.1'],['name'=>'www','type'=>'CNAME','address'=>'@']],'digest'=>str_repeat('b',64),'recordCheckHeld'=>false];
    $m['glue']+=['records'=>[],'recordCheckHeld'=>false];$m['glue']['actions']=['glue_add'=>'/domains/1/manage/glue_add'];
    $m['ds']+=['records'=>[],'recordCheckHeld'=>false];$m['ds']['actions']=['ds_add'=>'/domains/1/manage/ds_add','ds_delete'=>'/domains/1/manage/ds_delete'];
    $m['lock']['state']=true;
    $m['privacy']+=['free'=>false,'price'=>'$8.00','state'=>false];$m['privacy']['actions']=['privacy_enable'=>'/domains/1/manage/privacy_enable','privacy_disable'=>'/domains/1/manage/privacy_disable','privacy_purchase'=>'/domains/1/manage/privacy_purchase'];
    $m['contacts']+=['staged'=>false,'limits'=>\Paymenter\Extensions\Servers\Domains\Services\ContactDetails::limits('enom'),'saved'=>['first_name'=>'Jane','country'=>'US','phone'=>'+1.6502530000'],'role'=>'registrant','approved'=>null];
    $m['auth_code_email']['recipient']='owner';
    return array_replace_recursive($m,$more);
};
$domain=static fn(array $more=[])=>new OwnedDomain(...[...['name'=>$name,'status'=>'Active','tone'=>'ok','expiry'=>'2027-03-01','renewal'=>'On','renewalPrice'=>'$14.99',
    'actions'=>['show'=>'/domains/1'],'progress'=>[],'setup'=>['required'=>false,'contacts'=>false,'defaults'=>true],'transfer'=>[],'management'=>$management(),'testEnvironment'=>false,
    'renewNow'=>['url'=>'/domains/1/renew','terms'=>[1,2,3],'order_by'=>'2027-02-28'],'autoRenew'=>['url'=>'/domains/1/auto-renew','enabled'=>true],'owner'=>['name'=>'Jane Doe','email'=>'jane@example.com']],...$more]);
$summary=static fn(OwnedDomain $d,array $more=[])=>new DomainSummary($d->name,null,'managed',...['registrationKey'=>'domain.registration.platform','dnsKeys'=>[],'website'=>null,'email'=>null,'stateKeys'=>[],'labels'=>DomainSummaryStrings::all(),
    'expiry'=>$d->expiry,'expiryPrecision'=>'date','expirySource'=>null,'freshness'=>[],'renewalDueAt'=>null,'renewalPrice'=>$d->renewalPrice,'relatedServices'=>[],'hostnames'=>[],
    'urls'=>['detail'=>'/domains/1','support'=>'/tickets/create'],...$more]);
$render=static fn(OwnedDomain $d,?DomainSummary $s=null)=>view('domains::show',['domain'=>$d,'summary'=>$s])->render();
$norm=static fn(?DOMNode $n)=>$n?trim(preg_replace('/\s+/',' ',$n->textContent)):null;
$card=static fn(DOMXPath $dom,string $label)=>$norm($dom->query('//section[@id="renewal"]//dt[normalize-space(text())="'.$label.'"]/following-sibling::dd[1]')->item(0));
// A Renewal card value and its actions: the value stays on one line, the actions sit on their own line below it.
$split=static fn(DOMXPath $dom,string $label)=>[$norm($dom->query('//section[@id="renewal"]//dt[normalize-space(text())="'.$label.'"]/following-sibling::dd[1]/span[contains(@class,"domains-card__v")]')->item(0)),
    array_map($norm,iterator_to_array($dom->query('//section[@id="renewal"]//dt[normalize-space(text())="'.$label.'"]/following-sibling::dd[1]/span[contains(@class,"domains-card__a")]//*[self::a or self::button][contains(@class,"domains-a")]')))];

// Header: breadcrumb, mono name with tags, pill. No Expires meta, Domain help link, tabs or Overview.
$d=$domain();$html=$render($d,$summary($d,['daysLeft'=>129]));$dom=storefrontDom($html);
same('Domains '.$name,$norm($dom->query('//nav[contains(@class,"domains-crumb")]')->item(0)),'Breadcrumb names the domain');
same(1,$dom->query('//nav[contains(@class,"domains-crumb")]/a[@href="'.route('domains.domains.index').'"][.="Domains"]')->length,'Breadcrumb links to the list');
same(1,$dom->query('//h1[contains(@class,"domains-mono")][starts-with(normalize-space(.),"'.$name.'")]')->length,'Name is the mono h1');
same(1,$dom->query('//div[contains(@class,"domains-ph")]//span[contains(@class,"domains-pill")]')->length,'One pill in the header');
foreach(['Domain help</a>','class="domains-tabs','>Overview<','Contact support to change','Historical invoices','Next renewal','Scroll sideways','Renewal settings','registrar auto-renew','Save nameservers','Use default nameservers','Transfer code','transfer code','Domain privacy','—']as$gone){expect(!str_contains($html,$gone),'Removed: '.$gone);}
same(['Registrar: Enom.'],array_map($norm,iterator_to_array($dom->query('//dialog[@id="edit-nameservers"]/form/p[starts-with(normalize-space(.),"Registrar:")]'))),'The nameserver editor quietly names its saved registrar once');
$outsideEditors=storefrontDom($html);foreach(iterator_to_array($outsideEditors->query('//dialog'))as$dialog){$dialog->parentNode->removeChild($dialog);}
foreach(['Enom','NameSilo','enom','namesilo']as$registrar){expect(!str_contains($outsideEditors->document->textContent,$registrar),'No registrar name outside editors: '.$registrar);}
expect(!preg_match('/unavailable/i',strip_tags($html)),'No unavailable wording');

// As the approved mock: Renewal then Settings on the sheet, History in the rail.
same(['Renewal','Settings'],array_map($norm,iterator_to_array($dom->query('//div[contains(@class,"domains-cols")]/div[contains(@class,"domains-sheet")]/section/div/h2'))),'Sheet sections in order');
same(['History'],array_map($norm,iterator_to_array($dom->query('//div[contains(@class,"domains-cols")]/aside[contains(@class,"domains-rail")]/section/div/h2'))),'History in the rail');

// Rows: label, value, one action.
$text=static fn(string $id)=>$norm($dom->query('//li[@id="'.$id.'"]/span[contains(@class,"domains-row__v")]')->item(0));
$action=static fn(string $id)=>array_map($norm,iterator_to_array($dom->query('//li[@id="'.$id.'"]/*[contains(@class,"domains-row__a")]//*[self::a or self::button]')));
$row=static fn(DOMXPath $x,string $id)=>[$norm($x->query('//li[@id="'.$id.'"]/span[contains(@class,"domains-row__v")]')->item(0)),array_map($norm,iterator_to_array($x->query('//li[@id="'.$id.'"]/*[contains(@class,"domains-row__a")]//*[self::a or self::button]')))];
// slop-sweep: the Registrar records row gives the count; this row lists every name, one per line, untruncated.
same(['ns1.host.example ns2.host.example',['Edit']],[$text('nameservers'),$action('nameservers')],'Nameservers row: every name');
same(1,$dom->query('//li[@id="nameservers"]/span[contains(@class,"domains-row__v")]/span[contains(@class,"domains-ns-list")]')->length,'All names sit in one list');
expect(str_contains($html,'.domains .domains-ns-list { display: flex; flex-direction: column;')&&!str_contains($html,'line-clamp'),'One name per line, never cut off');
expect(str_contains($html,'.domains .domains-sheet .domains-card dt, .domains .domains-sheet .domains-row__l { font-size: inherit; font-weight: 400; }'),'Settings labels are the size of the Renewal labels');
same(['2 records',['Edit']],[$text('dns'),$action('dns')],'DNS records row');
same(['No glue records known here',['Manage']],[$text('glue'),$action('glue')],'Glue row does not claim an unread inventory is empty');
same('/domains/1?edit=glue#edit-glue',$dom->query('//li[@id="glue"]/span[contains(@class,"domains-row__a")]/a[@data-dialog-open]')->item(0)?->getAttribute('href'),'Glue Manage opens its editor');
foreach(['partial'=>'No glue records known here','complete'=>'0 records']as$state=>$value){
    $glue=$domain(['management'=>$management(['glue'=>['family'=>['state'=>$state,'value'=>ucfirst($state),'kind'=>'glue','count'=>0,'at'=>null,'last_good_at'=>null,'customer'=>'']]])]);
    same([$value,['Manage']],$row(storefrontDom($render($glue)),'glue'),'Glue row distinguishes '.$state.' inventory');
}
same(['Off',['Add DS record']],[$text('ds'),$action('ds')],'DNSSEC row');
same(['On',['Turn off']],[$text('lock'),$action('lock')],'Transfer lock row');
same([null,[]],[$text('privacy'),$action('privacy')],'Paid privacy is hidden while its DTO data stays intact');
same(false,$d->management['privacy']['state'],'Hiding privacy does not change stored state');
expect(!str_contains(strip_tags($html),'WHOIS privacy')&&!str_contains($html,'/manage/privacy_'),'No privacy text, help or action leaks onto the customer page');
same(['Jane Doe jane@example.com',['Edit']],[$text('contacts'),$action('contacts')],'Owner row: registrant name and email');
same('Contacts',$norm($dom->query('//li[@id="contacts"]/span[contains(@class,"domains-row__l")]/text()')->item(0)),'Contacts row label');
same('Contacts',$norm($dom->query('//dialog[@id="edit-contacts"]//h3')->item(0)),'Contacts dialog title');
same(0,$dom->query('//*[contains(@class,"domains-row__a")]//*[self::a or self::button][not(contains(@class,"domains-a"))]')->length,'Every row action uses the one link style');
expect(str_contains($html,'.domains .domains-a { color: var(--e-accent); font-weight: 600; text-decoration: underline;'),'Inline links are underlined');
expect(str_contains($html,'.domains .domains-row__a .domains-a, .domains .domains-card dd > .domains-a, .domains .domains-card__a .domains-a, .domains .domains-linkbtn { text-decoration: none; }'),'Standalone row actions keep their link style');
expect(str_contains($html,'.domains .domains-row__a .domains-a:hover, .domains .domains-card dd > .domains-a:hover, .domains .domains-card__a .domains-a:hover, .domains .domains-linkbtn:hover { text-decoration: none; }'),'Standalone actions have explicit no-underline hover exceptions');
$inlineHover=strpos($html,'.domains .domains-a:hover { text-decoration: underline; }');
$actionHover=strpos($html,'.domains .domains-row__a .domains-a:hover, .domains .domains-card dd > .domains-a:hover, .domains .domains-card__a .domains-a:hover, .domains .domains-linkbtn:hover { text-decoration: none; }');
expect($inlineHover!==false&&$actionHover!==false&&$actionHover>$inlineHover,'Action hover exceptions follow the inline rule so equal-specificity link buttons keep no underline');
same(['We email it to the owner',['Email code']],[$text('auth_code_email'),$action('auth_code_email')],'Auth code row');
same(0,$dom->query('//li[@id="auto_renew_off"]')->length,'Registrar auto-renew row is staff only');
foreach(['nameservers'=>'nameservers','dns'=>'dns-records','glue'=>'glue-records','ds'=>'dnssec','lock'=>'transfer-lock','auth_code_email'=>'transfer-code']as$id=>$anchor){
    same(1,$dom->query('//li[@id="'.$id.'"]//a[@href="'.route('domains.help').'#'.$anchor.'"]')->length,'Help popover links to help: '.$id);
}
// Editors open in a dialog; without script the link opens it on the server.
same(1,$dom->query('//li[@id="nameservers"]//a[@data-dialog-open][@href="/domains/1?edit=nameservers#edit-nameservers"]')->length,'Change opens the editor');
same(1,$dom->query('//li[@id="nameservers"]//dialog[@id="edit-nameservers"][not(@open)]//textarea[@name="nameservers"]')->length,'Editor dialog closed by default');
foreach(['Nameservers, one per line','2 to 12, like ns1.example.com.','Use site default nameservers','>Save<','Change nameservers?','If the new nameservers aren’t set up, your website and email stop working.']as$t){expect(str_contains($html,$t),'Nameservers dialog: '.$t);}
foreach(['Turn off transfer lock?','Anyone with the auth code can then move '.$name.' to another registrar.']as$t){expect(str_contains($html,$t),'Lock dialog: '.$t);}
same(1,$dom->query('//li[@id="lock"]//form[@data-confirm]//dialog[not(@open)]//button[@name="confirmed"][normalize-space(.)="Turn off"]')->length,'Lock off confirms in a dialog');
foreach(['Email the auth code?','We’ll email it to the owner.']as$t){expect(str_contains($html,$t),'Auth code dialog: '.$t);}
same(1,$dom->query('//li[@id="auth_code_email"]//dialog//button[@name="confirmed"][normalize-space(.)="Email auth code"]')->length,'Auth code dialog button');
same(0,$dom->query('//li[@id="contacts"]//select[@name="role"]')->length,'One contact form, no role to pick');
same(['Jane','US','+1 650-253-0000'],[$dom->query('//li[@id="contacts"]//input[@name="contact[first_name]"]')->item(0)->getAttribute('value'),$dom->query('//li[@id="contacts"]//select[@name="contact[country]"]/option[@selected]')->item(0)->getAttribute('value'),$dom->query('//li[@id="contacts"]//input[@name="contact[phone]"]')->item(0)->getAttribute('value')],'Dialog opens on the owner details, with the saved phone readable');
$forms=static fn(DOMXPath $x)=>array_map(static fn($f)=>[$f->getAttribute('action'),$norm($x->query('.//button[@type="submit"]',$f)->item(0))],iterator_to_array($x->query('//li[@id="contacts"]//form')));
$isolated=['staged'=>true,'actions'=>['contacts'=>'/domains/1/manage/contacts']];
$d=$domain(['management'=>$management(['contacts'=>$isolated])]);$dom=storefrontDom($render($d,$summary($d)));
same([['/domains/1/manage/contacts','Save']],$forms($dom),'Isolated-profile registrar uses one ordinary save form');
same('text',$dom->query('//li[@id="contacts"]//input[@name="contact[first_name]"]')->item(0)->getAttribute('type'),'Contact details remain editable');
same(0,$dom->query('//li[@id="contacts"]//a[contains(@href,"new=1")]')->length,'No manual profile start-over action');
// One term for the owner: no visible text on the page says registrant.
$visible=implode(' ',array_map(static fn($n)=>$n->nodeValue,iterator_to_array($dom->query('//body//text()[not(ancestor::style) and not(ancestor::script)]'))));
expect(stripos($visible,'registrant')===false,'Page text says owner, never registrant');
// Renewal card at desktop width: labels and Missing plus its fix stay on one line each; the cause sits below.
expect(str_contains($html,'.domains .domains-card > div { position: relative; display: flex; flex-wrap: wrap;'),'Renewal card rows put the value under the label when both do not fit, large text included');
expect(str_contains($html,'.domains .domains-card dd { flex: 1 1 7rem; min-width: 0;'),'Renewal card values keep room beside the label at phone width and can wrap');
expect(!preg_match('/\\.domains \\.domains-card (dt|dd)[^{]*\\{[^}]*nowrap|\\.domains-card__v \\{[^}]*nowrap/',$html),'Renewal card labels and values never force one line, so large text cannot run past the card');
expect(str_contains($html,'.domains .domains-card__a { display: flex; justify-content: flex-end;'),'Renewal card actions sit right-aligned on their own line');
expect(str_contains($html,'.domains .domains-missing__line { flex-wrap: nowrap; white-space: nowrap; }'),'Missing and its fix share one line at desktop width');
expect(str_contains($html,'Up to 50 records. Saving replaces the list.'),'DNS limit hint');
same(0,$dom->query('//input[@type="checkbox"]')->length,'No checkboxes on the page');
same(['Delete','Delete'],array_map(fn($b)=>$norm($b->firstChild),iterator_to_array($dom->query('//dialog[@id="edit-dns"]//tbody//button[@data-delete-record][@type="submit"][@value="1"]'))),'Each DNS record has a Delete action');
same('records[1][_delete]',$dom->query('//dialog[@id="edit-dns"]//tbody//button[@data-delete-record]')->item(1)?->getAttribute('name'),'Delete names its record');
$retry=$domain(['management'=>$management(['dns'=>['retry'=>['action'=>'dns','confirm'=>true,'values'=>['digest'=>str_repeat('b',64),'records'=>[['name'=>'@','type'=>'A','address'=>'192.0.2.1','_delete'=>'1'],['name'=>'www','type'=>'CNAME','address'=>'@']]]]]])]);$x=storefrontDom($render($retry));
same(['records[0][_delete]'],array_map(fn($i)=>$i->getAttribute('name'),iterator_to_array($x->query('//dialog[@id="edit-dns"]//tbody//input[@type="hidden"][@value="1"][contains(@name,"[_delete]")]'))),'A deletion waiting for confirmation is kept');
same(['records[1][_delete]'],array_map(fn($b)=>$b->getAttribute('name'),iterator_to_array($x->query('//dialog[@id="edit-dns"]//tbody//button[@data-delete-record]'))),'Only kept records offer Delete');
same('@ A 192.0.2.1',$norm($x->query('//dialog[@id="edit-dns"]//dialog[@open]//*[@data-confirm-slot="removed"]')->item(0)),'Confirm lists the record to delete');
request()->query->set('edit','dns');$open=storefrontDom($render($d));request()->query->remove('edit');
same(1,$open->query('//dialog[@id="edit-dns"][@open]')->length,'?edit opens the editor without script');

// Enter uses the first enabled submit control; only an explicit Delete removes a row.
foreach (['dns' => [
    ['name'=>'@','type'=>'A','address'=>'8.8.8.8','mx_pref'=>10],
    ['name'=>'www','type'=>'CNAME','address'=>'example.com.','mx_pref'=>10],
], 'srv' => [
    ['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>0,'port'=>443,'target'=>'sip.example.com.'],
    ['service'=>'xmpp','protocol'=>'tcp','priority'=>0,'weight'=>0,'port'=>5222,'target'=>'chat.example.com.'],
]] as $cap => $records) {
    $section=['label'=>strtoupper($cap).' records','records'=>$records,'digest'=>str_repeat('b',64),'actions'=>[$cap=>'/domains/1/manage/'.$cap]];
    $recordDom=storefrontDom(view('domains::partials.owner-dns',['section'=>$section,'cap'=>$cap,'domain'=>$domain()])->render());
    $buttons=$recordDom->query('//form//button[@type="submit"][not(@disabled)]');
    $default=$buttons->item(0);
    same('Save',trim($default?->textContent ?? ''),'Enter defaults to Save before any Delete: '.$cap);
    same('true',$default->getAttribute('aria-hidden'),'Default Save is hidden from assistive technology: '.$cap);
    same('-1',$default->getAttribute('tabindex'),'Default Save is outside the tab order: '.$cap);
    expect(in_array('domains-sr',explode(' ',$default->getAttribute('class')),true),'Default Save uses visually hidden style: '.$cap);
    $visible=$recordDom->query('//button[@type="submit"][normalize-space(.)="Save"][not(@aria-hidden)]');
    same(1,$visible->length,'Visible Save remains: '.$cap);
    foreach (['name','value'] as $attribute) { same($visible->item(0)->getAttribute($attribute),$default->getAttribute($attribute),'Save submitters have the same '.$attribute.': '.$cap); }
    // Serialize successful controls, including only the selected submitter, as an ordinary form POST does.
    $post=static function(DOMElement $submitter)use($recordDom):array {
        $pairs=[];
        foreach($recordDom->query('//form//input[not(@disabled)]|//form//select[not(@disabled)]|//form//button[@type="submit"]')as$control){
            if($control->tagName==='button'&&$control!==$submitter||!$control->hasAttribute('name')){continue;}
            $value=$control->tagName==='select'?$recordDom->query('option[@selected]',$control)->item(0)?->getAttribute('value'):$control->getAttribute('value');
            $pairs[]=rawurlencode($control->getAttribute('name')).'='.rawurlencode($value??'');
        }
        parse_str(implode('&',$pairs),$payload);return $payload;
    };
    $saved=$post($default);
    foreach($saved['records']as$record){expect(!isset($record['_delete']),'Implicit Save posts no deletion marker: '.$cap);}
    same(2,count(\Paymenter\Extensions\Servers\Domains\Services\OwnerRecordInput::payload($cap,$saved,'example.com')['records']),'Implicit Save retains both records without script: '.$cap);
    $deletes=$recordDom->query('//button[@data-delete-record]');same(2,$deletes->length,'Both explicit Delete controls remain: '.$cap);
    foreach($deletes as$i=>$delete){
        $deleted=$post($delete);
        same([strval($i)],array_map('strval',array_keys(array_filter($deleted['records'],fn($r)=>isset($r['_delete'])))),'Explicit Delete selects only its own row: '.$cap);
        $kept=\Paymenter\Extensions\Servers\Domains\Services\OwnerRecordInput::payload($cap,$deleted,'example.com')['records'];
        same([array_map('strval',$records[1-$i])],$kept,'Explicit Delete retains the other row: '.$cap);
    }
}

// Renewal card: active, auto-renew on.
// Renew now is the Expires row's action, at the end of the row as the mock places every row action, not loose under the card.
same(['Mar 1, 2027',['Renew now']],$split($dom,'Expires'),'Expires date, with Renew now as its row action');
same(0,$dom->query('//section[@id="renewal"]//div[contains(@class,"domains-panel__b")]/a[.="Renew now"]')->length,'Renew now is not loose under the card');
expect(str_starts_with($card($dom,'Renewal invoices'),'On Turn off'),'Renewal on');
same(['On',['Turn off']],$split($dom,'Renewal invoices'),'Renewal action sits on its own line');
same('$14.99/yr',$card($dom,'Renewal price'),'Renewal price');
same(1,$dom->query('//section[@id="renewal"]//a[@data-dialog-open][.="Renew now"]')->length,'Renew now button');
// Four months before expiry Renew now is a quiet link, not the page's main action.
same('domains-a',$dom->query('//section[@id="renewal"]//a[@data-dialog-open][.="Renew now"]')->item(0)?->getAttribute('class'),'Renew now stays a quiet row link until renewal is near');
// The last day to renew online is a fact about expiry, so it sits under the dialog's Expires date.
same(['Renew online by Feb 28, 2027.'],array_map($norm,iterator_to_array($dom->query('//dialog[@id="edit-renew"]//dt[.="Expires"]/following-sibling::dd[1]/span[@class="domains-sub"][time[@datetime="2027-02-28"]]'))),'The renewal deadline sits under the dialog Expires date');
foreach(['Renew '.$name,'>Years<','Create invoice','Turn off renewal invoices?','This stops scheduled renewal invoices. You can still use Renew now when your domain is eligible. An invoice already issued stays payable. Paying it can still renew '.$name.'.']as$t){expect(str_contains($html,$t),'Renewal card: '.$t);}
same(0,$dom->query('//div[contains(@class,"domains-notice")]')->length,'An active domain has no notice');
same('No changes yet.',$norm($dom->query('//section[@id="history"]//p')->item(0)),'Empty history');

// Expiring soon, invoice not issued yet.
$d=$domain(['expiry'=>'2026-11-02']);$dom=storefrontDom($render($d,$summary($d,['daysLeft'=>10,'renewalInvoiceMissing'=>true,'renewalIssueAt'=>'2026-10-03'])));
same('Nov 2, 2026 In 10 days Renew now',$card($dom,'Expires'),'Expires soon');
same('domains-btn domains-btn--primary domains-btn--sm',$dom->query('//dt[normalize-space(text())="Expires"]/following-sibling::dd[1]/span[contains(@class,"domains-card__a")]/a[.="Renew now"]')->item(0)?->getAttribute('class'),'Near expiry Renew now leads, still in its row');
same(1,$dom->query('//section[@id="renewal"]//dd//*[contains(@class,"domains-tone-text-warn")]')->length,'Warn colour within 30 days');
same('Missing Contact support Not issued yet. Expected Oct 3, 2026.',$card($dom,'Next invoice'),'Missing names the expected date');
// Expired with renewal On: the list shows Expired, and the page still says the invoice due before expiry was never issued.
$d=$domain(['status'=>'Expired','tone'=>'bad','expiry'=>'2026-10-01']);$dom=storefrontDom($render($d,$summary($d,['daysLeft'=>-21,'renewalInvoiceOverdue'=>true,'renewalIssueAt'=>'2026-09-01'])));
same('Missing Contact support Not issued yet. Expected Sep 1, 2026.',$card($dom,'Next invoice'),'An expired domain never says its invoice issues on a passed date');
// Planned, due, paid and cancelled invoices.
// Renewal On: Auto Pay has its own row and says what it does to the renewal invoice.
$on=$domain(['renewal'=>'On','actions'=>['show'=>'/domains/1','autoPay'=>'/services/1#auto-pay']]);
$autoPay=static fn(DOMXPath $dom)=>[...$split($dom,'Auto Pay'),$norm($dom->query('//section[@id="renewal"]//dt[normalize-space(text())="Auto Pay"]/following-sibling::dd[1]/span[contains(@class,"domains-sub")]')->item(0))];
same(['Off',['Manage Auto Pay'],'We send you an invoice and you pay it to renew.'],$autoPay(storefrontDom($render($on,$summary($on)))),'Auto Pay off explains the invoice');
$on=$domain(['renewal'=>'On','autoPay'=>true]);
same(['On',[],'We charge your saved payment method for each renewal invoice.'],$autoPay(storefrontDom($render($on,$summary($on)))),'Auto Pay on says the card is charged');
$d=$domain();same('Issues Feb 1, 2027. Due Feb 8, 2027.',$card(storefrontDom($render($d,$summary($d,['renewalDueAt'=>'2027-02-08','renewalIssueAt'=>'2027-02-01']))),'Next invoice'),'Planned invoice says when it is issued and when it is due');
$d=$domain(['renewalDue'=>'2027-02-01','renewNow'=>['invoice'=>'/invoices/9']]);$dom=storefrontDom($render($d,$summary($d)));
same('Due Feb 1, 2027 Pay invoice',$card($dom,'Next invoice'),'Due invoice');
same(['Due Feb 1, 2027',['Pay invoice']],$split($dom,'Next invoice'),'Pay invoice sits on its own line');
same(0,$dom->query('//a[.="Renew now"]')->length,'No Renew now while an invoice is due');
// After a failed charge (Auto Pay or a card), the row says it failed and what to do, not only that the invoice is ready.
$d=$domain(['renewalDue'=>'2027-02-01','renewNow'=>['invoice'=>'/invoices/9','declined'=>true]]);
same('Due Feb 1, 2027 The last payment failed. Pay the invoice with another card. Pay invoice',$card(storefrontDom($render($d,$summary($d))),'Next invoice'),'A failed charge says so with its next step');
same('Paid Current date. The new one shows once the renewal completes.',$card(storefrontDom($render($domain(['renewNow'=>['status'=>'paid']]))),'Next invoice').' '.preg_replace('/^.*2027 /','',$card(storefrontDom($render($domain(['renewNow'=>['status'=>'paid']]))),'Expires')),'Paid invoice');
same('Missing Contact support Invoice cancelled',$card(storefrontDom($render($d=$domain(['renewNow'=>['status'=>'cancelled']]),$summary($d))),'Next invoice'),'Cancelled invoice');
$dom=storefrontDom($render($d=$domain(['renewal'=>'Off','renewNow'=>['status'=>'review']]),$summary($d)));
same('Under review Renewal being checked. No action needed.',$card($dom,'Next invoice'),'Invoice under review shows even with auto-renew off');
// The review is ours to clear: blue Under review, never a red Missing with nothing for the customer to do.
same(1,$dom->query('//dt[starts-with(normalize-space(.),"Next invoice")]/following-sibling::dd//span[contains(@class,"domains-tone-info") and .="Under review"]')->length,'Under review is blue');
same(0,$dom->query('//dt[starts-with(normalize-space(.),"Next invoice")]/following-sibling::dd//*[contains(@class,"domains-missing")]')->length,'No Missing while we check the renewal');
// An expired domain asks the customer to contact support, so a renewal under review there never says no action is needed.
$d=$domain(['status'=>'Expired','tone'=>'bad','expiry'=>'2026-10-01','statusReason'=>\Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::LATE,'renewNow'=>['status'=>'review']]);
same('Under review Renewal being checked.',$card(storefrontDom($render($d,$summary($d,['daysLeft'=>-22]))),'Next invoice'),'Expired: Under review without the reassurance');

// Auto-renew off by the owner, and stopped by staff.
$d=$domain(['renewal'=>'Off','renewalOff'=>'owner','autoRenew'=>['url'=>'/domains/1/auto-renew','enabled'=>false]]);$html=$render($d,$summary($d));$dom=storefrontDom($html);
same(['Mar 1, 2027 Cancels Mar 1, 2027 Renew now','Off Turn on'],[$card($dom,'Expires'),$card($dom,'Renewal invoices')],'Owner off');
same(['Off',['Turn on']],$split($dom,'Renewal invoices'),'Turn on sits on its own line');
same(null,$card($dom,'Next invoice'),'No next invoice while off');
expect(!str_contains($html,'Auto-renew is off.'),'No auto-renew notice');
$d=$domain(['renewal'=>'Off','renewalOff'=>'staff','autoRenew'=>[],'renewNow'=>[]]);$dom=storefrontDom($render($d,$summary($d)));
same('Off Contact support',$card($dom,'Renewal invoices'),'Staff stop');
same(['Off',['Contact support']],$split($dom,'Renewal invoices'),'Contact support sits on its own line');

// Missing expiry; pending transfer; setup; cutoff; import; demo sync paused; test mode.
// Renew now needs a registrar expiry, so a domain without one has no Renew now.
$d=$domain(['expiry'=>null,'renewNow'=>[]]);$dom=storefrontDom($render($d,$summary($d)));
same('Missing Fills in by itself after the next sync',$card($dom,'Expires'),'Missing expiry: one fix, then its cause');
same('Missing Fills in by itself after the next sync',$card($dom,'Next invoice'),'Missing next invoice: one fix, then its cause');
// Credited: one state, nothing to fix. No Missing and no Contact support, here or in the notice.
$d=$domain(['status'=>'Cancelled','expiry'=>null,'renewal'=>'','renewNow'=>[],'autoRenew'=>[],'order'=>['state'=>'cancelled','kind'=>'registration','credit'=>true]]);$html=$render($d,$summary($d));$dom=storefrontDom($html);
expect(str_contains($html,'We added what you paid to account credit'),'Credited order: the notice stays');
// r16: no registration means no date; the page leaves out Renewal rather than say Expires None.
same([0,0],[$dom->query('//section[@id="renewal"]')->length,$dom->query('//dt[normalize-space(.)="Expires"]')->length],'Credited order: no Renewal card and no Expires None');
same(0,$dom->query('//div[contains(@class,"domains-notice")]//a')->length,'Credited order: the notice asks for nothing');
$d=$domain(['status'=>'Cancelled','expiry'=>null,'renewal'=>'','renewNow'=>[],'autoRenew'=>[],'order'=>['state'=>'cancelled','kind'=>'registration']]);
same('Missing Contact support Order cancelled',$card(storefrontDom($render($d,$summary($d))),'Expires'),'A closed order with no credit still needs us');
$d=$domain(['expiry'=>null,'renewNow'=>[],'order'=>['state'=>'failed','kind'=>'transfer']]);$dom=storefrontDom($render($d,$summary($d)));
same('Missing Contact support Transfer did not finish',$card($dom,'Expires'),'A failed order: the fix, then the cause');
same(1,$dom->query('//section[@id="renewal"]//dd/span[contains(@class,"domains-missing__line")][span[contains(@class,"domains-missing")]/following-sibling::*[1][self::a[contains(@class,"domains-missing__fix")]]]/following-sibling::*[1][contains(@class,"domains-sub")]')->length,'Fix sits on the Missing line, cause below');
$d=$domain(['owner'=>null]);$dom=storefrontDom($render($d,$summary($d)));
same(['Missing Add them with Edit',['Edit']],$row($dom,'contacts'),'Contacts not on file say what to do next');
$d=$domain(['management'=>$management(['lock'=>['state'=>null]])]);$dom=storefrontDom($render($d,$summary($d)));
same(['Missing Fills in by itself within a day',['Change']],$row($dom,'lock'),'Unknown lock names its cause');
$pending=$domain(['status'=>'Pending transfer','tone'=>'info','renewal'=>'','expiry'=>null,'management'=>[],'renewNow'=>[],'autoRenew'=>[],'actions'=>['show'=>'/domains/1','code'=>'/domains/1/transfer-code'],
    'transfer'=>['open'=>true,'received'=>false,'phase'=>'editing']]);
$dom=storefrontDom($render($pending,$summary($pending,['expiryWaits'=>'transfer'])));
same('Enter the auth code from your current registrar. Enter auth code',$norm($dom->query('//div[contains(@class,"domains-notice")]')->item(0)),'Pending transfer notice');
same('Shows when the transfer finishes',$card($dom,'Expires'),'Pending transfer expiry says when it shows, not Missing');
same([null,null],[$card($dom,'Renewal invoices'),$card($dom,'Next invoice')],'No renewal timing for an inactive domain');
$saved=new OwnedDomain(...[...get_object_vars($pending),'transfer'=>['open'=>true,'received'=>true,'phase'=>'editing']]);
$dom=storefrontDom($render($saved));same('Saved Change auth code',$card($dom,'Auth code'),'Saved auth code');same(['Saved',['Change auth code']],$split($dom,'Auth code'),'Change auth code sits on its own line');same(0,$dom->query('//div[contains(@class,"domains-notice")]')->length,'No notice once saved');
$setup=static fn(array $s,array $t=[])=>$domain(['status'=>'Action required','tone'=>'warn','renewal'=>'','management'=>[],'actions'=>['show'=>'/domains/1','setup'=>'/domains/1/setup'],'setup'=>$s,'transfer'=>$t]);
same('Choose nameservers before registration starts, or we use the registrar’s default nameservers. Choose nameservers',$norm(storefrontDom($render($setup(['defaults'=>true,'contacts'=>false])))->query('//div[contains(@class,"domains-notice")]')->item(0)),'Nameservers setup notice');
same('Finish setup to start registration. Finish setup',$norm(storefrontDom($render($setup(['defaults'=>false,'contacts'=>true])))->query('//div[contains(@class,"domains-notice")]')->item(0)),'Setup notice');
same('The auth code deadline passed. Update your details so we can finish the transfer. Update details',$norm(storefrontDom($render($setup(['defaults'=>false,'contacts'=>true],['phase'=>'cutoff','open'=>false,'received'=>false])))->query('//div[contains(@class,"domains-notice")]')->item(0)),'Cutoff notice');
$import=$domain(['status'=>'Under review','tone'=>'info','renewal'=>'','management'=>[],'renewNow'=>[],'autoRenew'=>[],'setupPending'=>true]);
same('We’re confirming this domain. Changes and renewals start after that. If it expires soon, let us know. Contact support',$norm(storefrontDom($render($import))->query('//div[contains(@class,"domains-notice")]')->item(0)),'Import notice');
$moved=$domain();$dom=storefrontDom($render($moved,$summary($moved,['dnsPreviouslyMatched'=>true,'stateKeys'=>['domain.active','watch.ns_changed'],'noticeDates'=>['dns'=>1792540800]])));
same($name.' stopped using Example Hosting nameservers on Oct 21, 2026. Change nameservers',$norm($dom->query('//div[contains(@class,"domains-notice")]')->item(0)),'Nameserver change notice');
same('/domains/1?edit=nameservers#edit-nameservers',$dom->query('//div[contains(@class,"domains-notice")]/a')->item(0)?->getAttribute('href'),'Nameserver change opens the editor');
$moved=$domain(['management'=>[]]);$dom=storefrontDom($render($moved,$summary($moved,['dnsPreviouslyMatched'=>true,'stateKeys'=>['watch.ns_changed']])));
same($name.' stopped using Example Hosting nameservers. Contact support',$norm($dom->query('//div[contains(@class,"domains-notice")]')->item(0)),'Nameserver change without a date or an editor');
$demo=$domain(['management'=>[],'demo'=>true,'syncPaused'=>true]);$html=$render($demo,$summary($demo,['demo'=>true]));$dom=storefrontDom($html);
same(1,$dom->query('//div[contains(@class,"domains-notice")]')->length,'One notice');
same('Sync paused: the demo registrar is off. Nameservers show when it resumes. Contact support',$norm($dom->query('//div[contains(@class,"domains-notice")]')->item(0)),'Demo sync paused notice');
same('Missing Fills in by itself when sync resumes',$norm($dom->query('//li[@id="nameservers"]/span[contains(@class,"domains-row__v")]')->item(0)),'Paused nameservers are Missing and say when they fill in');
$paused=$domain(['demo'=>true,'syncPaused'=>true,'management'=>$management(['dns'=>['records'=>null],'ds'=>['records'=>null],'lock'=>['state'=>null],'privacy'=>['state'=>null]])]);$html=$render($paused,$summary($paused,['demo'=>true]));$dom=storefrontDom($html);
foreach(['nameservers','dns','glue','ds','lock']as$id){same(['Missing Fills in by itself when sync resumes',[]],$row($dom,$id),'Paused '.$id.' is Missing with no action');}
same(0,substr_count(strip_tags($html),'Fills in by itself within a day'),'Paused rows wait for the sync to resume');
same(1,$dom->query('//h1//span[contains(@class,"domains-demo-tag")][not(contains(@class,"domains-demo-tag--test"))]')->length,'Demo tag');
$test=$domain(['testEnvironment'=>true]);$dom=storefrontDom($render($test));
same(1,$dom->query('//h1//span[contains(@class,"domains-demo-tag--test")]')->length,'Test tag');
same(0,$dom->query('//div[contains(@class,"domains-notice")]')->length,'Test mode adds no banner');
same(false,DomainPage::notice($domain())!==null,'No notice for an active domain');
same('Changes are on hold: DNS, nameservers and contacts are Off. Contact us to turn them on.',DomainPage::notice($domain(['changesUnavailable'=>true,'changesRefused'=>Paymenter\Extensions\Servers\Domains\Services\DomainManagement::SWITCH_OFF]),'/tickets/create')['text']??null,'Customers read the same reason and their next step');
same('Changes are on hold until we finish checking an earlier request. There’s nothing you need to do.',DomainPage::notice($domain(['changesUnavailable'=>true,'changesRefused'=>7]),'/tickets/create')['text']??null,'A request in the way says who acts');
// The notice already says there is nothing to do, so the renewal row under review does not say it again (demo R18b).
$dom=storefrontDom($render($d=$domain(['changesUnavailable'=>true,'changesRefused'=>7,'renewNow'=>['status'=>'review']]),$summary($d)));
same('Under review Renewal being checked.',$card($dom,'Next invoice'),'Renewal under review leaves the no-action line to the notice');
$text=$dom->document->textContent;same(1,substr_count($text,'nothing you need to do')+substr_count($text,'No action needed'),'No-action reassurance once per page');
same('Changes to this domain are on hold right now. Try again later.',DomainPage::notice($domain(['changesUnavailable'=>true]),'/tickets/create')['text']??null,'No known cause stays general');
$expired=DomainPage::notice($domain(['statusReason'=>\Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::LATE]),'/tickets/create');
same(['This domain has expired. Contact support to check whether it can still be renewed.','Contact support','/tickets/create'],[$expired['text'],$expired['action']['label'],$expired['action']['url']],'An expired domain says what to do next, with the way to do it');

// History: changes with dates and renewal invoices.
$d=$domain(['progress'=>[['label'=>'Nameservers','state'=>'Completed','date'=>'2026-10-20'],['label'=>'WHOIS privacy','state'=>'Completed','date'=>'2026-10-21','by'=>'Ada']],'history'=>[['number'=>'INV-7','status'=>'Paid','url'=>'/invoices/7','date'=>'2026-09-30']]]);$dom=storefrontDom($render($d));
same([['Nameservers','Oct 20, 2026, 12:00 AM UTC','Completed'],['Domain change','By Ada','Oct 21, 2026, 12:00 AM UTC','Completed'],['Invoice #INV-7','Sep 30, 2026','Paid']],array_map(static fn(DOMNode $li)=>[...array_map($norm,iterator_to_array($dom->query('./div/*',$li))),$norm($dom->query('./span[last()]',$li)->item(0))],iterator_to_array($dom->query('//section[@id="history"]//ol[@class="domains-trace"]/li'))),'History steps: what, when, how it ended; privacy keeps its actor and date under a neutral label');
same(['ok','ok','ok'],array_map(fn($n)=>substr($n->getAttribute('class'),strlen('domains-status domains-tone-')),iterator_to_array($dom->query('//section[@id="history"]//li/span[@aria-hidden="true"]'))),'Finished steps carry the green mark');
same(1,$dom->query('//section[@id="history"]//a[@href="/invoices/7"][.="Invoice #INV-7"]')->length,'Invoice step links');
// The mark span is empty; it shows only when the stylesheet draws its hexagon (content), as in the approved mock.
expect(preg_match('/\.domains-trace li > \.domains-status::before \{[^}]*content: ""/',file_get_contents(dirname(__DIR__).'/Domains/resources/views/partials/styles.blade.php'))===1,'History marks are drawn');
same(['ok','bad','warn','warn','muted','info','info'],array_map([DomainPage::class,'stepTone'],['Completed','Failed','Unpaid','Action required','Cancelled','Under review','On hold']),'Only a step the customer owns is amber; our work is blue');

// Every help popover the page uses has an anchor.
foreach(['nameservers','dns','glue','ds','lock','privacy','auth_code_email','contacts','auto-renew']as$id){expect(isset(OwnerHelp::SECTIONS[$id]),'Help section: '.$id);}
expect(str_contains($html, '.domains .domains-rows > .domains-row { position: relative;'), 'Help popovers anchor to the full managed row');
expect(str_contains($html, '.domains .domains-card > div { position: relative;'), 'Card help anchors to the full card entry');

expect(str_contains($html, '.domains .domains-ph { position: relative;'), 'Status help anchors to the full page header');
expect(preg_match('/\\.domains \\.domains-help-pop \\{[^}]*white-space: normal;/s',$html)===1, 'Card help resets inherited label nowrap');
$d=$domain(['dnsCorrection'=>true]);$html=$render($d,$summary($d,['dnsKeys'=>['domain.dns.platform','domain.points.platform_empty'],'website'=>false,'email'=>false]));$dom=storefrontDom($html);
same('The registered nameservers do not match the registration settings. Set up DNS with your provider, then change nameservers. Change nameservers',$norm($dom->query('//div[contains(@class,"domains-notice")]')->item(0)),'Refused registration DNS gives a quiet next step');
expect(str_contains($html,'To use this domain, buy a service or point it to your own nameservers.'),'Domain without hosting explains the next step');

$d=$domain(['dnsCorrection'=>true,'management'=>[]]);$dom=storefrontDom($render($d,$summary($d)));
$dnsNextSteps=['correction_support'=>$dom->query('//div[contains(@class,"domains-notice")]//a[@href="/tickets/create"][.="Contact support"]')->length];
$d=$domain();$html=$render($d,$summary($d,['dnsKeys'=>['domain.dns.external','domain.points.external_empty'],'website'=>false,'email'=>false]));
$dnsNextSteps['configured_dns_hint']=str_contains($html,'To use this domain, buy a service or point it to your own nameservers.');
same(['correction_support'=>1,'configured_dns_hint'=>false],$dnsNextSteps,'DNS next steps: '.json_encode($dnsNextSteps));
foreach([[true,null,'then change nameservers.','Change nameservers'],[false,'/support','then contact support to change nameservers.','Contact support'],[false,null,'Set up DNS with your provider. Nameserver changes are currently unavailable.',null]]as[$editor,$support,$step,$label]){
    $notice=DomainPage::notice($domain(['dnsCorrection'=>true,'management'=>$editor?$management():[]]),$support);
    expect(str_contains($notice['text'],$step),'Correction describes an available next step: '.json_encode([$editor,$support,$notice]));
    same($label,$notice['action']['label']??null,'Correction action follows actual availability');
}
$nativeSuccessful=true;echo "Client domain page checked.\n";

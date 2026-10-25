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
    $m['nameservers']+=['max'=>12,'defaults'=>true,'defaultServers'=>['ns1.hosting.example','ns2.hosting.example'],'current'=>['ns1.host.example','ns2.host.example']];
    $m['dns']+=['records'=>[['name'=>'@','type'=>'A','address'=>'192.0.2.1'],['name'=>'www','type'=>'CNAME','address'=>'@']],'digest'=>str_repeat('b',64),'recordCheckHeld'=>false];
    $m['glue']+=['records'=>[],'recordCheckHeld'=>false];$m['glue']['actions']=['glue_add'=>'/domains/1/manage/glue_add'];
    $m['ds']+=['records'=>[],'recordCheckHeld'=>false];$m['ds']['actions']=['ds_add'=>'/domains/1/manage/ds_add','ds_delete'=>'/domains/1/manage/ds_delete'];
    $m['lock']['state']=true;
    $m['privacy']+=['free'=>false,'price'=>'$8.00','state'=>false];$m['privacy']['actions']=['privacy_enable'=>'/domains/1/manage/privacy_enable','privacy_disable'=>'/domains/1/manage/privacy_disable','privacy_purchase'=>'/domains/1/manage/privacy_purchase'];
    $m['contacts']+=['staged'=>false,'limits'=>\Paymenter\Extensions\Servers\Domains\Services\ContactDetails::limits('enom')];
    $m['auth_code_email']['recipient']='owner';
    return array_replace_recursive($m,$more);
};
$domain=static fn(array $more=[])=>new OwnedDomain(...[...['name'=>$name,'status'=>'Active','tone'=>'ok','expiry'=>'2027-03-01','renewal'=>'On','renewalPrice'=>'$14.99',
    'actions'=>['show'=>'/domains/1'],'progress'=>[],'setup'=>['required'=>false,'contacts'=>false,'defaults'=>true],'transfer'=>[],'management'=>$management(),'testEnvironment'=>false,
    'renewNow'=>['url'=>'/domains/1/renew','terms'=>[1,2,3],'order_by'=>'2027-02-28'],'autoRenew'=>['url'=>'/domains/1/auto-renew','enabled'=>true],'owner'=>['name'=>'Jane Doe','email'=>'jane@example.com']],...$more]);
$summary=static fn(OwnedDomain $d,array $more=[])=>new DomainSummary($d->name,null,'managed','domain.registration.platform',[],null,null,...['stateKeys'=>[],'labels'=>DomainSummaryStrings::all(),
    'expiry'=>$d->expiry,'expiryPrecision'=>'date','expirySource'=>null,'freshness'=>[],'renewalDueAt'=>null,'renewalPrice'=>$d->renewalPrice,'relatedServices'=>[],'hostnames'=>[],
    'urls'=>['detail'=>'/domains/1','support'=>'/tickets/create'],...$more]);
$render=static fn(OwnedDomain $d,?DomainSummary $s=null)=>view('domains::show',['domain'=>$d,'summary'=>$s])->render();
$norm=static fn(?DOMNode $n)=>$n?trim(preg_replace('/\s+/',' ',$n->textContent)):null;
$card=static fn(DOMXPath $dom,string $label)=>$norm($dom->query('//section[@id="renewal"]//dt[normalize-space(text())="'.$label.'"]/following-sibling::dd[1]')->item(0));

// Header: breadcrumb, mono name with tags, pill. No Expires meta, Domain help link, tabs or Overview.
$d=$domain();$html=$render($d,$summary($d,['daysLeft'=>129]));$dom=storefrontDom($html);
same('Domains / '.$name,$norm($dom->query('//nav[contains(@class,"domains-crumb")]')->item(0)),'Breadcrumb names the domain');
same(1,$dom->query('//nav[contains(@class,"domains-crumb")]/a[@href="'.route('domains.domains.index').'"][.="Domains"]')->length,'Breadcrumb links to the list');
same(1,$dom->query('//h1[contains(@class,"domains-mono")][starts-with(normalize-space(.),"'.$name.'")]')->length,'Name is the mono h1');
same(1,$dom->query('//div[contains(@class,"domains-ph")]//span[contains(@class,"domains-pill")]')->length,'One pill in the header');
foreach(['Domain help</a>','domains-tabs','>Overview<','Contact support to change','Historical invoices','Next renewal','Scroll sideways','Renewal settings','registrar auto-renew','Save nameservers','Use default nameservers','Transfer code','transfer code','Domain privacy','—']as$gone){expect(!str_contains($html,$gone),'Removed: '.$gone);}
foreach(['Enom','NameSilo','enom','namesilo']as$registrar){expect(!str_contains(strip_tags($html),$registrar),'No registrar name: '.$registrar);}
expect(!preg_match('/unavailable/i',strip_tags($html)),'No unavailable wording');

// Sections in order, Renewal in the side column.
same(['DNS','Security and contacts','History'],array_map($norm,iterator_to_array($dom->query('//div[contains(@class,"domains-cols")]/div[1]/section/div/h2'))),'Main column sections in order');
same(['Renewal'],array_map($norm,iterator_to_array($dom->query('//div[contains(@class,"domains-cols")]/aside/section/div/h2'))),'Renewal card on the side');

// Rows: label, value, one action.
$text=static fn(string $id)=>$norm($dom->query('//li[@id="'.$id.'"]/span[contains(@class,"domains-row__v")]')->item(0));
$action=static fn(string $id)=>array_map($norm,iterator_to_array($dom->query('//li[@id="'.$id.'"]/*[contains(@class,"domains-row__a")]//*[self::a or self::button]')));
$row=static fn(DOMXPath $x,string $id)=>[$norm($x->query('//li[@id="'.$id.'"]/span[contains(@class,"domains-row__v")]')->item(0)),array_map($norm,iterator_to_array($x->query('//li[@id="'.$id.'"]/*[contains(@class,"domains-row__a")]//*[self::a or self::button]')))];
same(['ns1.host.example ns2.host.example',['Change']],[$text('nameservers'),$action('nameservers')],'Nameservers row');
same(['2 records',['Edit']],[$text('dns'),$action('dns')],'DNS records row');
same(['No glue records',['Add']],[$text('glue'),$action('glue')],'Glue row');
same(['Off',['Add DS record']],[$text('ds'),$action('ds')],'DNSSEC row');
same(['On',['Turn off']],[$text('lock'),$action('lock')],'Transfer lock row');
same(['Off',['Add · $8.00/yr']],[$text('privacy'),$action('privacy')],'WHOIS privacy row, paid');
same(['Jane Doe jane@example.com',['Edit contacts']],[$text('contacts'),$action('contacts')],'Owner row: registrant name and email');
same('Owner',$norm($dom->query('//li[@id="contacts"]/span[contains(@class,"domains-row__l")]/text()')->item(0)),'Owner row label');
same(0,$dom->query('//*[contains(@class,"domains-row__a")]//*[self::a or self::button][not(contains(@class,"domains-a"))]')->length,'Every row action uses the one link style');
expect(str_contains($html,'.domains .domains-a { color: var(--e-accent); font-weight: 600; text-decoration: none;'),'Row actions are not underlined');
same(['Emailed to the owner',['Email auth code']],[$text('auth_code_email'),$action('auth_code_email')],'Auth code row');
same(0,$dom->query('//li[@id="auto_renew_off"]')->length,'Registrar auto-renew row is staff only');
foreach(['nameservers'=>'nameservers','dns'=>'dns-records','glue'=>'glue-records','ds'=>'dnssec','lock'=>'transfer-lock','privacy'=>'privacy','auth_code_email'=>'transfer-code']as$id=>$anchor){
    same(1,$dom->query('//li[@id="'.$id.'"]//a[@href="'.route('domains.help').'#'.$anchor.'"]')->length,'Help popover links to help: '.$id);
}
// Editors open in a dialog; without script the link opens it on the server.
same(1,$dom->query('//li[@id="nameservers"]//a[@data-dialog-open][@href="/domains/1?edit=nameservers#edit-nameservers"]')->length,'Change opens the editor');
same(1,$dom->query('//li[@id="nameservers"]//dialog[@id="edit-nameservers"][not(@open)]//textarea[@name="nameservers"]')->length,'Editor dialog closed by default');
foreach(['Nameservers, one per line','2 to 12, like ns1.example.com.','Use the default nameservers','>Save<','Change nameservers?','If the new nameservers aren’t set up, your website and email stop working.']as$t){expect(str_contains($html,$t),'Nameservers dialog: '.$t);}
foreach(['Turn off transfer lock?','Anyone with the auth code can then move '.$name.' to another registrar.']as$t){expect(str_contains($html,$t),'Lock dialog: '.$t);}
same(1,$dom->query('//li[@id="lock"]//form[@data-confirm]//dialog[not(@open)]//button[@name="confirmed"][normalize-space(.)="Turn off"]')->length,'Lock off confirms in a dialog');
foreach(['Email the auth code?','We’ll email it to the owner.']as$t){expect(str_contains($html,$t),'Auth code dialog: '.$t);}
same(1,$dom->query('//li[@id="auth_code_email"]//dialog//button[@name="confirmed"][normalize-space(.)="Email auth code"]')->length,'Auth code dialog button');
same(['All contacts','Owner','Admin','Tech','Billing'],array_map($norm,iterator_to_array($dom->query('//li[@id="contacts"]//select[@name="role"]/option'))),'Contact roles');
// One term for the owner: no visible text on the page says registrant.
$visible=implode(' ',array_map(static fn($n)=>$n->nodeValue,iterator_to_array($dom->query('//body//text()[not(ancestor::style) and not(ancestor::script)]'))));
expect(stripos($visible,'registrant')===false,'Page text says owner, never registrant');
// Renewal card at desktop width: labels and Missing plus its fix stay on one line each; the cause sits below.
expect(str_contains($html,'.domains .domains-card dt { flex: none; white-space: nowrap; }'),'Renewal card labels do not wrap at desktop width');
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
same('Mar 1, 2027',$card($dom,'Expires'),'Expires date');
same('On Turn off',$card($dom,'Auto-renew'),'Auto-renew on');
same('$14.99/yr',$card($dom,'Renewal price'),'Renewal price');
same(1,$dom->query('//section[@id="renewal"]//a[@data-dialog-open][.="Renew now"]')->length,'Renew now button');
foreach(['Renew '.$name,'>Years<','You can renew online until <time datetime="2027-02-28">Feb 28, 2027</time>.','Create invoice','Turn off auto-renew?',$name.' expires on Mar 1, 2027 unless you renew it.']as$t){expect(str_contains($html,$t),'Renewal card: '.$t);}
same(0,$dom->query('//div[contains(@class,"domains-notice")]')->length,'An active domain has no notice');
same('No changes yet.',$norm($dom->query('//section[@id="history"]//p')->item(0)),'Empty history');

// Expiring soon, invoice not issued yet.
$d=$domain(['expiry'=>'2026-11-02']);$dom=storefrontDom($render($d,$summary($d,['daysLeft'=>10,'renewalInvoiceMissing'=>true,'renewalIssueAt'=>'2026-10-03'])));
same('Nov 2, 2026 In 10 days',$card($dom,'Expires'),'Expires soon');
same(1,$dom->query('//section[@id="renewal"]//dd//*[contains(@class,"domains-tone-text-warn")]')->length,'Warn colour within 30 days');
same('Missing Not issued yet. Expected Oct 3, 2026.',$card($dom,'Next invoice'),'Missing names the expected date');
// Planned, due, paid and cancelled invoices.
$d=$domain();same('Feb 1, 2027',$card(storefrontDom($render($d,$summary($d,['renewalDueAt'=>'2027-02-01']))),'Next invoice'),'Planned invoice date');
$d=$domain(['renewalDue'=>'2027-02-01','renewNow'=>['invoice'=>'/invoices/9']]);$dom=storefrontDom($render($d,$summary($d)));
same('Due Feb 1, 2027 Pay invoice',$card($dom,'Next invoice'),'Due invoice');
same(0,$dom->query('//a[.="Renew now"]')->length,'No Renew now while an invoice is due');
same('Paid Updates when the renewal completes',$card(storefrontDom($render($domain(['renewNow'=>['status'=>'paid']]))),'Next invoice').' '.preg_replace('/^.*2027 /','',$card(storefrontDom($render($domain(['renewNow'=>['status'=>'paid']]))),'Expires')),'Paid invoice');
same('Missing Contact support Invoice cancelled',$card(storefrontDom($render($d=$domain(['renewNow'=>['status'=>'cancelled']]),$summary($d))),'Next invoice'),'Cancelled invoice');
same('Missing Contact support Renewal being checked',$card(storefrontDom($render($d=$domain(['renewal'=>'Off','renewNow'=>['status'=>'review']]),$summary($d))),'Next invoice'),'Invoice under review shows even with auto-renew off');

// Auto-renew off by the owner, and stopped by staff.
$d=$domain(['renewal'=>'Off','renewalOff'=>'owner','autoRenew'=>['url'=>'/domains/1/auto-renew','enabled'=>false]]);$html=$render($d,$summary($d));$dom=storefrontDom($html);
same(['Mar 1, 2027 Cancels Mar 1, 2027','Off Turn on'],[$card($dom,'Expires'),$card($dom,'Auto-renew')],'Owner off');
same(null,$card($dom,'Next invoice'),'No next invoice while off');
expect(!str_contains($html,'Auto-renew is off.'),'No auto-renew notice');
$d=$domain(['renewal'=>'Off','renewalOff'=>'staff','autoRenew'=>[],'renewNow'=>[]]);$dom=storefrontDom($render($d,$summary($d)));
same('Off Contact support',$card($dom,'Auto-renew'),'Staff stop');

// Missing expiry; pending transfer; setup; cutoff; import; demo sync paused; test mode.
$d=$domain(['expiry'=>null]);$dom=storefrontDom($render($d,$summary($d)));
same('Missing Contact support Shows after the next sync',$card($dom,'Expires'),'Missing expiry: one fix, then its cause');
same('Missing Contact support Not issued yet',$card($dom,'Next invoice'),'Missing next invoice: one fix, then its cause');
same(2,$dom->query('//section[@id="renewal"]//dd/span[contains(@class,"domains-missing__line")][span[contains(@class,"domains-missing")]/following-sibling::*[1][self::a[contains(@class,"domains-missing__fix")]]]/following-sibling::*[1][contains(@class,"domains-sub")]')->length,'Fix sits on the Missing line, cause below');
$d=$domain(['owner'=>null]);$dom=storefrontDom($render($d,$summary($d)));
same(['Missing No contact details on file',['Edit contacts']],$row($dom,'contacts'),'Owner without stored contact');
$d=$domain(['management'=>$management(['lock'=>['state'=>null]])]);$dom=storefrontDom($render($d,$summary($d)));
same(['Missing Shows after the next sync',['Change']],$row($dom,'lock'),'Unknown lock names its cause');
$pending=$domain(['status'=>'Pending transfer','tone'=>'info','renewal'=>'','expiry'=>null,'management'=>[],'renewNow'=>[],'autoRenew'=>[],'actions'=>['show'=>'/domains/1','code'=>'/domains/1/transfer-code'],
    'transfer'=>['open'=>true,'received'=>false,'phase'=>'editing']]);
$dom=storefrontDom($render($pending,$summary($pending,['expiryWaits'=>'transfer'])));
same('Enter the auth code from your current registrar. Enter auth code',$norm($dom->query('//div[contains(@class,"domains-notice")]')->item(0)),'Pending transfer notice');
same('Missing Shows when the transfer completes',$card($dom,'Expires'),'Pending expiry waits');
same([null,null],[$card($dom,'Auto-renew'),$card($dom,'Next invoice')],'No renewal timing for an inactive domain');
$saved=new OwnedDomain(...[...get_object_vars($pending),'transfer'=>['open'=>true,'received'=>true,'phase'=>'editing']]);
$dom=storefrontDom($render($saved));same('Saved View transfer',$card($dom,'Auth code'),'Saved auth code');same(0,$dom->query('//div[contains(@class,"domains-notice")]')->length,'No notice once saved');
$setup=static fn(array $s,array $t=[])=>$domain(['status'=>'Action required','tone'=>'warn','renewal'=>'','management'=>[],'actions'=>['show'=>'/domains/1','setup'=>'/domains/1/setup'],'setup'=>$s,'transfer'=>$t]);
same('Choose nameservers before registration starts, or we use Example Hosting nameservers. Choose nameservers',$norm(storefrontDom($render($setup(['defaults'=>true,'contacts'=>false])))->query('//div[contains(@class,"domains-notice")]')->item(0)),'Nameservers setup notice');
same('Finish setup to start registration. Finish setup',$norm(storefrontDom($render($setup(['defaults'=>false,'contacts'=>true])))->query('//div[contains(@class,"domains-notice")]')->item(0)),'Setup notice');
same('The auth code deadline passed. Update your details so we can finish the transfer. Update details',$norm(storefrontDom($render($setup(['defaults'=>false,'contacts'=>true],['phase'=>'cutoff','open'=>false,'received'=>false])))->query('//div[contains(@class,"domains-notice")]')->item(0)),'Cutoff notice');
$import=$domain(['status'=>'Under review','tone'=>'info','renewal'=>'','management'=>[],'renewNow'=>[],'autoRenew'=>[],'setupPending'=>true]);
same('We’re confirming this domain. Changes and renewals start after that. Contact support if it expires soon.',$norm(storefrontDom($render($import))->query('//div[contains(@class,"domains-notice")]')->item(0)),'Import notice');
$moved=$domain();$dom=storefrontDom($render($moved,$summary($moved,['stateKeys'=>['domain.active','watch.ns_changed'],'noticeDates'=>['dns'=>1792540800]])));
same($name.' stopped using Example Hosting nameservers on Oct 21, 2026. Change nameservers',$norm($dom->query('//div[contains(@class,"domains-notice")]')->item(0)),'Nameserver change notice');
same('/domains/1?edit=nameservers#edit-nameservers',$dom->query('//div[contains(@class,"domains-notice")]/a')->item(0)?->getAttribute('href'),'Nameserver change opens the editor');
$moved=$domain(['management'=>[]]);$dom=storefrontDom($render($moved,$summary($moved,['stateKeys'=>['watch.ns_changed']])));
same($name.' stopped using Example Hosting nameservers. Contact support',$norm($dom->query('//div[contains(@class,"domains-notice")]')->item(0)),'Nameserver change without a date or an editor');
$demo=$domain(['management'=>[],'demo'=>true,'syncPaused'=>true]);$html=$render($demo,$summary($demo,['demo'=>true]));$dom=storefrontDom($html);
same(1,$dom->query('//div[contains(@class,"domains-notice")]')->length,'One notice');
same('Sync paused: the demo registrar is off. Nameservers show when it resumes. Contact support',$norm($dom->query('//div[contains(@class,"domains-notice")]')->item(0)),'Demo sync paused notice');
same('Missing',$norm($dom->query('//li[@id="nameservers"]/span[contains(@class,"domains-row__v")]')->item(0)),'Paused nameservers are Missing, cause in the notice');
$paused=$domain(['demo'=>true,'syncPaused'=>true,'management'=>$management(['dns'=>['records'=>null],'ds'=>['records'=>null],'lock'=>['state'=>null],'privacy'=>['state'=>null]])]);$html=$render($paused,$summary($paused,['demo'=>true]));$dom=storefrontDom($html);
foreach(['nameservers','dns','glue','ds','lock','privacy']as$id){same(['Missing',[]],$row($dom,$id),'Paused '.$id.' is Missing with no action');}
same(0,substr_count(strip_tags($html),'Shows after the next sync'),'Paused rows leave the cause to the notice');
same(1,$dom->query('//h1//span[contains(@class,"domains-demo-tag")][not(contains(@class,"domains-demo-tag--test"))]')->length,'Demo tag');
$test=$domain(['testEnvironment'=>true]);$dom=storefrontDom($render($test));
same(1,$dom->query('//h1//span[contains(@class,"domains-demo-tag--test")]')->length,'Test tag');
same(0,$dom->query('//div[contains(@class,"domains-notice")]')->length,'Test mode adds no banner');
same(false,DomainPage::notice($domain())!==null,'No notice for an active domain');

// History: changes with dates and renewal invoices.
$d=$domain(['progress'=>[['label'=>'Nameservers','state'=>'Done','date'=>'2026-10-20']],'history'=>[['number'=>'INV-7','status'=>'Paid','url'=>'/invoices/7','date'=>'2026-09-30']]]);$dom=storefrontDom($render($d));
same(['Change','Status','Date'],array_map($norm,iterator_to_array($dom->query('//section[@id="history"]//th'))),'History columns');
same(['Nameservers Done Oct 20, 2026','Renewal invoice INV-7 Paid Sep 30, 2026'],array_map($norm,iterator_to_array($dom->query('//section[@id="history"]//tbody/tr'))),'History rows');
same(1,$dom->query('//section[@id="history"]//a[@href="/invoices/7"][.="Renewal invoice INV-7"]')->length,'Invoice row links');

// Every help popover the page uses has an anchor.
foreach(['nameservers','dns','glue','ds','lock','privacy','auth_code_email','contacts','auto-renew']as$id){expect(isset(OwnerHelp::SECTIONS[$id]),'Help section: '.$id);}
$nativeSuccessful=true;echo "Client domain page checked.\n";

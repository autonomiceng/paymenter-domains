<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real Blade with synthetic customer DTOs, no provider I/O.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
require dirname(__DIR__).'/Domains/routes/web.php';
require dirname(__DIR__).'/Domains/routes/owned.php';

use Illuminate\Pagination\LengthAwarePaginator;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainSummary, OwnedDomain};

storefrontViews();
$out = $argv[1];
expect(is_dir($out), 'Output directory required');
$domain = new OwnedDomain('example.com', 'Active', 'ok', '2027-10-01', 'On', '$14.00',
    ['show' => '/domains/1'], [], ['contacts' => false, 'required' => false], [], [], false);
$summary = new DomainSummary('example.com', null, 'watch', 'domain.registration.external', [], null, null,
    [], [], '2027-10-01', 'date', null, [], null, null, [], [], []);
$cases = [
    'partials.client-script' => [],
    'index' => ['domains' => new LengthAwarePaginator([], 0, 25)],
    'show' => ['domain' => $domain, 'keys' => []],
    'setup' => ['domain' => $domain],
    'search' => storefrontData(),
    'transfer' => storefrontData(),
    'pricing' => ['rows' => storefrontData()['endings'], 'guest' => true, 'testEnvironment' => false],
    'watch-notices' => ['summary' => $summary, 'name' => 'example.com', 'kind' => 'watch', 'id' => 1,
        'editable' => false, 'notices' => [], 'portal' => [],
        'preferences' => ['account' => ['enabled' => true, 'revision' => 1], 'target' => ['enabled' => true, 'revision' => 1]]],
];
$recordManagement=[];
foreach (['dns'=>[
    ['name'=>'@','type'=>'A','address'=>'192.0.2.1','mx_pref'=>10],
    ['name'=>'www','type'=>'A','address'=>'192.0.2.2','mx_pref'=>10],
], 'srv'=>[
    ['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>0,'port'=>443,'target'=>'sip.example.com.'],
    ['service'=>'xmpp','protocol'=>'tcp','priority'=>0,'weight'=>0,'port'=>5222,'target'=>'chat.example.com.'],
]] as $cap=>$records) {
    $recordManagement[$cap]=['label'=>strtoupper($cap).' records','records'=>$records,'digest'=>str_repeat('b',64),
        'recordCheckHeld'=>false,'actions'=>[$cap=>'/domains/1/manage/'.$cap],...($cap==='dns'?['ttls'=>[300,3600]]:[])];
}
$recordManagement['nameservers']=['label'=>'Nameservers','max'=>12,'letterEnding'=>false,'current'=>['ns1.example.com','ns2.example.com'],
    'registrar'=>'Enom','url'=>'/domains/1/manage/nameservers','actions'=>['nameservers'=>'/domains/1/manage/nameservers']];
$pendingDomain=new OwnedDomain(...[...get_object_vars($domain),'management'=>['lock'=>['label'=>'Transfer lock','progress'=>['id'=>42,'pending'=>true,'slow'=>false,'text'=>'Turning off…']]]]);
writeRenderArtifact($out.'/show-management-pending.html',view('domains::show',['domain'=>$pendingDomain,'keys'=>[]])->render());
$orderDomain=new OwnedDomain(...[...get_object_vars($domain),'status'=>'Pending registration']);
writeRenderArtifact($out.'/show-order-pending.html',view('domains::show',['domain'=>$orderDomain,'keys'=>[]])->render());
$recordDomain=new OwnedDomain(...[...get_object_vars($domain),'management'=>$recordManagement]);
writeRenderArtifact($out.'/show-records.html',view('domains::show',['domain'=>$recordDomain,
    'keys'=>['dns'=>str_repeat('a',64),'srv'=>str_repeat('b',64),'nameservers'=>str_repeat('c',64)]])->render());
$ns=storefrontDom(view('domains::show',['domain'=>$recordDomain,'keys'=>['dns'=>str_repeat('a',64),'srv'=>str_repeat('b',64),'nameservers'=>str_repeat('c',64)]])->render());
$form=$ns->query('//form[@data-nameservers-max]')->item(0);$field=$ns->query('//textarea[@name="nameservers"]')->item(0);
expect($form!==null&&$field!==null,'The client fixture has the real nameserver editor');
writeRenderArtifact($out.'/nameserver-form.json',json_encode(['max'=>$form->getAttribute('data-nameservers-max'),
    'letterEnding'=>$form->getAttribute('data-nameservers-letter-ending'),'id'=>$field->getAttribute('id')],JSON_THROW_ON_ERROR));
foreach ($cases as $view => $data) {
    writeRenderArtifact($out.'/'.$view.'.html', view('domains::'.$view, $data)->render());
}
foreach ([
    'company' => ['Example Hosting (Test)', 'Fallback Cloud'],
    'app' => ['', 'Example Cloud (Local)'],
    'neutral' => ['', ''],
    'quoted' => ['Example\'s "Cloud" <script> & Co', ''],
] as $case => [$company, $appName]) {
    config(['settings.company_name' => $company, 'app.name' => $appName]);
    writeRenderArtifact($out.'/label-'.$case.'.html', view('domains::partials.client-script')->render());
}

// Render real contact fields so the client check covers both initial suggestions and country changes.
$contacts = [];
foreach (['US', 'CA', 'GB', 'AU', ''] as $country) {
    foreach ([false, true] as $stateRequired) {
        $dom = storefrontDom(view('domains::partials.contact', ['prefix'=>'contact',
            'values'=>['country'=>$country, 'state_province'=>'Retained state'], 'stateRequired'=>$stateRequired])->render());
        $state = $dom->query('//input[@name="contact[state_province]"]')->item(0);
        $list = $dom->query('//datalist')->item(0);
        $contacts[] = ['country'=>$country, 'stateRequired'=>$stateRequired, 'state'=>$state->getAttribute('value'),
            'required'=>$state->hasAttribute('required'), 'codes'=>$list->getAttribute('data-state-codes'),
            'options'=>array_map(fn($option)=>$option->getAttribute('value'), iterator_to_array($dom->query('//datalist/option')))];
    }
}
writeRenderArtifact($out.'/contact-suggestions.json', json_encode($contacts, JSON_THROW_ON_ERROR));

$nativeSuccessful = true;
echo "Rendered shared client script, all seven including views, company-name cases and contact suggestions.\n";

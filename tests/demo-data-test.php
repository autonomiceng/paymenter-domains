<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Seeded demo records carry one Demo tag; real records never do.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
require __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/facts-probe.php';
require __DIR__.'/Support/accounts.php';
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Paymenter\Extensions\Servers\Domains\Admin\Support\DemoTag;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, ManagedPublicConfig, Operation, WatchName, WatchRegistryObservation};
use Paymenter\Extensions\Servers\Domains\Services\FoundationSchema;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
storefrontViews();

$domain=static fn(array $snapshot)=>tap(new Domain,fn($d)=>$d->snapshot=$snapshot);
$operation=static fn(array $policy,string $basis)=>tap(new Operation,function($o)use($policy,$basis){$o->policy=$policy;$o->funding_basis=$basis;});
$account=static fn(string $key,string $identity)=>tap(new Account,function($a)use($key,$identity){$a->registrar_key=$key;$a->stable_identity=$identity;});
$watch=static fn(?array $facts)=>tap(new WatchName,fn($w)=>$w->setRelation('registry',$facts===null?null:new WatchRegistryObservation(['facts'=>$facts])));
// Each seed marker is a demo record.
foreach ([
    'domain snapshot'=>$domain(['offline_demo'=>true,'checkout'=>['invoice_id'=>9]]),
    'request policy'=>$operation(['offline_demo'=>true],'invoice'),
    'request funding basis'=>$operation([],'offline_demo'),
    'watch reason'=>new ManagedPublicConfig(['reason'=>'offline_demo']),
    'demo Enom account'=>$account('enom','beco-demo-enom'),
    'demo NameSilo account'=>$account('namesilo','beco-demo-namesilo'),
    'hosting watch registry facts'=>$watch(['result'=>'facts','offline_demo'=>true]),
] as $marker=>$model) { expect(DemoData::is($model),'Demo marker: '.$marker); }
// Real records are never tagged.
foreach ([
    'domain'=>$domain(['checkout'=>['invoice_id'=>9]]), 'domain without snapshot'=>new Domain,
    'domain with a false marker'=>$domain(['offline_demo'=>false]),
    'request'=>$operation(['dispatch_attempted_at'=>'2026-10-01T00:00:00+00:00'],'invoice'),
    'watch'=>new ManagedPublicConfig(['reason'=>'Customer asked for checks']),
    'Enom account'=>$account('enom','reseller-account'), 'NameSilo account'=>$account('namesilo','Beco-Demo-NameSilo'),
    'hosting watch'=>$watch(['result'=>'facts']), 'unchecked hosting watch'=>$watch(null),
    'no record'=>null, 'other object'=>new stdClass,
] as $real=>$model) { expect(!DemoData::is($model),'Real record: '.$real); }

// Client tag: one word, one line, tooltip and accessible name from the glossary.
$tag=storefrontDom(view('domains::partials.demo-tag')->render());
$node=$tag->query('//span[@class="domains-demo-tag"]')->item(0);
same(DemoData::TOOLTIP,$node?->getAttribute('title'),'Tooltip text');
same('Demo data. Seeded for testing. Nothing is sent to a registrar and no money moves.',DemoData::TOOLTIP,'Glossary tooltip');
same('Demo '.DemoData::TOOLTIP,preg_replace('/\s+/',' ',trim($node?->textContent??'')),'Accessible name says Demo and why');
same('Demo',trim($tag->query('//span[@class="domains-demo-tag"]/text()')->item(0)?->textContent??''),'Visible text is Demo');
same(view('domains::partials.demo-tag')->render(),DemoData::tag()->toHtml(),'DemoData::tag renders the partial');
$css=file_get_contents(__DIR__.'/../Domains/resources/views/partials/styles.blade.php');
expect((bool)preg_match('/\.domains-demo-tag\s*\{[^}]*display:\s*inline-flex[^}]*white-space:\s*nowrap/',$css),'Demo tag stays on one line and wraps as a unit');
expect((bool)preg_match('/\.domains-missing\s*\{[^}]*color:\s*var\(--e-bad\)/',$css),'Missing uses the danger colour');

// Staff: entries and columns add the tag after the name only for demo records.
staffPanel();
$demo=$account('enom','beco-demo-enom');$real=$account('enom','reseller-account');
$facts=renderFacts([DemoTag::apply(TextEntry::make('demo')->label('Registrar')->state('OFFLINE DEMO Enom (synthetic)')->model($demo)),
    DemoTag::apply(TextEntry::make('real')->label('Registrar')->state('Enom reseller')->model($real))]);
same(1,substr_count($facts['html'],'domains-demo-tag'),'Only the demo entry carries the tag');
expect(str_contains($facts['html'],DemoData::TOOLTIP),'Staff tag has the tooltip');
$column=static fn($record)=>DemoTag::apply(TextColumn::make('name'))->record($record)->getSuffix();
expect(str_contains((string)$column($demo)?->toHtml(),'>Demo'),'Demo column suffix');
same(null,$column($real),'Real column has no suffix');

// Staff banner: shown on Domains pages while seeded demo accounts exist.
FoundationSchema::install();
same('',DemoTag::banner(),'No banner on a system without demo accounts');
fixtureAccount(1,'enom','reseller-account');
same('',DemoTag::banner(),'Real accounts do not raise the banner');
fixtureAccount(2,'enom','beco-demo-enom');
$banner=storefrontDom(DemoTag::banner());
same('Test system. Records tagged Demo are seeded examples.',preg_replace('/\s+/',' ',trim($banner->query('//*[@role="note"]')->item(0)?->textContent??'')),'Banner text');
$nativeSuccessful=true;echo "Demo data checks passed.\n";

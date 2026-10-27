<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/native-app.php'; require __DIR__.'/Support/psl.php';
use Paymenter\Extensions\Servers\Domains\Data\{PslSnapshot, Registrable, Held};
use Paymenter\Extensions\Servers\Domains\Services\{PslValidator, RegistrableNameResolver};
$v = new PslValidator(128, 5, 1); $snapshot = new PslSnapshot(pslFixture(), $v); $resolver = new RegistrableNameResolver;
$good = ['example.com'=>'example.com', 'a.co.uk'=>'a.co.uk', 'WWW.Example.COM.'=>'example.com',
    'shop.api.example.com'=>'example.com', 'x.y.ck'=>'x.y.ck', 'www.ck'=>'www.ck', 'a.www.ck'=>'www.ck',
    'xn--bcher-kva.com'=>'xn--bcher-kva.com', 'a.xn--55qx5d.cn'=>'a.xn--55qx5d.cn', placeholderComFixtureName((str_repeat('a',51).'-placeholder').'.com')=>placeholderComFixtureName((str_repeat('a',51).'-placeholder').'.com')];
$maxHostname=str_repeat('a',63).'.'.str_repeat('b',63).'.'.str_repeat('c',63).'.'.placeholderComFixtureName((str_repeat('d',45).'-placeholder').'.com');
same(253,strlen($maxHostname),'Maximum hostname keeps its 253-byte boundary');
same(57,strlen(explode('.', $maxHostname)[3]),'Maximum hostname retains its 57-byte registrable label');
$good[$maxHostname]=placeholderComFixtureName((str_repeat('d',45).'-placeholder').'.com');
foreach ($good as $host=>$expected) {
    $r=$resolver->resolve($host,$snapshot); expect($r instanceof Registrable,'Registrable '.$host); same($expected,$r->name,'Exact derivation '.$host);
    same(strtolower(rtrim($host,'.')),$r->hostname,'Primary hostname retained');same($snapshot->revision,$r->psl_revision,'Revision bound');
}
$held = ['com'=>'suffix_unknown','co.uk'=>'suffix_unknown','a.ck'=>'suffix_unknown','host.unknown'=>'suffix_unknown',
    'tenant.github.io'=>'private_suffix','github.io'=>'private_suffix','www.tenant.github.io'=>'private_suffix',
    'bücher.com'=>'idn_held','aа.com'=>'idn_held'];
foreach (['','example.com..','https://example.com','a@example.com','example.com:443','example.com/path','*.example.com',
    '127.0.0.1','::1','[::1]','a.com,b.com','a.com b.com',"a.com\n",'a_b.com','-a.com','a-.com',str_repeat('a',64).'.com',
    implode('.',array_fill(0,4,str_repeat('a',63))).'.com'] as $host) { $held[$host]='source_invalid'; }
foreach ($held as $host=>$code) {
    $r=$resolver->resolve($host,$snapshot);expect($r instanceof Held,'Held '.$host);same($code,$r->safe_code,'Reason '.$host);same($snapshot->revision,$r->psl_revision,'Held revision');
}
$changed = new PslSnapshot(str_replace("\ncom\n", "\ncom\nexample.com\n", pslFixture()), $v);
$old=$resolver->resolve('shop.example.com',$snapshot); $new=$resolver->resolve('shop.example.com',$changed);
same(true,RegistrableNameResolver::changed($old,$new),'Changed derivation explicitly reported');same('example.com',$old->name,'Previous result never rewritten');
$private = new PslSnapshot(str_replace('github.io',"github.io\n*.example.com\n!safe.example.com",pslFixture()),$v);
same('private_suffix',$resolver->resolve('x.tenant.example.com',$private)->safe_code,'Private wildcard tenant held');
same('private_suffix',$resolver->resolve('safe.example.com',$private)->safe_code,'Private exception still protects registry parent');
$safe = new PslSnapshot(str_replace('github.io',"github.io\n*.com\n!example.com",pslFixture()),$v);
same('example.com',$resolver->resolve('example.com',$safe)->name,'Exception at ICANN boundary is safe');
same('private_suffix',$resolver->resolve('example.com',$private)->safe_code,'Private wildcard parent is also held');
$heldPrivate = $resolver->resolve('tenant.github.io',$snapshot);
$heldPrivateAgain = $resolver->resolve('tenant.github.io',$snapshot);
$heldUnknown = $resolver->resolve('host.unknown',$snapshot);
expect($heldPrivate instanceof Held && $heldPrivateAgain instanceof Held && $heldUnknown instanceof Held,'Transition fixtures are held names');
same(true,RegistrableNameResolver::changed($old,$heldPrivate),'Registrable to held reported');
same(true,RegistrableNameResolver::changed($heldPrivate,$old),'Held to registrable reported');
same(false,RegistrableNameResolver::changed($heldPrivate,$heldPrivateAgain),'Same held safe code is stable');
same(true,RegistrableNameResolver::changed($heldPrivate,$heldUnknown),'Changed held safe code is reported');
same(false,RegistrableNameResolver::changed($old,$resolver->resolve('shop.example.com',$snapshot)),'Same derivation stable');
$odd = new PslSnapshot(str_replace("\ncom\n", "\ncom\n!com\n", pslFixture()),$v);
same('suffix_unknown',$resolver->resolve('x.com',$odd)->safe_code,'Exception cannot create a TLD registration');
echo "Registrable resolver paired ordinary, compound, wildcard, exception, private, IDN and invalid-hostname matrix passed.\n"; $nativeSuccessful=true;

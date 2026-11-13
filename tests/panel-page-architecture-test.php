<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Every staff page extends one of the four page types, except the pages
// named below, which were built before the types. The list only shrinks: a listed page that now extends a type, or no
// longer exists, fails until it is removed from the list, and a new page can't join it.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\PageType;

/** Built before the page types, 2026-11-13. Remove a page when it moves to its type; never add one. */
const PAGES_BEFORE_TYPES = [
    'Pages\PanelKit',
    'Pages\WhatYouSell',
    'Resources\DomainResource\Pages\AdoptDomain', 'Resources\DomainResource\Pages\AdoptionPreview',
    'Resources\DomainResource\Pages\ViewDomain',
    'Resources\PendingAdoptionResource\Pages\ListPending', 'Resources\PendingAdoptionResource\Pages\ViewPending',
    'Resources\TldResource\Pages\PriceList',
];
$admin = dirname(__DIR__).'/Domains/Admin';
$prefix = 'Paymenter\\Extensions\\Servers\\Domains\\Admin\\';
$files = [...glob($admin.'/Pages/*.php'), ...glob($admin.'/Resources/*/Pages/*.php')];
expect(count($files) > 20, 'The page folders were read');
$typed = static function (string $class): ?string {
    foreach (class_parents($class) as $parent) { if (in_array($parent, PageType::TYPES, true)) { return $parent; } }
    return null;
};
$seen = [];
foreach ($files as $file) {
    $short = str_replace('/', '\\', substr($file, strlen($admin) + 1, -4));
    $class = $prefix.$short;
    expect(class_exists($class), 'Each page file declares its class: '.$short);
    $seen[] = $short;
    $type = $typed($class);
    if (in_array($short, PAGES_BEFORE_TYPES, true)) {
        expect($type === null, $short.' now extends a page type: remove it from the list');
        continue;
    }
    expect($type !== null, $short.' extends a page type (Detail, Settings, List or History)');
    expect(is_subclass_of($class, PageType::class), $short.' is a page type');
}
foreach (PAGES_BEFORE_TYPES as $short) { expect(in_array($short, $seen, true), $short.' is gone: remove it from the list'); }

// A page supplies data only: the head, header, footer, view, width and content are its type's, and final.
foreach (PageType::TYPES as $type) {
    $reflection = new ReflectionClass($type);
    expect($reflection->isAbstract() && $reflection->implementsInterface(PageType::class), class_basename($type).' is an abstract page type');
    foreach (['getTitle', 'getHeading', 'getSubheading', 'getHeader', 'getFooter', 'getHeaderActions', 'getHeaderWidgets', 'getFooterWidgets', 'getView', 'getMaxContentWidth', 'content'] as $method) {
        expect($reflection->getMethod($method)->isFinal(), class_basename($type).'::'.$method.' is final');
    }
    if ($reflection->hasMethod('table')) { expect($reflection->getMethod('table')->isFinal(), class_basename($type).'::table is final'); }
}
$nativeSuccessful = true;
echo 'Every page extends a page type, except '.count(PAGES_BEFORE_TYPES)." pages built before them.\n";

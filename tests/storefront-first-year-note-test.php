<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// A first-year price that differs from the renewal price says why, on search results and on the price list.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
storefrontViews();
$why = 'Registrars price the first year and renewals separately.';
$data = storefrontData();
$text = static fn (string $html): string => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
// Search: the available row names both prices and why they differ.
$row = storefrontDom(view('domains::search', $data)->render())->query('//*[@data-first-year-note]')->item(0);
same($why.' The first year is $12.00; each renewal is $14.00 a year.', $row ? $text($row->textContent) : null, 'A search result says why the first year differs');
// The same prices say nothing more.
$data['results'][0]['terms'][1] = '$14.00';
same(0, storefrontDom(view('domains::search', $data)->render())->query('//*[@data-first-year-note]')->length, 'Equal prices need no note');
// Price list: one sentence under the table.
$rows = storefrontData()['endings'];
$list = $text(view('domains::pricing', ['rows' => $rows, 'guest' => true, 'testEnvironment' => false])->render());
expect(str_contains($list, 'Prices plus tax where applicable. '.$why.' Register is the price of the first year; Renew is the price of each later year.'), 'The price list says why Register and Renew differ');
$same = array_map(static fn ($row) => [...$row, 'renew' => $row['register']], $rows);
expect(!str_contains($text(view('domains::pricing', ['rows' => $same, 'guest' => true, 'testEnvironment' => false])->render()), $why), 'Equal prices need no sentence');
$nativeSuccessful = true; echo "First-year price note passed.\n";

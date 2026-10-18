<?php
// Adapted from Enom 444f31c Http/Controllers/DomainController.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Paymenter\Extensions\Servers\Domains\Data\CanonicalName;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Models\SalesPolicy;
use Paymenter\Extensions\Servers\Domains\Services\Storefront;

/** Dormant until39 explicitly loads routes, views and the shared native cart admission hooks. */
final class StorefrontController extends Controller
{
    public function __construct(private readonly Storefront $storefront) {}

    public function search(Request $request)
    {
        $endings = $this->storefront->endings(); $names = array_column($endings, 'tld');
        $raw = $this->string($request->query('name'), 300); $chosen = $this->string($request->query('tld'), 190);
        $preferred = in_array($chosen, $names, true) ? $chosen : ($names[0] ?? null);
        $parsed = $raw === '' ? ['sld' => null, 'ending' => null, 'error' => null] : self::parseName($raw);
        $notice = null;
        if ($parsed['ending'] !== null) {
            if (in_array($parsed['ending'], $names, true)) { $preferred = $parsed['ending']; }
            elseif (self::endsWithAny($parsed['ending'], $names)) {
                $parsed['error'] = 'Enter the name without a subdomain, for example '.$parsed['ending'].'.';
            } else { $notice = '.'.$parsed['ending'].' isn’t available here. We offer '.self::listEndings($names).'.'; }
        }
        $sld = $parsed['sld']; $error = $parsed['error']; $results = [];
        if ($sld !== null && $error === null) {
            usort($names, static fn ($a, $b) => ($b === $preferred) <=> ($a === $preferred));
            foreach (array_slice($names, 0, 12) as $ending) {
                $results[] = ['domain' => $sld.'.'.$ending, 'sld' => $sld, 'tld' => $ending, 'status' => 'idle'];
            }
        }
        return view('domains::search', ['endings' => $endings, 'name' => $raw, 'preferred' => $preferred,
            'sld' => $sld, 'error' => $error, 'notice' => $notice, 'results' => $results, 'years' => null,
            'guest' => !$request->user(), 'testEnvironment' => $this->storefront->testEnvironment($endings), 'checkKey' => FormKeys::issue($request, 'search')]);
    }

    public function searchAlias(Request $request)
    {
        return redirect()->route('domains.domains.search', array_filter($request->query(), 'is_string'), 301);
    }

    public function pricing(Request $request)
    {
        $rows = $this->storefront->endings();
        return view('domains::pricing', ['rows' => $rows, 'guest' => !$request->user(), 'testEnvironment' => $this->storefront->testEnvironment($rows)]);
    }

    public function check(Request $request)
    {
        FormKeys::verify($request, 'search');
        if ($limited = $this->limit($request)) { return $limited; }
        $input = $request->input('domain');
        if (!is_string($input) || strlen($input) > 253) { return response()->json(['status' => 'invalid', 'message' => 'Enter a valid domain name.'], 422); }
        try { $result = $this->storefront->check($input); }
        catch (\InvalidArgumentException) { return response()->json(['status' => 'invalid', 'message' => 'Enter a valid domain name.'], 422); }
        catch (\Throwable) { return response()->json(['status' => 'error', 'message' => 'Couldn’t check this name.'], 503); }
        if (in_array($result['status'], ['available', 'transferable'], true)) {
            $result['key'] = FormKeys::issue($request, 'order:'.$result['domain']);
        }
        return response()->json(['status' => $result['status'], 'html' => view('domains::partials.result', ['result' => $result, 'first' => false])->render()]);
    }

    /** Native cart handoff through a neutral POST. No product/account ID in customer URLs or form values. */
    public function order(Request $request)
    {
        $input = $request->input('domain');
        abort_unless(is_string($input) && strlen($input) <= 253, 422);
        FormKeys::verify($request, 'order:'.$input);
        if ($this->limit($request)) {
            return redirect()->route('domains.domains.search', ['name' => $input])->with('domains_notice', 'Too many searches. Wait a minute, then retry.');
        }
        FormKeys::consume($request, 'order:'.$input);
        try {
            $name = CanonicalName::normalize($input); $action = $request->input('action'); $term = $request->input('term');
            if (!in_array($action, ['register', 'transfer'], true) || !is_string($term) || !preg_match('/^(?:[1-9]|10)$/D', $term)) { throw new \DomainException; }
            $ending = CanonicalName::parse($name, SalesPolicy::pluck('tld')->all())['tld'];
            $offer = $this->storefront->offer($ending, $action, (int) $term);
            \App\Classes\Cart::add($offer['product'], $offer['plan'], [], ['domain' => $name]);
        } catch (\Throwable) {
            return redirect()->route('domains.domains.search', ['name' => $input])->with('domains_notice', 'We couldn’t add that name to your cart. Check the latest results below and try again.');
        }
        return redirect()->route('cart');
    }

    private function limit(Request $request)
    {
        $keys = ['domains.search.ip:'.hash('sha256', (string) $request->ip()) => 30];
        if ($request->user()) { $keys['domains.search.user:'.$request->user()->id] = 60; }
        foreach ($keys as $key => $max) {
            if (RateLimiter::hit($key, 60) > $max) {
                return response()->json(['status' => 'error', 'message' => 'Too many searches. Wait a minute, then retry.'], 429)
                    ->header('Retry-After', (string) RateLimiter::availableIn($key));
            }
        }
        return null;
    }
    public static function parseName(string $raw): array
    {
        $value = strtolower(trim($raw));
        $value = preg_replace('#\A[a-z][a-z0-9+.-]*://#', '', $value);
        $value = preg_replace('#[/?\#].*\z#s', '', $value);
        $value = rtrim(preg_replace('/\Awww\./', '', $value), '.');
        if ($value === '') {
            return ['sld' => null, 'ending' => null, 'error' => 'Enter a name to search for.'];
        }
        if (preg_match('/[^\x21-\x7e]/', $value)) {
            return ['sld' => null, 'ending' => null, 'error' => preg_match('/\s/', $value)
                ? 'Domain names can’t contain spaces.' : 'Use only letters a–z, numbers and hyphens. International characters aren’t supported.'];
        }
        [$sld, $ending] = array_pad(explode('.', $value, 2), 2, null);
        if (strlen($sld) > 63) {
            return ['sld' => null, 'ending' => null, 'error' => 'The name can be at most 63 characters.'];
        }
        if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $sld) || substr($sld, 2, 2) === '--') {
            return ['sld' => null, 'ending' => null, 'error' => 'Use only letters a–z, numbers and hyphens, without a hyphen at the start or end.'];
        }
        if ($ending !== null && !preg_match('/\A[a-z0-9]+(?:\.[a-z0-9]+)*\z/', $ending)) {
            return ['sld' => $sld, 'ending' => null, 'error' => 'Check the ending after the dot, for example '.$sld.'.com.'];
        }

        return ['sld' => $sld, 'ending' => $ending, 'error' => null];
    }

    private static function endsWithAny(string $ending, array $names): bool
    {
        foreach ($names as $name) {
            if (str_ends_with($ending, '.'.$name)) {
                return true;
            }
        }

        return false;
    }

    public static function listEndings(array $names): string
    {
        $names = array_map(fn ($name) => '.'.$name, $names);
        if (count($names) <= 1) {
            return $names[0] ?? 'no endings yet';
        }
        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }

    private function string(mixed $value, int $max): string { return is_string($value) ? trim(substr($value, 0, $max)) : ''; }
}

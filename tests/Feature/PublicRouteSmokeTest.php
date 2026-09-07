<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every parameterless GET page in the storefront, fetched once as a guest and
 * once signed in, asserting only that it does not 5xx.
 *
 * A deliberately weak assertion. 200, a redirect to login, 403, 404 are all
 * fine — the question is whether the page can be served at all. That is the
 * failure mode this codebase actually has: `/products/compare` was a guaranteed
 * 500 for as long as anyone can remember because no test ever asked for it, and
 * `/admin` was throwing on every page (#958). Neither needed a clever test, only
 * one that asked.
 *
 * Routes are read from the router rather than listed here, so a new page is
 * covered without anyone remembering to add it.
 */
class PublicRouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Prefixes owned by a package or a panel, which bring their own tests and
     * their own bootstrapping.
     */
    private const NOT_OURS = ['admin', 'app', 'livewire', 'sanctum', 'horizon', 'telescope', '_debugbar', '_ignition', 'api'];

    public function test_no_storefront_page_returns_a_server_error(): void
    {
        $failures = [];

        foreach ($this->pages() as $uri) {
            foreach (['guest' => null, 'signed in' => User::factory()->create()] as $as => $user) {
                $response = $user
                    ? $this->actingAs($user)->get($uri)
                    : $this->get($uri);

                if ($response->getStatusCode() >= 500) {
                    $failures[] = "/{$uri} as {$as} -> ".$response->getStatusCode()
                        .($response->exception ? ' :: '.$response->exception::class.': '.$response->exception->getMessage() : '');
                }
            }
        }

        $this->assertSame([], $failures, "Storefront pages that return a server error:\n".implode("\n", $failures));
    }

    /**
     * @return list<string>
     */
    private function pages(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            // Bound parameters would need fixtures per route, which turns a
            // smoke test into a suite. The parameterless pages are the ones a
            // visitor lands on anyway.
            if (str_contains($uri, '{')) {
                continue;
            }

            if (in_array(explode('/', $uri)[0], self::NOT_OURS, true)) {
                continue;
            }

            $uris[] = $uri;
        }

        return array_values(array_unique($uris));
    }
}

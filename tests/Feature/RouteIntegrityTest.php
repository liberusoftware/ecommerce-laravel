<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A route pointing at a controller method that does not exist is a guaranteed
 * 500 that nothing catches: Laravel resolves the action lazily, at dispatch, so
 * the route table builds fine and `route:cache` succeeds. It only fails for a
 * visitor.
 *
 * Four product-compare routes shipped that way — their methods had been
 * commented out — and stayed broken because no test ever named them. This walks
 * the whole table instead of naming any of them, so the next one is caught by
 * the same assertion.
 */
class RouteIntegrityTest extends TestCase
{
    public function test_every_route_action_exists(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('uses');

            // Closures and invokable-by-string actions Laravel resolves itself.
            // Only 'Controller@method' strings are checked here.
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class)) {
                $missing[] = "{$route->uri()} -> {$class} (class not found)";

                continue;
            }

            // method_exists, not is_callable: __call on a controller would make
            // is_callable true for every name and the check would assert nothing.
            if (! method_exists($class, $method)) {
                $missing[] = "{$route->uri()} -> {$action}";
            }
        }

        $this->assertSame([], $missing, "Routes point at actions that do not exist:\n".implode("\n", $missing));
    }
}

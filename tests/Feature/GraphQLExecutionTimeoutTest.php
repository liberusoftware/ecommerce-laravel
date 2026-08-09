<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The wall-clock bound on one GraphQL request.
 *
 * The endpoint is public and anonymous, and the two bounds it already had —
 * depth and complexity — bound the *shape* of a query rather than its time. A
 * query can sit inside both and still hold a worker for as long as the database
 * takes, which `throttle:api` does not help with either: it counts requests,
 * and one is enough.
 *
 * The defect was recorded on #950 as *"no execution timeout; that needs a
 * number nobody has picked"*. The number is now ten seconds, in
 * `config/graphql.php`, with the reasoning next to it.
 */
class GraphQLExecutionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_query_that_outlives_the_deadline_is_stopped(): void
    {
        Product::factory()->create(['name' => 'Alpha']);

        // Zero seconds expires before the first resolver runs, which is the
        // whole mechanism without having to make a query slow enough to prove
        // it — a test that waited ten seconds would be a ten-second test.
        config(['graphql.execution_timeout' => 0]);

        $response = $this->postJson('/api/graphql', ['query' => '{ products { data { id name } } }']);

        // 200 with errors, not 500: the caller is told what happened and gets
        // whatever resolved, rather than an empty internal error.
        $response->assertOk();

        $this->assertStringContainsString(
            'exceeded the execution time limit',
            json_encode($response->json('errors')),
        );
    }

    public function test_an_ordinary_query_is_unaffected(): void
    {
        Product::factory()->create(['name' => 'Alpha']);

        // The bound is real, so the thing worth pinning is that it does not
        // fire on the queries the storefront actually makes — a timeout that
        // trips on normal traffic is an outage rather than a control.
        $response = $this->postJson('/api/graphql', ['query' => '{ products { data { id name } } }']);

        $response->assertOk();

        $this->assertNull($response->json('errors'));
        $this->assertSame('Alpha', $response->json('data.products.data.0.name'));
    }

    public function test_the_deadline_reaches_fields_that_have_their_own_resolver(): void
    {
        Product::factory()->create(['name' => 'Alpha', 'price' => 12.5]);

        config(['graphql.execution_timeout' => 0]);

        // `price` has an explicit resolver, and a default-resolver-only guard
        // would sail straight past it. Most of the expensive fields in this
        // schema are the ones with their own resolvers, so covering only the
        // default would bound the cheap half.
        $response = $this->postJson('/api/graphql', ['query' => '{ products { data { price } } }']);

        $response->assertOk();

        $this->assertStringContainsString(
            'exceeded the execution time limit',
            json_encode($response->json('errors')),
        );
    }
}

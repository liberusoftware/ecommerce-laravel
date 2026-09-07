<?php

namespace Tests\Unit;

use App\Services\Facebook\FacebookCatalog;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookCatalogTest extends TestCase
{
    private function client(string $token = 'test-token', string $catalog = '123456', string $version = 'v21.0'): FacebookCatalog
    {
        return new FacebookCatalog($token, $catalog, 'biz-1', $version);
    }

    public function test_connection_succeeds_and_reports_catalog_name(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['id' => '123456', 'name' => 'My Store Catalog'], 200),
        ]);

        $result = $this->client()->testConnection();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('My Store Catalog', $result['message']);
    }

    public function test_connection_sends_bearer_token_to_versioned_catalog_node(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['id' => '123456', 'name' => 'C'], 200),
        ]);

        $this->client(version: 'v20.0')->testConnection();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-token')
                && str_contains($request->url(), '/v20.0/123456')
                && str_contains($request->url(), 'fields=id%2Cname');
        });
    }

    public function test_connection_reports_meta_error_message_on_failure(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(
                ['error' => ['message' => 'Invalid OAuth access token.']],
                400
            ),
        ]);

        $result = $this->client()->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid OAuth access token.', $result['message']);
    }

    public function test_connection_requires_credentials_before_calling_meta(): void
    {
        Http::fake();

        $result = $this->client(token: '')->testConnection();

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }
}

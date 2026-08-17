<?php

namespace App\Services\Facebook;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one HTTP client for the Meta Commerce Catalog. We address the Catalog,
 * never Marketplace itself, which has no public API.
 */
class FacebookCatalog
{
    public function __construct(
        protected string $accessToken,
        protected string $catalogId,
        protected string $businessId = '',
        protected string $graphVersion = 'v21.0',
    ) {}

    /** Verify credentials by reading the Catalog node. Never throws. */
    public function testConnection(): array
    {
        if (blank($this->accessToken) || blank($this->catalogId)) {
            return ['success' => false, 'message' => 'Set an access token and Catalog ID before testing.'];
        }

        try {
            $response = $this->graph()->get('/'.$this->catalogId, ['fields' => 'id,name']);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach Meta: '.$e->getMessage()];
        }

        if ($response->successful()) {
            $name = $response->json('name', $this->catalogId);

            return ['success' => true, 'message' => "Connected to Catalog: {$name}"];
        }

        $error = $response->json('error.message', $response->body());

        return ['success' => false, 'message' => "Meta rejected the request: {$error}"];
    }

    /**
     * Upsert/delete Catalog items in one call. Each request is
     * ['method' => 'UPDATE'|'DELETE', 'data' => ['id' => retailerId, ...]].
     *
     * @return array{ok: bool, status: int, body: array}
     */
    public function itemsBatch(array $requests): array
    {
        $response = $this->graph()->post('/'.$this->catalogId.'/items_batch', [
            'item_type' => 'PRODUCT_ITEM',
            'allow_upsert' => true,
            'requests' => $requests,
        ]);

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $response->json() ?? [],
        ];
    }

    /**
     * Meta-owned item status keyed by retailer id. Read-back only — the
     * application stays source of truth for content.
     *
     * @param  array<int, string>  $retailerIds
     * @return array<string, array>
     */
    public function readItemStatuses(array $retailerIds): array
    {
        if ($retailerIds === []) {
            return [];
        }

        try {
            // ponytail: one page of 1000. Follow `paging.next` if a real
            // catalogue outgrows it.
            $response = $this->graph()->get('/'.$this->catalogId.'/products', [
                'fields' => 'id,retailer_id,review_status,errors,availability',
                'limit' => 1000,
            ]);
        } catch (Throwable $e) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        if ($response->json('paging.next')) {
            Log::warning('FacebookCatalog: catalog exceeds one page; reconciliation covered only the first 1000 items.');
        }

        $wanted = array_flip($retailerIds);
        $map = [];

        foreach ($response->json('data', []) as $item) {
            $retailerId = $item['retailer_id'] ?? null;

            if ($retailerId !== null && isset($wanted[$retailerId])) {
                $map[$retailerId] = $item;
            }
        }

        return $map;
    }

    /** A Graph request pinned to the configured version and bearer token. */
    protected function graph(): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.$this->graphVersion)
            ->withToken($this->accessToken)
            ->acceptJson();
    }
}

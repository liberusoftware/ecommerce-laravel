<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;

class WebhookEndpointController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json(WebhookEndpoint::latest()->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'url' => $this->urlRules('required'),
            'events' => 'required|array|min:1',
            'events.*' => 'required|string',
            'is_active' => 'sometimes|boolean',
        ]);

        // The signing secret is generated server-side and returned once, so the
        // receiver can verify the X-Webhook-Signature. The model hides it from
        // every other response, so this one has to opt back in.
        $endpoint = WebhookEndpoint::create([
            'url' => $validated['url'],
            'events' => $validated['events'],
            'is_active' => $validated['is_active'] ?? true,
            'secret' => 'whsec_'.Str::random(40),
        ]);

        return response()->json($endpoint->makeVisible('secret'), 201);
    }

    public function update(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'url' => $this->urlRules('sometimes'),
            'events' => 'sometimes|array|min:1',
            'events.*' => 'required|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $webhookEndpoint->update($validated);

        return response()->json($webhookEndpoint->fresh());
    }

    public function destroy(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->authorizeStaff($request);

        $webhookEndpoint->delete();

        return response()->json(['message' => 'Webhook endpoint deleted.']);
    }

    /** Delivery attempt log for an endpoint — newest first, optionally filtered by ?success=0|1. */
    public function deliveries(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->authorizeStaff($request);

        $deliveries = $webhookEndpoint->deliveries()
            ->when($request->has('success'), fn ($q) => $q->where('success', $request->boolean('success')))
            ->latest()
            ->paginate(25);

        return response()->json($deliveries);
    }

    /**
     * SendWebhookDelivery posts to this URL verbatim and logs the status code and
     * error body back to the deliveries endpoint, so an unchecked host makes this
     * an SSRF probe against anything the app can reach (cloud metadata, internal
     * services). Require https and refuse hosts that resolve into private or
     * reserved space.
     */
    private function urlRules(string $presence): array
    {
        return [$presence, 'url:https', function (string $attribute, mixed $value, Closure $fail): void {
            // trim('[]') unwraps bracketed IPv6 literals, which Uri::host() preserves.
            $host = trim((string) Uri::of((string) $value)->host(), '[]');

            // ponytail: a host that doesn't resolve now is allowed through (test and
            // staging hostnames are routinely resolvable only from the delivery box).
            // Resolution here can't be authoritative anyway — DNS can rebind between
            // this check and the job. Block at egress if that gap ever matters.
            $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

            foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $fail("The {$attribute} must not point at a private or reserved network address.");

                    return;
                }
            }
        }];
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403);
    }
}

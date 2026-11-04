<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated user's own customer profile. A Customer is the same identity as
 * a User, so this always operates on the caller's own record (resolved/created via
 * getOrCreateCustomer) — there is no id in the route, so no cross-user access.
 */
class CustomerController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->getOrCreateCustomer()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $customer = $request->user()->getOrCreateCustomer();
        $customer->update($data);

        return response()->json(['data' => $customer->fresh()]);
    }
}

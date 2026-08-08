<?php

namespace App\Http\Controllers;

use Exception;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Stripe;
use Stripe\Plan;

class SubscriptionController extends Controller
{
   
    private $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * The public pricing list.
     *
     * Cached, because this route is anonymous by design and called Stripe once
     * per request: anyone could drive unlimited outbound API calls against the
     * merchant's account by holding down refresh. An hour is fine — a plan list
     * changes rarely, and a stale price for that long is not a billing decision,
     * since the charge is made against the plan id at subscribe time.
     *
     * An unconfigured install returns an empty list rather than a 500. Stripe
     * throws AuthenticationException when no key is set, which made /subscriptions
     * a server error on every fresh checkout — including CI.
     */
    public function viewAvailableSubscriptions()
    {
        if (blank(config('services.stripe.secret'))) {
            return response()->json(['plans' => []]);
        }

        $plans = Cache::remember(
            'stripe.plans',
            now()->addHour(),
            fn () => Plan::all()->toArray(),
        );

        return response()->json(['plans' => $plans]);
    }

    public function subscribeToPlan(Request $request)
    {
        $request->validate([
            'plan' => 'required|string',
            'payment_method' => 'required|string',
        ]);

        $user = Auth::user();

        try {
            $user->newSubscription('default', $request->plan)
                 ->create($request->payment_method);
            return response()->json(['success' => true]);
        } catch (IncompletePayment $exception) {
            return response()->json(['success' => false, 'error' => $exception->getMessage()], 402);
        }
    }

    public function changePlan(Request $request)
    {
        $request->validate([
            'plan' => 'required|string',
        ]);

        $user = Auth::user();

        try {
            $user->subscription('default')->swap($request->plan);
            return response()->json(['success' => true]);
        } catch (Exception $exception) {
            return response()->json(['success' => false, 'error' => $exception->getMessage()], 400);
        }
    }

    public function cancelSubscription()
    {
        $user = Auth::user();

        try {
            $user->subscription('default')->cancel();
            return response()->json(['success' => true]);
        } catch (Exception $exception) {
            return response()->json(['success' => false, 'error' => $exception->getMessage()], 400);
        }
    }


    

    public function createPaypalSubscription(Request $request)
    {
        $request->validate([
            'paymentMethodId' => 'required|string',
            'planId' => 'required|string',
            'userDetails' => 'required|array',
        ]);

        $result = $this->subscriptionService->createSubscription($request->input('paymentMethodId'), $request->input('planId'), $request->input('userDetails'));

        return response()->json($result);
    }

    public function updatePaypalSubscription(Request $request)
    {
        $request->validate([
            'subscriptionId' => 'required|string',
            'planId' => 'required|string',
        ]);

        $result = $this->subscriptionService->updateSubscription($request->input('subscriptionId'), $request->input('planId'));

        return response()->json($result);
    }

    public function cancelPaypalSubscription(Request $request)
    {
        $request->validate([
            'subscriptionId' => 'required|string',
        ]);

        $result = $this->subscriptionService->cancelSubscription($request->input('subscriptionId'));

        return response()->json($result);
    }
}
<?php

namespace App\Http\Controllers;

use Exception;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    public function viewAvailableSubscriptions()
    {
        $plans = Plan::all();
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
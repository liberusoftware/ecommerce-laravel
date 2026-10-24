<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\StoreAccess;
use App\Support\TeamPermission;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $query = Invoice::with('order');

        // Customers see only their own invoices (via the owning order); staff see all.
        if (! StoreAccess::allows($request->user(), TeamPermission::ORDERS_VIEW, null, 'view_any_invoice')) {
            $query->whereHas('order', fn ($q) => $q->where('user_id', $request->user()->id));
        }

        if ($request->has('date')) {
            $query->whereDate('invoice_date', $request->date);
        }
        if ($request->has('status')) {
            $query->where('payment_status', $request->status);
        }
        $invoices = $query->latest()->paginate(10);

        return view('invoices.index', compact('invoices'));
    }

    public function show(Request $request, int $id): View
    {
        $query = Invoice::with(['order', 'lines', 'customer']);

        // Scope to the owner unless staff — a foreign id 404s instead of leaking PII.
        if (! StoreAccess::allows($request->user(), TeamPermission::ORDERS_VIEW, null, 'view_any_invoice')) {
            $query->whereHas('order', fn ($q) => $q->where('user_id', $request->user()->id));
        }

        $invoice = $query->findOrFail($id);

        return view('invoices.show', compact('invoice'));
    }
}

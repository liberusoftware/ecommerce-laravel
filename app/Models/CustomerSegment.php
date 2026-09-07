<?php

namespace App\Models;

use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerSegment extends Model
{
    use HasFactory, IsTenantModel;

    protected $fillable = [
        'name',
        'description',
        'conditions',
        'match_type',
        'is_active',
        'customer_count',
        'last_calculated_at',
    ];

    protected $casts = [
        'conditions' => 'array',
        'is_active' => 'boolean',
        'customer_count' => 'integer',
        'last_calculated_at' => 'datetime',
    ];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'customer_segment_members', 'segment_id', 'user_id')
            ->withPivot('added_at');
    }

    /**
     * Calculate and update segment membership based on conditions
     */
    public function calculateMembers(): void
    {
        if (! $this->is_active) {
            return;
        }

        $query = $this->membershipCandidates();

        // match_type 'any' => OR the conditions, 'all' => AND them.
        // Each condition is its own nested group so whereHas/has clauses combine correctly.
        $boolean = $this->match_type === 'any' ? 'orWhere' : 'where';

        // The whole condition set is one group, and that grouping is what makes
        // the tenant constraint above survive. Applied directly to $query, an
        // `orWhere` condition would sit at the top level beside the tenant
        // predicate — `WHERE (customer is this merchant's) OR (condition)` —
        // and the right-hand side is qualified by nothing, so a single
        // match_type='any' segment selects every user on the deployment. AND
        // binds tighter than OR, so the constraint has to be outside a group
        // the conditions are inside.
        $query->where(function ($query) use ($boolean) {
            foreach ($this->conditions as $condition) {
                $query->{$boolean}(function ($query) use ($condition) {
                    $this->applyCondition($query, $condition);
                });
            }
        });

        $userIds = $query->pluck('id');

        // Sync members
        $this->members()->sync($userIds);

        // Update count and timestamp
        $this->update([
            'customer_count' => $userIds->count(),
            'last_calculated_at' => now(),
        ]);
    }

    /**
     * The users this segment is allowed to consider at all.
     *
     * A segment belongs to one merchant, so its members do too. This used to be
     * a bare `User::query()`, which is every user on the deployment, and the
     * `sync()` below then wrote other merchants' shoppers into this segment.
     *
     * It is not a corner case. `IsTenantModel` writes `team_id` on create and
     * installs no read scope, so `CustomerSegment::active()->get()` in
     * `segments:calculate` returns *every* merchant's segments and fills each
     * one from every merchant's users. One run of one command crossed every
     * tenant boundary on the deployment.
     *
     * The link is the shopper's `Customer` record, which is where a person's
     * relationship with a merchant is actually recorded — the same path
     * `in_customer_group` already takes. `Customer`'s own store scope rides
     * along on top and narrows further inside a panel; it is inert on the
     * console, which is exactly where this runs, so it is not the control.
     *
     * **An unstamped segment sees unstamped customers, and only those.**
     * `team_id` is nullable on both tables and null means nobody could say who
     * owns the row, which is the ordinary state of a deployment that has not
     * configured tenancy yet. Matching null to null keeps that deployment
     * working exactly as it did while still making a tenanted one correct:
     * team 1's segment never sees team 2's shoppers either way.
     *
     * The alternative — an unstamped segment matching nobody — reads as the
     * safer choice and is not. It turns a leak into a silently empty segment,
     * and a merchant reading an empty segment acts on it just as confidently as
     * one reading a wrong one. A control that fails closed has to fail visibly,
     * and there is nowhere here to say so.
     */
    private function membershipCandidates(): Builder
    {
        return User::query()->whereHas('customer', function (Builder $query) {
            $this->team_id === null
                ? $query->whereNull('customers.team_id')
                : $query->where('customers.team_id', $this->team_id);
        });
    }

    /**
     * Apply a single condition to the query
     */
    protected function applyCondition($query, array $condition): void
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? '=';
        $value = $condition['value'] ?? null;

        if (! $field) {
            return;
        }

        // Handle different condition types
        match ($field) {
            // has() builds a correlated `(select count(*) ...) <op> <value>` predicate.
            // The old havingRaw()-in-whereHas produced invalid SQL ("HAVING on a non-aggregate query").
            'total_orders' => $query->has('orders', $operator, (int) $value),
            'lifetime_value' => $query->whereHas('customerMetric', function ($q) use ($operator, $value) {
                $q->where('lifetime_value', $operator, $value);
            }),
            // Compare the user's MOST RECENT order date. whereHas() compiles to an
            // EXISTS subquery where latest()/limit() are inert — it would match a
            // user with ANY qualifying order, which is wrong for <=/</=. Use a
            // correlated MAX() so the comparison is against the last order only.
            'last_order_date' => $query->whereRaw(
                '(select max(created_at) from orders where orders.user_id = users.id) '.$this->safeOperator($operator).' ?',
                [$value]
            ),
            'has_purchased_product' => $query->whereHas('orders.items', function ($q) use ($value) {
                $q->where('product_id', $value);
            }),
            // A user is "in" a customer group when their identity-linked Customer
            // belongs to it (User -> customer -> customer_group_memberships). The old
            // where('customer_group_id') hit a nonexistent users column.
            'in_customer_group' => $query->whereHas('customer.groups', function ($q) use ($value) {
                $q->where('customer_groups.id', $value);
            }),
            // Fail closed: an unrecognised field must not silently drop the filter
            // (which would match EVERY user under match_type=all) — match no one.
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Whitelist comparison operators — condition config must never reach raw SQL unchecked. */
    private function safeOperator(string $operator): string
    {
        return in_array($operator, ['=', '!=', '<', '<=', '>', '>='], true) ? $operator : '=';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use JoelButcher\Socialstream\HasConnectedAccounts;
use Laravel\Cashier\Billable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    use Billable;
    use HasApiTokens;
    use HasConnectedAccounts;
    use HasFactory;
    use HasProfilePhoto {
        HasProfilePhoto::profilePhotoUrl as getPhotoUrl;
    }

    /*
     * Both traits declare teams(), which is a fatal error unless resolved —
     * `spatie/laravel-permission` gained the method in 8.x, and the bump to
     * ^8.3 stopped the application booting at all.
     *
     * Jetstream's wins, because it is the one this application means:
     * allTeams(), currentTeam, switchTeam, Filament tenancy on both panels and
     * canAccessPanel all read it, and `teams` rows carry Jetstream's shape.
     *
     * Spatie's is dropped rather than aliased. Its teams feature is off
     * (config/permission.php sets 'teams' => false), under which its own
     * implementation returns a `whereRaw('1 = 0')` no-op that exists only so
     * model introspection does not break — and nothing inside the package
     * calls it.
     */
    use HasRoles, HasTeams {
        HasTeams::teams insteadof HasRoles;
    }
    use Notifiable;

    // use SetsProfilePhotoFromUrl;
    use TwoFactorAuthenticatable;

    /**
     * Panel access.
     *
     * `admin` is the back-office for the whole store: super_admin only.
     *
     * `app` is a team's back-office — Products, Orders, Invoices, Customers,
     * Articles, Collections — scoped by Filament to the current tenant. So the
     * question it answers is "do you belong to a team?", and teams are handed out
     * through TeamPolicy::create, which requires the `create_store` permission.
     * That keeps the decision in one place instead of duplicating a role check here.
     *
     * This previously ended `return true; // TODO: Check panel and role`, so any
     * authenticated user — no roles, no teams — was waved into /app. Only a
     * separate bug in registration kept strangers from arriving; fixing that
     * without this would have opened the door.
     *
     * Shoppers are not refused anything by this: their account area lives on the
     * storefront (/orders, /wishlist, /invoices, /payment_methods).
     *
     * Note $this, not auth()->user(): Filament passes the user to authorize, and
     * reading the session instead silently authorized the wrong person under
     * impersonation or in queued contexts.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->hasRole('super_admin'),
            'app' => $this->allTeams()->isNotEmpty(),
            default => false,
        };
    }

    public function wishlist()
    {
        return $this->hasMany(Wishlist::class);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * Get the URL to the user's profile photo.
     */
    public function profilePhotoUrl(): Attribute
    {
        return filter_var($this->profile_photo_path, FILTER_VALIDATE_URL)
            ? Attribute::get(fn () => $this->profile_photo_path)
            : $this->getPhotoUrl();
    }

    /**
     * @return array<Model> | Collection
     */
    public function getTenants(Panel $panel): array|Collection
    {
        // Every team the user can act in — owned + membership. Using only
        // ownedTeams (as before) hid shared/member teams from the tenant switcher.
        return $this->allTeams();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        // belongsToTeam covers ownership AND membership; the previous
        // teams-only check locked a team's own owner out of their tenant.
        return $this->belongsToTeam($tenant);
    }

    public function canAccessFilament(): bool
    {
        //        return $this->hasVerifiedEmail();
        return true;
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->latestTeam;
    }

    public function latestTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function browsingHistory(): HasMany
    {
        return $this->hasMany(BrowsingHistory::class);
    }

    // A user's ratings are reached through their Customer since ADR 0008 —
    // `$user->customer->rating`. The relation that hung off `User` belonged to
    // the retired stack and pointed at a table that no longer exists.

    public function membership(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot(['role']);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function customerSegments(): BelongsToMany
    {
        // The pivot tracks membership with its own `added_at` column and has no
        // created_at/updated_at — so withPivot('added_at'), NOT withTimestamps()
        // (which would select/write pivot timestamp columns that don't exist and error).
        return $this->belongsToMany(CustomerSegment::class, 'customer_segment_members', 'user_id', 'segment_id')
            ->withPivot('added_at');
    }

    public function customerMetric(): HasOne
    {
        return $this->hasOne(CustomerMetric::class);
    }

    /**
     * The Customer record for this user — a Customer is the same identity as a User.
     */
    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    /**
     * Resolve (creating if needed) this user's Customer record, seeded from the
     * user's name + email. Idempotent — the customers.user_id link is unique.
     */
    public function getOrCreateCustomer(): Customer
    {
        $parts = explode(' ', trim((string) $this->name), 2);

        return $this->customer()->firstOrCreate([], [
            'first_name' => $parts[0] !== '' ? $parts[0] : 'Customer',
            'last_name' => $parts[1] ?? '',
            'email' => $this->email,
        ]);
    }

    public function giftRegistries(): HasMany
    {
        return $this->hasMany(GiftRegistry::class);
    }

    public function productInteractions(): HasMany
    {
        return $this->hasMany(ProductInteraction::class);
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The /admin panel is Team-tenant-scoped too (`ownershipRelationship: 'team'`),
 * which the sibling AppPanelTenancyTest covers for /app but nothing covered
 * here.
 *
 * That gap is what #958 is. It was recorded as either a broken page or a leak,
 * and the answer turned out to be the leak — for every resource in both panels
 * rather than the two the issue names. See the second test.
 *
 * The resources are read off the panel rather than listed here by hand. A hand
 * list covers the resources somebody remembered, and the two that were missing
 * from it were the two registered by a plugin — `usingMenuResource()` and
 * `usingMenuItemResource()` — which is precisely where nobody looks. Enumerating
 * the panel means a resource cannot be added without being covered.
 */
class AdminPanelTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function actingInAdminPanel(): Team
    {
        // Allow all authorization so this isolates the TENANCY wiring rather
        // than the Shield permission layer — same reasoning as AppPanelTenancyTest.
        Gate::before(fn () => true);
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $team = $user->ownedTeams()->first();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($team);

        return $team;
    }

    /**
     * Over HTTP rather than through Livewire::test, and that is the whole point.
     *
     * Filament 5 registers the tenant scope as a global scope from its tenancy
     * middleware — `BelongsToTenant` says so in as many words: "scoping is
     * applied via global scopes registered after tenant identification in
     * middleware". Mounting a page with Livewire::test skips that middleware, so
     * no scope is ever registered and the page renders clean whether or not the
     * table could survive being scoped. A first version of this test did exactly
     * that and passed against the known-broken resources.
     */
    public function test_every_admin_panel_list_page_responds(): void
    {
        $team = $this->actingInAdminPanel();

        $resources = Filament::getPanel('admin')->getResources();

        $this->assertNotEmpty($resources, 'The admin panel registered no resources — the enumeration is wrong.');

        // Collected rather than asserted one at a time: the first failure would
        // otherwise hide the rest, and the point is the whole set.
        $failures = [];

        foreach ($resources as $resource) {
            if (! $resource::hasPage('index')) {
                continue;
            }

            $response = $this->get($resource::getUrl(tenant: $team));

            if ($response->getStatusCode() >= 300) {
                $failures[] = class_basename($resource).' -> '.$response->getStatusCode()
                    .($response->exception ? ' :: '.$response->exception::class.': '.$response->exception->getMessage() : '');
            }
        }

        sort($failures);

        $this->assertSame([], $failures, "Admin panel list pages that do not respond 2xx:\n".implode("\n", $failures));
    }

    /**
     * A resource is tenant-scoped unless it says otherwise on itself.
     *
     * `$isScopedToTenant` is declared by Filament's `BelongsToTenant` trait on
     * `Filament\Resources\Resource`, so every resource that does not redeclare
     * it shares **one** storage slot. `SomeResource::scopeToTenant(false)`
     * therefore reads as a per-resource opt-out and behaves as a global one:
     * one such call, made for Shield's role resource, turned tenant scoping off
     * for every resource in both panels. Every page still responded, and each
     * one listed every merchant's rows.
     *
     * So the check is not "is it scoped" but "if it is not scoped, did this
     * class say so" — which is the only version that can tell a deliberate
     * exemption from somebody else's side effect.
     */
    public function test_no_resource_is_unscoped_without_declaring_it_itself(): void
    {
        $unscoped = [];

        // Both panels, because the slot they share is one slot: the write that
        // exempted a resource on /admin unscoped every resource on /app too.
        foreach (['admin', 'app'] as $panel) {
            foreach (Filament::getPanel($panel)->getResources() as $resource) {
                if ($resource::isScopedToTenant()) {
                    continue;
                }

                $declaredHere = (new ReflectionProperty($resource, 'isScopedToTenant'))
                    ->getDeclaringClass()
                    ->getName() === $resource;

                if (! $declaredHere) {
                    $unscoped[] = "{$panel}: {$resource}";
                }
            }
        }

        sort($unscoped);

        $this->assertSame([], $unscoped, implode("\n", [
            'These resources are not tenant-scoped, and did not ask to be exempt.',
            'They are reading a shared static that something else wrote — look for a',
            'scopeToTenant(false) call, and replace it with a declared property on',
            'the resource that wants the exemption.',
            '',
            ...$unscoped,
        ]));
    }
}

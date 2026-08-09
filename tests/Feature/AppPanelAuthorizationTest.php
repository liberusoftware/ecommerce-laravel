<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Collections\CollectionResource;
use App\Models\User;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ArticleResource and CollectionResource shipped with no policy. Filament's
 * get_authorization_response() returns allow() when no policy exists, so every
 * member of every team had full CRUD on Articles and ProductCollections —
 * CollectionResource's form includes price.
 *
 * The fix is two-sided and only works as a pair: a policy alone would have denied
 * everyone (including super_admin), because the Shield permission set had no
 * article_* or product::collection_* entries to grant. So these tests pin both
 * halves — the deny, the allow, and that the seeded super_admin still works.
 *
 * Permission suffixes follow the seeded convention: model basename, snake_cased,
 * with '_' replaced by '::' (Product => product, ProductCategory =>
 * product::category). Hence ProductCollection => product::collection.
 *
 * Article is gone — deleted rather than moved to the CMS that owns it, because
 * its table held `id` and `timestamps()` and nothing else (ADR 0012). The
 * paragraph above keeps its name because the fault was a *pair*, and a reader
 * who meets `article_*` in the Shield seeder should find out here why it has no
 * model rather than assume the seeder is wrong.
 */
class AppPanelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string, string}> */
    public static function unprotectedResources(): array
    {
        return [
            'collections' => [CollectionResource::class, 'product::collection'],
        ];
    }

    /** The gap: no policy meant Filament allowed everything. */
    #[Test]
    #[DataProvider('unprotectedResources')]
    public function a_team_member_without_permissions_is_denied(string $resource, string $suffix): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $this->assertFalse($resource::canViewAny(), $resource.' must not be viewable without permission.');
        $this->assertFalse($resource::canCreate(), $resource.' must not be creatable without permission.');
        $this->assertFalse($resource::canDeleteAny(), $resource.' must not be bulk-deletable without permission.');
    }

    /** The other half: the policy must actually grant when the permission is held. */
    #[Test]
    #[DataProvider('unprotectedResources')]
    public function a_user_holding_the_permissions_is_allowed(string $resource, string $suffix): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        foreach (['view_any', 'create', 'delete_any'] as $affix) {
            $user->givePermissionTo(Permission::findOrCreate($affix.'_'.$suffix, 'web'));
        }

        $this->actingAs($user);

        $this->assertTrue($resource::canViewAny(), $resource.' must be viewable with view_any_'.$suffix);
        $this->assertTrue($resource::canCreate(), $resource.' must be creatable with create_'.$suffix);
        $this->assertTrue($resource::canDeleteAny(), $resource.' must be bulk-deletable with delete_any_'.$suffix);
    }

    /** A policy checking a permission nobody seeds would lock out the whole feature. */
    #[Test]
    #[DataProvider('unprotectedResources')]
    public function the_seeder_ships_the_permissions_the_policy_checks(string $resource, string $suffix): void
    {
        $this->seed(PermissionsTableSeeder::class);

        foreach ([
            'view', 'view_any', 'create', 'update', 'delete', 'delete_any',
            'force_delete', 'force_delete_any', 'restore', 'restore_any',
            'replicate', 'reorder',
        ] as $affix) {
            $this->assertDatabaseHas('permissions', [
                'name' => $affix.'_'.$suffix,
                'guard_name' => 'web',
            ]);
        }
    }

    /**
     * RolesSeeder syncs super_admin to every web permission, so appending to
     * PermissionsTableSeeder is enough — there is no Gate::before bypass in this
     * app, super_admin is only ever as powerful as the seeded permission set.
     */
    #[Test]
    #[DataProvider('unprotectedResources')]
    public function the_seeded_super_admin_can_still_manage_the_resource(string $resource, string $suffix): void
    {
        $this->seed(PermissionsTableSeeder::class);
        $this->seed(RolesSeeder::class);

        $user = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $this->actingAs($user);

        $this->assertTrue($resource::canViewAny(), 'super_admin must keep access to '.$resource);
        $this->assertTrue($resource::canCreate(), 'super_admin must keep access to '.$resource);
        $this->assertTrue($resource::canDeleteAny(), 'super_admin must keep access to '.$resource);
    }
}

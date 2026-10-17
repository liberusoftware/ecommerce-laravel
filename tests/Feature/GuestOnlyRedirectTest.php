<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestOnlyRedirectTest extends TestCase
{
    use RefreshDatabase;

    public static function guestOnlyPages(): array
    {
        return [['/login'], ['/register'], ['/forgot-password']];
    }

    #[DataProvider('guestOnlyPages')]
    public function test_a_signed_in_user_is_redirected_to_a_page_that_exists(string $page): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get($page)->assertRedirect();

        $this->get($response->headers->get('Location'))->assertOk();
    }
}

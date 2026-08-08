<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use JoelButcher\Socialstream\Contracts\CreatesUserFromProvider;
use JoelButcher\Socialstream\Contracts\GeneratesProviderRedirect;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * Social login runs entirely on the package's defaults, and that is deliberate.
 *
 * This repo shipped `App\Providers\SocialstreamServiceProvider` and eight
 * published actions under `App\Actions\Socialstream`, none of them registered
 * (#936). Every one was byte-identical to its upstream counterpart apart from
 * the namespace and two deltas, both of which were losses:
 *
 *   - the app's `GenerateRedirectForProvider` had dropped the
 *     `socialstream.previous_url` session write the callback reads to send a
 *     visitor back where they came from;
 *   - the app's provider bound `CreateUserFromProvider` unconditionally, while
 *     the package picks `CreateUserWithTeamsFromProvider` when Jetstream has
 *     team features — which this app enables.
 *
 * So registering the provider, the obvious reading of #936, would have been the
 * regression: social signups would have stopped getting a personal team. The
 * files were deleted instead. These two tests are what keeps that shut.
 */
class SocialstreamDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function providerUser(): SocialiteUser
    {
        // setToken because `connected_accounts.token` is NOT NULL.
        return (new SocialiteUser)->setRaw([])->setToken('fake-token')->map([
            'id' => '12345',
            'nickname' => 'ada',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            // Empty rather than a URL: a real one would send
            // setProfilePhotoFromUrl out to fetch it.
            'avatar' => '',
        ]);
    }

    public function test_a_social_signup_gets_a_personal_team(): void
    {
        $user = app(CreatesUserFromProvider::class)->create('github', $this->providerUser());

        $this->assertTrue(
            $user->ownedTeams()->where('personal_team', true)->exists(),
            'A user created through social login has no personal team, so they can reach no panel.',
        );
    }

    public function test_the_redirect_records_where_the_visitor_came_from(): void
    {
        $this->startSession();

        // Enough for Socialite to build a redirect URL. It is a URL builder —
        // nothing here reaches GitHub.
        config(['services.github' => [
            'client_id' => 'id',
            'client_secret' => 'secret',
            'redirect' => '/oauth/github/callback',
        ]]);

        app(GeneratesProviderRedirect::class)->generate('github');

        $this->assertTrue(
            session()->has('socialstream.previous_url'),
            'Nothing recorded the origin, so the OAuth callback has nowhere to send the visitor back to.',
        );
    }
}

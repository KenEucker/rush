<?php

use App\Models\MemberProfile;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost');
});

it('authenticates both roles using a regenerated cookie session and exposes only their identity', function (string $role) {
    $profile = MemberProfile::factory()->create();
    $profile->membership->update(['role' => $role]);
    $user = $profile->membership->user;
    $this->getJson('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
    $oldId = session()->getId();

    $response = $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('memberships.0.role', $role)
        ->assertJsonCount(1, 'memberships')->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.permissions')->assertHeader('Cache-Control', 'no-store, private');
    expect(session()->getId())->not->toBe($oldId);
    $this->assertAuthenticatedAs($user);
    $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('user.id', $user->id);
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
    expect($cookie->isHttpOnly())->toBeTrue()->and($cookie->getSameSite())->toBe('lax');
})->with(['ranger', 'management']);

it('denies invalid credentials and inactive or missing memberships without identifying accounts', function () {
    $inactive = OrganizationMembership::factory()->create(['is_active' => false]);
    $noMembership = User::factory()->create();
    foreach ([$inactive->user->email, $noMembership->email, 'missing@example.test'] as $email) {
        $this->postJson('/login', ['email' => $email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'The provided credentials could not be verified.');
        $this->assertGuest();
    }
});

it('rate limits repeated login attempts', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/login', ['email' => 'bad@example.test', 'password' => 'wrong'])->assertUnprocessable();
    }
    $this->postJson('/login', ['email' => 'bad@example.test', 'password' => 'wrong'])->assertTooManyRequests();
});

it('rejects a wrong password and malformed credentials without starting a session', function () {
    $user = OrganizationMembership::factory()->create()->user;
    $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
    $this->postJson('/login', ['email' => ['invalid'], 'password' => 'wrong'])->assertUnprocessable();
    $this->assertGuest();
});

it('denies unauthenticated and bearer-token access', function () {
    $this->getJson('/api/v1/session')->assertUnauthorized();
    $this->withToken('1|untrusted-token')->getJson('/api/v1/session')->assertUnauthorized();
});

it('invalidates the session and csrf token on logout and isolates the next account', function () {
    $first = OrganizationMembership::factory()->create()->user;
    $second = OrganizationMembership::factory()->create()->user;
    $this->postJson('/login', ['email' => $first->email, 'password' => 'password'])->assertOk();
    $oldId = session()->getId();
    $oldToken = session()->token();
    $this->postJson('/login', ['email' => $second->email, 'password' => 'password'])->assertConflict();
    $this->postJson('/logout')->assertNoContent();
    $this->assertGuest('web');
    expect(session()->getId())->not->toBe($oldId)->and(session()->token())->not->toBe($oldToken);
    Auth::forgetGuards();
    $this->getJson('/api/v1/session')->assertUnauthorized();
    $this->postJson('/login', ['email' => $second->email, 'password' => 'password'])->assertOk()
        ->assertJsonPath('user.id', $second->id)->assertJsonMissing(['email' => $first->email]);
});

it('enforces real csrf validation on login logout and authenticated writes', function () {
    // Laravel skips forgery middleware under APP_ENV=testing. Exercise its real path.
    $this->app->instance('env', 'local');
    $profile = MemberProfile::factory()->create();
    $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
    $credentials = ['email' => $profile->membership->user->email, 'password' => 'password'];
    $this->postJson('/login', $credentials)->assertStatus(419);
    $this->withHeader('X-CSRF-TOKEN', session()->token())->postJson('/login', $credentials)->assertOk();
    $this->withHeader('X-CSRF-TOKEN', 'invalid');
    $this->postJson('/logout')->assertStatus(419);
    $this->patchJson("/api/v1/organizations/{$profile->organization_id}/profiles/{$profile->id}", ['display_name' => 'Forged'])->assertStatus(419);
    $this->withHeader('X-CSRF-TOKEN', session()->token())->postJson('/logout')->assertNoContent();
});

it('marks the production session cookie secure', function () {
    config(['session.secure' => true]);
    $response = $this->getJson('/sanctum/csrf-cookie');
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
    expect($cookie->isSecure())->toBeTrue()->and($cookie->isHttpOnly())->toBeTrue();
});

it('rejects replay of a signed-out or expired database session cookie', function (bool $expire) {
    config(['session.driver' => 'database']);
    $this->withCredentials();
    $user = OrganizationMembership::factory()->create()->user;
    $response = $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    $cookie = $response->getCookie(config('session.cookie'), false)->getValue();
    $sessionId = session()->getId();
    session()->flush();
    Auth::forgetGuards();
    $this->withUnencryptedCookie(config('session.cookie'), $cookie)
        ->getJson('/api/v1/session')->assertOk()->assertJsonPath('user.id', $user->id);
    if ($expire) {
        DB::table('sessions')->where('id', $sessionId)
            ->update(['last_activity' => now()->subMinutes(config('session.lifetime') + 1)->timestamp]);
    } else {
        $this->withUnencryptedCookie(config('session.cookie'), $cookie)->postJson('/logout')->assertNoContent();
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
    }
    // Simulate a fresh HTTP request instead of reusing the test container's session attributes.
    session()->flush();
    Auth::forgetGuards();
    $this->withUnencryptedCookie(config('session.cookie'), $cookie)
        ->getJson('/api/v1/session')->assertUnauthorized();
})->with([false, true]);

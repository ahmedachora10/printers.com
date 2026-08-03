<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

describe('Authentication', function () {
    test('login page renders successfully', function () {
        $this->get('/login')->assertStatus(200);
    });

    test('user can login with username and password', function () {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    });

    test('login fails with wrong password and returns generic arabic error', function () {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username' => 'بيانات الدخول غير صحيحة']);
    });

    test('login fails with unknown username and returns generic arabic error (no enumeration)', function () {
        $response = $this->post('/login', [
            'username' => 'nonexistent_user',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username' => 'بيانات الدخول غير صحيحة']);
    });

    test('email field is not accepted as login credential', function () {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    });

    test('super-admin is redirected to dashboard after login', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->addRole('super-admin');

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    });

    test('agent is redirected to the portal after login', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->addRole('agent');

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('agent-portal.index', absolute: false));
    });

    test('logout invalidates server session and redirects to home', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    });

    test('authenticated user cannot access login page', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/login')->assertRedirect('/dashboard');
    });
});

describe('Deactivated accounts', function () {
    test('a deactivated user cannot login even with correct credentials', function () {
        $user = User::factory()->create(['is_active' => false]);

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username' => 'هذا الحساب معطّل، راجع الإدارة']);
    });

    test('a user deactivated mid-session is logged out on the next request', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        User::query()->whereKey($user->id)->update(['is_active' => false]);

        // The test app is reused between requests; drop the resolved guard so the
        // next request re-reads the user from the session like a real one does.
        $this->app['auth']->forgetGuards();

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    });

    test('an active user is not affected by the active-account check', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertAuthenticated();
    });
});

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Entrar');
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'segredo123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'segredo123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create(['password' => 'segredo123', 'active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'segredo123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_only_admin_manages_users(): void
    {
        $this->actingAs(User::factory()->create())->get('/usuarios')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/usuarios')->assertOk();
    }
}

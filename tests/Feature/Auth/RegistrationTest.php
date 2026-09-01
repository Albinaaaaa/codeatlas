<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register_and_open_the_authenticated_application()
    {
        $response = $this
            ->withSession(['url.intended' => '/projects/999'])
            ->post(route('register.store'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('dashboard', absolute: false));
        $response->assertSessionMissing('url.intended');
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
        $this->assertAuthenticated();

        $this->get(route('dashboard'))
            ->assertOk();
    }
}

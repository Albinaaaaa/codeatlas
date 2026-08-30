<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppearanceTest extends TestCase
{
    public function test_dark_appearance_cookie_is_applied_to_the_initial_document(): void
    {
        $this->withUnencryptedCookie('appearance', 'dark')
            ->get(route('home'))
            ->assertOk()
            ->assertSee('<html lang="en" class="dark">', false);
    }

    public function test_invalid_appearance_cookie_falls_back_to_system(): void
    {
        $this->withUnencryptedCookie('appearance', 'invalid')
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('<html lang="en" class="dark">', false)
            ->assertSee("const appearance = 'system';", false);
    }
}

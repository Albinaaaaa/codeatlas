<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_switch_the_interface_to_ukrainian(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('projects.index'))
            ->patch(route('locale.update'), ['locale' => 'uk'])
            ->assertStatus(303)
            ->assertSessionHas('locale', 'uk')
            ->assertRedirect(route('projects.index'));

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee('<html lang="uk"', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('localization.locale', 'uk')
                ->where('localization.translations.navigation.projects', 'Проєкти')
                ->where(
                    'localization.translations.projects.show.empty_title',
                    'Джерело ще не підключено.',
                ),
            );
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $this->from(route('home'))
            ->patch(route('locale.update'), ['locale' => 'fr'])
            ->assertSessionHasErrors('locale')
            ->assertRedirect(route('home'));

        $this->assertNull(session('locale'));
    }

    public function test_project_validation_messages_are_available_in_ukrainian(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['locale' => 'uk'])
            ->post(route('projects.store'), ['name' => ''])
            ->assertSessionHasErrors([
                'name' => 'Поле «назва» є обов’язковим.',
            ]);
    }
}

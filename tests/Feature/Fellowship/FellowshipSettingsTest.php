<?php

namespace Tests\Feature\Fellowship;

use App\Filament\Pages\FellowshipSettingsPage;
use App\Support\Fellowship\FellowshipSettings;
use Livewire\Livewire;

/**
 * The one global Fellowship setting: the advisory per-question time limit.
 */
class FellowshipSettingsTest extends FellowshipTestCase
{
    public function test_the_limit_falls_back_to_configuration_before_one_is_saved(): void
    {
        config(['fellowship.question_time_limit_seconds' => 75]);

        $this->assertSame(75, FellowshipSettings::questionTimeLimitSeconds());
    }

    public function test_a_saved_limit_takes_precedence_over_configuration(): void
    {
        config(['fellowship.question_time_limit_seconds' => 75]);

        FellowshipSettings::setQuestionTimeLimitSeconds(120);

        $this->assertSame(120, FellowshipSettings::questionTimeLimitSeconds());
    }

    public function test_the_limit_is_never_zero_or_negative(): void
    {
        FellowshipSettings::setQuestionTimeLimitSeconds(0);

        $this->assertSame(1, FellowshipSettings::questionTimeLimitSeconds());
    }

    public function test_the_setting_is_not_exposed_by_the_public_settings_endpoint(): void
    {
        FellowshipSettings::setQuestionTimeLimitSeconds(90);

        $this->assertDatabaseHas('settings', [
            'key' => FellowshipSettings::QUESTION_TIME_LIMIT_KEY,
            'is_public' => false,
        ]);

        $response = $this->getJson('/api/public/settings')->assertOk();

        $this->assertStringNotContainsString(
            FellowshipSettings::QUESTION_TIME_LIMIT_KEY,
            $response->getContent(),
        );
    }

    public function test_an_admin_with_update_permission_can_change_the_limit(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        Livewire::test(FellowshipSettingsPage::class)
            ->assertSuccessful()
            ->fillForm(['question_time_limit_seconds' => 45])
            ->call('save');

        $this->assertSame(45, FellowshipSettings::questionTimeLimitSeconds());
    }

    public function test_the_limit_must_be_at_least_one_second(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        Livewire::test(FellowshipSettingsPage::class)
            ->fillForm(['question_time_limit_seconds' => 0])
            ->call('save')
            ->assertHasFormErrors(['question_time_limit_seconds']);
    }

    public function test_a_read_only_admin_cannot_change_the_limit(): void
    {
        FellowshipSettings::setQuestionTimeLimitSeconds(90);

        $this->actingAs($this->adminWithPermissions(['fellowships.view']), 'admin');

        $this->assertTrue(FellowshipSettingsPage::canAccess());

        Livewire::test(FellowshipSettingsPage::class)
            ->fillForm(['question_time_limit_seconds' => 10])
            ->call('save');

        $this->assertSame(90, FellowshipSettings::questionTimeLimitSeconds());
    }

    public function test_an_admin_without_fellowship_view_cannot_open_the_page(): void
    {
        $this->actingAs($this->adminWithPermissions(['subjects.view']), 'admin');

        $this->assertFalse(FellowshipSettingsPage::canAccess());
    }
}

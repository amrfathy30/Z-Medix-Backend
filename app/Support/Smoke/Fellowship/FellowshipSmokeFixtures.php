<?php

namespace App\Support\Smoke\Fellowship;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Models\Admin;
use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipQuestion;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The accounts a Fellowship smoke run needs, and the removal of whatever a
 * previous run left behind.
 *
 * Nothing here creates a Fellowship or a question: the Fellowship is created
 * through the admin panel's own Create page and every question arrives through
 * the import wizard, because the point of the run is that those paths work. What
 * this class owns is the two accounts the run acts as, and a cleanup narrow
 * enough to be safe to run against a developer's own database.
 *
 * Cleanup removes only rows carrying {@see self::MARKER} in their name, plus the
 * two fixed smoke accounts. No table is truncated, no migration or seeder is
 * invoked, and a Fellowship an admin created by hand is left alone unless they
 * happened to name it with the marker.
 */
class FellowshipSmokeFixtures
{
    /** Every record this run creates carries this marker in its name. */
    public const MARKER = 'FELLOWSHIP_SMOKE_';

    public const FELLOWSHIP_NAME = self::MARKER.'Internal Medicine Board';

    /** A second Fellowship, so cross-Fellowship isolation can be checked. */
    public const OTHER_FELLOWSHIP_NAME = self::MARKER.'Surgical Board Isolation';

    public const STUDENT_EMAIL = 'fellowship.smoke.student@example.com';

    public const STUDENT_NAME = self::MARKER.'Smoke Student';

    /** A second student, so one student cannot reach another's session. */
    public const OTHER_STUDENT_EMAIL = 'fellowship.smoke.intruder@example.com';

    public const OTHER_STUDENT_NAME = self::MARKER.'Smoke Intruder';

    /** Local-only fixed password, so a run is reproducible. */
    public const STUDENT_PASSWORD = 'SmokeLocal#2026';

    public const STUDENT_PHONE = '01010000222';

    public const OTHER_STUDENT_PHONE = '01010000333';

    /** A deliberately under-privileged admin, to check authorization refuses. */
    public const VIEWER_ADMIN_EMAIL = 'fellowship.smoke.viewer@example.com';

    public const VIEWER_ADMIN_NAME = self::MARKER.'Smoke Viewer Admin';

    /**
     * An admin for signing into the panel in a browser.
     *
     * Separate from the installation's own super-admin so that a browser run
     * never needs that account's password, and separate from the viewer above so
     * the viewer can stay under-privileged for the authorization checks.
     */
    public const BROWSER_ADMIN_EMAIL = 'fellowship.smoke.admin@example.com';

    public const BROWSER_ADMIN_NAME = self::MARKER.'Smoke Browser Admin';

    /**
     * Remove the previous run's records, and nothing else.
     *
     * Questions are deleted before their Fellowship rather than left to the
     * cascade: a question's Performance Topic is a `RESTRICT` reference to a
     * filter value, and the filter values go the moment the Fellowship's filters
     * cascade, so removing the Fellowship first can be refused by the database.
     *
     * @return array<string, int>
     */
    public function cleanPreviousRun(): array
    {
        return DB::transaction(function (): array {
            $removed = [
                'fellowships' => 0,
                'questions' => 0,
                'imports' => 0,
                'sessions' => 0,
                'students' => 0,
                'admins' => 0,
                'phone_numbers' => 0,
                'api_tokens' => 0,
            ];

            $fellowships = Fellowship::withTrashed()
                ->where('name', 'like', self::MARKER.'%')
                ->get();

            foreach ($fellowships as $fellowship) {
                $removed['sessions'] += $fellowship->quizSessions()->count();
                $removed['imports'] += $fellowship->imports()->count();

                // The designation has to go before the filter it points at, or
                // the `SET NULL` on the Fellowship fires against a row the
                // cascade is already removing.
                $fellowship->update(['performance_filter_id' => null]);

                $removed['questions'] += FellowshipQuestion::withTrashed()
                    ->where('fellowship_id', $fellowship->getKey())
                    ->forceDelete();

                FellowshipFilter::query()
                    ->where('fellowship_id', $fellowship->getKey())
                    ->delete();

                $fellowship->forceDelete();
                $removed['fellowships']++;
            }

            foreach ([self::STUDENT_EMAIL, self::OTHER_STUDENT_EMAIL] as $email) {
                foreach (User::withTrashed()->where('email', $email)->get() as $student) {
                    $removed['api_tokens'] += $this->forgetTokens($student);
                    $removed['phone_numbers'] += PhoneNumber::withTrashed()
                        ->where('owner_type', $student->getMorphClass())
                        ->where('owner_id', $student->getKey())
                        ->forceDelete();

                    $student->forceDelete();
                    $removed['students']++;
                }
            }

            $adminEmails = [self::VIEWER_ADMIN_EMAIL, self::BROWSER_ADMIN_EMAIL];

            foreach (Admin::query()->whereIn('email', $adminEmails)->get() as $admin) {
                $removed['api_tokens'] += $this->forgetTokens($admin);
                $admin->forceDelete();
                $removed['admins']++;
            }

            return $removed;
        });
    }

    /**
     * The admin the import wizard is driven as: the installation's own
     * super-admin, so the run exercises the real privilege set rather than one
     * invented for it.
     */
    public function importingAdmin(): ?Admin
    {
        return Admin::query()
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))
            ->orderBy('id')
            ->first()
            ?? Admin::query()->orderBy('id')->first();
    }

    /**
     * An admin holding only the Fellowship *view* permissions, so the run can
     * check that creating and importing are actually refused rather than merely
     * hidden.
     */
    public function viewerAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Admin::query()->firstOrCreate(
            ['email' => self::VIEWER_ADMIN_EMAIL],
            [
                'name' => self::VIEWER_ADMIN_NAME,
                'password' => self::STUDENT_PASSWORD,
            ],
        );

        $admin->syncRoles([]);
        $admin->syncPermissions(['fellowships.view', 'fellowship_questions.view', 'fellowship_filters.view']);

        return $admin->refresh();
    }

    /**
     * An admin that can sign into the panel in a browser and drive the import
     * wizard: the full Fellowship permission set, and a fixed local-only
     * password so a browser run is reproducible.
     *
     * Given the permissions directly rather than a role, so what the browser run
     * exercises is exactly the Fellowship surface and nothing else in the panel.
     */
    public function browserAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Admin::query()->firstOrCreate(
            ['email' => self::BROWSER_ADMIN_EMAIL],
            [
                'name' => self::BROWSER_ADMIN_NAME,
                'password' => self::STUDENT_PASSWORD,
                'status' => AccountStatus::Active,
                'type' => AdminType::Admin,
            ],
        );

        $admin->forceFill([
            'password' => Hash::make(self::STUDENT_PASSWORD),
            'status' => AccountStatus::Active,
        ])->save();

        $admin->syncRoles([]);
        $admin->syncPermissions([
            'fellowships.view',
            'fellowships.create',
            'fellowships.update',
            'fellowships.delete',
            'fellowship_questions.view',
            'fellowship_questions.create',
            'fellowship_questions.update',
            'fellowship_questions.delete',
            'fellowship_filters.view',
            'fellowship_filters.create',
            'fellowship_filters.update',
            'fellowship_filters.delete',
        ]);

        return $admin->refresh();
    }

    /**
     * Snapshot of every Fellowship table's row count, so the run can prove it
     * changed nothing outside its own records.
     *
     * @return array<string, int>
     */
    public function tableCounts(): array
    {
        $tables = [
            'fellowships',
            'fellowship_filters',
            'fellowship_filter_values',
            'fellowship_questions',
            'fellowship_question_options',
            'fellowship_question_filter_values',
            'fellowship_imports',
            'fellowship_import_rows',
            'fellowship_quiz_sessions',
            'fellowship_session_questions',
            'fellowship_session_periods',
        ];

        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * The same counts restricted to rows this run does *not* own, which is the
     * figure that must not move.
     *
     * @param  list<int>  $smokeFellowshipIds
     * @param  list<int>  $smokeUserIds
     * @return array<string, int>
     */
    public function foreignRowCounts(array $smokeFellowshipIds, array $smokeUserIds): array
    {
        $fellowshipIds = $smokeFellowshipIds === [] ? [0] : $smokeFellowshipIds;
        $userIds = $smokeUserIds === [] ? [0] : $smokeUserIds;

        return [
            'fellowships' => (int) DB::table('fellowships')->whereNotIn('id', $fellowshipIds)->count(),
            'fellowship_filters' => (int) DB::table('fellowship_filters')->whereNotIn('fellowship_id', $fellowshipIds)->count(),
            'fellowship_questions' => (int) DB::table('fellowship_questions')->whereNotIn('fellowship_id', $fellowshipIds)->count(),
            'fellowship_imports' => (int) DB::table('fellowship_imports')->whereNotIn('fellowship_id', $fellowshipIds)->count(),
            'fellowship_quiz_sessions' => (int) DB::table('fellowship_quiz_sessions')
                ->whereNotIn('fellowship_id', $fellowshipIds)
                ->whereNotIn('user_id', $userIds)
                ->count(),
        ];
    }

    private function forgetTokens(Admin|User $owner): int
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', $owner->getMorphClass())
            ->where('tokenable_id', $owner->getKey())
            ->forceDelete();
    }
}

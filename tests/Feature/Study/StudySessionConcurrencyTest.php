<?php

namespace Tests\Feature\Study;

use App\Exceptions\Learning\StudyFlowException;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\User;
use App\Services\Learning\StudySessionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Real processes and row locks in a throwaway schema, never the app database.
 */
class StudySessionConcurrencyTest extends TestCase
{
    #[DataProvider('operations')]
    public function test_lifecycle_row_locking(string $scenario): void
    {
        if (! extension_loaded('pdo_mysql') || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('MySQL PDO and pcntl are required.');
        }

        $schema = 'zmedix_session_test_'.strtolower(Str::random(12));
        $connection = config('database.connections.mysql');
        $connection['url'] = null;
        $connection['database'] = null;
        config(['database.connections.session_test_admin' => $connection]);
        try {
            DB::connection('session_test_admin')->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('A local MySQL server is required.');
        }

        $default = DB::getDefaultConnection();
        $directory = sys_get_temp_dir().'/'.$schema;
        mkdir($directory, 0700);
        $children = [];

        try {
            DB::connection('session_test_admin')->statement("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $connection['database'] = $schema;
            config(['database.connections.session_test' => $connection]);
            DB::setDefaultConnection('session_test');
            Artisan::call('migrate', ['--database' => 'session_test', '--force' => true, '--no-interaction' => true]);
            $student = User::factory()->create();
            $other = $scenario === 'unrelated' ? User::factory()->create() : $student;
            $subjects = Subject::factory()->count(2)->create();
            $existing = in_array($scenario, ['start_end', 'end_start', 'end_end'])
                ? app(StudySessionService::class)->start($student, $subjects[0]) : null;
            $students = [$student->id, $other->id];
            DB::disconnect('session_test');
            DB::disconnect('session_test_admin');

            foreach ($students as $index => $studentId) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    try {
                        DB::purge('session_test');
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
                        $user = User::findOrFail($studentId);
                        $subjectIndex = $scenario === 'same' ? 0 : ($scenario === 'start_end' ? 1 : $index);
                        $subject = Subject::findOrFail($subjects[$subjectIndex]->id);
                        $stale = $existing?->fresh();
                        if ($index === 0) {
                            DB::listen(function (QueryExecuted $query) use ($directory, $scenario): void {
                                if (str_contains($query->sql, 'from `users`') && str_contains($query->sql, 'for update')) {
                                    file_put_contents($directory.'/locked', '1');
                                    if ($scenario === 'unrelated') {
                                        $this->awaitFile($directory.'/result-1');
                                    } else {
                                        usleep(250000);
                                    }
                                }
                            });
                        }
                        file_put_contents($directory.'/ready-'.$index, '1');
                        $this->awaitFile($directory.'/go');
                        if ($index === 1) {
                            $this->awaitFile($directory.'/locked');
                        }
                        $service = app(StudySessionService::class);
                        $shouldEnd = $scenario === 'end_end' || ($scenario === 'start_end' && $index === 1) || ($scenario === 'end_start' && $index === 0);
                        $session = $shouldEnd ? $service->end($user, $stale) : $service->start($user, $subject);
                        $result = ['id' => $session->id, 'ended_at' => $session->ended_at?->toIso8601String(), 'duration' => $session->duration_seconds];
                    } catch (StudyFlowException) {
                        $result = ['error' => 'already_ended'];
                    } catch (Throwable $exception) {
                        $result = ['failure' => get_class($exception).': '.$exception->getMessage()];
                    }
                    file_put_contents($directory.'/result-'.$index, json_encode($result));
                    DB::disconnect('session_test');
                    exit(0);
                }
                $children[] = $pid;
            }

            $this->awaitFile($directory.'/ready-0');
            $this->awaitFile($directory.'/ready-1');
            file_put_contents($directory.'/go', '1');
            $this->awaitFile($directory.'/result-0');
            $this->awaitFile($directory.'/result-1');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $children = [];
            $results = array_map(fn (int $index): array => json_decode(file_get_contents($directory.'/result-'.$index), true), [0, 1]);
            foreach ($results as $result) {
                $this->assertArrayNotHasKey('failure', $result, json_encode($result));
            }
            $this->assertSame($scenario === 'end_end' ? 0 : 1, $student->studySessions()->open()->count());
            if ($scenario === 'same') {
                $this->assertSame($results[0]['id'], $results[1]['id']);
                $this->assertSame(1, StudySession::count());
            } elseif ($scenario === 'different') {
                $old = StudySession::findOrFail($results[0]['id']);
                $next = StudySession::findOrFail($results[1]['id']);
                $this->assertTrue($old->ended_at->equalTo($next->started_at));
                $this->assertSame(2, StudySession::count());
            } elseif ($scenario === 'end_end') {
                $this->assertSame(['error' => 'already_ended'], $results[1]);
                $this->assertSame($results[0]['ended_at'], $existing->refresh()->ended_at->toIso8601String());
                $this->assertSame($results[0]['duration'], $existing->duration_seconds);
            } elseif ($scenario === 'start_end') {
                $this->assertSame(['error' => 'already_ended'], $results[1]);
                $this->assertTrue($existing->refresh()->hasEnded());
            } elseif ($scenario === 'end_start') {
                $this->assertNotSame($existing->id, $results[1]['id']);
                $this->assertTrue($existing->refresh()->hasEnded());
            } else {
                $this->assertSame(1, $other->studySessions()->open()->count());
            }
        } finally {
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            DB::disconnect('session_test');
            DB::setDefaultConnection($default);
            DB::connection('session_test_admin')->statement("DROP DATABASE IF EXISTS `{$schema}`");
            DB::purge('session_test');
            DB::purge('session_test_admin');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! file_exists($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Concurrent test barrier timed out: '.basename($path));
            }
            usleep(10000);
        }
    }

    public static function operations(): array
    {
        return [
            'same subject starts' => ['same'],
            'different subject starts' => ['different'],
            'start racing end' => ['start_end'],
            'end before start' => ['end_start'],
            'end racing end with stale models' => ['end_end'],
            'unrelated student finishes while first user remains locked' => ['unrelated'],
        ];
    }
}

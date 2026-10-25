<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\BillingCycle;
use App\Exceptions\Subscription\SubscriptionException;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\SubscriptionPeriod;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Services\Subscription\RecordedPayment;
use App\Services\Subscription\SubscriptionService;
use Database\Factories\SubscriptionPeriodFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * Uses a throwaway MySQL schema because SQLite cannot exercise FOR UPDATE.
 * Never migrates, truncates or writes to the configured application database.
 */
class PromoCodeConcurrencyTest extends TestCase
{
    #[DataProvider('contenders')]
    public function test_concurrent_submissions_serialize_capacity_and_student_requests(bool $sameStudent, bool $simulateDeadlock = false, bool $withoutPromo = false, bool $approve = false): void
    {
        if (! extension_loaded('pdo_mysql') || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('MySQL PDO and pcntl are required for the row-lock integration test.');
        }

        $schema = 'zmedix_promo_test_'.strtolower(Str::random(12));
        $connection = config('database.connections.mysql');
        $connection['url'] = null;
        $connection['database'] = null;
        config(['database.connections.promo_test_admin' => $connection]);
        try {
            DB::connection('promo_test_admin')->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('A local MySQL server is required for the row-lock integration test.');
        }

        $default = DB::getDefaultConnection();
        $directory = sys_get_temp_dir().'/'.$schema;
        mkdir($directory, 0700);
        $children = [];

        try {
            DB::connection('promo_test_admin')->statement("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $connection['database'] = $schema;
            config(['database.connections.promo_test' => $connection]);
            DB::setDefaultConnection('promo_test');
            Artisan::call('migrate', ['--database' => 'promo_test', '--force' => true, '--no-interaction' => true]);
            Event::fakeExcept(['eloquent.creating: '.PromoCodeUsage::class]);
            $plan = Plan::factory()->create();
            $otherPlan = $sameStudent && ! $withoutPromo ? $plan : Plan::factory()->create();
            $plans = [$plan->id, $otherPlan->id];
            $promo = PromoCode::factory()->create(['max_usage_limit' => $simulateDeadlock ? 2 : 1]);
            $first = User::factory()->create();
            $second = $sameStudent ? $first : User::factory()->create();
            $students = [$first->id, $second->id];
            $pendingId = null;
            if ($approve) {
                Storage::fake('local');
                $pendingId = app(SubscriptionService::class)->submitRequest($first, $plan, BillingCycle::Monthly, $promo->code)->id;
            }
            DB::disconnect('promo_test');
            DB::disconnect('promo_test_admin');

            foreach ($students as $index => $studentId) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    try {
                        DB::purge('promo_test');
                        if ($simulateDeadlock && $index === 0) {
                            $usageAttempts = 0;
                            PromoCodeUsage::creating(function () use (&$usageAttempts, $directory): void {
                                $usageAttempts++;
                                file_put_contents($directory.'/usage-attempts', (string) $usageAttempts);
                                if ($usageAttempts === 1) {
                                    throw new QueryException(
                                        'promo_test', 'insert into promo_code_usages', [],
                                        new \PDOException('Deadlock found when trying to get lock; try restarting transaction', 40001),
                                    );
                                }
                            });
                        }
                        file_put_contents($directory.'/ready-'.$index, 'ready');
                        $deadline = microtime(true) + 10;
                        while (! file_exists($directory.'/go')) {
                            if (microtime(true) > $deadline) {
                                throw new \RuntimeException('Concurrent test start timed out.');
                            }
                            usleep(10000);
                        }
                        if ($approve) {
                            app(SubscriptionService::class)->recordPeriod(new RecordedPayment(
                                student: User::findOrFail($studentId), plan: Plan::findOrFail($plans[$index]),
                                cycle: BillingCycle::Monthly, amount: 10, proof: SubscriptionPeriodFactory::fakeProof(),
                                request: SubscriptionRequest::findOrFail($pendingId),
                            ));
                        } else {
                            app(SubscriptionService::class)->submitRequest(
                                User::findOrFail($studentId), Plan::findOrFail($plans[$index]), BillingCycle::Monthly,
                                $withoutPromo ? null : $promo->code,
                            );
                        }
                        $result = $approve ? 'approved' : 'reserved';
                    } catch (SubscriptionException $exception) {
                        $result = $exception->errorCode;
                    } catch (Throwable $exception) {
                        $result = get_class($exception).': '.$exception->getMessage();
                    }
                    file_put_contents($directory.'/result-'.$index, $result);
                    DB::disconnect('promo_test');
                    exit(0);
                }
                $children[] = $pid;
            }

            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($directory.'/ready-*'));
            file_put_contents($directory.'/go', 'go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $children = [];
            $results = [file_get_contents($directory.'/result-0'), file_get_contents($directory.'/result-1')];
            sort($results);
            $expected = $sameStudent ? ['PROMO_CODE_ALREADY_USED', 'reserved'] : ['PROMO_CODE_EXHAUSTED', 'reserved'];
            if ($simulateDeadlock) {
                $expected = ['reserved', 'reserved'];
                $this->assertSame('2', file_get_contents($directory.'/usage-attempts'));
            }
            if ($withoutPromo) {
                $expected = ['reserved', 'reserved'];
            } elseif ($approve) {
                $expected = ['REQUEST_NOT_PENDING', 'approved'];
            }
            sort($expected);
            $this->assertSame($expected, $results);
            $this->assertSame($withoutPromo || $approve ? 0 : ($simulateDeadlock ? 2 : 1), PromoCodeUsage::query()->where('status', 'reserved')->count());
            $this->assertSame($approve ? 0 : ($simulateDeadlock ? 2 : 1), SubscriptionRequest::query()->pending()->count());
            if ($sameStudent) {
                $this->assertSame(1, SubscriptionRequest::query()->where('user_id', $first->id)->count());
            }
            if ($approve) {
                $this->assertSame(1, SubscriptionPeriod::query()->count());
                $this->assertSame(1, PromoCodeUsage::query()->where('status', 'consumed')->count());
            }
        } finally {
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            DB::disconnect('promo_test');
            DB::setDefaultConnection($default);
            DB::connection('promo_test_admin')->statement("DROP DATABASE IF EXISTS `{$schema}`");
            DB::purge('promo_test');
            DB::purge('promo_test_admin');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function contenders(): array
    {
        return [
            'different students competing for one slot' => [false],
            'same student submitting twice' => [true],
            'deadlock retry after request insert' => [false, true],
            'same student changing plans without promo' => [true, false, true],
            'two admins approving the same request' => [true, false, false, true],
        ];
    }
}

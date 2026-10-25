<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\BillingCycle;
use App\Exceptions\Subscription\SubscriptionException;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
    public function test_concurrent_submissions_serialize_capacity_and_student_requests(bool $sameStudent): void
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
            Event::fake();
            $plan = Plan::factory()->create();
            $otherPlan = $sameStudent ? $plan : Plan::factory()->create();
            $plans = [$plan->id, $otherPlan->id];
            $promo = PromoCode::factory()->create(['max_usage_limit' => 1]);
            $first = User::factory()->create();
            $second = $sameStudent ? $first : User::factory()->create();
            $students = [$first->id, $second->id];
            DB::disconnect('promo_test');
            DB::disconnect('promo_test_admin');

            foreach ($students as $index => $studentId) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    try {
                        DB::purge('promo_test');
                        file_put_contents($directory.'/ready-'.$index, 'ready');
                        $deadline = microtime(true) + 10;
                        while (! file_exists($directory.'/go')) {
                            if (microtime(true) > $deadline) {
                                throw new \RuntimeException('Concurrent test start timed out.');
                            }
                            usleep(10000);
                        }
                        app(SubscriptionService::class)->submitRequest(
                            User::findOrFail($studentId), Plan::findOrFail($plans[$index]), BillingCycle::Monthly, $promo->code,
                        );
                        $result = 'reserved';
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
            $expected = $sameStudent ? ['reserved', 'reserved'] : ['PROMO_CODE_EXHAUSTED', 'reserved'];
            sort($expected);
            $this->assertSame($expected, $results);
            $this->assertSame(1, PromoCodeUsage::query()->where('status', 'reserved')->count());
            $this->assertSame(1, SubscriptionRequest::query()->pending()->count());
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
        return ['different students competing for one slot' => [false], 'same student submitting twice' => [true]];
    }
}

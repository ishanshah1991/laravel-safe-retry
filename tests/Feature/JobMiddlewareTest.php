<?php

namespace IshanShah\SafeRetry\Tests\Feature;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Event;
use IshanShah\SafeRetry\Events\DuplicateJobSkipped;
use IshanShah\SafeRetry\Exceptions\IdempotencyKeyInUseException;
use IshanShah\SafeRetry\Facades\SafeRetry;
use IshanShah\SafeRetry\Jobs\SafeRetryJob;
use IshanShah\SafeRetry\Tests\TestCase;

class ChargeJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public static int $runs = 0;

    public static bool $throw = false;

    public function __construct(public int $orderId) {}

    public function middleware(): array
    {
        return [new SafeRetryJob("charge:{$this->orderId}")];
    }

    public function handle(): void
    {
        static::$runs++;

        if (static::$throw) {
            throw new \RuntimeException('gateway down');
        }
    }
}

class KeyedJob extends ChargeJob
{
    public function middleware(): array
    {
        return [new SafeRetryJob];
    }

    public function safeRetryKey(): string
    {
        return (string) $this->orderId;
    }
}

class JobMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ChargeJob::$runs = 0;
        ChargeJob::$throw = false;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'sync');
    }

    public function test_a_job_with_the_same_key_runs_once(): void
    {
        Event::fake([DuplicateJobSkipped::class]);

        dispatch(new ChargeJob(1));
        dispatch(new ChargeJob(1));
        dispatch(new ChargeJob(2));

        $this->assertSame(2, ChargeJob::$runs);
        Event::assertDispatched(DuplicateJobSkipped::class, fn ($e) => $e->key === 'charge:1');
        $this->assertTrue(SafeRetry::hasJob('charge:1'));
    }

    public function test_a_failed_job_is_not_marked_done(): void
    {
        ChargeJob::$throw = true;

        try {
            dispatch(new ChargeJob(1));
        } catch (\RuntimeException) {
        }

        ChargeJob::$throw = false;
        dispatch(new ChargeJob(1));

        $this->assertSame(2, ChargeJob::$runs);
    }

    public function test_forget_job(): void
    {
        dispatch(new ChargeJob(1));
        SafeRetry::forgetJob('charge:1');
        dispatch(new ChargeJob(1));

        $this->assertSame(2, ChargeJob::$runs);
    }

    public function test_key_from_the_job_method(): void
    {
        dispatch(new KeyedJob(5));
        dispatch(new KeyedJob(5));

        $this->assertSame(1, ChargeJob::$runs);
        $this->assertTrue(SafeRetry::hasJob(KeyedJob::class.':5'));
    }

    public function test_a_duplicate_running_at_the_same_time_is_released(): void
    {
        $storageKey = hash('sha256', 'job|charge:1');
        SafeRetry::store()->acquireLock($storageKey, 60);

        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->expects($this->once())->method('release')->with(10);

        $job = (new ChargeJob(1))->setJob($queueJob);
        (new SafeRetryJob('charge:1'))->handle($job, fn ($j) => $j->handle());

        $this->assertSame(0, ChargeJob::$runs);
    }

    public function test_a_duplicate_without_a_queue_job_throws(): void
    {
        SafeRetry::store()->acquireLock(hash('sha256', 'job|charge:1'), 60);

        $this->expectException(IdempotencyKeyInUseException::class);
        (new SafeRetryJob('charge:1'))->handle(new ChargeJob(1), fn ($j) => $j->handle());
    }

    public function test_a_released_job_is_not_marked_done(): void
    {
        $queueJob = $this->createStub(QueueJob::class);
        $queueJob->method('isReleased')->willReturn(true);

        $job = (new ChargeJob(1))->setJob($queueJob);
        (new SafeRetryJob('charge:1'))->handle($job, fn ($j) => $j->handle());

        $this->assertFalse(SafeRetry::hasJob('charge:1'));
    }
}

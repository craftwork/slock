<?php
declare(strict_types=1);

use Craftwork\Slock\Lock\Memcached\Semaphore;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class SemaphoreTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testSingleRequestSuccessfulLock()
    {
        $memcached = Mockery::mock(\Memcached::class);
        $memcached->shouldIgnoreMissing();
        $memcached
            ->shouldReceive('get')
            ->andReturn(1, 0)
        ;
        $memcached
            ->shouldReceive('getResultCode')
            ->andReturn(\Memcached::RES_SUCCESS)
        ;
        $memcached
            ->shouldReceive('cas')
            ->with(null, "semaphore_test", 0) // it decrements the semaphore first
            ->with(null, "semaphore_test", 1) // then returns it to the original value in "release", freeing it
        ;

        $semaphore = new Semaphore($memcached);
        $semaphore->acquire("test");
        $semaphore->release();
    }

    public function testSemaphoreAlreadyInUse()
    {
        $memcached = Mockery::mock(\Memcached::class);
        $memcached->shouldIgnoreMissing();
        $memcached
            ->shouldReceive('get')
            ->andReturn(0, 1, 0) // first time it tries to get the semaphore is in use, then it's freed
        ;
        $memcached
            ->shouldReceive('getResultCode')
            ->andReturn(\Memcached::RES_SUCCESS)
        ;
        $memcached
            ->shouldReceive('cas')
            ->with(null, "semaphore_test", 0) // it decrements the semaphore first
            ->with(null, "semaphore_test", 1) // then returns it to the original value in "release", freeing it
        ;

        $semaphore = new Semaphore($memcached);
        $semaphore->acquire("test");
        $semaphore->release();
    }

    public function testSemaphoreMissingReadd()
    {
        $memcached = Mockery::mock(\Memcached::class);
        $memcached->shouldIgnoreMissing();
        $memcached
            ->shouldReceive('get')
            ->andReturn(null, 1, 0)
        ;
        $memcached
            ->shouldReceive('getResultCode')
            ->andReturn(\Memcached::RES_NOTFOUND, \Memcached::RES_NOTFOUND, \Memcached::RES_SUCCESS)
        ;
        $memcached
            ->shouldReceive('add')
            ->twice()
        ;

        $semaphore = new Semaphore($memcached);
        $semaphore->acquire("test");
        $semaphore->release();
    }

    public function testLostCas()
    {
        $memcached = Mockery::mock(\Memcached::class);
        $memcached->shouldIgnoreMissing();
        $memcached
            ->shouldReceive('get')
            ->andReturn(1, 1, 0) // gets value, another request gets it and locks it before we lock it, so we go back
                                 // to start
        ;
        $memcached
            ->shouldReceive('getResultCode')
            ->andReturn(
                \Memcached::RES_SUCCESS,
                \Memcached::RES_SUCCESS,
                \Memcached::RES_DATA_EXISTS,
                \Memcached::RES_DATA_EXISTS,
                \Memcached::RES_SUCCESS,
                \Memcached::RES_SUCCESS
            )
        ;
        $memcached
            ->shouldReceive('cas')
            ->with(null, "semaphore_test", 0) // it decrements the semaphore first
            ->with(null, "semaphore_test", 1) // then returns it to the original value in "release", freeing it
        ;

        $semaphore = new Semaphore($memcached);
        $semaphore->acquire("test");
        $semaphore->release();
    }

    public function testSemaphoreAlteredExternally()
    {
        $memcached = Mockery::mock(\Memcached::class);
        $memcached->shouldIgnoreMissing();
        $memcached
            ->shouldReceive('get')
            ->andReturn(1, null, 1, 0) // the null shouldn't happen unless the semaphore was modified externally or lost
        ;
        $memcached
            ->shouldReceive('getResultCode')
            ->andReturn(\Memcached::RES_SUCCESS)
        ;
        $memcached
            ->shouldReceive('cas')
            ->with(null, "semaphore_test", 0) // it decrements the semaphore
            ->with(null, "semaphore_test", 0) // it decrements the semaphore again (after it was modified and recreated)
            ->with(null, "semaphore_test", 1) // then returns it to the original value in "release", freeing it
        ;

        $semaphore = new Semaphore($memcached);
        $semaphore->acquire("test");
        $semaphore->release();
    }
}

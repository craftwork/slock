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
        /** @var Memcached|Mockery\Mock $memcached */
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
            ->with(null, "semaphore_SESSID", 0) // it decrements the semaphore first
            ->with(null, "semaphore_SESSID", 1) // then returns it to the original value in "release", freeing it
        ;

        $semaphore = new Semaphore($memcached);
        $semaphore->acquire("test");
        $semaphore->release();
    }

    public function testSemaphoreAlreadyInUse()
    {
        /** @var Memcached|Mockery\Mock $memcached */
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
            ->with(null, "semaphore_SESSID", 0) // it decrements the semaphore first
            ->with(null, "semaphore_SESSID", 1) // then returns it to the original value in "release", freeing it
        ;

        $semaphore = new Semaphore($memcached);
        $semaphore->acquire("test");
        $semaphore->release();
    }

    public function testSemaphoreMissingReadd()
    {
        /** @var Memcached|Mockery\Mock $memcached */
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
        /** @var Memcached|Mockery\Mock $memcached */
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
            ->with(null, "semaphore_SESSID", 0) // it decrements the semaphore first
            ->with(null, "semaphore_SESSID", 1) // then returns it to the original value in "release", freeing it
        ;

        $semaphore = new Semaphore($memcached);
        $semaphore->acquire("test");
        $semaphore->release();
    }

    public function testSemaphoreAlteredExternally()
    {
        /** @var Memcached|Mockery\Mock $memcached */
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
            ->with(null, "semaphore_SESSID", 0) // it decrements the semaphore
            ->with(null, "semaphore_SESSID", 0) // it decrements the semaphore again (after it was modified and recreated)
            ->with(null, "semaphore_SESSID", 1) // then returns it to the original value in "release", freeing it
        ;

        $semaphore = new Semaphore($memcached);
        $semaphore->acquire("test");
        $semaphore->release();
    }

    public function testDestroyError()
    {
        /** @var Memcached|Mockery\Mock $memcached */
        $memcached = Mockery::mock(\Memcached::class);
        $memcached->shouldIgnoreMissing();
        $memcached
            ->shouldReceive('delete')
            ->with('fifo_queue_SESSID');

        $memcached
            ->shouldReceive('getResultCode')
            ->andReturn(\Memcached::RES_CONNECTION_SOCKET_CREATE_FAILURE);

        $this->expectException(\Craftwork\Slock\SlockException::class);

        $semaphore = new Semaphore($memcached);
        $semaphore->destroy('SESSID');
    }

    public function testDestroySuccess()
    {
        /** @var Memcached|Mockery\Mock $memcached */
        $memcached = Mockery::mock(\Memcached::class);
        $memcached
            ->shouldReceive('delete')
            ->with('semaphore_SESSID');

        $memcached
            ->shouldReceive('getResultCode')
            ->andReturn(\Memcached::RES_SUCCESS);

        $semaphore = new Semaphore($memcached);
        $semaphore->destroy('SESSID');
    }
}

<?php
declare(strict_types=1);

use Craftwork\Slock\Lock\Memcached\FifoQueue;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class FifoQueueTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testSingleRequestSuccessfulLock()
    {
        /** @var Memcached|Mockery\Mock $memcached */
        $memcached = Mockery::mock(Memcached::class);
        $fifoQueue = new FifoQueue($memcached);
        $item = $this->getItem($fifoQueue);
        $sessionId = 'SESSID';
        $queueName = 'fifo_queue_' . $sessionId;

        $this->assertEquals(FifoQueue::ITEM_SIZE, strlen($item));

        $memcached
            ->shouldReceive('add')
            ->with($queueName, $item);

        $memcached
            ->shouldReceive('getResultCode')
            ->andReturn(
                \Memcached::RES_NOTSTORED, // When trying to create the queue (it should already exist)
                \Memcached::RES_SUCCESS,   // When appending the item to the queue
                \Memcached::RES_SUCCESS,   // When getting the queue to check the item position (it will be first)
                \Memcached::RES_SUCCESS,   // When getting the queue to check it's still okay before releasing
                \Memcached::RES_SUCCESS,   // When checking the results of removing the item from the queue
                \Memcached::RES_SUCCESS    // Same as above
            );

        $memcached
            ->shouldReceive('append')
            ->with($queueName, $item);

        $memcached
            ->shouldReceive('get')
            ->andReturn($item);

        // Removing the item from the queue. Being the only item in the queue it should end up empty
        $memcached
            ->shouldReceive('cas')
            ->with(null, $queueName, '');

        $fifoQueue->acquire($sessionId);
        $fifoQueue->release();
    }

    public function testSingleRequestCreateQueueSuccessfulLock()
    {
        /** @var Memcached|Mockery\Mock $memcached */
        $memcached = Mockery::mock(Memcached::class);
        $fifoQueue = new FifoQueue($memcached);
        $item = $this->getItem($fifoQueue);
        $sessionId = 'SESSID';
        $queueName = 'fifo_queue_' . $sessionId;

        $this->assertEquals(FifoQueue::ITEM_SIZE, strlen($item));

        $memcached
            ->shouldReceive('add')
            ->with($queueName, $item);

        $memcached
            ->shouldReceive('getResultCode')
            ->andReturn(
                \Memcached::RES_SUCCESS,   // When trying to create the queue (doesn't exist so creates it)
                \Memcached::RES_SUCCESS,   // When getting the queue to check the item position (it will be first)
                \Memcached::RES_SUCCESS,   // When getting the queue to check it's still okay before releasing
                \Memcached::RES_SUCCESS,   // When checking the results of removing the item from the queue
                \Memcached::RES_SUCCESS    // Same as above
            );

        $memcached
            ->shouldReceive('append')
            ->never();

        $memcached
            ->shouldReceive('get')
            ->andReturn($item);

        // Removing the item from the queue. Being the only item in the queue it should end up empty
        $memcached
            ->shouldReceive('cas')
            ->with(null, $queueName, '');

        $fifoQueue->acquire($sessionId);
        $fifoQueue->release();
    }

    public function testMultiRequestSuccessfulLock()
    {
        /** @var Memcached|Mockery\Mock $memcached */
        $memcached = Mockery::mock(Memcached::class);
        $fifoQueue = new FifoQueue($memcached);
        $item = $this->getItem($fifoQueue);
        $pretendItem1 = random_bytes(FifoQueue::ITEM_SIZE);
        $pretendItem2 = random_bytes(FifoQueue::ITEM_SIZE);
        $sessionId = 'SESSID';
        $queueName = 'fifo_queue_' . $sessionId;

        $this->assertEquals(FifoQueue::ITEM_SIZE, strlen($item));

        $memcached
            ->shouldReceive('add')
            ->with($queueName, $item);

        $memcached
            ->shouldReceive('getResultCode')
            ->andReturn(
                \Memcached::RES_NOTSTORED, // When trying to create the queue (it should already exist)
                \Memcached::RES_SUCCESS,   // When appending the item to the queue
                \Memcached::RES_SUCCESS,   // When getting the queue to check the item position (it will be second)
                \Memcached::RES_SUCCESS,   // When getting the queue to check the item position (it will be first)
                \Memcached::RES_SUCCESS,   // When getting the queue to check it's still okay before releasing
                \Memcached::RES_SUCCESS,   // When checking the results of removing the item from the queue
                \Memcached::RES_SUCCESS    // Same as above
            );

        $memcached
            ->shouldReceive('append')
            ->with($queueName, $item);

        // Scenario:
        // $pretendItem1 was already in the queue and unlocks itself successfully, and $pretendItem2 is appended
        // whilst we're waiting for the queue to unlock.
        $memcached
            ->shouldReceive('get')
            ->andReturn($pretendItem1 . $item, $item . $pretendItem2);

        // Removing the item from the queue. $pretendItem2 was appended to the queue after the current item so it
        // should still be in the queue after we've popped the current item from it.
        $memcached
            ->shouldReceive('cas')
            ->with(null, $queueName, $pretendItem2);

        $fifoQueue->acquire($sessionId);
        $fifoQueue->release();
    }

    public function testDestroySuccess()
    {
        /** @var Memcached|Mockery\Mock $memcached */
        $memcached = Mockery::mock(\Memcached::class);
        $memcached
            ->shouldReceive('delete')
            ->with('fifo_queue_SESSID');

        $memcached
            ->shouldReceive('getResultCode')
            ->andReturn(\Memcached::RES_SUCCESS);

        $fifoQueue = new FifoQueue($memcached);
        $fifoQueue->destroy('SESSID');
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

        $fifoQueue = new FifoQueue($memcached);
        $fifoQueue->destroy('SESSID');
    }

    private function getItem(FifoQueue $fifoQueue): string
    {
        $itemGetter = \Closure::bind(function () {
            return $this->item;
        }, $fifoQueue, FifoQueue::class);
        return $itemGetter();
    }
}

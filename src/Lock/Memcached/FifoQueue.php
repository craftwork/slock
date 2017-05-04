<?php
namespace Slock\Strategy\Memcached;

use Slock\SlockException;
use Slock\Strategy\StrategyInterface;

/**
 * Uses memcache as a store for creating a FIFO queue for the user's session.
 *
 * @package Slock\Strategy\Memcached
 */
final class FifoQueue implements StrategyInterface
{
    const ITEM_SIZE = 15;

    /**
     * @var \Memcached
     */
    private $memcache;

    /**
     * @var string
     */
    private $item;

    /**
     * @var string
     */
    private $queue;

    /**
     * @var string
     */
    private $first;

    /**
     * @var int
     */
    private $firstRemoveTimer;

    /**
     * @var int
     */
    private $removeFirstItemFromQueueAfterSeconds;

    public function __construct(\Memcached $memcache, int $removeFirstItemFromQueueAfterSeconds = 0)
    {
        $this->memcache = $memcache;
        $this->removeFirstItemFromQueueAfterSeconds = $removeFirstItemFromQueueAfterSeconds;
        $this->item = random_bytes(static::ITEM_SIZE);
    }

    public function acquire(string $sessionId)
    {
        $this->queue = static::queueName($sessionId);

        $acquired = false;
        do {
            /*
             * Create the queue or append this item to it.
             * The reason for the do-while loop is if the queue is deleted
             * or altered externally then we recreate it or append the
             * item to it again.
             */

            $this->createOrAppend();

            /*
             * Now wait until we're first in the queue. If the item disappears
             * from the queue then getPosition will return -1 and we'll append
             * it again.
             */

            $items = $this->memcache->get($this->queue) ?: "";
            $this->checkResults([\Memcached::RES_NOTFOUND, \Memcached::RES_SUCCESS]);
            while (static::getPosition($items, $this->item) > 0) {

                // Sleep a moment so we don't hammer the memcached instance.
                usleep(10000);

                /*
                 * If the first item in the queue has been the same for at
                 * least the configured amount of time then remove it from the
                 * queue.
                 */
                $this->checkToRemoveFirstItem();

                // Now reacquire the queue and check item's position.
                $items = $this->memcache->get($this->queue) ?: "";
                $this->checkResults([\Memcached::RES_NOTFOUND, \Memcached::RES_SUCCESS]);
            }

            if (static::getPosition($items, $this->item) === 0) {
                $acquired = true;
            }

            /*
             * Implied: if getPosition returned -1 then we've lost our place
             * in the queue. Possibly the memcached server was restarted or
             * the value was reset for some reason. Best thing we can do is
             * join the back of the queue again.
             */

        } while (!$acquired);
    }

    public function release()
    {
        /*
         * "cas" means store only if the queue hasn't been modified in between
         * getting it and trying to modify it. If the queue was modified in the
         * meantime (eg. by another item being appended) then try again.
         */

        do {
            $items = $this->memcache->get($this->queue, null, $cas) ?: "";
            $this->checkResults([\Memcached::RES_NOTFOUND, \Memcached::RES_SUCCESS]);

            // The queue was invalidated externally, eg. by a restart or flush.
            if (static::getPosition($items, $this->item) !== 0) {
                return;
            }

            $this->memcache->cas($cas, $this->queue, substr($items, static::ITEM_SIZE));
        } while ($this->memcache->getResultCode() === \Memcached::RES_DATA_EXISTS);

        $this->checkResults([\Memcached::RES_SUCCESS]);
    }

    public function destroy(string $sessionId)
    {
        $this->memcache->delete(static::queueName($sessionId));
        $this->checkResults([\Memcached::RES_NOTFOUND, \Memcached::RES_SUCCESS]);
    }

    private static function queueName(string $sessionId): string
    {
        return "fifo_queue_$sessionId";
    }

    private static function getItem($items, $i): string
    {
        return substr($items, $i * static::ITEM_SIZE, static::ITEM_SIZE);
    }

    private static function getPosition($items, $item): int
    {
        $itemCount = strlen($items) / static::ITEM_SIZE;
        for ($i = 0; $i < $itemCount; $i++) {
            if (static::getItem($items, $i) === $item) {
                return $i;
            }
        }

        return -1;
    }

    private function createOrAppend()
    {
        $this->memcache->add($this->queue, $this->item);
        if ($this->memcache->getResultCode() === \Memcached::RES_NOTSTORED) {
            $this->memcache->append($this->queue, $this->item);
        }

        $this->checkResults([\Memcached::RES_SUCCESS]);
    }

    private function checkResults(array $validCodes)
    {
        if (!in_array($this->memcache->getResultCode(), $validCodes, true)) {
            throw new SlockException(sprintf("Memcached error: %s", $this->memcache->getResultMessage()));
        }
    }

    private function checkToRemoveFirstItem()
    {
        if ($this->removeFirstItemFromQueueAfterSeconds <= 0) {
            return;
        }

        $items = $this->memcache->get($this->queue, null, $cas);
        $first = static::getItem($items, 0);
        if ($first !== $this->first) {
            $this->first = $first;
            $this->firstRemoveTimer = time();
        }

        if (time() - $this->firstRemoveTimer > $this->removeFirstItemFromQueueAfterSeconds) {
            $this->memcache->cas($cas, $this->queue, substr($items, static::ITEM_SIZE));
        }

        $this->checkResults([\Memcached::RES_SUCCESS, \Memcached::RES_DATA_EXISTS, \Memcached::RES_NOTFOUND]);
    }
}

<?php declare(strict_types=1);
namespace Slock\Lock\Memcached;

use \Slock\SlockException;
use \Slock\Lock\LockInterface;

final class Semaphore implements LockInterface
{
    /**
     * @var \Memcached
     */
    private $memcache;

    /**
     * @var string
     */
    private $semaphore;

    /**
     * @var int
     */
    private $value;

    /**
     * @var float
     */
    private $cas;

    public function __construct(\Memcached $memcache)
    {
        $this->memcache = $memcache;
    }

    public function acquire(string $sessionId)
    {
        $this->semaphore = static::getName($sessionId);
        $acquired = false;
        $cas = null;

        do {
            /*
             * Create the semaphore if it doesn't already exist, and retrieve
             * the value. The reason for the do-while loop is if the semaphore
             * is deleted or altered externally then we recreate it and
             * retrieve the value again.
             */

            $this->memcache->add($this->semaphore, 1);
            $this->value = $this->memcache->get($this->semaphore, null, $cas);
            $this->checkResults([\Memcached::RES_NOTFOUND, \Memcached::RES_SUCCESS]);

            /*
             * If the semaphore is already in use, or has been removed from
             * memcache then sleep and try again.
             */
            if ($this->value < 1 || $this->memcache->getResultCode() === \Memcached::RES_NOTFOUND) {
                usleep(10000);
                continue;
            }

            /*
             * Decrement the value of the semaphore then attempt to store it
             * using cas, which will reject our attempt to store if the
             * data has been altered in the meantime.
             */
            $this->value--;
            $this->memcache->cas($cas, $this->semaphore, $this->value);
            $this->checkResults([\Memcached::RES_NOTFOUND, \Memcached::RES_SUCCESS, \Memcached::RES_DATA_EXISTS]);
            if ($this->memcache->getResultCode() !== \Memcached::RES_SUCCESS) {
                continue;
            }

            /*
             * So we've now got the lock. Reacquire its value with a cas which
             * we'll use when we release the semaphore later.
             * If there has been any alteration to the semaphore value since it
             * was retrieved then start again. This won't happen from the code,
             * but could happen if the server was restarted for example.
             */
            $reacquired = $this->memcache->get($this->semaphore, null, $this->cas);
            $this->checkResults([\Memcached::RES_SUCCESS, \Memcached::RES_NOTFOUND]);
            if ($reacquired == $this->value) {
                $acquired = true;
            }
        } while (!$acquired);
    }

    public function release()
    {
        // Only release if it's still the value we stored.
        $this->memcache->cas($this->cas, $this->semaphore, ++$this->value);
        $this->checkResults([\Memcached::RES_SUCCESS, \Memcached::RES_NOTFOUND, \Memcached::RES_DATA_EXISTS]);
    }

    public function destroy(string $sessionId)
    {
        $this->memcache->delete(static::getName($sessionId));
        $this->checkResults([\Memcached::RES_SUCCESS, \Memcached::RES_NOTFOUND]);
    }

    private static function getName(string $sessionId): string
    {
        return "semaphore_$sessionId";
    }

    private function checkResults(array $validCodes)
    {
        if (!in_array($this->memcache->getResultCode(), $validCodes, true)) {
            throw new SlockException(sprintf("Memcached error: %s", $this->memcache->getResultMessage()));
        }
    }
}

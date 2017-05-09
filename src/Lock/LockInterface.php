<?php declare(strict_types=1);
namespace Slock\Lock;

interface LockInterface
{
    /**
     * @param string $sessionId
     * @throws \Slock\SlockException
     */
    public function acquire(string $sessionId);

    /**
     * @throws \Slock\SlockException
     */
    public function release();

    /**
     * @param string $sessionId
     * @throws \Slock\SlockException
     */
    public function destroy(string $sessionId);
}

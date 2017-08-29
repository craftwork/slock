<?php
declare(strict_types=1);

namespace Craftwork\Slock\Lock;

interface LockInterface
{
    /**
     * @param string $sessionId
     * @throws \Craftwork\Slock\SlockException
     */
    public function acquire(string $sessionId);

    /**
     * @throws \Craftwork\Slock\SlockException
     */
    public function release();

    /**
     * @param string $sessionId
     * @throws \Craftwork\Slock\SlockException
     */
    public function destroy(string $sessionId);
}

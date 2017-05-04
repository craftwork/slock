<?php
namespace Slock\Lock;

interface LockInterface
{
    public function acquire(string $sessionId);
    public function release();
    public function destroy(string $sessionId);
}

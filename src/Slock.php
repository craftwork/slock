<?php declare(strict_types=1);
namespace Slock;

use Slock\Lock\LockInterface;

final class Slock implements \SessionHandlerInterface
{
    /**
     * @var \SessionHandlerInterface
     */
    private $handler;

    /**
     * @var LockInterface
     */
    private $lock;

    public function __construct(\SessionHandlerInterface $handler, LockInterface $lock)
    {
        $this->handler = $handler;
        $this->lock = $lock;
    }

    public function open($save_path, $name)
    {
        // acquire lock before opening the session

        $this->lock->acquire(session_id());
        $this->handler->open($save_path, $name);
    }

    public function close()
    {
        // close the session before unlocking it

        $this->handler->close();
        $this->lock->release();
    }

    public function destroy($session_id)
    {
        // destroy the session, then destroy the lock

        $this->handler->destroy($session_id);
        $this->lock->destroy($session_id);
    }

    public function gc($maxlifetime)
    {
        return $this->handler->gc($maxlifetime);
    }

    public function read($session_id)
    {
        return $this->handler->read($session_id);
    }

    public function write($session_id, $session_data)
    {
        return $this->handler->write($session_id, $session_data);
    }
}

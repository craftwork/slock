<?php
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
        $this->lock->acquire(session_id());
        $this->handler->open($save_path, $name);
    }

    public function close()
    {
        $this->handler->close();
        $this->lock->release();
    }

    public function destroy($session_id)
    {
        $this->lock->destroy($session_id);
        $this->handler->destroy($session_id);
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

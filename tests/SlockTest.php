<?php declare(strict_types=1);

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Slock\Slock;
use Slock\Lock\LockInterface;

class SlockTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testCorrectLockAcquireLogic()
    {
        $sessionHandler = Mockery::mock(SessionHandlerInterface::class);
        $lock = Mockery::mock(LockInterface::class);
        $savePath = "save path";
        $name = "name";
        $sessionId = "session ID";
        session_id($sessionId);

        $lock
            ->shouldReceive('acquire')
            ->with($sessionId)
            ->once()
            ->globally()
            ->ordered()
        ;

        $sessionHandler
            ->shouldReceive('open')
            ->with($savePath, $name)
            ->once()
            ->globally()
            ->ordered()
        ;

        $slock = new Slock($sessionHandler, $lock);
        $slock->open($savePath, $name);
    }

    public function testCorrectLockReleaseLogic()
    {
        $sessionHandler = Mockery::mock(SessionHandlerInterface::class);
        $lock = Mockery::mock(LockInterface::class);

        $sessionHandler
            ->shouldReceive('close')
            ->once()
            ->globally()
            ->ordered()
        ;

        $lock
            ->shouldReceive('release')
            ->once()
            ->globally()
            ->ordered()
        ;

        $slock = new Slock($sessionHandler, $lock);
        $slock->close();
    }

    public function testCorrectLockDestroyLogic()
    {
        $sessionHandler = Mockery::mock(SessionHandlerInterface::class);
        $lock = Mockery::mock(LockInterface::class);
        $sessionId = "session ID";
        session_id($sessionId);

        $sessionHandler
            ->shouldReceive('destroy')
            ->once()
            ->with($sessionId)
            ->globally()
            ->ordered()
        ;

        $lock
            ->shouldReceive('destroy')
            ->once()
            ->with($sessionId)
            ->globally()
            ->ordered()
        ;

        $slock = new Slock($sessionHandler, $lock);
        $slock->destroy($sessionId);
    }
}

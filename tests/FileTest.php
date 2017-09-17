<?php
declare(strict_types=1);

use Craftwork\Slock\Lock\FileLock;
use PHPUnit\Framework\TestCase;

class FileTest extends TestCase
{
    public function tearDown()
    {
        if (file_exists('./SESSID')) {
            unlink('./SESSID');
        }

        if (is_dir('./dir')) {
            $this->rrmdir('./dir');
        }
    }

    public function testSingleRequestSuccessfulLock()
    {
        $lock = new FileLock('./');
        $sessionId = 'SESSID';
        $lock->acquire($sessionId);

        $lockFile = fopen(sprintf('%s/%s', './', $sessionId), 'c');

        $this->assertFalse(flock($lockFile, LOCK_EX | LOCK_NB));

        $lock->release();

        $this->assertTrue(flock($lockFile, LOCK_EX | LOCK_NB));
        flock($lockFile, LOCK_UN);
    }

    public function testMultiRequestSuccessfulLock()
    {
        $lock1 = new FileLock('./');
        $lock2 = new FileLock('./');
        $sessionId = 'SESSID';

        $lock1->acquire($sessionId);

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new Exception('Unable to fork the process');
        } elseif ($pid) {
            // Parent
            $lock2->acquire($sessionId);

            // Verify that we can't lock the file externally.
            $lockFile = fopen(sprintf('%s/%s', './', $sessionId), 'c');

            $this->assertFalse(flock($lockFile, LOCK_EX | LOCK_NB));

            $lock2->release();

            // Verify that the file lock has been released.
            $this->assertTrue(flock($lockFile, LOCK_EX));
            flock($lockFile, LOCK_UN);

            pcntl_wait($status);
        } else {
            // Child - hold on to the lock for a little bit before releasing
            usleep(50000);
            $lock1->release();
            $this->assertTrue(true);
        }
    }

    public function testLockDirIsFile()
    {
        $this->expectException(InvalidArgumentException::class);
        new FileLock(__FILE__);
    }

    public function testLockDirIsNotWritable()
    {
        $this->expectException(InvalidArgumentException::class);
        mkdir('./dir', 0700, true);
        mkdir('./dir/readonly', 0400);
        new FileLock('./dir/readonly');
    }

    public function testDestroy()
    {
        touch('./SESSID');
        $lock = new FileLock('./');
        $lock->destroy('SESSID');
        $this->assertFalse(file_exists('./SESSID'));
    }

    /**
     * Shamelessly stolen from https://stackoverflow.com/a/13490957
     *
     * @param string $dir
     */
    private function rrmdir(string $dir)
    {
        foreach (glob($dir . '/*') as $file) {
            if (is_dir($file)) {
                $this->rrmdir($file);
            } else {
                unlink($file);
            }
        }

        rmdir($dir);
    }
}

<?php
declare(strict_types=1);

namespace Craftwork\Slock\Lock;

use Craftwork\Slock\SlockException;

final class FileLock implements LockInterface
{
    /**
     * @var resource
     */
    private $fh;

    /**
     * @var string
     */
    private $directory;

    /**
     * @param string $directory The full path to the directory to store lock files in.
     * @throws \InvalidArgumentException
     */
    public function __construct(string $directory)
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException(sprintf("Directory '%s' doesn't exist", $directory));
        }

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \InvalidArgumentException(sprintf("Directory '%s' isn't writable", $directory));
        }

        $this->directory = $directory;
    }

    /**
     * {@inheritdoc}
     */
    public function acquire(string $sessionId)
    {
        $fileName = sprintf('%s/%s', $this->directory, $sessionId);
        $this->fh = fopen($fileName, 'c');

        if (!$this->fh) {
            throw new SlockException(sprintf("Unable to open lock file '%s' for writing", $fileName));
        }

        if (!flock($this->fh, LOCK_EX)) {
            throw new SlockException(sprintf("Unable to acquire a lock on the lock file '%s'", $fileName));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function release()
    {
        flock($this->fh, LOCK_UN);
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(string $sessionId)
    {
        $fileName = sprintf('%s/%s', $this->directory, $sessionId);
        if (file_exists($fileName)) {
            unlink($fileName);
        }
    }
}

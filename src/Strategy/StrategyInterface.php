<?php
namespace Slock\Strategy;

interface StrategyInterface
{
    public function acquire(string $sessionId);
    public function release();
    public function destroy(string $sessionId);
}
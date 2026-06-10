<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RunLock;
use PHPUnit\Framework\TestCase;

final class RunLockTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/runlock-test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $lockDir = $this->projectDir.'/var/run';
        if (is_dir($lockDir)) {
            foreach (glob($lockDir.'/*.lock') ?: [] as $file) {
                @unlink($file);
            }
        }
        @rmdir($lockDir);
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    public function testSecondAcquireFromAnotherHolderFails(): void
    {
        $first = new RunLock($this->projectDir);
        $second = new RunLock($this->projectDir);

        self::assertTrue($first->acquire('import'));
        self::assertFalse($second->acquire('import'));
        self::assertTrue($first->isLocked('import'));
        self::assertTrue($second->isLocked('import'));
    }

    public function testAcquireIsIdempotentForTheSameHolder(): void
    {
        $lock = new RunLock($this->projectDir);

        self::assertTrue($lock->acquire('import'));
        self::assertTrue($lock->acquire('import'));
    }

    public function testReleaseFreesTheLockForOthers(): void
    {
        $first = new RunLock($this->projectDir);
        $second = new RunLock($this->projectDir);

        self::assertTrue($first->acquire('import'));
        $first->release('import');

        self::assertFalse($first->isLocked('import'));
        self::assertTrue($second->acquire('import'));
        $second->release('import');
    }

    public function testDifferentNamesDoNotBlockEachOther(): void
    {
        $first = new RunLock($this->projectDir);
        $second = new RunLock($this->projectDir);

        self::assertTrue($first->acquire('import'));
        self::assertTrue($second->acquire('dedup'));
    }

    public function testIsLockedIsFalseWithoutAnyLockFile(): void
    {
        $lock = new RunLock($this->projectDir);

        self::assertFalse($lock->isLocked('never-acquired'));
    }
}

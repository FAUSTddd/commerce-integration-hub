<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\CleanupImportFilesCliCommand;
use App\Entity\ImportRun;
use App\Repository\ImportRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupImportFilesCliCommandTest extends KernelTestCase
{
    private string $testDir;
    private ImportRunRepository $repository;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->repository = $container->get(ImportRunRepository::class);

        $this->testDir = sys_get_temp_dir() . '/cml_cleanup_test_' . uniqid();
        mkdir($this->testDir, 0777, true);

        $command = $container->get(CleanupImportFilesCliCommand::class);
        $this->commandTester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $files = glob($this->testDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file) || is_link($file)) {
                        @unlink($file);
                    }
                }
            }
            @rmdir($this->testDir);
        }

        parent::tearDown();
    }

    public function testDeletesFilesOlderThanTtl(): void
    {
        $oldFile = $this->testDir . '/old.xml';
        file_put_contents($oldFile, '<xml>old</xml>');
        touch($oldFile, time() - (25 * 3600)); // 25 hours old

        $freshFile = $this->testDir . '/fresh.xml';
        file_put_contents($freshFile, '<xml>fresh</xml>');
        touch($freshFile, time() - (2 * 3600)); // 2 hours old

        $this->commandTester->execute([
            '--dir' => $this->testDir,
            '--ttl-hours' => '24',
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        self::assertFileDoesNotExist($oldFile);
        self::assertFileExists($freshFile);
    }

    public function testDryRunDoesNotDeleteFiles(): void
    {
        $oldFile = $this->testDir . '/old.xml';
        file_put_contents($oldFile, '<xml>old</xml>');
        touch($oldFile, time() - (30 * 3600));

        $this->commandTester->execute([
            '--dir' => $this->testDir,
            '--ttl-hours' => '24',
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        self::assertFileExists($oldFile);
        self::assertStringContainsString('[DRY-RUN] Would delete "old.xml"', $this->commandTester->getDisplay());
    }

    public function testProtectsActiveImportRunFiles(): void
    {
        $activeFile = $this->testDir . '/active_import.xml';
        file_put_contents($activeFile, '<xml>active</xml>');
        touch($activeFile, time() - (48 * 3600)); // 48 hours old

        // Register active ImportRun
        $run = new ImportRun('catalog', 'active_import.xml', 'hash123');
        $run->setStatus(ImportRun::STATUS_PROCESSING);
        $this->repository->save($run);

        $this->commandTester->execute([
            '--dir' => $this->testDir,
            '--ttl-hours' => '24',
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        self::assertFileExists($activeFile, 'File bound to active ImportRun must not be deleted even if older than TTL');
        self::assertStringContainsString('Preserving "active_import.xml"', $this->commandTester->getDisplay());
    }

    public function testSkipsSymlinksAndHandlesNonExistentDirectory(): void
    {
        // Target non-existent dir
        $nonExistentDir = $this->testDir . '/does_not_exist';
        $this->commandTester->execute([
            '--dir' => $nonExistentDir,
        ]);
        $this->assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('does not exist, skipping', $this->commandTester->getDisplay());

        // Test symlink protection
        $targetFile = $this->testDir . '/target.xml';
        file_put_contents($targetFile, '<xml>target</xml>');
        touch($targetFile, time() - (50 * 3600));

        $symlink = $this->testDir . '/symlink.xml';
        symlink($targetFile, $symlink);

        $this->commandTester->execute([
            '--dir' => $this->testDir,
            '--ttl-hours' => '24',
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Skipping symlink "symlink.xml"', $this->commandTester->getDisplay());
    }

    public function testRejectsTtlHoursLessThanMinimum(): void
    {
        $this->commandTester->execute([
            '--dir' => $this->testDir,
            '--ttl-hours' => '0',
        ]);

        $this->assertSame(2, $this->commandTester->getStatusCode());
        self::assertStringContainsString('TTL hours option must be at least 1 hour(s)', $this->commandTester->getDisplay());

        $this->commandTester->execute([
            '--dir' => $this->testDir,
            '--ttl-hours' => '-5',
        ]);

        $this->assertSame(2, $this->commandTester->getStatusCode());
        self::assertStringContainsString('TTL hours option must be at least 1 hour(s)', $this->commandTester->getDisplay());
    }

    public function testAcceptsMinimumTtlOfOneHour(): void
    {
        $oldFile = $this->testDir . '/one_hour_old.xml';
        file_put_contents($oldFile, '<xml>old</xml>');
        touch($oldFile, time() - 3700); // 1 hour + 100 sec

        $this->commandTester->execute([
            '--dir' => $this->testDir,
            '--ttl-hours' => '1',
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        self::assertFileDoesNotExist($oldFile);
    }
}

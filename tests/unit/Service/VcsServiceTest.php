<?php

declare(strict_types=1);

namespace Service;

use OCA\GitCloud\Db\Snapshot;
use OCA\GitCloud\Db\SnapshotMapper;
use OCA\GitCloud\Service\VcsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class VcsServiceTest extends TestCase {
    private ?string $tmpRepoPath = null;

    protected function tearDown(): void {
        if ($this->tmpRepoPath !== null && is_dir($this->tmpRepoPath)) {
            exec('rm -rf ' . escapeshellarg($this->tmpRepoPath));
        }
        $this->tmpRepoPath = null;
    }

    public function testCommitChangesRecordsSnapshotWithHeadCommitHashAndNoParent(): void {
        $this->tmpRepoPath = sys_get_temp_dir() . '/gitcloud-test-' . uniqid();
        mkdir($this->tmpRepoPath);
        file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');

        $logger = $this->createMock(LoggerInterface::class);
        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getTime')->willReturn(1720000000);

        $snapshotMapper = $this->createMock(SnapshotMapper::class);
        $snapshotMapper->method('findAllForFile')->with('testuser', 'file1.txt')->willReturn([]);
        $snapshotMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Snapshot $snapshot): bool {
                return $snapshot->getUserId() === 'testuser'
                    && $snapshot->getFilePath() === 'file1.txt'
                    && $snapshot->getMessage() === 'Initial commit'
                    && $snapshot->getParentSnapshotId() === null
                    && $snapshot->getStatus() === 'committed'
                    && preg_match('/^[0-9a-f]{40}$/', $snapshot->getCommitHash()) === 1;
            }))
            ->willReturnArgument(0);

        $service = new VcsService($logger, $snapshotMapper, $timeFactory);

        $result = $service->commitChanges($this->tmpRepoPath, ['file1.txt'], 'Initial commit', 'testuser');

        $this->assertTrue($result['success']);
    }

    public function testCommitChangesUsesMostRecentSnapshotAsParent(): void {
        $this->tmpRepoPath = sys_get_temp_dir() . '/gitcloud-test-' . uniqid();
        mkdir($this->tmpRepoPath);
        file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');

        $logger = $this->createMock(LoggerInterface::class);
        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getTime')->willReturn(1720000000);

        $previousSnapshot = new Snapshot();
        $previousSnapshot->setId(7);

        $snapshotMapper = $this->createMock(SnapshotMapper::class);
        $snapshotMapper->method('findAllForFile')->with('testuser', 'file1.txt')->willReturn([$previousSnapshot]);
        $snapshotMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(fn (Snapshot $snapshot): bool => $snapshot->getParentSnapshotId() === 7))
            ->willReturnArgument(0);

        $service = new VcsService($logger, $snapshotMapper, $timeFactory);

        $result = $service->commitChanges($this->tmpRepoPath, ['file1.txt'], 'Second commit', 'testuser');

        $this->assertTrue($result['success']);
    }

    public function testCreateSnapshotRecordPopulatesAndInsertsSnapshot(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getTime')->willReturn(1720000000);

        $snapshotMapper = $this->createMock(SnapshotMapper::class);
        $snapshotMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Snapshot $snapshot): bool {
                return $snapshot->getUserId() === 'testuser'
                    && $snapshot->getFilePath() === 'file1.txt'
                    && $snapshot->getCommitHash() === 'abc123'
                    && $snapshot->getMessage() === 'Initial commit'
                    && $snapshot->getParentSnapshotId() === null
                    && $snapshot->getStatus() === 'committed'
                    && $snapshot->getCreatedAt() === 1720000000;
            }))
            ->willReturnArgument(0);

        $service = new VcsService($logger, $snapshotMapper, $timeFactory);

        $result = $service->createSnapshotRecord('testuser', 'file1.txt', 'abc123', 'Initial commit', null, 'committed');

        $this->assertSame('testuser', $result->getUserId());
    }

    public function testUpdateSnapshotStatusUpdatesAndPersistsStatus(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $timeFactory = $this->createMock(ITimeFactory::class);

        $existing = new Snapshot();
        $existing->setStatus('committed');

        $snapshotMapper = $this->createMock(SnapshotMapper::class);
        $snapshotMapper->method('find')->with(42)->willReturn($existing);
        $snapshotMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(fn (Snapshot $snapshot): bool => $snapshot->getStatus() === 'rolled_back'))
            ->willReturnArgument(0);

        $service = new VcsService($logger, $snapshotMapper, $timeFactory);

        $result = $service->updateSnapshotStatus(42, 'rolled_back');

        $this->assertSame('rolled_back', $result->getStatus());
    }

    public function testUpdateSnapshotStatusPropagatesDoesNotExistException(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $timeFactory = $this->createMock(ITimeFactory::class);

        $snapshotMapper = $this->createMock(SnapshotMapper::class);
        $snapshotMapper->method('find')->willThrowException(new DoesNotExistException('not found'));

        $service = new VcsService($logger, $snapshotMapper, $timeFactory);

        $this->expectException(DoesNotExistException::class);
        $service->updateSnapshotStatus(99, 'rolled_back');
    }
}

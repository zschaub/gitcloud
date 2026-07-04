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

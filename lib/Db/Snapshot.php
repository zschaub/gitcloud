<?php

declare(strict_types=1);

namespace OCA\GitCloud\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method void setUserId(string $userId)
 * @method string getUserId()
 * @method void setFilePath(string $filePath)
 * @method string getFilePath()
 * @method void setCommitHash(string $commitHash)
 * @method string getCommitHash()
 * @method void setMessage(string $message)
 * @method string getMessage()
 * @method void setParentSnapshotId(?int $parentSnapshotId)
 * @method int|null getParentSnapshotId()
 * @method void setStatus(string $status)
 * @method string getStatus()
 * @method void setCreatedAt(int $createdAt)
 * @method int getCreatedAt()
 */
class Snapshot extends Entity {
    protected string $userId = '';
    protected string $filePath = '';
    protected string $commitHash = '';
    protected string $message = '';
    protected ?int $parentSnapshotId = null;
    protected string $status = 'committed';
    protected int $createdAt = 0;

    public function __construct() {
        $this->addType('userId', Types::STRING);
        $this->addType('filePath', Types::STRING);
        $this->addType('commitHash', Types::STRING);
        $this->addType('message', Types::STRING);
        $this->addType('parentSnapshotId', Types::INTEGER);
        $this->addType('status', Types::STRING);
        $this->addType('createdAt', Types::INTEGER);
    }
}

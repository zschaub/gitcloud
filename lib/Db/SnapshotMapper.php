<?php

declare(strict_types=1);

namespace OCA\GitCloud\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Snapshot>
 */
class SnapshotMapper extends QBMapper {
	public const TABLE_NAME = 'gitcloud_snapshots';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, Snapshot::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Snapshot {
		$qb = $this->db->getQueryBuilder();

		$select = $qb
			->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, $qb::PARAM_INT)));

		return $this->findEntity($select);
	}

	/**
	 * @return Snapshot[]
	 */
	public function findAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();

		$select = $qb
			->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($select);
	}

	/**
	 * @return Snapshot[]
	 */
	public function findAllForFile(string $userId, string $filePath): array {
		$qb = $this->db->getQueryBuilder();

		$select = $qb
			->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_path', $qb->createNamedParameter($filePath)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($select);
	}

	/**
	 * Permanently deletes every snapshot row for the given user, used when the
	 * user wipes their Git history (their commit hashes become invalid afterward).
	 */
	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();

		$qb
			->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}
}

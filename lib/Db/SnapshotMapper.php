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
	 * @return Snapshot[]
	 */
	public function findAllForFileId(string $userId, int $fileId): array {
		$qb = $this->db->getQueryBuilder();

		$select = $qb
			->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, $qb::PARAM_INT)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($select);
	}

	public function findLatestForFileId(string $userId, int $fileId): ?Snapshot {
		$qb = $this->db->getQueryBuilder();

		$select = $qb
			->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, $qb::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($select);
		} catch (DoesNotExistException $e) {
			return null;
		}
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

	/**
	 * Deletes every snapshot row chained to the given file_id, used when a user
	 * stops tracking a file - clears its whole history regardless of which path(s)
	 * it was recorded under across any renames.
	 */
	public function deleteAllForFileId(string $userId, int $fileId): void {
		$qb = $this->db->getQueryBuilder();

		$qb
			->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, $qb::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Deletes snapshot rows by exact path match. Used as a fallback when stopping
	 * tracking of a file whose history predates the file_id migration
	 * (Version000123Date20260825120000) and so has no file_id to chain by.
	 */
	public function deleteAllForFile(string $userId, string $filePath): void {
		$qb = $this->db->getQueryBuilder();

		$qb
			->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_path', $qb->createNamedParameter($filePath)));

		$qb->executeStatement();
	}
}

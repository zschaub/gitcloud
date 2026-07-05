<?php

declare(strict_types=1);

namespace OCA\GitCloud\Migration;

use Closure;
use OCA\GitCloud\Db\SnapshotMapper;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000102Date20260704120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(SnapshotMapper::TABLE_NAME)) {
			$table = $schema->createTable(SnapshotMapper::TABLE_NAME);
			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('user_id', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('file_path', 'string', [
				'notnull' => true,
				'length' => 4000,
			]);
			$table->addColumn('commit_hash', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('message', 'string', [
				'notnull' => false,
				'length' => 4000,
			]);
			$table->addColumn('parent_snapshot_id', 'bigint', [
				'notnull' => false,
			]);
			$table->addColumn('status', 'string', [
				'notnull' => true,
				'length' => 32,
				'default' => 'committed',
			]);
			$table->addColumn('created_at', 'bigint', [
				'notnull' => true,
				'length' => 20,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'gitcloud_snap_user_idx');
			$table->addIndex(['user_id', 'created_at'], 'gitcloud_snap_user_time_idx');
			$table->addIndex(['parent_snapshot_id'], 'gitcloud_snap_parent_idx');
		}

		return $schema;
	}
}

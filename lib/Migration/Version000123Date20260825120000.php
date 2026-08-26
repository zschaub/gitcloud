<?php

declare(strict_types=1);

namespace OCA\GitCloud\Migration;

use Closure;
use OCA\GitCloud\Db\SnapshotMapper;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000123Date20260825120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable(SnapshotMapper::TABLE_NAME);

		if (!$table->hasColumn('file_id')) {
			$table->addColumn('file_id', 'bigint', [
				'notnull' => false,
			]);
		}

		if (!$table->hasIndex('gitcloud_snap_fileid_idx')) {
			$table->addIndex(['user_id', 'file_id'], 'gitcloud_snap_fileid_idx');
		}

		return $schema;
	}
}

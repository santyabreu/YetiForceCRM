<?php
namespace App\Db;

use App\Db\Importers\Base;
use App\Exceptions;

/**
 * Class that imports structure and data to database
 * @package YetiForce.App
 * @copyright YetiForce Sp. z o.o.
 * @license YetiForce Public License 2.0 (licenses/License.html or yetiforce.com)
 * @author Mariusz Krzaczkowski <m.krzaczkowski@yetiforce.com>
 */
class Importer
{

	/**
	 * End of line character
	 * @var string 
	 */
	public $logs = "\n";

	/**
	 * Path to the directory with files to import
	 * @var string 
	 */
	public $path = 'install/install_schema';

	/**
	 * Stop import if an error occurs
	 * @var bool 
	 */
	public $dieOnError = false;

	/**
	 * Check redundant tables
	 * @var bool 
	 */
	public $redundantTables = false;

	/**
	 * Array with objects to import
	 * @var App\Db\Importers\Base[]
	 */
	private $importers = [];

	/**
	 * Load all files for import
	 * @param string|bool $path
	 */
	public function loadFiles($path = false)
	{
		$dir = new \DirectoryIterator($path ? $path : $this->path);
		foreach ($dir as $fileinfo) {
			if ($fileinfo->getType() !== 'dir' && $fileinfo->getExtension() === 'php') {
				require $fileinfo->getPath() . DIRECTORY_SEPARATOR . $fileinfo->getFilename();
				$className = 'Importers\\' . $fileinfo->getBasename('.php');
				$instance = new $className();
				if (method_exists($instance, 'scheme')) {
					$instance->scheme();
				}
				if (method_exists($instance, 'data')) {
					$instance->data();
				}
				$this->importers[] = $instance;
			}
		}
	}

	/**
	 * Import database structure
	 */
	public function importScheme()
	{
		foreach ($this->importers as &$importer) {
			$this->addTables($importer);
		}
	}

	/**
	 * Import database rows
	 */
	public function importData()
	{
		foreach ($this->importers as &$importer) {
			$this->addData($importer);
		}
	}

	/**
	 * Post Process action
	 */
	public function postImport()
	{
		foreach ($this->importers as &$importer) {
			$this->addForeignKey($importer);
		}
	}

	/**
	 * Creating tables
	 * @param Base $importer
	 */
	public function addTables(Base $importer)
	{
		$this->logs .= "> start add tables\n";
		foreach ($importer->tables as $tableName => $table) {
			$this->logs .= "  > add table: $tableName ... ";
			try {
				$importer->db->createCommand()->createTable($tableName, $this->getColumns($importer, $table), $this->getOptions($importer, $table))->execute();
				$this->logs .= "done\n";
			} catch (\Exception $e) {
				$this->logs .= " | Error(1) [{$e->getMessage()}] in {$e->getFile()}:{$e->getLine()} !!!\n";
				if ($this->dieOnError) {
					throw new Exceptions\AppException('Importer error: ' . $e->getMessage(), (int) $e->getCode(), $e);
				}
			}
			if ($indexes = $this->getIndexes($importer, $table)) {
				foreach ($indexes as $index) {
					$this->logs .= "  > create index: {$index[0]} ... ";
					try {
						$importer->db->createCommand()->createIndex($index[0], $tableName, $index[1], (isset($index[2]) && $index[2]) ? true : false )->execute();
						$this->logs .= "done\n";
					} catch (\Exception $e) {
						$this->logs .= " | Error(2) [{$e->getMessage()}] in {$e->getFile()}:{$e->getLine()} !!!\n";
						if ($this->dieOnError) {
							throw new Exceptions\AppException('Importer error: ' . $e->getMessage(), (int) $e->getCode(), $e);
						}
					}
				}
			}
			if (isset($table['primaryKeys'])) {
				foreach ($table['primaryKeys'] as $primaryKey) {
					$this->logs .= "  > add primary key: {$primaryKey[0]} ... ";
					try {
						$importer->db->createCommand()->addPrimaryKey($primaryKey[0], $tableName, $primaryKey[1])->execute();
						$this->logs .= "done\n";
					} catch (\Exception $e) {
						$this->logs .= " | Error(3) [{$e->getMessage()}] in {$e->getFile()}:{$e->getLine()} !!!\n";
						if ($this->dieOnError) {
							throw new Exceptions\AppException('Importer error: ' . $e->getMessage(), (int) $e->getCode(), $e);
						}
					}
				}
			}
		}
		$this->logs .= "# end add tables\n";
	}

	/**
	 * Get additional SQL fragment that will be appended to the generated SQL.
	 * @param string $type
	 * @param array $table
	 * @return string
	 */
	public function getOptions(Base $importer, $table)
	{
		$options = null;
		switch ($importer->db->getDriverName()) {
			case 'mysql':
				$options = "ENGINE={$table['engine']} DEFAULT CHARSET={$table['charset']}";
				break;
		}
		return $options;
	}

	/**
	 * Get columns to create
	 * @param Base $importer
	 * @param array $table
	 * @return array
	 */
	public function getColumns(Base $importer, $table)
	{
		$type = $importer->db->getDriverName();
		$columns = $table['columns'];
		if (isset($table['columns_' . $type])) {
			foreach ($table['columns_' . $type] as $column => $customType) {
				$this->logs .= "    > custom column type, name: $column, driver: $type, type: $customType \n";
				$columns[$column] = $customType;
			}
		}
		return $columns;
	}

	/**
	 * Get index to create
	 * @param Base $importer
	 * @param array $table
	 * @return array
	 */
	public function getIndexes(Base $importer, $table)
	{
		if (!isset($table['index'])) {
			return false;
		}
		$type = $importer->db->getDriverName();
		$indexes = $table['index'];
		if (isset($table['index_' . $type])) {
			foreach ($table['index_' . $type] as $customIndex) {
				foreach ($indexes as $key => $index) {
					if ($customIndex[0] === $index[0]) {
						$this->logs .= "    > custom index, driver: $type, type: {$customIndex['0']} \n";
						$indexes[$key] = $customIndex;
					}
				}
			}
		}
		return $indexes;
	}

	/**
	 * Creates a SQL command for adding a foreign key constraint to an existing table.
	 * @param Base $importer
	 */
	public function addForeignKey(Base $importer)
	{
		if (!isset($importer->foreignKey)) {
			return;
		}
		$this->logs .= "> start add foreign key\n";
		foreach ($importer->foreignKey as $key) {
			$this->logs .= "  > add: {$key[0]}, {$key[1]} ... ";
			try {
				$importer->db->createCommand()->addForeignKey($key[0], $key[1], $key[2], $key[3], $key[4], $key[5], $key[6])->execute();
				$this->logs .= "done\n";
			} catch (\Exception $e) {
				$this->logs .= " | Error(4) [{$e->getMessage()}] in {$e->getFile()}:{$e->getLine()} !!!\n";
				if ($this->dieOnError) {
					throw new Exceptions\AppException('Importer error: ' . $e->getMessage(), (int) $e->getCode(), $e);
				}
			}
		}
		$this->logs .= "# end add foreign key\n";
	}

	/**
	 * Creating rows
	 * @param Base $importer
	 */
	public function addData(Base $importer)
	{
		if (!isset($importer->data)) {
			return;
		}
		$this->logs .= "> start add data rows\n";
		foreach ($importer->data as $tableName => $table) {
			$this->logs .= "  > add data to table: $tableName ... ";
			try {
				$keys = $table['columns'];
				foreach ($table['values'] as $values) {
					$importer->db->createCommand()->insert($tableName, array_combine($keys, $values))->execute();
				}
				$this->logs .= "done\n";
			} catch (\Exception $e) {
				$this->logs .= " | Error(5) [{$e->getMessage()}] in {$e->getFile()}:{$e->getLine()} !!!\n";
				if ($this->dieOnError) {
					throw new Exceptions\AppException('Importer error: ' . $e->getMessage(), (int) $e->getCode(), $e);
				}
			}
		}
		$this->logs .= "# end add data rows\n";
		$this->logs .= "> start reset sequence\n";
		foreach ($importer->data as $tableName => $table) {
			$tableSchema = $importer->db->getTableSchema($tableName);
			$isAutoIncrement = false;
			foreach ($tableSchema->columns as $column) {
				if ($column->autoIncrement) {
					$isAutoIncrement = true;
					break;
				}
			}
			if ($isAutoIncrement) {
				$this->logs .= "  > reset sequence: $tableName ... ";
				try {
					$importer->db->createCommand()->resetSequence($tableName)->execute();
					$this->logs .= "done\n";
				} catch (\Exception $e) {
					$this->logs .= " | Error(6) [{$e->getMessage()}] in {$e->getFile()}:{$e->getLine()} !!!\n";
					if ($this->dieOnError) {
						throw new Exceptions\AppException('Importer error: ' . $e->getMessage(), (int) $e->getCode(), $e);
					}
				}
				if ($this->redundantTables && isset($importer->data[$tableName . '_seq'])) {
					$this->logs .= "   > Error: redundant table {$tableName}_seq !!!\n";
					if ($this->dieOnError) {
						throw new Exceptions\AppException('Importer error: ' . $e->getMessage(), (int) $e->getCode(), $e);
					}
				}
			}
		}
		$this->logs .= "# end reset sequence\n";
	}

	/**
	 * Rename tables
	 * 
	 * $tables = [
	 * 		['oldName', 'newName']
	 * 		['u_#__mail_address_boock', 'u_#__mail_address_book']
	 * ];
	 * @param array $tables
	 */
	public function renameTables($tables)
	{
		$db = \App\Db::getInstance();
		$dbCommand = $db->createCommand();
		foreach ($tables as $table) {
			if ($db->isTableExists($table[0])) {
				$dbCommand->renameTable($table[0], $table[1])->execute();
			}
		}
	}

	/**
	 * Drop table
	 * @param string|array $tables
	 */
	public function dropTable($tables)
	{
		$db = \App\Db::getInstance();
		if (is_string($tables)) {
			$tables = [$tables];
		}
		foreach ($tables as $tableName) {
			if ($db->isTableExists($tableName)) {
				$db->createCommand()->dropTable($tableName)->execute();
			}
		}
	}

	/**
	 * Rename columns
	 * 
	 * $columns = [
	 * 		['TableName', 'oldName', 'newName'],
	 * 		['vtiger_smsnotifier', 'status', 'smsnotifier_status'],
	 * ];
	 * @param array $columns
	 */
	public function renameColumns($columns)
	{
		$db = \App\Db::getInstance();
		$dbCommand = $db->createCommand();
		$schema = $db->getSchema();
		foreach ($columns as $column) {
			$tableSchema = $schema->getTableSchema($column[0]);
			if ($tableSchema && isset($tableSchema->columns[$column[1]]) && !isset($tableSchema->columns[$column[2]])) {
				$dbCommand->renameColumn($column[0], $column[1], $column[2])->execute();
			}
		}
	}

	/**
	 * Drop columns
	 * 
	 * $columns = [
	 * 		['TableName', 'columnName'],
	 * 		['vtiger_smsnotifier', 'status'],
	 * ];
	 * @param array $columns
	 */
	public function dropColumns($columns)
	{
		$db = \App\Db::getInstance();
		$dbCommand = $db->createCommand();
		$schema = $db->getSchema();
		foreach ($columns as $column) {
			$tableSchema = $schema->getTableSchema($column[0]);
			if ($tableSchema && isset($tableSchema->columns[$column[1]])) {
				$dbCommand->dropColumn($column[0], $column[1])->execute();
			}
		}
	}

	/**
	 * Refresh db schema
	 */
	public function refreshSchema()
	{
		\App\Db::getInstance()->getSchema()->getTableSchemas('', true);
	}

	/**
	 * Show or save logs
	 * @param bool $show
	 */
	public function logs($show = true)
	{
		if ($show) {
			echo $this->logs;
		} else {
			file_put_contents('cache/logs/Importer.log', $this->logs);
		}
	}

	/**
	 * Update db scheme
	 */
	public function updateScheme()
	{
		foreach ($this->importers as &$importer) {
			$this->updateTables($importer);
		}
	}

	/**
	 * Update tables structure
	 * @param Base $importer
	 * @throws Exceptions\AppException
	 */
	public function updateTables(Base $importer)
	{
		$this->logs .= "> start update tables\n";
		$schema = $importer->db->getSchema();
		$queryBuilder = $schema->getQueryBuilder();
		$dbCommand = $importer->db->createCommand();
		foreach ($importer->tables as $tableName => $table) {
			try {
				if (!$importer->db->isTableExists($tableName)) {
					$this->logs .= "  > add table: $tableName ... ";
					$dbCommand->createTable($tableName, $this->getColumns($importer, $table), $this->getOptions($importer, $table))->execute();
					$this->logs .= "done\n";
				} else {
					$tableSchema = $schema->getTableSchema($tableName);
					foreach ($this->getColumns($importer, $table) as $columnName => $column) {
						if (!isset($tableSchema->columns[$columnName])) {
							$this->logs .= "  > add column: $tableName:$columnName ... ";
							$dbCommand->addColumn($tableName, $columnName, $column)->execute();
							$this->logs .= "done\n";
						} else {
							if ($this->comperColumns($queryBuilder, $tableSchema->columns[$columnName], $column)) {
								$this->logs .= "  > alter column: $tableName:$columnName ... ";
								$dbCommand->alterColumn($tableName, $columnName, $column)->execute();
								$this->logs .= "done\n";
							}
						}
					}
				}
			} catch (\Exception $e) {
				$this->logs .= " | Error(7) [{$e->getMessage()}] in {$e->getFile()}:{$e->getLine()} !!!\n";
				if ($this->dieOnError) {
					throw new Exceptions\AppException('Importer error: ' . $e->getMessage(), (int) $e->getCode(), $e);
				}
			}
			if ($indexes = $this->getIndexes($importer, $table)) {
				$dbIndexes = $importer->db->getTableKeys($tableName);
				foreach ($indexes as $index) {
					try {
						if (isset($dbIndexes[$index[0]])) {
							$update = false;
							if (is_string($index[1]) ? !isset($dbIndexes[$index[0]][$index[1]]) : array_diff($index[1], array_keys($dbIndexes[$index[0]]))) {
								$update = true;
							} else {
								foreach ($dbIndexes[$index[0]] as $dbIndex) {
									if (empty($index[2]) !== empty($dbIndex['unique'])) {
										$update = true;
									}
								}
							}
							if ($update) {
								$this->logs .= "  > update index: {$index[0]} ... ";
								$dbCommand->dropIndex($index[0], $tableName)->execute();
								$dbCommand->createIndex($index[0], $tableName, $index[1], (isset($index[2]) && $index[2]) ? true : false )->execute();
								$this->logs .= "done\n";
							}
						} else {
							$this->logs .= "  > create index: {$index[0]} ... ";
							$dbCommand->createIndex($index[0], $tableName, $index[1], (isset($index[2]) && $index[2]) ? true : false )->execute();
							$this->logs .= "done\n";
						}
					} catch (\Exception $e) {
						$this->logs .= " | Error(8) [{$e->getMessage()}] in {$e->getFile()}:{$e->getLine()} !!!\n";
						if ($this->dieOnError) {
							throw new Exceptions\AppException('Importer error: ' . $e->getMessage(), (int) $e->getCode(), $e);
						}
					}
				}
			}
			if (isset($table['primaryKeys'])) {
				$dbPrimaryKeys = $importer->db->getPrimaryKey($tableName);
				foreach ($table['primaryKeys'] as $primaryKey) {
					$status = false;
					foreach ($dbPrimaryKeys as $dbPrimaryKey) {
						if (is_string($primaryKey[1]) ? !(count($dbPrimaryKey) === 1 && $primaryKey[1] === $dbPrimaryKey[0]) : array_diff($primaryKey[1], $dbPrimaryKey)) {
							$status = true;
						}
					}
					if ($status) {
						$this->logs .= "  > update primary key: {$primaryKey[0]} ... ";
						try {
							if (isset($dbPrimaryKeys[$primaryKey[0]])) {
								$dbCommand->dropPrimaryKey($primaryKey[0], $tableName)->execute();
							} else {
								$dbCommand->dropPrimaryKey(key($dbPrimaryKeys), $tableName)->execute();
							}
							$dbCommand->addPrimaryKey($primaryKey[0], $tableName, $primaryKey[1])->execute();
							$this->logs .= "done\n";
						} catch (\Exception $e) {
							$this->logs .= " | Error(9) [{$e->getMessage()}] in {$e->getFile()}:{$e->getLine()} !!!\n";
							if ($this->dieOnError) {
								throw new Exceptions\AppException('Importer error: ' . $e->getMessage(), (int) $e->getCode(), $e);
							}
						}
					}
				}
			}
		}
		$this->logs .= "# end update tables\n";
	}

	/**
	 * Compare two columns if they are identical
	 * @param \yii\db\QueryBuilder $queryBuilder
	 * @param \yii\db\ColumnSchema $baseColumn
	 * @param \yii\db\ColumnSchemaBuilder $targetColumn
	 * @return boolean
	 */
	protected function comperColumns($queryBuilder, $baseColumn, $targetColumn)
	{
		if ($baseColumn->dbType !== strtok($queryBuilder->getColumnType($targetColumn), ' ')) {
			return true;
		}
		if (($baseColumn->allowNull !== (is_null($targetColumn->isNotNull))) || ($baseColumn->defaultValue !== $targetColumn->default) || ($baseColumn->unsigned !== $targetColumn->isUnsigned)) {
			return true;
		}
		return false;
	}

	/**
	 * Post Process action
	 */
	public function postUpdate()
	{
		foreach ($this->importers as &$importer) {
			$this->updateForeignKey($importer);
		}
	}

	/**
	 * Update a foreign key constraint to an existing table.
	 * @param Base $importer
	 */
	public function updateForeignKey(Base $importer)
	{
		if (!isset($importer->foreignKey)) {
			return;
		}
		$this->logs .= "> start update foreign key\n";
		$dbCommand = $importer->db->createCommand();
		$schema = $importer->db->getSchema();
		foreach ($importer->foreignKey as $key) {
			$add = true;
			$tableSchema = $schema->getTableSchema($key[1]);
			foreach ($tableSchema->foreignKeys as $dbForeignKey) {
				if ($key[3] === $dbForeignKey[0] && isset($dbForeignKey[$key[2]]) && $key[4] === $dbForeignKey[$key[2]]) {
					$add = false;
				}
			}
			if ($add) {
				$this->logs .= "  > add: {$key[0]}, {$key[1]} ... ";
				try {
					$dbCommand->addForeignKey($key[0], $key[1], $key[2], $key[3], $key[4], $key[5], $key[6])->execute();
					$this->logs .= "done\n";
				} catch (\Exception $e) {
					$this->logs .= " | Error(10) [{$e->getMessage()}] in {$e->getFile()}:{$e->getLine()} !!!\n";
					if ($this->dieOnError) {
						throw new Exceptions\AppException('Importer error: ' . $e->getMessage(), (int) $e->getCode(), $e);
					}
				}
			}
		}
		$this->logs .= "# end update foreign key\n";
	}
}

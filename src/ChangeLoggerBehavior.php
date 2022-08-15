<?php

namespace Finanzcheck\ChangeLogger;

use Propel\Generator\Builder\Om\ObjectBuilder;
use Propel\Generator\Exception\InvalidArgumentException;
use Propel\Generator\Model\Behavior;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\Table;

/**
 * Logs all modifications for each defined column.
 *
 * @author Marc J. Schmidt <marc@marcjschmidt.de>
 */
class ChangeLoggerBehavior extends Behavior
{
    // default parameters value
    protected $parameters = array(
        'created_at' => 'false',
        'created_by' => 'false',
        'comment' => 'false',

        'created_at_column' => 'log_created_at',
        'created_by_column' => 'log_created_by',
        'comment_column' => 'log_comment',

        'version_column' => 'version',
        'log' => '',

        'table_alias' => ''
    );

    /**
     * @var Table[]
     */
    protected $logTables = [];

    public function modifyTable(): void
    {
        $this->addLogTables();
    }

    public function objectAttributes($builder)
    {
        $script = '';

        if ('true' === $this->getParameter('comment')) {
            foreach ($this->getColumns() as $column) {
                $name = lcfirst($column->getPhpName());
                $script .= "
/**
 * Is used as comment for ChangeLoggerBehavior.
 *
 * @var string
 */
protected \${$name}ChangeComment;
";
            }
        }

        if ('true' === $this->getParameter('created_by')) {
            foreach ($this->getColumns() as $column) {
                $name = lcfirst($column->getPhpName());

                $script .= "
/**
 * Is used as createdBy for ChangeLoggerBehavior.
 *
 * @var string
 */
protected \${$name}ChangeBy;
";
            }
        }

        $script .= "
/**
  *
  * @var mixed[]
  */
protected \$changeLoggerTracker = [];
";

        return $script;
    }

    public function objectMethods($builder)
    {
        $script = '';

        foreach ($this->getColumns() as $column) {
            $this->appendAddVersionMethod($builder, $script, $column);


            if ('true' === $this->getParameter('comment')) {
                $this->appendGetterSetterMethods($builder, $script, $column, 'ChangeComment');
            }

            if ('true' === $this->getParameter('created_by')) {
                $this->appendGetterSetterMethods($builder, $script, $column, 'ChangeBy');
            }
        }

        return $script;
    }

    /**
     * @param ObjectBuilder $builder
     * @param string        $script
     * @param Column        $column
     */
    protected function appendGetterSetterMethods(ObjectBuilder $builder, &$script, $column, $affix)
    {
        $name = lcfirst($column->getPhpName()) . $affix;
        $methodSetName = 'set' . $column->getPhpName() . $affix;
        $methodGetName = 'get' . $column->getPhpName() . $affix;

        $script .= "
/**
 * @param string \$comment
 */
public function $methodSetName(\$comment)
{
    \$this->{$name} = \$comment;
}";

        $script .= "
/**
 * @param string \$comment
 */
public function $methodGetName()
{
    return \$this->{$name};
}";

    }
    /**
     * @param ObjectBuilder $builder
     * @param string        $script
     * @param Column        $column
     */
    protected function appendAddVersionMethod(ObjectBuilder $builder, &$script, $column)
    {
        $columnPhpName = $column->getPhpName();
        $methodName = "add{$columnPhpName}Version";

        $logTable = $this->getLogTable($column);

        $logTableName = $logTable->getName();
        $logARClassName = $builder->getClassNameFromBuilder($builder->getNewStubObjectBuilder($logTable));
        $logARQueryName = $builder->getNewStubQueryBuilder($logTable)->getFullyQualifiedClassName();

        $varName = lcfirst($column->getPhpName());

        $script .= "
/**
 * @return $logARClassName|false model instance of saved log ($logTableName) or false if nothing was changed.
 */
public function $methodName()
{
    if (!isset(\$this->changeLoggerTracker['$varName'])) {
        return false;
    }

    \$log = new {$logARClassName}();";

        foreach ($this->getTable()->getPrimaryKey() as $col) {
            $script .= "
    \$log->set" . $col->getPhpName() . "(\$this->get" . $col->getPhpName() . "());";
        }

        $script .= "
    \$log->set" . $column->getPhpName() . "(\$this->changeLoggerTracker['$varName']);";

        if ('true' === $this->getParameter('created_at')) {
            $createdAtColumn = $logTable->getColumn($this->getParameter('created_at_column'));
            $script .= "
    \$log->set{$createdAtColumn->getPhpName()}(time());
";
        }

        if ('true' === $this->getParameter('created_by')) {
            $createdByColumn = $logTable->getColumn($this->getParameter('created_by_column'));
            $methodGetName = 'get' . $column->getPhpName() . 'ChangeBy';
            $script .= "
    \$log->set{$createdByColumn->getPhpName()}(\$this->$methodGetName());
";
        }

        if ('true' === $this->getParameter('comment')) {
            $commentColumn = $logTable->getColumn($this->getParameter('comment_column'));
            $methodGetName = 'get' . $column->getPhpName() . 'ChangeComment';
            $script .= "
    \$log->set{$commentColumn->getPhpName()}(\$this->$methodGetName());
";
        }

        $script .= "
    \$lastVersion = $logARQueryName::create()
        ->filterByOrigin(\$this)
        ->orderByVersion('desc')
        ->findOne();

    \$log->setVersion(\$lastVersion ? \$lastVersion->getVersion() + 1 : 1);
    \$log->save();

    \$this->changeLoggerTracker['$varName'] = \$this->get{$column->getPhpName()}();
    return \$log;
}
";
    }

    /**
     * @param ObjectBuilder $builder
     *
     * @return string
     */
    public function postUpdate(ObjectBuilder $builder)
    {
        $hooks = '';

        foreach ($this->getColumns() as $column) {
            $varName = 'was' . $column->getPhpName() . 'Changed';
            $hooks .= "
if (\$$varName) {
    \$this->add{$column->getPhpName()}Version();
}";
        }

        return $hooks;
    }

    /**
     * @param ObjectBuilder $builder
     *
     * @return string
     */
    public function preSave(ObjectBuilder $builder)
    {
        $hooks = '';

        foreach ($this->getColumns() as $column) {
            $varName = 'was' . $column->getPhpName() . 'Changed';
            $hooks .= "
\$$varName = \$this->isColumnModified({$column->getFQConstantName()});";
        }

        return $hooks;
    }

    public function postSave(ObjectBuilder $builder)
    {
        $hooks = '';

        foreach ($this->getColumns() as $column) {
            $getter = 'get' . $column->getPhpName();
            $varName = lcfirst($column->getPhpName());
            $hooks .= "
\$this->changeLoggerTracker['$varName'] = \$this->{$getter}();";
        }

        return $hooks;
    }

    public function postHydrate(ObjectBuilder $builder)
    {
        $hooks = '';

        foreach ($this->getColumns() as $column) {
            $getter = 'get' . $column->getPhpName();
            $varName = lcfirst($column->getPhpName());
            $hooks .= "
\$this->changeLoggerTracker['$varName'] = \$this->{$getter}();";
        }

        return $hooks;
    }

    /**
     * @return Column[]
     */
    protected function getColumns()
    {
        $columnNames = trim($this->getParameter('log'));
        if (!$columnNames) {
            throw new InvalidArgumentException(
                'ChangeLogger behavior needs at least one specified column as `log` parameter.'
            );
        }

        $columns = [];
        foreach (explode(',', str_replace(' ', '', $columnNames)) as $columnName) {
            if ($column = $this->getTable()->getColumn($columnName)) {
                $columns[] = $column;
            } else {
                throw new InvalidArgumentException(
                    sprintf(
                        'ChangeLogger behavior can not find `%s` column at table `%s`.',
                        $columnName,
                        $this->getTable()->getName()
                    )
                );
            }
        }

        return $columns;
    }

    /**
     * @param string|Column $column
     *
     * @return Table
     */
    protected function getLogTable($column)
    {
        $columnName = $column;
        if ($column instanceof Column) {
            $columnName = $column->getName();
        }

        return $this->logTables[$columnName];
    }

    /**
     * Adds all log tables for each defined column.
     */
    protected function addLogTables()
    {
        $table = $this->getTable();
        $database = $table->getDatabase();

        [$globalAlias, $tableAliases] = $this->parseAliases($this->getParameter('table_alias'));

        foreach ($this->getColumns() as $column) {
            $tableAlias = $globalAlias ?: $tableAliases[$column->getName()] ?? $table->getName();
            $logTableName = sprintf('%s_%s_log', $tableAlias, $column->getName());

            if ($database->hasTable($logTableName)) {
                $logTable = $database->getTable($logTableName);
            } else {
                $logTable = $database->addTable(
                    array(
                        'name' => $logTableName,
                        'package' => $table->getPackage(),
                        'schema' => $table->getSchema(),
                        'namespace' => $table->getNamespace() ? '\\' . $table->getNamespace() : null,
                        'skipSql' => $table->isSkipSql()
                    )
                );
            }

            $this->logTables[$column->getName()] = $logTable;

            $this->addPrimaryKey($logTable);
            $this->addForeignKey($logTable);
            $this->addColumnToLog($logTable, $column);
            $this->addLogColumns($logTable);
        }
    }

    /**
     * Adds a relation from logTable to origin table.
     *
     * @param Table $logTable
     */
    protected function addForeignKey(Table $logTable)
    {
        $table = $this->getTable();

        if ($table->getForeignKeysReferencingTable($table->getName())) {
            //if already a foreignKey exist to origin table then don't add a second.
            return;
        }

        // create the foreign key
        $fk = new ForeignKey();
        $fk->setForeignTableCommonName($table->getCommonName());
        $fk->setForeignSchemaName($table->getSchema());
        $fk->setPhpName('Origin');
        $fk->setOnDelete('CASCADE');
        $fk->setOnUpdate('CASCADE');

        foreach ($table->getPrimaryKey() as $column) {
            $fk->addReference($logTable->getColumn($column->getName()), $column);
        }

        $logTable->addForeignKey($fk);
    }

    /**
     * Adds the actual column which want to track.
     *
     * @param Table  $logTable
     * @param Column $column
     */
    protected function addColumnToLog(Table $logTable, Column $column)
    {
        if ($logTable->hasColumn($column->getName())) {
            return;
        }

        $columnInLogTable = clone $column;
        if ($columnInLogTable->hasReferrers()) {
            $columnInLogTable->clearReferrers();
        }

        $columnInLogTable->setAutoIncrement(false);
        $columnInLogTable->setPrimaryKey(false);
        $logTable->addColumn($columnInLogTable);
    }

    /**
     * Adds the primary key and version_column.
     *
     * @param Table $logTable
     */
    protected function addPrimaryKey(Table $logTable)
    {
        foreach ($this->getTable()->getPrimaryKey() as $primaryKey) {
            $column = clone $primaryKey;
            $column->setAutoIncrement(false);
            $column->setPrimaryString(false);
            $logTable->addColumn($column);
        }

        // add the version column
        if (!$logTable->hasColumn($this->getParameter('version_column'))) {
            $logTable->addColumn(
                array(
                    'name' => $this->getParameter('version_column'),
                    'type' => 'INTEGER',
                    'primaryKey' => true,
                    'required' => true,
                    'default' => 0
                )
            );
        }
    }

    protected function addLogColumns(Table $table)
    {
        if ('true' === $this->getParameter('created_at') && !$table->hasColumn(
                $this->getParameter('created_at_column')
            )
        ) {
            $table->addColumn(
                array(
                    'name' => $this->getParameter('created_at_column'),
                    'type' => 'TIMESTAMP'
                )
            );
        }
        if ('true' === $this->getParameter('created_by') && !$table->hasColumn(
                $this->getParameter('created_by_column')
            )
        ) {
            $table->addColumn(
                array(
                    'name' => $this->getParameter('created_by_column'),
                    'type' => 'VARCHAR',
                    'size' => 100
                )
            );
        }
        if ('true' === $this->getParameter('comment') && !$table->hasColumn(
                $this->getParameter('comment_column')
            )
        ) {
            $table->addColumn(
                array(
                    'name' => $this->getParameter('comment_column'),
                    'type' => 'VARCHAR',
                    'size' => 255
                )
            );
        }
    }

    /**
     * Parses the parameter string and returns a two-element array.
     * The first element is the global alias, or null if none was given.
     * The second element is the list of individual aliases indexed by column name, which may be empty.
     */
    private function parseAliases(string $paramString): array
    {
        if (!empty($paramString) && strpos($paramString, ':') === false) {
            // global alias
            return [$paramString, []];
        }
        // individual aliases
        $aliases = [];
        $aliasChunks = array_filter(array_map('trim', explode(',', $paramString)));
        foreach ($aliasChunks as $chunk) {
            [$columnName, $alias] = array_map('trim', explode(':', $chunk));
            $aliases[$columnName] = $alias;
        }
        return [null, $aliases];
    }
}

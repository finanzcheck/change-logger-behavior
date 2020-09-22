### ChangeLoggerBehavior

A behavior for Propel2, like the VersionableBehavior, but column based. It logs basically all changes
into a extra logger table, defined for each column you have specified in the `log` parameter.

## Usage

```xml
<table name="user">
    <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
    <column name="username" type="VARCHAR" size="100" primaryString="true" />
    <behavior name="change_logger">
        <parameter name="log" value="username"/>
        <parameter name="created_at" value="true"/>
    </behavior>
</table>
```

If you haven't installed this behavior through composer, you need to specify the full class name as behavior name:

```xml
    <behavior name="\Finanzcheck\ChangeLogger\ChangeLoggerBehavior">
```

You can also define multiple columns. Each column gets a own logger table.

```xml
    <parameter name="log" value="username, email"/>
```

```php
$user = UserQuery::create()->findByUsername('Klaus');
$user->setUsername('Erik');
$user->setUsernameChangeComment('Due to XY');
$user->setUsernameChangeBy('Superuser');
$user->save();

$usernameChangeLogs = UserUsernameLogQuery::create()
    ->filterByOrigin($user)
    ->orderByVersion('desc')
    ->find();

foreach ($usernameChangeLogs as $log) {
    echo $log->getVersion();
    echo $log->getId(); //foreignKey to `user`
    echo $log->getUsername(); //'Klaus'
    echo $log->getCreatedAt(); //timestamp
}
```

### Table aliases

By default, the name of the log table is `<table_name>_<column_name>_log`. It is possible to replace `<table_name>`
with an alias by using the `table_alias` parameter:

```xml
<table name="user">
    <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
    <column name="username" type="VARCHAR" size="100" primaryString="true" />
    <behavior name="Finanzcheck\ChangeLogger\ChangeLoggerBehavior">
        <parameter name="log" value="username"/>
        <parameter name="table_alias" value="foo"/>
    </behavior>
</table>
```

This will name the log table `foo_username_log` instead of `user_username_log`.

When logging multiple columns for the same table, it is also possible to define separate aliases for specific column.
Columns without an explicitly defined alias use the table name as per default:

```xml
<table name="foo">
    <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
    <column name="alpha" type="INTEGER" />
    <column name="beta" type="INTEGER" />
    <column name="gamma" type="INTEGER" />
    <behavior name="Finanzcheck\ChangeLogger\ChangeLoggerBehavior">
        <parameter name="log" value="alpha, beta, gamma"/>
        <parameter name="table_alias" value="beta: bar, gamma: baz"/>
    </behavior>
</table>
```

This will create three log tables named `foo_alpha_log`, `bar_beta_log` and `baz_gamma_log`.

### Parameter

with its default value.

```xml
<parameter name="created_at" value="false" />
<parameter name="created_by" value="false" />
<parameter name="comment" value="false" />
<parameter name="created_at_column" value="log_created_at" />
<parameter name="created_by_column" value="log_created_by" />
<parameter name="comment_column" value="log_comment" />
<parameter name="version_column" value="version" />
<parameter name="log" value="" />
<parameter name="table_alias" value="" />
```

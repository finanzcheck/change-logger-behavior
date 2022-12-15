<?php

namespace Finanzcheck\ChangeLogger;

use PHPUnit\Framework\TestCase;
use Propel\Generator\Util\QuickBuilder;

class ChangeLoggerTest extends TestCase
{
    public function setUp(): void
    {
        if (!class_exists('\ChangeloggerBehaviorSingle')) {
            $schema = <<<EOF
<database name="changelogger_behavior_test">
    <table name="changelogger_behavior_single">
        <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
        <column name="title" type="VARCHAR" size="100" primaryString="true" />
        <column name="age" type="INTEGER" />
        <behavior name="Finanzcheck\ChangeLogger\ChangeLoggerBehavior">
            <parameter name="log" value="title"/>
            <parameter name="comment" value="true"/>
            <parameter name="created_by" value="true"/>
        </behavior>
    </table>
    <table name="changelogger_behavior_multiple">
        <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
        <column name="title" type="VARCHAR" size="100" primaryString="true" />
        <column name="age" type="INTEGER" />
        <behavior name="Finanzcheck\ChangeLogger\ChangeLoggerBehavior">
            <parameter name="log" value="title, age"/>
        </behavior>
    </table>
    <table name="changelogger_behavior_aliased">
        <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
        <column name="title" type="VARCHAR" size="100" primaryString="true" />
        <behavior name="Finanzcheck\ChangeLogger\ChangeLoggerBehavior">
            <parameter name="log" value="title"/>
            <parameter name="table_alias" value="foo"/>
        </behavior>
    </table>
    <table name="changelogger_behavior_multiple_aliases">
        <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
        <column name="alpha" type="INTEGER" />
        <column name="beta" type="INTEGER" />
        <column name="gamma" type="INTEGER" />
        <behavior name="Finanzcheck\ChangeLogger\ChangeLoggerBehavior">
            <parameter name="log" value="alpha, beta, gamma"/>
            <parameter name="table_alias" value="beta: bar, gamma: baz"/>
        </behavior>
    </table>
</database>
EOF;
            QuickBuilder::buildSchema($schema);
        }
    }

    public function testSingle()
    {
        \ChangeloggerBehaviorSingleQuery::create()->deleteAll();
        \ChangeloggerBehaviorSingleTitleLogQuery::create()->deleteAll();

        $this->assertTrue(class_exists('\ChangeloggerBehaviorSingle'));
        $this->assertTrue(class_exists('\ChangeloggerBehaviorSingleTitleLog'));

        $item = new \ChangeloggerBehaviorSingle();

        $this->assertTrue(method_exists($item, 'addTitleVersion'));
        $item->setTitle('Initial');
        $item->save();

        //initial save doesn't save a log entry
        $this->assertEquals(0, \ChangeloggerBehaviorSingleTitleLogQuery::create()->count());

        $item->setTitle('Teschd');
        $item->setTitleChangeComment('Sohalt.');
        $item->setTitleChangeBy('Me');
        $item->save();

        //second save saves a log entry
        $this->assertEquals(1, \ChangeloggerBehaviorSingleTitleLogQuery::create()->count());
        $changeLog = \ChangeloggerBehaviorSingleTitleLogQuery::create()->findOne();
        $this->assertEquals(1, $changeLog->getVersion());
        $this->assertEquals('Sohalt.', $changeLog->getLogComment());
        $this->assertEquals('Me', $changeLog->getLogCreatedBy());
        $this->assertEquals('Initial', $changeLog->getTitle());

        $item->setAge(2);
        $item->save();

        //another column doesnt trigger a new version for `title`
        $this->assertEquals(1, \ChangeloggerBehaviorSingleTitleLogQuery::create()->count());
        $lastVersion = \ChangeloggerBehaviorSingleTitleLogQuery::create()->orderByVersion('desc')->findOne();
        $this->assertEquals(1, $lastVersion->getVersion());
        $this->assertEquals('Initial', $lastVersion->getTitle());

        $item->setTitle('Changed');
        $item->save();

        //title has been changed, we have now two versions
        $this->assertEquals(2, \ChangeloggerBehaviorSingleTitleLogQuery::create()->count());
        $lastVersion = \ChangeloggerBehaviorSingleTitleLogQuery::create()->orderByVersion('desc')->findOne();
        $this->assertEquals(2, $lastVersion->getVersion());
        $this->assertEquals('Teschd', $lastVersion->getTitle());
    }

    public function testFromQuery()
    {
        \ChangeloggerBehaviorSingleQuery::create()->deleteAll();
        \ChangeloggerBehaviorSingleTitleLogQuery::create()->deleteAll();

        $item = new \ChangeloggerBehaviorSingle();

        $item->setTitle('Initial');
        $item->save();

        \Map\ChangeloggerBehaviorSingleTableMap::clearInstancePool();

        $itemRetrieved = \ChangeloggerBehaviorSingleQuery::create()->findOne();
        $this->assertNotSame($itemRetrieved, $item);

        $itemRetrieved->setTitle('New Title');
        $itemRetrieved->save();

        $this->assertEquals(1, \ChangeloggerBehaviorSingleTitleLogQuery::create()->count());
        $lastVersion = \ChangeloggerBehaviorSingleTitleLogQuery::create()->orderByVersion('desc')->findOne();
        $this->assertEquals(1, $lastVersion->getVersion());
        $this->assertEquals('Initial', $lastVersion->getTitle());
    }

    public function testMultiple()
    {
        \ChangeloggerBehaviorMultipleQuery::create()->deleteAll();
        \ChangeloggerBehaviorMultipleTitleLogQuery::create()->deleteAll();

        $this->assertTrue(class_exists('\ChangeloggerBehaviorMultiple'));
        $this->assertTrue(class_exists('\ChangeloggerBehaviorMultipleTitleLog'));
        $this->assertTrue(class_exists('\ChangeloggerBehaviorMultipleAgeLog'));

        $item = new \ChangeloggerBehaviorMultiple();

        $this->assertTrue(method_exists($item, 'addTitleVersion'));
        $this->assertTrue(method_exists($item, 'addAgeVersion'));
        $item->setTitle('Initial');
        $item->save();

        $this->assertEquals(0, \ChangeloggerBehaviorMultipleTitleLogQuery::create()->count());

        $item->setTitle('Teschd');
        $item->save();

        //initial save saves already a log entry
        $this->assertEquals(1, \ChangeloggerBehaviorMultipleTitleLogQuery::create()->count());
        $this->assertEquals(1, \ChangeloggerBehaviorMultipleTitleLogQuery::create()->findOne()->getVersion());
        $lastVersion = \ChangeloggerBehaviorMultipleTitleLogQuery::create()->orderByVersion('desc')->findOne();
        $this->assertEquals(1, $lastVersion->getVersion());
        $this->assertEquals('Initial', $lastVersion->getTitle());
        $this->assertEquals(0, \ChangeloggerBehaviorMultipleAgeLogQuery::create()->count());

        $item->setAge(2);
        $item->save();

        //title has not changed anything, so check as above
        $this->assertEquals(1, \ChangeloggerBehaviorMultipleTitleLogQuery::create()->count());
        $lastVersion = \ChangeloggerBehaviorMultipleTitleLogQuery::create()->orderByVersion('desc')->findOne();
        $this->assertEquals(1, $lastVersion->getVersion());
        $this->assertEquals('Initial', $lastVersion->getTitle());

        //`age` is empty, so we have no log record
        $this->assertEquals(0, \ChangeloggerBehaviorMultipleAgeLogQuery::create()->count());

        $item->setTitle('Changed');
        $item->setAge(null);
        $item->save();

        //we have now additional a `age` log entry
        $this->assertEquals(1, \ChangeloggerBehaviorMultipleAgeLogQuery::create()->count());
        $lastVersion = \ChangeloggerBehaviorMultipleAgeLogQuery::create()->orderByVersion('desc')->findOne();
        $this->assertEquals(1, $lastVersion->getVersion());
        $this->assertEquals(2, $lastVersion->getAge());

        //title has been changed, we have now two versions
        $this->assertEquals(2, \ChangeloggerBehaviorMultipleTitleLogQuery::create()->count());
        $lastVersion = \ChangeloggerBehaviorMultipleTitleLogQuery::create()->orderByVersion('desc')->findOne();
        $this->assertEquals(2, $lastVersion->getVersion());
        $this->assertEquals('Teschd', $lastVersion->getTitle());

        //age has not changed anything, so check as above
        $this->assertEquals(1, \ChangeloggerBehaviorMultipleAgeLogQuery::create()->count());
        $lastVersion = \ChangeloggerBehaviorMultipleAgeLogQuery::create()->orderByVersion('desc')->findOne();
        $this->assertEquals(1, $lastVersion->getVersion());
        $this->assertEquals(2, $lastVersion->getAge());
    }

    public function testAliasedTableName()
    {
        $this->assertTrue(class_exists('\ChangeloggerBehaviorAliased'));
        $this->assertFalse(class_exists('\ChangeloggerBehaviorAliasedTitleLog'));
        $this->assertTrue(class_exists('\FooTitleLog'));

        $item = new \ChangeloggerBehaviorAliased();
        $item->setTitle('Initial');
        $item->save();

        $this->assertEquals(0, \FooTitleLogQuery::create()->count());

        $item->setTitle('Changed');
        $item->save();

        $this->assertEquals(1, \FooTitleLogQuery::create()->count());

        $lastVersion = \FooTitleLogQuery::create()->findOne();
        $this->assertEquals('Initial', $lastVersion->getTitle());
    }

    public function testMultilpeTableNameAliases()
    {
        $this->assertTrue(class_exists('\ChangeloggerBehaviorMultipleAliases'));
        $this->assertTrue(class_exists('\ChangeloggerBehaviorMultipleAliasesAlphaLog'));
        $this->assertTrue(class_exists('\BarBetaLog'));
        $this->assertTrue(class_exists('\BazGammaLog'));

        $item = new \ChangeloggerBehaviorMultipleAliases();
        $item->setAlpha(1);
        $item->setBeta(1);
        $item->setGamma(1);
        $item->save();

        $this->assertEquals(0, \ChangeloggerBehaviorMultipleAliasesAlphaLogQuery::create()->count());
        $this->assertEquals(0, \BarBetaLogQuery::create()->count());
        $this->assertEquals(0, \BazGammaLogQuery::create()->count());

        $item->setAlpha(2);
        $item->setBeta(2);
        $item->setGamma(2);
        $item->save();

        $this->assertEquals(1, \ChangeloggerBehaviorMultipleAliasesAlphaLogQuery::create()->count());
        $this->assertEquals(1, \BarBetaLogQuery::create()->count());
        $this->assertEquals(1, \BazGammaLogQuery::create()->count());

        $lastAlphaVersion = \ChangeloggerBehaviorMultipleAliasesAlphaLogQuery::create()->findOne();
        $this->assertEquals(1, $lastAlphaVersion->getAlpha());
        $lastBetaVersion = \BarBetaLogQuery::create()->findOne();
        $this->assertEquals(1, $lastBetaVersion->getBeta());
        $lastGammaVersion = \BazGammaLogQuery::create()->findOne();
        $this->assertEquals(1, $lastGammaVersion->getGamma());
    }
}

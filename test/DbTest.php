<?php
use Coroq\Db;
use Coroq\Db\Query;

class TestDb extends Db {
  public function __construct() {
    parent::__construct(new Coroq\Db\QueryBuilder());
  }
  public function connect() {
  }
  protected function doExecute(Query $query) {
  }
  protected function doQuery(Query $query) {
  }
  public function lastInsertId($name = null) {
  }
  public function lastAffectedRowsCount() {
  }
}

class DbTest extends PHPUnit_Framework_TestCase {
  public function testLogFormat() {
    $db = new TestDb();
    $db->setLogging(true);
    $db->execute(new Query("test ?", [0]));
    $db->execute(new Query("test ?", [1]));
    $log = $db->getFormattedLog();
    $this->assertEquals(1, preg_match('|^Count: 2\nTime: [0-9.]+ ms\n([12]\. [0-9.]+ ms test \'[01]\'\n){2}$|', $log));
  }
}

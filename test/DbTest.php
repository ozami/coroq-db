<?php
use Coroq\Db;
use Coroq\Db\Query;
use \Mockery as Mock;

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
  protected function doExecuteDirectly($query) {
  }
  public function lastInsertId($name = null) {
  }
  public function lastAffectedRowsCount() {
  }
}

/**
 * @covers Coroq\Db
 */
class DbTest extends PHPUnit_Framework_TestCase {
  public function tearDown() {
    Mock::close();
  }

  public function testBegin() {
    $db = Mock::mock("TestDb[doExecuteDirectly]")->shouldAllowMockingProtectedMethods();
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("begin")
      ->andReturn(null);
    $db->begin();
  }

  public function testLogFormat() {
    $db = new TestDb();
    $db->setLogging(true);
    $db->execute(new Query("test ?", [0]));
    $db->execute(new Query("test ?", [1]));
    $log = $db->getFormattedLog();
    $this->assertEquals(1, preg_match('|^Count: 2\nTime: [0-9.]+ ms\n([12]\. [0-9.]+ ms test \'[01]\'\n){2}$|', $log));
  }
}

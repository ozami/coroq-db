<?php
use Coroq\Db;
use Coroq\Db\Query;
use Coroq\Db\Noop;
use \Mockery as Mock;

/**
 * @covers Coroq\Db
 */
class DbTest extends PHPUnit_Framework_TestCase {
  public function tearDown() {
    Mock::close();
  }

  public function testBegin() {
    $db = Mock::mock('Coroq\Db\Noop[doExecuteDirectly]')->shouldAllowMockingProtectedMethods();
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("begin")
      ->andReturn(null);
    $db->begin();
  }

  public function testCommit() {
    $db = Mock::mock('Coroq\Db\Noop[doExecuteDirectly]')->shouldAllowMockingProtectedMethods();
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("begin")
      ->andReturn(null);
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("commit")
      ->andReturn(null);
    $db->begin();
    $db->commit();
  }

  public function testNestedTransactionCommit() {
    $db = Mock::mock('Coroq\Db\Noop[doExecuteDirectly]')->shouldAllowMockingProtectedMethods();
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("begin")
      ->andReturn(null);
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("commit")
      ->andReturn(null);
    $db->begin();
    $db->begin();
    $db->commit();
    $db->commit();
  }

  public function testRollback() {
    $db = Mock::mock('Coroq\Db\Noop[doExecuteDirectly]')->shouldAllowMockingProtectedMethods();
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("begin")
      ->andReturn(null);
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("rollback")
      ->andReturn(null);
    $db->begin();
    $db->rollback();
  }

  public function testNestedTransactionRollback() {
    $db = Mock::mock('Coroq\Db\Noop[doExecuteDirectly]')->shouldAllowMockingProtectedMethods();
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("begin")
      ->andReturn(null);
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("rollback")
      ->andReturn(null);
    $db->begin();
    $db->begin();
    $db->rollback();
    $db->rollback();
  }

  public function testNestedTransactionCommitAfterRollback() {
    $db = Mock::mock('Coroq\Db\Noop[doExecuteDirectly]')->shouldAllowMockingProtectedMethods();
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("begin")
      ->andReturn(null);
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("rollback")
      ->andReturn(null);
    $db->begin();
    $db->begin();
    $db->rollback();
    $db->commit();
  }

  public function testNestedTransactionRollbackAfterCommit() {
    $db = Mock::mock('Coroq\Db\Noop[doExecuteDirectly]')->shouldAllowMockingProtectedMethods();
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("begin")
      ->andReturn(null);
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("rollback")
      ->andReturn(null);
    $db->begin();
    $db->begin();
    $db->commit();
    $db->rollback();
  }

  public function testUnmatchingCommitAfterCommitThrowsException() {
    $db = Mock::mock('Coroq\Db\Noop[doExecuteDirectly]')->shouldAllowMockingProtectedMethods();
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("begin")
      ->andReturn(null);
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("commit")
      ->andReturn(null);
    $db->begin();
    $db->commit();
    try {
      $db->commit();
      $this->assertTrue(false);
    }
    catch (\Exception $exception) {
      $this->assertInstanceOf('LogicException', $exception);
    }
  }

  public function testUnmatchingRollbackAfterCommitThrowsException() {
    $db = Mock::mock('Coroq\Db\Noop[doExecuteDirectly]')->shouldAllowMockingProtectedMethods();
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("begin")
      ->andReturn(null);
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("commit")
      ->andReturn(null);
    $db->begin();
    $db->commit();
    try {
      $db->rollback();
      $this->assertTrue(false);
    }
    catch (\Exception $exception) {
      $this->assertInstanceOf('LogicException', $exception);
    }
  }

  public function testUnmatchingCommitAfterRollbackThrowsException() {
    $db = Mock::mock('Coroq\Db\Noop[doExecuteDirectly]')->shouldAllowMockingProtectedMethods();
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("begin")
      ->andReturn(null);
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("rollback")
      ->andReturn(null);
    $db->begin();
    $db->rollback();
    try {
      $db->commit();
      $this->assertTrue(false);
    }
    catch (\Exception $exception) {
      $this->assertInstanceOf('LogicException', $exception);
    }
  }

  public function testUnmatchingRollbackAfterRollbackThrowsException() {
    $db = Mock::mock('Coroq\Db\Noop[doExecuteDirectly]')->shouldAllowMockingProtectedMethods();
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("begin")
      ->andReturn(null);
    $db->shouldReceive("doExecuteDirectly")
      ->once()
      ->with("rollback")
      ->andReturn(null);
    $db->begin();
    $db->rollback();
    try {
      $db->rollback();
      $this->assertTrue(false);
    }
    catch (\Exception $exception) {
      $this->assertInstanceOf('LogicException', $exception);
    }
  }

  public function testTransactionCommit() {
    $db = Mock::mock('Coroq\Db\Noop')->makePartial();
    $db->shouldReceive("begin")
      ->once();
    $db->shouldReceive("commit")
      ->once();
    $transaction = $db->transaction();
    $transaction->commit();
    $this->assertInstanceOf('Coroq\Db\Transaction', $transaction);
  }

  public function testTransactionRollback() {
    $db = Mock::mock('Coroq\Db\Noop')->makePartial();
    $db->shouldReceive("begin")
      ->once();
    $db->shouldReceive("rollback")
      ->once();
    $transaction = $db->transaction();
    $this->assertInstanceOf('Coroq\Db\Transaction', $transaction);
  }

  public function testLogFormat() {
    $db = new Noop();
    $db->setLogging(true);
    $db->execute(new Query("test ?", [0]));
    $db->execute(new Query("test ?", [1]));
    $log = $db->getFormattedLog();
    $this->assertEquals(1, preg_match('|^Count: 2\nTime: [0-9.]+ ms\n([12]\. [0-9.]+ ms test \'[01]\'\n){2}$|', $log));
  }
}

<?php
use Coroq\Db\Transaction;
use Coroq\Db;
use Coroq\Db\Noop;
use \Mockery as Mock;

/**
 * @covers Coroq\Db\Transaction
 */
class TransactionTest extends PHPUnit_Framework_TestCase {
  public function tearDown() {
    Mock::close();
  }

  public function testCommit() {
    $db = Mock::mock('Coroq\Db\Noop');
    $db->shouldReceive("commit")
      ->once();
    $transaction = new Transaction($db);
    $transaction->commit();
  }

  public function testRollback() {
    $db = Mock::mock('Coroq\Db\Noop');
    $db->shouldReceive("rollback")
      ->once();
    $transaction = new Transaction($db);
    $transaction->rollback();
  }

  public function testRollbackOnDestruction() {
    $db = Mock::mock('Coroq\Db\Noop');
    $db->shouldReceive("rollback")
      ->once();
    $transaction = new Transaction($db);
  }
}

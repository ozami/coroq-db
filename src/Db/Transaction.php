<?php
namespace Coroq\Db;
use Coroq\Db;

class Transaction {
  /** @var Db */
  private $db;
  /** @var bool */
  private $closed;

  public function __construct(Db $db) {
    $this->db = $db;
    $this->closed = false;
  }

  public function __destruct() {
    if (!$this->closed) {
      $this->rollback();
    }
  }

  /**
   * @return void
   */
  public function commit() {
    $this->db->commit();
    $this->closed = true;
  }

  /**
   * @return void
   */
  public function rollback() {
    $this->db->rollback();
    $this->closed = true;
  }
}

<?php
namespace Coroq\Db;

class Transaction {
  private $db;
  private $closed;

  public function __construct($db) {
    $this->db = $db;
    $this->closed = false;
  }

  public function __destruct() {
    if (!$this->closed) {
      $this->rollback();
    }
  }

  public function commit() {
    $this->db->commit();
    $this->closed = true;
  }

  public function rollback() {
    $this->db->rollback();
    $this->closed = true;
  }
}

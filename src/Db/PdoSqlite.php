<?php
namespace Coroq\Db;

class PdoSqlite extends Pdo {
  /**
   * @param array $options
   */
  public function __construct(array $options) {
    parent::__construct($options, new QueryBuilder());
  }

  public function connect() {
    parent::connect();
    $this->execute("pragma foreign_keys = ON");
  }
}

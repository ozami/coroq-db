<?php
namespace Coroq\Db;

class PdoMySql extends Pdo {
  /**
   * @param array $options
   */
  public function __construct(array $options) {
    parent::__construct($options, new QueryBuilder\MySql());
  }

  /**
   * @return void
   */
  public function connect() {
    parent::connect();
    $this->pdo()->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
  }
}

<?php
namespace Coroq\Db;

class Sqlite extends Base
{
  public function connect()
  {
    parent::connect();
    $this->pdo->exec("pragma foreign_keys = ON");
  }
}

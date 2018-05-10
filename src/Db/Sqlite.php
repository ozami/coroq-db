<?php
namespace Coroq\Db;

class Sqlite extends \Coroq\Db
{
  public function connect()
  {
    parent::connect();
    $this->pdo->exec("pragma foreign_keys = ON");
  }
}

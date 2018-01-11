<?php
namespace Coroq\Db;

class MySql extends Base
{
  public function connect()
  {
    parent::connect();
    $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
  }
  
  public function quoteName($name)
  {
    return str_replace(parent::quoteName($name), '"', '`');
  }
}

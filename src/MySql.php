<?php
namespace Coroq\Db;

class MySql extends Base
{
  /**
   * @return void
   */
  public function connect()
  {
    parent::connect();
    $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
  }
  
  /**
   * @param string $name
   * @return string
   */
  public function quoteName($name)
  {
    return str_replace('"', '`', parent::quoteName($name));
  }
}

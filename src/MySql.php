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

  public function getTableInfo($table)
  {
    $info = array();
    $cols = self::select(new Db_Sql("show columns from $table"));
    foreach ($cols as $c) {
      $info[$c["Field"]] = array(
        "pk" => $c["Key"] == "PRI",
        "not_null" => $c["Null"] == "NO"
      );
    }
    return $info;
  }

}

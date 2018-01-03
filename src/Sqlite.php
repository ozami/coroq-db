<?php
namespace Coroq\Db;

class Sqlite extends Base
{
  public function connect()
  {
    parent::connect();
    $this->pdo->exec("pragma foreign_keys = ON");
  }
  
  public function getTableInfo($table)
  {
    $info = array();
    $cols = self::select(new Db_Sql("pragma table_info($table)"));
    foreach ($cols as $c) {
      $info[$c["name"]] = array(
        "pk" => $c["pk"] != 0,
        "not_null" => (bool) $c["notnull"]
      );
    }
    return $info;
  }
}

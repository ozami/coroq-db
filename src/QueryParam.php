<?php
namespace Coroq\Db;

class QueryParam
{
  public function __construct($value, $type = \PDO::PARAM_STR)
  {
    $this->value = $value;
    $this->type = $type;
  }
}

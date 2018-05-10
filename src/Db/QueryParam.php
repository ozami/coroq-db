<?php
namespace Coroq\Db;

class QueryParam
{
  /** @var mixed */
  public $value;

  /** @var int */
  public $type;

  /**
   * @param mixed $value
   * @param int $type
   */
  public function __construct($value, $type = \PDO::PARAM_STR)
  {
    $this->value = $value;
    $this->type = $type;
  }
}

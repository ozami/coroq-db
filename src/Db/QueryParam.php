<?php
namespace Coroq\Db;

class QueryParam {
  // same value as of PDO::PARAM_*
  const TYPE_INTEGER = 1;
  const TYPE_STRING = 2;

  /** @var mixed */
  public $value;

  /** @var int */
  public $type;

  /**
   * @param mixed $value
   * @param int $type
   */
  public function __construct($value, $type = self::TYPE_STRING) {
    $this->value = $value;
    $this->type = $type;
  }
}

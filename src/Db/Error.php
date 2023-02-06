<?php
namespace Coroq\Db;

class Error extends \RuntimeException {
  public function __construct() {
    $arguments = func_get_args();
    if (isset($arguments[0]) && $arguments[0] instanceof \RuntimeException) {
      parent::__construct($arguments[0]->getMessage(), $arguments[0]->getCode(), $arguments[0]);
    }
    else {
      $arguments += ["", 0, null];
      parent::__construct($arguments[0], $arguments[1], $arguments[2]);
    }
  }
}

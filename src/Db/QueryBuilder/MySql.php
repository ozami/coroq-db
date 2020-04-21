<?php
namespace Coroq\Db\QueryBuilder;
use \Coroq\Db\QueryBuilder;

class MySql extends QueryBuilder {
  /**
   * @param string $name
   * @return string
   */
  public function quoteName($name) {
    return str_replace('"', '`', parent::quoteName($name));
  }
}

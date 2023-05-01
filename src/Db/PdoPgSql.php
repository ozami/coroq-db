<?php
namespace Coroq\Db;

use Coroq\Db\Error\UniqueViolationError;
use PDOException;

class PdoPgSql extends Pdo {
  /**
   * @param array $options
   */
  public function __construct(array $options) {
    parent::__construct($options, new QueryBuilder\PostgreSql());
  }

  /**
   * @param string $name
   * @return int
   */
  public function nextSequence($name) {
    return $this->query(
      new Query("select nextval(?)", [$name]),
      self::FETCH_ONE
    );
  }

  /**
   * @param string $name
   * @return int
   */
  public function getSequence($name) {
    return $this->query(
      new Query("select currval(?)", [$name]),
      self::FETCH_ONE
    );
  }

  public function copyFromArray($table, array $rows, $delimiter = "\t", $null_as = "\\\\N") {
    $this->pdo()->pgsqlCopyFromArray($table, $rows, $delimiter, $null_as);
  }
}

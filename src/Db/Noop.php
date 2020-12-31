<?php
namespace Coroq\Db;
use Coroq\Db;

class Noop extends Db {
  public function __construct(QueryBuilder $query_builder = null) {
    if ($query_builder === null) {
      $query_builder = new QueryBuilder;
    }
    parent::__construct($query_builder);
  }

  /**
   * @return void
   */
  public function connect() {
  }

  /**
   * @param Query $query
   * @return void
   */
  protected function doExecute(Query $query) {
  }

  /**
   * @param string $query
   * @return void
   */
  protected function doExecuteDirectly($query) {
  }

  /**
   * @param Query $query
   * @return array
   */
  protected function doQuery(Query $query) {
    return [];
  }

  /**
   * @return mixed
   */
  public function lastInsertId($name = null) {
    return 0;
  }

  /**
   * @return int
   */
  public function lastAffectedRowsCount() {
    return 0;
  }
}

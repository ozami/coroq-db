<?php
namespace Coroq;
use Coroq\Db\Query;
use Coroq\Db\QueryBuilder;

abstract class Db {
  const FETCH_ALL = "all";
  const FETCH_ROW = "row";
  const FETCH_COL = "col";
  const FETCH_ONE = "one";

  /** @var QueryBuilder */
  protected $query_builder;
  /** @var int */
  private $transactionStack = 0;
  /** @var bool */
  private $rollbacked = false;
  /** @var array<array> */
  private $log = [];
  /** @var bool */
  private $logging_enabled = false;

  public function __construct(QueryBuilder $query_builder) {
    $this->query_builder = $query_builder;
  }

  /**
   * @return void
   */
  abstract public function connect();

  public function transaction() {
    $this->begin();
    return new Db\Transaction($this);
  }

  public function begin() {
    if ($this->transactionStack == 0) {
      $this->executeDirectly("begin");
    }
    ++$this->transactionStack;
  }

  public function commit() {
    $this->popTransaction();
  }

  public function rollback() {
    $this->rollbacked = true;
    $this->popTransaction();
  }

  public function popTransaction() {
    --$this->transactionStack;
    if ($this->transactionStack < 0) {
      $this->transactionStack = 0;
      throw new \LogicException("Database transaction mismatch");
    }
    if ($this->transactionStack > 0) {
      return;
    }
    if ($this->rollbacked) {
      $this->executeDirectly("rollback");
      $this->rollbacked = false;
      return;
    }
    $this->executeDirectly("commit");
  }

  public function savepoint($name) {
    $name = $this->query_builder->quoteName($name);
    $this->executeDirectly("savepoint $name");
  }

  public function releaseSavepoint($name) {
    $name = $this->query_builder->quoteName($name);
    $this->executeDirectly("release savepoint $name");
  }

  public function rollbackTo($name) {
    $name = $this->query_builder->quoteName($name);
    $this->executeDirectly("rollback to savepoint $name");
  }

  /**
   * @param Query|string $query
   * @return void
   */
  public function execute($query) {
    $query = new Query($query);
    try {
      $time_started = microtime(true);
      $this->doExecute($query);
      $this->addLog($query, microtime(true) - $time_started);
    }
    catch (\Exception $exception) {
      $this->addLog($query, 0, $exception->getMessage());
      throw $exception;
    }
  }

  /**
   * @param Query $query
   * @return void
   */
  abstract protected function doExecute(Query $query);

  /**
   * @param string $query
   * @return void
   */
  protected function executeDirectly($query) {
    if (!is_string($query)) {
      throw new \DomainException('Query must be type of string');
    }
    $this->query_builder->checkSqlInjection(new Query($query));
    try {
      $time_started = microtime(true);
      $this->doExecuteDirectly($query);
      $this->addLog(new Query($query), microtime(true) - $time_started);
    }
    catch (\Exception $exception) {
      $this->addLog(new Query($query), 0, $exception->getMessage());
      throw $exception;
    }
  }

  /**
   * @param string $query
   * @return void
   */
  abstract protected function doExecuteDirectly($query);

  /**
   * Query and fetch the result
   * @param Query|string $query
   * @param string $fetch
   * @return mixed
   */
  public function query($query, $fetch = self::FETCH_ALL) {
    $query = new Query($query);
    try {
      $time_started = microtime(true);
      $rows = $this->doQuery($query);
      $this->addLog($query, microtime(true) - $time_started);
    }
    catch (\Exception $exception) {
      $this->addLog($query, 0, $exception->getMessage());
      throw $exception;
    }
    if ($fetch == self::FETCH_ALL) {
      return $rows;
    }
    if ($fetch == self::FETCH_ROW) {
      return @$rows[0];
    }
    if ($fetch == self::FETCH_ONE) {
      if (!$rows) {
        return null;
      }
      return current($rows[0]);
    }
    if ($fetch == self::FETCH_COL) {
      $column = [];
      foreach ($rows as $row) {
        $column[] = current($row);
      }
      return $column;
    }
    throw new \LogicException("Unknown fetch type");
  }

  /**
   * Query and fetch the rows
   * @param Query $query
   * @return array
   */
  abstract protected function doQuery(Query $query);

  /**
   * @param Query|array $query
   * @return mixed
   */
  public function select($query, $fetch = self::FETCH_ALL) {
    if (is_array($query)) {
      if (isset($query["fetch"])) {
        $fetch = $query["fetch"];
      }
      $query = $this->query_builder->makeSelectStatement($query);
    }
    return $this->query($query, $fetch);
  }

  /**
   * @param Query|array $query
   * @return void
   */
  public function insert($query) {
    if (is_array($query)) {
      $query = $this->query_builder->makeInsertStatement($query);
    }
    $this->execute($query);
  }

  /**
   * @param Query|array $query
   * @return void
   */
  public function insertMultiple($query) {
    if (is_array($query)) {
      $query = $this->query_builder->makeInsertMultipleStatement($query);
    }
    $this->execute($query);
  }

  /**
   * @param Query|array $query
   * @return void
   */
  public function update($query) {
    if (is_array($query)) {
      $query = $this->query_builder->makeUpdateStatement($query);
    }
    $this->execute($query);
  }

  /**
   * @param Query|array $query
   * @return void
   */
  public function delete($query) {
    if (is_array($query)) {
      $query = $this->query_builder->makeDeleteStatement($query);
    }
    $this->execute($query);
  }

  /**
   * @return mixed
   */
  abstract public function lastInsertId($name = null);

  /**
   * @return int
   */
  abstract public function lastAffectedRowsCount();

  /**
   * @param bool $enable
   * @return void
   */
  public function setLogging($enable = true) {
    $this->logging_enabled = (bool)$enable;
  }

  /**
   * @return array
   */
  public function getLog() {
    return $this->log;
  }

  public function getFormattedLog() {
    $formatValue = function($value) {
      if ($value === null) {
        return $value;
      }
      return "'$value'";
    };
    $formatTime = function($time) {
      return number_format($time * 1000, 2) . " ms";
    };
    $formatted = ["Count: " . number_format(count($this->log))];
    $total_time = 0;
    foreach ($this->log as $log_entry) {
      $total_time += $log_entry["time"];
    }
    $formatted[] = "Time: " . $formatTime($total_time);
    foreach ($this->log as $index => $log_entry) {
      $query = "";
      $arguments = array_values($log_entry["query"]->params);
      foreach (explode("?", $log_entry["query"]->text) as $position => $part) {
        $query .= $part . $formatValue(@$arguments[$position]->value);
      }
      $line_number = $index + 1;
      $formatted[] = join(" ", [
        "$line_number.",
        $formatTime($log_entry["time"]),
        $query,
      ]);
      if (isset($log_entry["error"])) {
        $formatted[] = str_repeat(" ", strlen((string)$line_number) + 2) . $log_entry["error"];
      }
    }
    return join("\n", $formatted) . "\n";
  }

  /**
   * @return void
   */
  public function clearLog() {
    $this->log = [];
  }

  /**
   * @param Query $query
   * @param float $time
   * @param string|null $error
   * @return void
   */
  protected function addLog($query, $time, $error = null) {
    if (!$this->logging_enabled) {
      return;
    }
    $this->log[] = [
      "time" => $time,
      "error" => $error,
      "query" => $query,
    ];
  }
}

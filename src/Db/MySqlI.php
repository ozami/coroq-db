<?php
namespace Coroq\Db;
use Coroq\Db;

class MySqlI extends Db {
  /** @var \mysqli|null */
  private $mysqli;
  /** @var array */
  private $options;
  /** @var array<\mysqli_stmt> */
  private $statements = [];
  /** @var \mysqli_stmt|null */
  private $last_statement;

  /**
   * @param array $options
   */
  public function __construct(array $options) {
    parent::__construct(new QueryBuilder\MySql());
    $this->options = $options;
  }

  /**
   * @return \mysqli
   */
  public function mysqli() {
    if (!$this->mysqli) {
      $this->connect();
    }
    return $this->mysqli;
  }

  /**
   * @return void
   */
  public function connect() {
    $mysqli = new \mysqli(
      @$this->options["host"],
      @$this->options["user"],
      @$this->options["password"],
      @$this->options["database"],
      @$this->options["port"],
      @$this->options["socket"]
    );
    if ($mysqli->connect_errno) {
      throw new Error($mysqli->connect_error, $mysqli->connect_errno);
    }
    $this->mysqli = $mysqli;
  }

  /**
   * @param Query $query
   * @return array
   */
  protected function doQuery(Query $query) {
    $this->doExecute($query);
    $result = $this->last_statement->get_result();
    if ($result === false) {
      throw new Error($this->mysqli->error, $this->mysqli->errno);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
  }

  /**
   * @param Query $query
   * @return void
   */
  protected function doExecute(Query $query) {
    $mysqli = $this->mysqli();
    $statement = @$this->statements[$query->text];
    if (!$statement) {
      $this->query_builder->checkSqlInjection($query);
      $statement = $mysqli->prepare($query->text);
      if ($statement === false) {
        throw new Error($mysqli->error, $mysqli->errno);
      }
      $this->statements[$query->text] = $statement;
    }
    if ($query->params) {
      $param_types = "";
      $param_type_map = [
        QueryParam::TYPE_INTEGER => "i",
        QueryParam::TYPE_STRING => "s",
      ];
      foreach ($query->params as $param) {
        $param_type = @$param_type_map[$param->type];
        if ($param_type === null) {
          throw new \LogicException("Unknown parameter type {$param->type}");
        }
        $param_types .= $param_type;
      }
      $bind_param_arguments = [$param_types];
      foreach ($query->params as $index => $not_used) {
        // note that bind_param() takes $variable as a reference
        $bind_param_arguments[] = &$query->params[$index]->value;
      }
      if (!call_user_func_array([$statement, "bind_param"], $bind_param_arguments)) {
        throw new Error($statement->error, $statement->errno);
      }
    }
    if (!$statement->execute()) {
      throw new Error($statement->error, $statement->errno);
    }
    $this->last_statement = $statement;
  }

  /**
   * @return mixed
   */
  public function lastInsertId($name = null) {
    if (!$this->last_statement) {
      throw new \LogicException();
    }
    return $this->last_statement->insert_id;
  }

  /**
   * @return int
   */
  public function lastAffectedRowsCount() {
    if (!$this->last_statement) {
      throw new \LogicException();
    }
    return $this->last_statement->affected_rows;
  }
}

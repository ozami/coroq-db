<?php
namespace Coroq\Db;
use Coroq\Db;

class MySqlI extends Db {
  /** @var \mysqli|null */
  private $mysqli;
  /** @var array<string,mixed> */
  private $options;
  /** @var array<\mysqli_stmt> */
  private $statements = [];
  /** @var \mysqli_stmt|null */
  private $last_statement;

  /**
   * @param array<string,mixed> $options
   */
  public function __construct(array $options) {
    parent::__construct(new QueryBuilder\MySql());
    $this->options = $options;
  }

  public function __destruct() {
    $this->disconnect();
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
    $mysqli = mysqli_init();
    if (!$mysqli) {
      throw new Error("Error in mysqli_init().");
    }
    $options = $this->options + array_fill_keys([
      "host",
      "user",
      "password",
      "database",
      "port",
      "socket",
      "flags",
      "ssl",
    ], null);
    if ($options["ssl"]) {
      $ssl_options = $options["ssl"] + array_fill_keys([
        "key",
        "certificate",
        "ca_certificate",
        "ca_certificate_directory",
        "cipher",
      ], null);
      $mysqli->ssl_set(
        $ssl_options["key"],
        $ssl_options["certificate"],
        $ssl_options["ca_certificate"],
        $ssl_options["ca_certificate_directory"],
        $ssl_options["cipher"]
      );
    }
    $connected = $mysqli->real_connect(
      $options["host"],
      $options["user"],
      $options["password"],
      $options["database"],
      $options["port"],
      $options["socket"],
      $options["flags"]
    );
    if (!$connected) {
      throw new Error($mysqli->connect_error, $mysqli->connect_errno);
    }
    $this->mysqli = $mysqli;
  }

  public function disconnect() {
    $this->statements = [];
    $this->last_statement = null;
    if ($this->mysqli()) {
      mysqli_close($this->mysqli);
      $this->mysqli = null;
    }
  }

  /**
   * @param Query $query
   * @return array<int,mixed>
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
    if (isset($this->statements[$query->text])) {
      $statement = $this->statements[$query->text];
    }
    else {
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
        if (!isset($param_type_map[$param->type])) {
          throw new \LogicException("Unknown parameter type {$param->type}");
        }
        $param_types .= $param_type_map[$param->type];
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
   * @param string $query
   * @return void
   */
  protected function doExecuteDirectly($query) {
    $mysqli = $this->mysqli();
    if (!$mysqli->real_query($query)) {
      throw new Error($mysqli->error, $mysqli->errno);
    }
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
   * @return int|string
   */
  public function lastAffectedRowsCount() {
    if (!$this->last_statement) {
      throw new \LogicException();
    }
    return $this->last_statement->affected_rows;
  }
}

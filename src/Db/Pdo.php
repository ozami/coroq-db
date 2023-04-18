<?php
namespace Coroq\Db;
use Coroq\Db;
use PDOException;

abstract class Pdo extends Db {
  /** @var \PDO|null */
  private $pdo;
  /** @var array<string,mixed> */
  private $options;
  /** @var array<\PDOStatement> */
  private $statements = [];
  /** @var \PDOStatement|null */
  private $last_statement;

  /**
   * @param array<string,mixed> $options
   */
  public function __construct(array $options, QueryBuilder $query_builder) {
    parent::__construct($query_builder);
    $this->options = $options;
  }

  /**
   * @return \PDO
   */
  public function pdo() {
    if (!$this->pdo) {
      $this->connect();
    }
    return $this->pdo;
  }

  /**
   * @return void
   */
  public function connect() {
    try {
      $this->pdo = new \PDO(
        $this->options["dsn"],
        isset($this->options["user"]) ? $this->options["user"] : null,
        isset($this->options["password"]) ? $this->options["password"] : null,
        $this->options
      );
      $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }
    catch (\PDOException $exception) {
      throw $this->createErrorFromPdoException($exception);
    }
  }

  /**
   * @param Query $query
   * @return array<int,mixed>
   */
  protected function doQuery(Query $query) {
    try {
      $this->doExecute($query);
      return $this->last_statement->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\PDOException $exception) {
      throw $this->createErrorFromPdoException($exception);
    }
  }

  /**
   * @param Query $query
   * @return void
   */
  protected function doExecute(Query $query) {
    try {
      if (!isset($this->statements[$query->text])) {
        $this->query_builder->checkSqlInjection($query);
        $this->statements[$query->text] = $this->pdo()->prepare($query->text);
      }
      $statement = $this->statements[$query->text];
      // note that bindParam() takes $variable as a reference
      $params = array_values($query->params);
      foreach ($params as $i => $p) {
        $statement->bindParam($i + 1, $params[$i]->value, $p->type);
      }
      $statement->execute();
      $this->last_statement = $statement;
    }
    catch (\PDOException $exception) {
      throw $this->createErrorFromPdoException($exception);
    }
  }

  /**
   * @param string $query
   * @return void
   */
  protected function doExecuteDirectly($query) {
    try {
      $this->pdo()->exec($query);
    }
    catch (\PDOException $exception) {
      throw $this->createErrorFromPdoException($exception);
    }
  }

  /**
   * @return mixed
   */
  public function lastInsertId($name = null) {
    return $this->pdo()->lastInsertId($name);
  }

  /**
   * @return int
   */
  public function lastAffectedRowsCount() {
    if (!$this->last_statement) {
      throw new \LogicException();
    }
    return $this->last_statement->rowCount();
  }

  protected function createErrorFromPdoException(PDOException $pdoException) {
    return new Error($pdoException);
  }
}

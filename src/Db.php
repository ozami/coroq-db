<?php
namespace Coroq;
use Coroq\Db\Query;

abstract class Db
{
  const FETCH_ALL = "all";
  const FETCH_ROW = "row";
  const FETCH_COL = "col";
  const FETCH_ONE = "one";

  public $pdo;
  public $dsn;
  public $user;
  public $pw;
  public $options;
  public $transactionStack = 0;
  public $rollbacked = false;
  public $statementCache = [];
  public $tableInfos = [];
  private $log = [];
  private $logging_enabled = false;

  public function __construct($dsn, $user = null, $pw = null, array $options = [])
  {
    $this->dsn = $dsn;
    $this->user = $user;
    $this->pw = $pw;
    $this->options = $options;
  }

  public function pdo()
  {
    if (!$this->pdo) {
      $this->connect();
    }
    return $this->pdo;
  }

  /**
   * @return void
   */
  public function connect()
  {
    $this->pdo = new \PDO($this->dsn, $this->user, $this->pw, $this->options);
    $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
  }

  public function begin()
  {
    if ($this->transactionStack == 0) {
      $this->exec("begin");
    }
    ++$this->transactionStack;
  }

  public function commit()
  {
    $this->popTransaction();
  }

  public function rollback()
  {
    $this->rollbacked = true;
    $this->popTransaction();
  }

  public function popTransaction()
  {
    --$this->transactionStack;
    if ($this->transactionStack < 0) {
      $this->transactionStack = 0;
      throw new \LogicException("Database transaction mismatch");
    }
    if ($this->transactionStack > 0) {
      return;
    }
    if ($this->rollbacked) {
      $this->exec("rollback");
      $this->rollbacked = false;
      return;
    }
    $this->exec("commit");
  }

  public function savepoint($name)
  {
    $name = $this->quoteName($name);
    $this->exec("savepoint $name");
  }

  public function releaseSavepoint($name)
  {
    $name = $this->quoteName($name);
    $this->exec("release savepoint $name");
  }

  public function rollbackTo($name)
  {
    $name = $this->quoteName($name);
    $this->exec("rollback to savepoint $name");
  }

  /**
   * @param Query|string $query
   * @return int
   */
  public function execute($query)
  {
    return $this->prepareAndExecute($query)->rowCount();
  }

  /**
   * @param Query|string $query
   * @param string $fetch
   * @return mixed
   */
  public function query($query, $fetch = self::FETCH_ALL)
  {
    $s = $this->prepareAndExecute($query);
    $rows = $s->fetchAll(\PDO::FETCH_ASSOC);
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
      $col = [];
      foreach ($rows as $r) {
        $col[] = current($r);
      }
      return $col;
    }
    throw new \LogicException();
  }

  /**
   * @param Query $query
   * @return void
   */
  public function checkSqlInjection(Query $query)
  {
    if (!preg_match("##u", $query->text)) {
      throw new \LogicException();
    }
    if (strcspn($query->text, "';\\#") != strlen($query->text)) {
      throw new \LogicException();
    }
    if (strpos($query->text, "--") !== false) {
      throw new \LogicException();
    }
    if (strpos($query->text, "/*") !== false) {
      throw new \LogicException();
    }
  }

  /**
   * @param array $query
   * @return mixed
   */
  public function select(array $query)
  {
    return $this->query(
      $this->makeSelectStatement($query),
      @$query["fetch"] ?: self::FETCH_ALL
    );
  }

  /**
   * @param array $query
   * @return int
   */
  public function insert(array $query)
  {
    return $this->execute($this->makeInsertStatement($query));
  }

  /**
   * @param array $query
   * @return int
   */
  public function insertMultiple(array $query)
  {
    return $this->execute($this->makeInsertMultipleStatement($query));
  }

  /**
   * @param array $query
   * @return int
   */
  public function update(array $query)
  {
    return $this->execute($this->makeUpdateStatement($query));
  }

  /**
   * @param array $query
   * @return int
   */
  public function delete(array $query)
  {
    return $this->execute($this->makeDeleteStatement($query));
  }

  /**
   * @param array $query
   * @return Query
   */
  public function makeSelectStatement(array $query)
  {
    $distinct = @$query["distinct"] ? "distinct" : null;
    return (new Query("select"))
      ->append($distinct)
      ->append($this->makeSelectOptionClause(@$query["option"]))
      ->append($this->makeColumnClause(@$query["column"] ?: "*"))
      ->append($this->makeFromClause(@$query["table"]))
      ->append($this->makeAliasClause(@$query["alias"]))
      ->append($this->makeJoinClause(@$query["join"]))
      ->append($this->makeWhereClause(@$query["where"]))
      ->append($this->makeGroupByClause(@$query["group"]))
      ->append($this->makeOrderByClause(@$query["order"]))
      ->append($this->makeLimitClause(@$query["limit"]))
      ->append($this->makeOffsetClause(@$query["offset"]));
  }

  /**
   * @param array $query
   * @return Query
   */
  public function makeInsertStatement(array $query)
  {
    if (is_array($query["data"])) {
      if (isset($query["column"])) {
        $query["data"] = $this->arrayPick($query["data"], $query["column"]);
      }
      $query["column"] = array_keys($query["data"]);
    }
    else {
      if (!isset($query["column"])) {
        throw new \LogicException("The 'column' parameter required if 'data' is not an array.");
      }
    }
    $q = (new Query("insert"))
      ->append($this->makeIntoClause(@$query["table"]));
    $names = array_map(function($name) {
      return $this->makeNameClause($name);
    }, $query["column"]);
    $q = $q->append(Query::toList($names)->paren());
    return $q->append($this->makeValuesClause([$query["data"]]));
  }

  /**
   * @param array $query
   * @return Query
   */
  public function makeInsertMultipleStatement(array $query)
  {
    if (!isset($query["column"])) {
      throw new \LogicException("The 'column' parameter required.");
    }
    $query["data"] = array_map(function($values) use ($query) {
      if (is_array($values)) {
        $values = $this->arrayPick($values, $query["column"]);
      }
      return $values;
    }, $query["data"]);
    $q = (new Query("insert"))
      ->append($this->makeIntoClause(@$query["table"]));
    $names = array_map(function($name) {
      return $this->makeNameClause($name);
    }, $query["column"]);
    $q = $q->append(Query::toList($names)->paren());
    return $q->append($this->makeValuesClause($query["data"]));
  }

  /**
   * @param array $query
   * @return Query
   */
  public function makeUpdateStatement(array $query)
  {
    if (isset($query["column"])) {
      if (!is_array(@$query["data"])) {
        throw new \LogicException();
      }
      $query["data"] = $this->arrayPick($query["data"], $query["column"]);
    }
    return (new Query("update"))
      ->append($this->makeNameClause(@$query["table"]))
      ->append($this->makeSetClause(@$query["data"]))
      ->append($this->makeWhereClause(@$query["where"]));
  }

  /**
   * @param array $query
   * @return Query
   */
  public function makeDeleteStatement(array $query)
  {
    return (new Query("delete"))
      ->append($this->makeFromClause(@$query["table"]))
      ->append($this->makeWhereClause(@$query["where"]));
  }

  /**
   * @param array|Query|string|null $option
   * @return Query
   */
  public function makeSelectOptionClause($option) {
    if ($option instanceof Query) {
      return $option;
    }
    if (is_string($option)) {
      return new Query($option);
    }
    if (is_array($option)) {
      $options = array_map(function($option) {
        return $this->makeSelectOptionClause($option);
      }, $option);
      $options = array_filter($options, function($option) {
        return !$option->isEmpty();
      });
      return Query::join($options);
    }
    if ($option === null) {
      return new Query();
    }
    throw new \LogicException();
  }

  /**
   * @param array|Query|string $column
   * @return Query
   */
  public function makeColumnClause($column)
  {
    if ($column instanceof Query) {
      return $column;
    }
    if (is_string($column)) {
      return new Query($column);
    }
    if (!is_array($column)) {
      throw new \LogicException();
    }
    $column = array_map(function($col) {
      if ($col instanceof Query) {
        return $col;
      }
      if ($col == "*" || $col == "count(*)") {
        return new Query($col);
      }
      if (preg_match("/^(count|sum|avg|min|max)\\((.+)\\)$/u", $col, $match)) {
        $name = $this->makeNameClause($match[2])->paren();
        return (new Query($match[1]))->append($name, "");
      }
      return $this->makeNameClause($col);
    }, $column);
    $column = array_filter($column, function($col) {
      return !$col->isEmpty();
    });
    return Query::toList($column);
  }

  public function makeFromClause($name)
  {
    return $this->makePrefixedNameClauseHelper("from", $name);
  }

  public function makeAliasClause($name)
  {
    return $this->makePrefixedNameClauseHelper("as", $name);
  }

  public function makeIntoClause($name)
  {
    return $this->makePrefixedNameClauseHelper("into", $name);
  }

  public function makePrefixedNameClauseHelper($prefix, $name)
  {
    if (!($name instanceof Query)) {
      $name = $this->makeNameClause($name);
    }
    if ($name->isEmpty()) {
      return $name;
    }
    return (new Query($prefix))->append($name);
  }

  /**
   * @param array $values
   * @return Query
   */
  public function makeValuesClause(array $values)
  {
    $values = array_map(function($values) {
      if ($values instanceof Query) {
        return $values->paren();
      }
      $values = array_map(function($value) {
        if ($value instanceof Query) {
          return $value;
        }
        return new Query("?", [$value]);
      }, $values);
      return Query::toList($values)->paren();
    }, $values);
    $values = Query::toList($values);
    return (new Query("values"))->append($values);
  }

  /**
   * @param array|Query $data
   * @return Query
   */
  public function makeSetClause($data)
  {
    if ($data instanceof Query) {
      return $data;
    }
    $sets = [];
    foreach ($data as $name => $value) {
      if (!($value instanceof Query)) {
        $value = new Query("?", [$value]);
      }
      $sets[] = $this->makeNameClause($name)
        ->append("=")
        ->append($value);
    }
    return (new Query("set"))->append(Query::toList($sets));
  }

  public function makeJoinClause($join)
  {
    if ($join instanceof Query) {
      return $join;
    }
    if (!$join) {
      return new Query();
    }
    // multiple join clauses
    if (ctype_digit(join("", array_keys($join)))) {
      $joins = array_map(function($join) {
        return $this->makeJoinClause($join);
      }, $join);
      $joins = array_filter($joins, function($join) {
        return $join && !$join->isEmpty();
      });
      return Query::join($joins);
    }
    // single join clause
    static $types = ["inner", "left outer", "right outer", "full outer", "cross"];
    if (!in_array(@$join["type"], $types)) {
      throw new \LogicException();
    }
    return (new Query())
      ->append($join["type"])
      ->append("join")
      ->append($this->makeNameClause(@$join["table"]))
      ->append($this->makeAliasClause(@$join["alias"]))
      ->append($this->makeOnClause(@$join["where"]));
  }

  /**
   * @param string $name
   * @return Query
   */
  public function makeNameClause($name)
  {
    return new Query($this->quoteName($name));
  }

  /**
   * @param string $name
   * @return string
   */
  public function quoteName($name)
  {
    $name = trim($name);
    if ($name == "") {
      return $name;
    }
    $parts = explode(".", $name);
    $parts = array_map(function($i) {
      if ($i == "*") {
        return $i;
      }
      if ($i == "") {
        throw new \LogicException();
      }
      if (!preg_match("#^[A-Za-z0-9_]+$#u", $i)) {
        throw new \LogicException();
      }
      return '"' . $i . '"';
    }, $parts);
    return join(".", $parts);
  }

  public function makeWhereClause($conditions)
  {
    $q = $this->makeConditionClause($conditions);
    if ($q->isEmpty()) {
      return $q;
    }
    return (new Query("where"))->append($q);
  }

  public function makeOnClause($conditions)
  {
    $q = $this->makeConditionClause($conditions);
    if ($q->isEmpty()) {
      return $q;
    }
    return (new Query("on"))->append($q);
  }

  /**
   * @param array|Query|string $where
   * @return Query
   */
  public function makeConditionClause($where)
  {
    if ($where instanceof Query) {
      return $where;
    }
    if (!is_array($where)) {
      return new Query($where);
    }
    $where = array_map(function($value, $name) {
      if (ctype_digit("$name")) {
        return $value;
      }
      $name = str_replace("::", ".", $name);
      $matched = null;
      if (!preg_match("/^(([a-zA-Z0-9_]+[.])?[a-zA-Z0-9_]+)(:(!?[a-z_]+))?$/u", $name, $matched)) {
        throw new \LogicException();
      }
      @list (, $name, , , $operator) = $matched;
      $name_clause = $this->makeNameClause($name);
      if ($operator == "") {
        $operator = "eq";
      }
      return $this->parseCondition($name_clause, $operator, $value);
    }, $where, array_keys($where));
    $where = array_map(function($x) {
      return new Query($x);
    }, $where);
    $where = array_filter($where, function($x) {
      return !$x->isEmpty();
    });
    if (!$where) {
      return new Query();
    }
    return Query::join($where, " and ");
  }

  public function makeGroupByClause($group)
  {
    $group = array_map(function($g) {
      return ($g instanceof Query) ? $g : $this->makeNameClause($g);
    }, (array)$group);
    $group = array_filter($group, function($g) {
      return !$g->isEmpty();
    });
    if (!$group) {
      return null;
    }
    return (new Query("group by"))->append(Query::toList($group));
  }

  public function makeOrderByClause($order)
  {
    static $dirs = ["+" => "asc", "-" => "desc"];
    $order = array_map(function($o) use ($dirs) {
      if ($o instanceof Query) {
        return $o;
      }
      $o = trim($o);
      if ($o == "") {
        return new Query();
      }
      $dir = @$dirs[@$o[0]];
      if ($dir) {
        $o = substr($o, 1);
      }
      else {
        $dir = $dirs["+"];
      }
      return $this->makeNameClause($o)->append($dir);
    }, (array)$order);
    $order = array_filter($order, function($o) {
      return !$o->isEmpty();
    });
    if (!$order) {
      return null;
    }
    return (new Query("order by"))->append(Query::toList($order));
  }

  public function makeOffsetClause($offset)
  {
    if ($offset instanceof Query) {
      if ($offset->isEmpty()) {
        return null;
      }
    }
    else {
      if (!$offset) {
        return null;
      }
      $offset = new Query("?", [$offset]);
    }
    return (new Query("offset"))->append($offset);
  }

  public function makeLimitClause($limit)
  {
    if ($limit instanceof Query) {
      if ($limit->isEmpty()) {
        return null;
      }
    }
    else {
      if (!$limit) {
        return null;
      }
      $limit = new Query("?", [$limit]);
    }
    return (new Query("limit"))->append($limit);
  }

  /**
   * @param Query $name
   * @param string $op
   * @param mixed $value
   * @return Query
   */
  public function parseCondition(Query $name, $op, $value)
  {
    // simple operators
    $simple_ops = [
      "eq" => "=", "!eq" => "!=",
      "lt" => "<", "le" => "<=",
      "gt" => ">", "ge" => ">=",
      "like" => "like", "!like" => "not like",
    ];
    if (isset($simple_ops[$op])) {
      return $name
        ->append($simple_ops[$op])
        ->append(($value instanceof Query) ? $value : new Query("?", [$value]));
    }
    if ($op == "null") {
      return $name->append("is")->append($value ? "": "not")->append("null");
    }
    if ($op == "in" || $op == "!in") {
      $holders = join(", ",  array_fill(0, count($value), "?"));
      return $name
        ->append($op == "!in" ? "not" : "")
        ->append("in")
        ->append((new Query($holders, $value))->paren());
    }
    throw new \LogicException();
  }

  public function lastInsertId($name = null)
  {
    return $this->pdo()->lastInsertId($name);
  }

  /**
   * @param array $data
   * @param array $keys
   * @return array
   */
  public function arrayPick(array $data, array $keys)
  {
    return array_intersect_key($data, array_fill_keys($keys, null));
  }

  /**
   * @param string $query
   * @return int
   */
  protected function exec($query)
  {
    return $this->pdo()->exec($query);
  }

  /**
   * @param Query|string $query
   * @return \PDOStatement
   */
  protected function prepareAndExecute($query)
  {
    $query = new Query($query);
    try {
      if (!isset($this->statementCache[$query->text])) {
        $this->checkSqlInjection($query);
        $this->statementCache[$query->text] = $this->pdo()->prepare($query->text);
      }
      $s = $this->statementCache[$query->text];
      // note that bindParam() takes $variable as a reference
      $params = array_values($query->params);
      foreach ($params as $i => $p) {
        $s->bindParam($i + 1, $params[$i]->value, $p->type);
      }
      $time_started = microtime(true);
      $s->execute();
      $this->addLog($query, null, microtime(true) - $time_started);
      return $s;
    }
    catch (\Exception $exception) {
      $this->addLog($query, $exception->getMessage(), 0);
      throw $exception;
    }
  }

  public function setLogging($enable = true) {
    $this->logging_enabled = (bool)$enable;
  }

  public function getLog() {
    $total_time = 0;
    $queries = [];
    $formatValue = function($value) {
      if ($value === null) {
        return $value;
      }
      return "'$value'";
    };
    $formatTime = function($time) {
      return number_format($time * 1000, 2) . " ms";
    };
    foreach ($this->log as $log_entry) {
      $total_time += $log_entry["time"];
      $query = "";
      $arguments = array_values($log_entry["query"]->params);
      foreach (explode("?", $log_entry["query"]->text) as $position => $part) {
        $query .= $part . $formatValue(@$arguments[$position]->value);
      }
      $queries[] = [
        "time" => $formatTime($log_entry["time"]),
        "result" => $log_entry["error"] ?: "OK",
        "query" => $query,
      ];
    }
    return [
      "count" => number_format(count($this->log)),
      "time" => $formatTime($total_time),
      "queries" => $queries,
    ];
  }

  public function clearLog() {
    $this->log = [];
  }

  protected function addLog($query, $error, $time) {
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

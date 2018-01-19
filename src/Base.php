<?php
namespace Coroq\Db;

abstract class Base
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
      $this->pdo()->exec("begin");
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
      $this->pdo()->exec("rollback");
      $this->rollbacked = false;
      return;
    }
    $this->pdo()->exec("commit");
  }

  public function savepoint($name)
  {
    $name = $this->quoteName($name);
    $this->pdo()->exec("savepoint $name");
  }

  public function releaseSavepoint($name)
  {
    $name = $this->quoteName($name);
    $this->pdo()->exec("release savepoint $name");
  }

  public function rollbackTo($name)
  {
    $name = $this->quoteName($name);
    $this->pdo()->exec("rollback to savepoint $name");
  }
  
  /**
   * @param Query|string $query
   * @return \PDOStatement
   */
  public function execute($query)
  {
    $query = new Query($query);
    if (!isset($this->statementCache[$query->text])) {
      $this->checkSqlInjection($query);
      $this->statementCache[$query->text] = $this->pdo()->prepare($query->text);
    }
    $s = $this->statementCache[$query->text];
    // note that bindParam() takes $variable as a reference
    $params = array_values($query->params);
    foreach ($params as $i => $p) {
      if ($p instanceof QueryParam) {
        $s->bindParam($i + 1, $params[$i]->value, $p->type);
      }
      else {
        $s->bindParam($i + 1, $params[$i], \PDO::PARAM_STR);
      }
    }
    $s->execute();
    return $s;
  }
  
  /**
   * @param Query|string $query
   * @param string $fetch
   * @return mixed
   */
  public function query($query, $fetch = self::FETCH_ALL)
  {
    $s = $this->execute($query);
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
   * @return \PDOStatement
   */
  public function insert(array $query)
  {
    return $this->execute($this->makeInsertStatement($query));
  }
  
  /**
   * @param array $query
   * @return \PDOStatement
   */
  public function update(array $query)
  {
    return $this->execute($this->makeUpdateStatement($query));
  }
  
  /**
   * @param array $query
   * @return \PDOStatement
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
      ->append($this->makeColumnClause(@$query["column"] ?: "*"))
      ->append($this->makeFromClause(@$query["table"]))
      ->append($this->makeAliasClause(@$query["alias"]))
      ->append($this->makeJoinClause(@$query["join"]))
      ->append($this->makeWhereClause(@$query["where"]))
      ->append($this->makeGroupByClause(@$query["group"]))
      ->append($this->makeOrderByClause(@$query["order"]))
      ->append($this->makeOffsetClause(@$query["offset"]))
      ->append($this->makeLimitClause(@$query["limit"]));
  }
  
  /**
   * @param array $query
   * @return Query
   */
  public function makeInsertStatement(array $query)
  {
    if (isset($query["column"])) {
      if (!is_array(@$query["data"])) {
        throw new \LogicException();
      }
      $query["data"] = $this->arrayPick($query["data"], $query["column"]);
    }
    return (new Query("insert"))
      ->append($this->makeIntoClause(@$query["table"]))
      ->append($this->makeValuesClause(@$query["data"]));
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
   * @param array|Query|string $column
   * @return Query
   */
  public function makeColumnClause($column)
  {
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
    }, (array)$column);
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
   * @param array|Query $data
   * @return Query
   */
  public function makeValuesClause($data)
  {
    if ($data instanceof Query) {
      return $data;
    }
    $names = [];
    $values = [];
    foreach ($data as $name => $value) {
      $names[] = $this->makeNameClause($name);
      $values[] = new Query("?", [$value]);
    }
    $names = Query::toList($names)->paren();
    $values = Query::toList($values)->paren();
    return $names->append("values")->append($values);
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
      $sets[] = $this->makeNameClause($name)
        ->append("=")
        ->append(new Query("?", [$value]));
    }
    return Query::toList($sets);
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
}

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

  public function execute($query)
  {
    $query = new Query($query);
    if (!isset($this->statementCache[$query->text])) {
      $this->checkSqlInjection($query);
      $this->statementCache[$query->text] = $this->pdo()->prepare($query->text);
    }
    $s = $this->statementCache[$query->text];
    foreach (array_values($query->params) as $i => $p) {
      if ($p instanceof QueryParam) {
        $s->bindParam($i + 1, $p->value, $p->type);
      }
      else {
        $s->bindParam($i + 1, $p, \PDO::PARAM_STR);
      }
    }
    $s->execute();
    return $s;
  }
  
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
      return @$rows[0][0];
    }
    if ($fetch == self::FETCH_COL) {
      $col = [];
      foreach ($rows as $r) {
        $col[] = @$r[0];
      }
      return $col;
    }
    throw new \LogicException();
  }
  
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
  
  public function quoteName($name)
  {
    $name = trim($name);
    if (!preg_match("/^[A-Za-z0-9_.*]+$/u", $name)) {
      throw new \LogicException();
    }
    $parts = explode(".", $name);
    $parts = array_map(function($i) {
      if ($i == "*") {
        return $i;
      }
      if (strpos($i, "*") !== false) {
        throw new \LogicException();
      }
      return '"' . $i . '"';
    }, $parts);
    return join(".", $parts);
  }
  
  public function select(array $query)
  {
    return $this->query(
      $this->makeSelectStatement($query),
      @$query["fetch"] ?: self::FETCH_ALL
    );
  }
  
  public function insert(array $query)
  {
    return $this->execute(
      $this->makeInsertStatement($query)
    );
  }
  
  public function update(array $query)
  {
    return $this->execute(
      $this->makeUpdateStatement($query)
    );
  }
  
  public function delete(array $query)
  {
    return $this->execute(
      $this->makeDeleteStatement($query)
    );
  }
  
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
  
  public function makeInsertStatement(array $query)
  {
    return (new Query("insert"))
      ->append($this->makeIntoClause(@$query["table"]))
      ->append($this->makeValuesClause(@$query["data"]));
  }
  
  public function makeUpdateStatement(array $query)
  {
    return (new Query("update"))
      ->append($this->makeTableClause(@$query["table"]))
      ->append($this->makeSetClause(@$query["data"]))
      ->append($this->makeWhereClause(@$query["where"]));
  }

  public function makeDeleteStatement(array $query)
  {
    return (new Query("delete"))
      ->append($this->makeFromClause(@$query["table"]))
      ->append($this->makeWhereClause(@$query["where"]));
  }

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
        return new Query("$match[1]({$this->quoteName($match[2])})");
      }
      return new Query($this->quoteName($col));
    }, (array)$column);
    $column = array_filter($column, function($col) {
      return !$col->isEmpty();
    });
    return Query::toList($column);
  }
  
  public function makeFromClause($from)
  {
    if (!$from instanceof Query) {
    }
    else {
      $from = trim($from);
      if ($from == "") {
        return null;
      }
      $from = $this->makeNameClause($from);
    }
    if (!$from->isEmpty()) {
      return $from;
    }
    return (new Query("from"))->append($from);
  }
  
  public function makeAliasClause($alias)
  {
    if ($alias instanceof Query) {
      if ($alias->isEmpty()) {
        return null;
      }
    }
    else {
      $alias = trim($alias);
      if ($alias == "") {
        return null;
      }
      $alias = $this->makeNameClause($alias);
    }
    return (new Query("as"))->append($alias);
  }
  
  public function makeIntoClause($into)
  {
    
  }

  public function makeJoinClause($join)
  {
    if ($join instanceof Query) {
      if ($join->isEmpty()) {
        return null;
      }
      return $join;
    }
    if (!$join) {
      return null;
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

  public function makeNameClause($name)
  {
    if ($name instanceof Query) {
      return $name;
    }
    if (trim($name) == "") {
      return new Query();
    }
    return new Query($this->quoteName($name));
  }
  
  public function makeWhereClause($conditions)
  {
    $q = $this->makeConditionClause($conditions);
    if ($q) {
      $q = (new Query("where"))->append($q);
    }
    return $q;
  }
  
  public function makeOnClause($conditions)
  {
    $q = $this->makeConditionClause($conditions);
    if ($q) {
      $q = (new Query("on"))->append($q);
    }
    return $q;
  }
  
  public function makeConditionClause($where)
  {
    if ($where instanceof Query) {
      return $where;
    }
    if (!is_array($where)) {
      return new Query($where);
    }
    $where = array_map(function($value, $name) {
      if ($name == (int)$name) {
        return $value;
      }
      $name = str_replace("::", ".", $name);
      if (!preg_match("/^(([a-zA-Z0-9_]+[.])?[a-zA-Z0-9_]+)(:(!?[a-z_]+))?$/u", $name, $matched)) {
        throw new \LogicException();
      }
      @list (, $name, , , $operator) = $matched;
      $name = $this->quoteName($name);
      if ($operator == "") {
        $operator = "eq";
      }
      return $this->parseCondition($name, $operator, $value);
    }, $where, array_keys($where));
    $where = array_map(function($x) {
      return new Query($x);
    }, $where);
    $where = array_filter($where, function($x) {
      return !$x->isEmpty();
    });
    if (!$where) {
      return null;
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

  public function parseCondition($name, $op, $value)
  {
    // simple operators
    $simple_ops = [
      "eq" => "=", "!eq" => "!=",
      "lt" => "<", "le" => "<=",
      "gt" => ">", "ge" => ">=",
      "like" => "like", "!like" => "not like",
    ];
    if (isset($simple_ops[$op])) {
      return new Query("$name {$simple_ops[$op]} ?", [$value]);
    }
    if ($op == "null") {
      return new Query("$name is " . ($value ? "": "not ") . "null");
    }
    if ($op == "in" || $op == "!in") {
      $holders = join(", ",  array_fill(0, count($value), "?"));
      return new Query(
        "$name " . ($op == "!in" ? "not ": "") . "in ($holders)",
        $value
      );
    }
    throw new \LogicException();
  }
  
  public function lastInsertId($name = null)
  {
    return $this->pdo()->lastInsertId($name);
  }
  
  public abstract function getTableInfo($table);
}

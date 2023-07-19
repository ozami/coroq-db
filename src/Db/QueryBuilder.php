<?php
namespace Coroq\Db;

class QueryBuilder {
  /**
   * @param string $name
   * @return string
   */
  public function quoteName($name) {
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

  /**
   * @param string $name
   * @return Query
   */
  public function makeNameClause($name) {
    return new Query($this->quoteName($name));
  }

  /**
   * @param Query $query
   * @return void
   */
  public function checkSqlInjection(Query $query) {
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
   * @param array<string,mixed> $query
   * @return Query
   */
  public function makeSelectStatement(array $query) {
    $query += array_fill_keys([
      "distinct", "option", "column", "table", "alias", "join",
      "where", "group", "order", "limit", "offset", "for",
    ], null);
    return (new Query("select"))
      ->append($query["distinct"] ? "distinct" : null)
      ->append($this->makeSelectOptionClause($query["option"]))
      ->append($this->makeColumnClause($query["column"] ?: "*"))
      ->append($this->makeFromClause($query["table"]))
      ->append($this->makeAliasClause($query["alias"]))
      ->append($this->makeJoinClause($query["join"]))
      ->append($this->makeWhereClause($query["where"]))
      ->append($this->makeGroupByClause($query["group"]))
      ->append($this->makeOrderByClause($query["order"]))
      ->append($this->makeLimitClause($query["limit"]))
      ->append($this->makeOffsetClause($query["offset"]))
      ->append($this->makeSelectForClause($query["for"]));
  }

  /**
   * @param array<string,mixed> $query
   * @return Query
   */
  public function makeInsertStatement(array $query) {
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
      ->append($this->makeIntoClause(isset($query["table"]) ? $query["table"] : null));
    $names = array_map(function($name) {
      return $this->makeNameClause($name);
    }, $query["column"]);
    $q = $q->append(Query::toList($names)->paren());
    return $q->append($this->makeValuesClause([$query["data"]]));
  }

  /**
   * @param array<string,mixed> $query
   * @return Query
   */
  public function makeInsertMultipleStatement(array $query) {
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
      ->append($this->makeIntoClause(isset($query["table"]) ? $query["table"] : null));
    $names = array_map(function($name) {
      return $this->makeNameClause($name);
    }, $query["column"]);
    $q = $q->append(Query::toList($names)->paren());
    return $q->append($this->makeValuesClause($query["data"]));
  }

  /**
   * @param array<string,mixed> $query
   * @return Query
   */
  public function makeUpdateStatement(array $query) {
    $query += array_fill_keys(["column", "data", "table", "where"], null);
    if ($query["column"]) {
      if (!is_array($query["data"])) {
        throw new \LogicException();
      }
      $query["data"] = $this->arrayPick($query["data"], $query["column"]);
    }
    return (new Query("update"))
      ->append($this->makeNameClause($query["table"]))
      ->append($this->makeSetClause($query["data"]))
      ->append($this->makeWhereClause($query["where"]));
  }

  /**
   * @param array<string,mixed> $query
   * @return Query
   */
  public function makeDeleteStatement(array $query) {
    $query += array_fill_keys(["table", "where"], null);
    return (new Query("delete"))
      ->append($this->makeFromClause($query["table"]))
      ->append($this->makeWhereClause($query["where"]));
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
  public function makeColumnClause($column) {
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

  /**
   * @param Query|string $name
   * @return Query
   */
  public function makeFromClause($name) {
    return $this->makePrefixedNameClauseHelper("from", $name);
  }

  /**
   * @param Query|string $name
   * @return Query
   */
  public function makeAliasClause($name) {
    return $this->makePrefixedNameClauseHelper("as", $name);
  }

  /**
   * @param Query|string $name
   * @return Query
   */
  public function makeIntoClause($name) {
    return $this->makePrefixedNameClauseHelper("into", $name);
  }

  /**
   * @param Query|string $prefix
   * @param Query|string $name
   * @return Query
   */
  public function makePrefixedNameClauseHelper($prefix, $name) {
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
  public function makeValuesClause(array $values) {
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
  public function makeSetClause($data) {
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

  /**
   * @param Query|array<mixed> $join
   * @return Query
   */
  public function makeJoinClause($join) {
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
        return !$join->isEmpty();
      });
      return Query::join($joins);
    }
    $join += array_fill_keys(["type", "table", "alias", "where"], null);
    // single join clause
    static $types = ["inner", "left outer", "right outer", "full outer", "cross"];
    if (!in_array($join["type"], $types)) {
      throw new \LogicException();
    }
    return (new Query())
      ->append($join["type"])
      ->append("join")
      ->append($this->makeNameClause($join["table"]))
      ->append($this->makeAliasClause($join["alias"]))
      ->append($this->makeOnClause($join["where"]));
  }

  /**
   * @param array<mixed>|Query|string $conditions
   * @return Query
   */
  public function makeWhereClause($conditions) {
    $q = $this->makeConditionClause($conditions);
    if ($q->isEmpty()) {
      return $q;
    }
    return (new Query("where"))->append($q);
  }

  /**
   * @param array<mixed>|Query|string $conditions
   * @return Query
   */
  public function makeOnClause($conditions) {
    $q = $this->makeConditionClause($conditions);
    if ($q->isEmpty()) {
      return $q;
    }
    return (new Query("on"))->append($q);
  }

  /**
   * @param array<mixed>|Query|string $where
   * @return Query
   */
  public function makeConditionClause($where) {
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
      $name = $matched[1];
      $operator = isset($matched[4]) ? $matched[4] : null;
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

  /**
   * @param array<Query|string>|Query|string|null $group
   * @return Query
   */
  public function makeGroupByClause($group) {
    if ($group === null || $group === "" || $group === []) {
      return new Query();
    }
    if (!is_array($group)) {
      $group = [$group];
    }
    $group = array_map(function($g) {
      return ($g instanceof Query) ? $g : $this->makeNameClause($g);
    }, $group);
    $group = array_filter($group, function($g) {
      return !$g->isEmpty();
    });
    if (!$group) {
      return new Query();
    }
    return (new Query("group by"))->append(Query::toList($group));
  }

  /**
   * @param array<Query|string>|Query|string|null $order
   * @return Query
   */
  public function makeOrderByClause($order) {
    static $dirs = ["+" => "asc", "-" => "desc"];
    if ($order === null || $order === "" || $order === []) {
      return new Query();
    }
    if (!is_array($order)) {
      $order = [$order];
    }
    $order = array_map(function($o) use ($dirs) {
      if ($o instanceof Query) {
        return $o;
      }
      $o = trim("$o");
      if ($o == "") {
        return new Query();
      }
      $dir = isset($dirs[$o[0]]) ? $dirs[$o[0]] : null;
      if ($dir) {
        $o = substr($o, 1);
      }
      else {
        $dir = $dirs["+"];
      }
      return $this->makeNameClause($o)->append($dir);
    }, $order);
    $order = array_filter($order, function($o) {
      return !$o->isEmpty();
    });
    if (!$order) {
      return new Query();
    }
    return (new Query("order by"))->append(Query::toList($order));
  }

  /**
   * @param Query|int|null $offset
   * @return ?Query
   */
  public function makeOffsetClause($offset) {
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

  /**
   * @param Query|int|null $limit
   * @return ?Query
   */
  public function makeLimitClause($limit) {
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
   * @param Query|string|null $for
   * @return ?Query
   */
  public function makeSelectForClause($for): ?Query {
    $for = new Query($for);
    if ($for->isEmpty()) {
      return null;
    }
    return (new Query("for"))->append($for);
  }

  /**
   * @param Query $name
   * @param string $op
   * @param mixed $value
   * @return Query
   */
  public function parseCondition(Query $name, $op, $value) {
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
      if (!is_array($value)) {
        throw new \LogicException("Value for '$op' condition must be an array.");
      }
      if (!$value) {
        throw new \LogicException("Value for '$op' condition cannot be empty.");
      }
      $holders = join(", ",  array_fill(0, count($value), "?"));
      return $name
        ->append($op == "!in" ? "not" : "")
        ->append("in")
        ->append((new Query($holders, $value))->paren());
    }
    throw new \LogicException();
  }

  /**
   * @param array $data
   * @param array $keys
   * @return array
   */
  public function arrayPick(array $data, array $keys) {
    return array_intersect_key($data, array_fill_keys($keys, null));
  }
}

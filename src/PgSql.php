<?php
namespace Coroq\Db;

class PgSql extends Base
{
  public function parseCondition($name, $op, $value)
  {
    // simple operators
    $simple_ops = [
      "ilike" => "ilike", "!ilike" => "not ilike",
      "re" => "~", "!re" => "!~",
    ];
    if (isset($simple_ops[$op])) {
      $q = new Query("$name {$simple_ops[$op]}");
      if ($value instanceof Query) {
        $q->append($value);
      }
      else {
        $q->append(new Query("?", [$value]));
      }
      return $q;
    }
    // string operator
    $string_ops = [
      "starts_with", "!starts_with",
      "ends_with", "!ends_with",
      "contains", "!contains",
    ];
    if (in_array($op, $string_ops)) {
      $q = new Query($name);
      if ($op[0] == "!") {
        $q->append("not");
        $op = substr($op, 1);
      }
      $q->append("ilike");
      $pattern = new Query();
      if ($op == "ends_with" || $op == "contains") {
        $pattern->append(new Query("? ||", ["%"]));
      }
      // escape specials
      if ($value instanceof Query) {
        $pattern->append("regexp_replace(");
        $pattern->append($value, "");
        $pattern->append(", ?, ?)", ["([#%_])", "#\\1"]);
      }
      else {
        $pattern->append(new Query("?", [preg_replace("/([#%_])/u", "#$1", $value)]));
      }
      if ($op == "starts_with" || $op == "contains") {
        $pattern->append(new Query("|| ?", "%"));
      }
      $q->append($pattern->paren());
      $q->append("escape ?", ["#"]);
      return $q;
    }
    return parent::parseCondition($name, $op, $value);
  }
  
  public function lastInsertId()
  {
    if (Db::getDriver() == "pgsql") {
        $name = $this->_name . "_" . $this->getPk() . "_seq";
    }
  }
}

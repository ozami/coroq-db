<?php
namespace Coroq\Db;

class PgSql extends \Coroq\Db
{
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
        $q = $q->append("not");
        $op = substr($op, 1);
      }
      $q = $q->append("ilike");
      $pattern = new Query();
      if ($op == "ends_with" || $op == "contains") {
        $pattern = $pattern->append(new Query("? ||", ["%"]));
      }
      // escape specials
      if ($value instanceof Query) {
        $pattern = $pattern
          ->append("regexp_replace(")
          ->append($value, "")
          ->append(new Query(", ?, ?)", ["([#%_])", "#\\1"]));
      }
      else {
        $pattern = $pattern->append(
          new Query("?", [preg_replace("/([#%_])/u", "#$1", $value)])
        );
      }
      if ($op == "starts_with" || $op == "contains") {
        $pattern = $pattern->append(new Query("|| ?", ["%"]));
      }
      $q = $q
        ->append($pattern->paren())
        ->append(new Query("escape ?", ["#"]));
      return $q;
    }
    return parent::parseCondition($name, $op, $value);
  }

  public function copyFromArray($table, array $rows, $delimiter = "\t", $null_as = "\\\\N") {
    $this->pdo->pgsqlCopyFromArray($table, $rows, $delimiter, $null_as);
  }
}

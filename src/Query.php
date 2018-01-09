<?php
namespace Coroq\Db;

class Query
{
  public $text;
  public $params;
  
  public function __construct($text = "", array $params = [])
  {
    // copy
    if ($text instanceof self) {
      $this->text = $text->text;
      $this->params = $text->params;
      return;
    }
    // create
    if (substr_count($text, "?") != count($params)) {
      throw new \LogicException();
    }
    $this->text = trim("$text");
    $this->params = array_values($params);
  }

  public function isEmpty()
  {
    return $this->text == "";
  }

  public function append($x, $glue = " ")
  {
    $x = new Query($x);
    if ($this->isEmpty()) {
      return $x;
    }
    if ($x->isEmpty()) {
      return new Query($this);
    }
    return new Query(
      "$this->text$glue$x->text",
      array_merge($this->params, $x->params)
    );
  }

  public function appendAnd($x)
  {
    return $this->append($x, " and ");
  }

  public function appendOr($x)
  {
    return $this->append($x, " or ");
  }

  public function paren()
  {
    if ($this->isEmpty()) {
      return new Query($this);
    }
    return new Query(
      "($this->text)",
      $this->params
    );
  }

  public static function join(array $queries, $glue = " ")
  {
    if (!$queries) {
      return new Query();
    }
    $joined = new Query(array_shift($queries));
    foreach ($queries as $q) {
      $joined = $joined->append($q, $glue);
    }
    return $joined;
  }
  
  public static function toList(array $items)
  {
    return static::join($items, ", ");
  }
}

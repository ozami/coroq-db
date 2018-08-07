<?php
use Coroq\Db\QueryParam;

class QueryParamTest extends PHPUnit_Framework_TestCase {
  public function testConstruction() {
    $p = new QueryParam(0);
    $this->assertSame(0, $p->value);
    $this->assertSame(\PDO::PARAM_STR, $p->type);

    $p = new QueryParam("test", \PDO::PARAM_INT);
    $this->assertSame("test", $p->value);
    $this->assertSame(\PDO::PARAM_INT, $p->type);
  }
}

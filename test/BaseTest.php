<?php
use Coroq\Db\Base;
use Coroq\Db\Query;

class TestDb extends Base
{
  public function __construct() 
  {
    parent::__construct("");
  }
}

class BaseTest extends PHPUnit_Framework_TestCase
{
  public function testMakeValuesClauseCanHandleSingleElementArray()
  {
    $db = new TestDb();
    $this->assertEquals(
      new Query('("col1") values (?)', [1]),
      $db->makeValuesClause([
        "col1" => 1,
      ])
    );
  }

  public function testMakeValuesClauseCanHandleMultipleElementsArray()
  {
    $db = new TestDb();
    $this->assertEquals(
      new Query('("col1", "col2") values (?, ?)', [1, "text"]),
      $db->makeValuesClause([
        "col1" => 1,
        "col2" => "text",
      ])
    );
  }
}

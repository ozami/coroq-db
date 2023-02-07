<?php
use Coroq\Db\QueryBuilder;
use Coroq\Db\Query;

class QueryBuilderTest extends PHPUnit_Framework_TestCase {
  public function testMakeValuesClauseCanHandleSingleElementArray() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('values (?)', [1]),
      $query_builder->makeValuesClause([[
        "col1" => 1,
      ]])
    );
  }

  public function testMakeValuesClauseCanHandleMultipleElementsArray() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('values (?, ?)', [1, "text"]),
      $query_builder->makeValuesClause([[
        "col1" => 1,
        "col2" => "text",
      ]])
    );
  }
  
  public function testMakeSetClauseCanHandleSingleElementArray() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('set "col1" = ?', [1]),
      $query_builder->makeSetClause([
        "col1" => 1,
      ])
    );
  }

  public function testMakeSetClauseCanHandleMultipleElementsArray() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('set "col1" = ?, "col2" = ?', [1, "test"]),
      $query_builder->makeSetClause([
        "col1" => 1,
        "col2" => "test",
      ])
    );
  }
  
  public function testQuoteNameCanRejectInvalidCharacters() {
    $valids = array_merge(
      [ // white spaces (will be trimmed)
        0x00, // null
        0x09, // tab
        0x0a, // return
        0x0b, // vertical tab
        0x0d, // new line
        0x20, // space
      ],
      [
        0x2a, // asterisk
        0x5f, // underscore
      ],
      range(0x30, 0x39), // digits
      range(0x41, 0x5a), // upper alphabets
      range(0x61, 0x7a)  // lower alphabets
    );
    $query_builder = new QueryBuilder();
    for ($i = 0; $i <= 0xff; ++$i) {
      try {
        $query_builder->quoteName(chr($i));
        if (!in_array($i, $valids)) {
          $this->fail();
        }
      }
      catch (\Exception $e) {
        if (in_array($i, $valids)) {
          $this->fail();
        }
        $this->assertTrue($e instanceof \LogicException);
      }
    }
  }

  public function testMakeOrderByClauseFromNull() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query(),
      $query_builder->makeOrderByClause(null)
    );
  }

  public function testMakeOrderByClauseFromEmptyString() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query(),
      $query_builder->makeOrderByClause("")
    );
  }

  public function testMakeOrderByClauseFromWhiteSpaceString() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query(),
      $query_builder->makeOrderByClause(" ")
    );
  }

  public function testMakeOrderByClauseFromStringWithExplicitAscendingDirection() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('order by "col1" asc'),
      $query_builder->makeOrderByClause("+col1")
    );
  }

  public function testMakeOrderByClauseFromValidStringWithExplicitDescendingDirection() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('order by "col1" desc'),
      $query_builder->makeOrderByClause("-col1")
    );
  }

  public function testMakeOrderByClauseFromValidStringWithImplicitDirection() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('order by "col1" asc'),
      $query_builder->makeOrderByClause("col1")
    );
  }

  public function testMakeOrderByClauseFromQuery() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('order by col1'),
      $query_builder->makeOrderByClause(new Query("col1"))
    );
  }

  public function testMakeOrderByClauseFromEmptyArray() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query(),
      $query_builder->makeOrderByClause([])
    );
  }

  public function testMakeOrderByClauseFromArrayOfSingleString() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('order by "col1" asc'),
      $query_builder->makeOrderByClause(['col1'])
    );
  }

  public function testMakeOrderByClauseFromArrayOfStrings() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('order by "col1" asc, "col2" asc'),
      $query_builder->makeOrderByClause(['col1', 'col2'])
    );
  }

  public function testMakeGroupByClauseFromNull() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query(),
      $query_builder->makeGroupByClause(null)
    );
  }

  public function testMakeGroupByClauseFromEmptyString() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query(),
      $query_builder->makeGroupByClause("")
    );
  }

  public function testMakeGroupByClauseFromString() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('group by "col1"'),
      $query_builder->makeGroupByClause("col1")
    );
  }

  public function testMakeGroupByClauseFromWhiteSpace() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query(),
      $query_builder->makeGroupByClause(" ")
    );
  }

  public function testMakeGroupByClauseFromQuery() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('group by col1'),
      $query_builder->makeGroupByClause(new Query("col1"))
    );
  }

  public function testMakeGroupByClauseFromEmptyArray() {
    $query_builder = new QueryBuilder();
    $this->assertEquals(
      new Query('group by "col1", col2'),
      $query_builder->makeGroupByClause([
        null,
        "",
        " ",
        "col1",
        new Query("col2"),
      ])
    );
  }

  /**
   * @covers Coroq\Db\QueryBuilder::checkSqlInjection
   */
  public function testCheckSqlInjection() {
    $query_builder = new QueryBuilder();
    $this->assertNull($query_builder->checkSqlInjection(new Query()));

    $query_builder = new QueryBuilder();
    foreach ([";", "\\", "#", "--", "/*"] as $injection) {
      foreach ([$injection, "test$injection", "{$injection}test", "test{$injection}test"] as $s) {
        try {
          $query_builder->checkSqlInjection(new Query($s));
          $this->fail("Could not catch '$injection' in '$s'");
        }
        catch (\LogicException $e) {
          $this->assertTrue(true);
        }
      }
    }
  }
}

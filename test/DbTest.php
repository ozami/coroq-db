<?php
use \Coroq\Db;
use \Coroq\Db\Query;

class TestDb extends Db
{
  public function __construct() 
  {
    parent::__construct("");
  }
}

class DbTest extends PHPUnit_Framework_TestCase
{
  public function testMakeValuesClauseCanHandleSingleElementArray()
  {
    $db = new TestDb();
    $this->assertEquals(
      new Query('values (?)', [1]),
      $db->makeValuesClause([[
        "col1" => 1,
      ]])
    );
  }

  public function testMakeValuesClauseCanHandleMultipleElementsArray()
  {
    $db = new TestDb();
    $this->assertEquals(
      new Query('values (?, ?)', [1, "text"]),
      $db->makeValuesClause([[
        "col1" => 1,
        "col2" => "text",
      ]])
    );
  }
  
  public function testMakeSetClauseCanHandleSingleElementArray()
  {
    $db = new TestDb();
    $this->assertEquals(
      new Query('set "col1" = ?', [1]),
      $db->makeSetClause([
        "col1" => 1,
      ])
    );
  }

  public function testMakeSetClauseCanHandleMultipleElementsArray()
  {
    $db = new TestDb();
    $this->assertEquals(
      new Query('set "col1" = ?, "col2" = ?', [1, "test"]),
      $db->makeSetClause([
        "col1" => 1,
        "col2" => "test",
      ])
    );
  }
  
  public function testQuoteNameCanRejectInvalidCharacters()
  {
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
    $db = new TestDb();
    for ($i = 0; $i <= 0xff; ++$i) {
      try {
        $db->quoteName(chr($i));
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
}

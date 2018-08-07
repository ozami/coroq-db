<?php
use Coroq\Db\Query;
use Coroq\Db\QueryParam;

class QueryTest extends PHPUnit_Framework_TestCase {
  /**
   * @covers Query::__construct
   */
  public function testConstructionWithNoArguments() {
    $q = new Query();
    $this->assertSame("", $q->text);
    $this->assertSame([], $q->params);
  }

  /**
   * @covers Query::__construct
   */
  public function testConstructionWithArguments() {
    $q = new Query("? ?", ["param1", 2]);
    $this->assertSame("? ?", $q->text);
    $this->assertSame(["param1", 2], $q->params);
  }

  /**
   * @covers Query::__construct
   */
  public function testConstructionWithAssociativeArguments() {
    $q = new Query("? ?", ["param1" => 1, "second" => "two"]);
    $this->assertSame("? ?", $q->text);
    $this->assertSame([1, "two"], $q->params);
  }

  /**
   * @covers Query::__construct
   */
  public function testConstructionWithNumber() {
    $q = new Query(1);
    $this->assertSame("1", $q->text);
    $this->assertSame([], $q->params);
  }

  /**
   * @covers Query::__construct
   * @expectedException \LogicException
   */
  public function testConstructionWithPlaceholderAndParamsUnmatch1() {
    $q = new Query("", ["param1"]);
  }

  /**
   * @covers Query::__construct
   * @expectedException \LogicException
   */
  public function testConstructionWithPlaceholderAndParamsUnmatch2() {
    $q = new Query("? ?", ["param1"]);
  }

  /**
   * @covers Query::__construct
   * @expectedException \LogicException
   */
  public function testConstructionWithPlaceholderAndParamsUnmatch3() {
    $q = new Query("?", []);
  }

  /**
   * @covers Query::isEmpty
   */
  public function testIsEmpty() {
    $q = new Query();
    $this->assertTrue($q->isEmpty());

    $q = new Query(" \t\n\r ");
    $this->assertTrue($q->isEmpty());
  }

  /**
   * @covers Query::isEmpty
   */
  public function testIsNotEmpty() {
    $q = new Query("test");
    $this->assertFalse($q->isEmpty());
  }

  /**
   * @covers Query::append
   */
  public function testAppend() {
    $this->assertEquals(
      new Query(),
      (new Query())->append(new Query())
    );

    $this->assertEquals(
      new Query("test"),
      (new Query())->append(new Query("test"))
    );

    $this->assertEquals(
      new Query("test test"),
      (new Query("test"))->append(new Query("test"))
    );

    $this->assertEquals(
      new Query("test test"),
      (new Query("test"))->append(new Query(" \n\r\ttest"))
    );

    $this->assertEquals(
      new Query("testtest"),
      (new Query("test"))->append(new Query("test"), "")
    );

    $this->assertEquals(
      new Query("test and test"),
      (new Query("test"))->append(new Query("test"), " and ")
    );

    $this->assertEquals(
      new Query("test"),
      (new Query())->append(new Query("test"), "glue")
    );

    $this->assertEquals(
      new Query("test"),
      (new Query("test"))->append(new Query(), "glue")
    );

    $this->assertEquals(
      new Query("test"),
      (new Query("test"))->append(new Query(""))
    );

    $this->assertEquals(
      new Query("test ?", [1]),
      (new Query())->append(new Query("test ?", [1]))
    );

    $this->assertEquals(
      new Query("? ?", [1, 2]),
      (new Query("?", [1]))->append(new Query("?", [2]))
    );

    $this->assertEquals(
      new Query("? ? ? ?", [1, 2, 3, 4]),
      (new Query("? ?", [1, 2]))->append(new Query("? ?", [3, 4]))
    );
  }

  /**
   * @covers Query::append
   */
  public function testAppendString() {
    $this->assertEquals(
      new Query(),
      (new Query())->append("")
    );

    $this->assertEquals(
      new Query("test"),
      (new Query())->append("test")
    );

    $this->assertEquals(
      new Query("test test ? ?", [1, 2]),
      (new Query("test"))->append(new Query("test ? ?", [1, 2]))
    );
  }

  /**
   * @covers Query::append
   */
  public function testAppendImmutability() {
    $q = new Query();
    $r = $q->append(new Query("test ?", [1]));
    $this->assertNotSame($q, $r);
    $this->assertEquals(new Query(), $q);
  }

  /**
   * @covers Query::appendAnd
   */
  public function testAppendAnd() {
    $left = new Query("left ?", [1]);
    $right = new Query("right ?", [2]);
    $result = $left->appendAnd($right);
    $this->assertEquals(
      new Query("left ? and right ?", [1, 2]),
      $result
    );
    $this->assertNotSame($left, $result);
    $this->assertNotSame($right, $result);
  }

  /**
   * @covers Query::appendOr
   */
  public function testAppendOr() {
    $left = new Query("left ?", [1]);
    $right = new Query("right ?", [2]);
    $result = $left->appendOr($right);
    $this->assertEquals(
      new Query("left ? or right ?", [1, 2]),
      $result
    );
    $this->assertNotSame($left, $result);
    $this->assertNotSame($right, $result);
  }

  /**
   * @covers Query::paren
   */
  public function testParen() {
    $this->assertEquals(
      new Query(),
      (new Query())->paren()
    );

    $this->assertEquals(
      new Query("(?, ?)", [1, 2]),
      (new Query("?, ?", [1, 2]))->paren()
    );
  }

  /**
   * @covers Query::paren
   */
  public function testParenImmutability() {
    $q = new Query("test + ?", [1]);
    $r = $q->paren($q);
    $this->assertNotSame($q, $r);
    $this->assertEquals(new Query("test + ?", [1]), $q);
  }

  /**
   * @covers Query::join
   */
  public function testJoin() {
    $this->assertEquals(
      new Query(),
      Query::join([])
    );

    $this->assertEquals(
      new Query(),
      Query::join([new Query()])
    );
    
    $this->assertEquals(
      new Query(),
      Query::join([new Query(), new Query()])
    );
    
    $q1 = new Query("test1 ?", [1]);
    $q2 = new Query("test2 ?", [2]);
    $q3 = new Query("test3 ?", [3]);
    $result = Query::join([$q1, $q2, $q3]);
    $this->assertEquals(
      new Query("test1 ? test2 ? test3 ?", [1, 2, 3]),
      $result
    );
    $this->assertNotSame($result, $q1);
    $this->assertNotSame($result, $q2);
    $this->assertNotSame($result, $q3);

    $this->assertEquals(
      new Query("test1 ? and test2 ?", [1, 2]),
      Query::join([
        new Query("test1 ?", [1]),
        new Query("test2 ?", [2]),
      ], " and ")
    );
  }
  
  /**
   * @covers Query::toList
   */
  public function testToList() {
    $this->assertEquals(
      new Query("test1, test2"),
      Query::toList([
        new Query("test1"),
        new Query("test2"),
      ])
    );
  }
}

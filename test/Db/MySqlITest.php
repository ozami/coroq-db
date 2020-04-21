<?php
use Coroq\Db\Query;
use Coroq\Db\QueryParam;
use \Mockery as Mock;

class MockedMySqlI extends Coroq\Db\MySqlI {
  private $mysqli_mock;
  public function __construct($mysqli_mock) {
    parent::__construct([]);
    $this->mysqli_mock = $mysqli_mock;
  }
  public function connect() {
  }
  public function mysqli() {
    return $this->mysqli_mock;
  }
}

class MySqlITest extends PHPUnit_Framework_TestCase {
  public function tearDown() {
    Mock::close();
  }

  public function testExecute() {
    $test_query = new Query(
      "select * from test where test_int = ? and test_string = ?",
      [
        new QueryParam(10, QueryParam::TYPE_INTEGER),
        new QueryParam("test", QueryParam::TYPE_STRING),
      ]
    );
    $statement = Mock::mock();
    $statement->shouldReceive("bind_param")
      ->once()
      ->with("is", $test_query->params[0]->value, $test_query->params[1]->value)
      ->andReturn(true);
    $statement->shouldReceive("execute")
      ->andReturn(true);
    $mysqli = Mock::mock();
    $mysqli->shouldReceive("prepare")
      ->once()
      ->with($test_query->text)
      ->andReturn($statement);
    $db = new MockedMySqlI($mysqli);
    $db->execute($test_query);
  }
}

<?php

$db = new Coroq\Db\PgSql(
  "dbname=sample-db user=ozawa password=ozawa"
);

$db->begin();

$db->select([
  "table" => "users",
  "alias" => "u",
  "column" => ["*"],
  "fetch" => "select",
  "join" => [
    "type" => "inner",
    "table" => "profile",
    "alias" => "p",
    "where" => []
  ],
  "where" => ["active" => true],
  "order" => "-time_created",
  "limit" => 20,
]);

$db->insert([
  "table" => "users",
  "data" => [
    "name" => "ozawa",
    "age" => 40,
  ],
]);

$db->update([
  "table" => "users",
  "data" => [
    "name" => "ozami",
    "age" => "41",
  ],
  "byPkey" => "",
  "where" => [
    "id" => 20,
  ],
]);

$db->delete([
  "table" => "users",
  "where" => [
    "id" => 20,
  ],
]);
$db->commit();

<?php
namespace Coroq\Db;

use Throwable;

class Error extends \RuntimeException {
  /** @var ?string */
  protected $sqlState;

  public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null, ?string $sqlState = null) {
    parent::__construct($message = "", $code, $previous);
    $this->setSqlState($sqlState);
  }

  public function getSqlState(): ?string {
    return $this->sqlState;
  }

  public function setSqlState(?string $sqlState): void {
    $this->sqlState = $sqlState;
  }
}

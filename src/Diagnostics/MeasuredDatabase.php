<?php

namespace Reactor\Diagnostics;

use Amp\Future;
use Reactor\Contracts\AsyncDatabaseManagerInterface;
use function Amp\async;

final class MeasuredDatabase implements AsyncDatabaseManagerInterface
{
    public function __construct(private AsyncDatabaseManagerInterface $inner, private RuntimeMetrics $metrics) {}
    public function getDriver(): string { return $this->inner->getDriver(); }
    public function fetchAll(string $sql, array $params = []): Future { return $this->measure(fn () => $this->inner->fetchAll($sql, $params)); }
    public function fetchOne(string $sql, array $params = []): Future { return $this->measure(fn () => $this->inner->fetchOne($sql, $params)); }
    public function execute(string $sql, array $params = []): Future { return $this->measure(fn () => $this->inner->execute($sql, $params)); }
    public function transaction(callable $callback): Future
    {
        return $this->inner->transaction(fn ($tx) => $callback(new self($tx, $this->metrics)));
    }
    private function measure(callable $query): Future
    {
        return async(function () use ($query) {
            $start = hrtime(true); $failed = true;
            try { $result = $query()->await(); $failed = false; return $result; }
            finally { $this->metrics->query((hrtime(true) - $start) / 1e9, $failed); }
        });
    }
}

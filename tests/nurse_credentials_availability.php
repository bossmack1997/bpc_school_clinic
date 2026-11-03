<?php
/** No database connection: exercise metadata failures without changing school data. */
require_once __DIR__ . '/../includes/nurse_credentials_service.php';
final class UnavailableCredentialPDO extends PDO
{
    public function __construct(private int $driver) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        $e = new PDOException('Synthetic metadata failure');
        $e->errorInfo = [$this->driver === 1146 ? '42S02' : '42000', $this->driver, ''];
        throw $e;
    }
}
foreach ([1146 => 'database update', 1142 => 'Please retry', 2006 => 'Please retry'] as $driver => $message) {
    $service = new NurseCredentialsService(new UnavailableCredentialPDO($driver), ['id' => 1, 'role' => 'nurse']);
    if ($service->ready() || count($service->missingSchema()) !== 2 || !str_contains($service->unavailableMessage(), $message)) {
        throw new RuntimeException('Incorrect credential availability state for driver ' . $driver);
    }
    try {
        $service->saveDraft([]);
        throw new LogicException('Unavailable service allowed a write');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== $service->unavailableMessage()) throw $e;
    }
    echo 'PASS metadata failure ' . $driver . ' blocks writes and gives accurate recovery guidance' . PHP_EOL;
}

<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Doctrine;

use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use YetiDevWorks\YetiSQL\Engine\Blob;
use YetiDevWorks\YetiSQL\Engine\Database;
use YetiDevWorks\YetiSQL\Sql\Ast\Statement as Ast;

/**
 * DBAL driver statement. Parses once at construction, accumulates bound
 * parameters, and produces a {@see Result} on execute().
 */
final class Statement implements StatementInterface
{
    /** @var list<Ast> */
    private array $statements;
    /** @var array<string,null|int|float|string|Blob> */
    private array $params = [];

    public function __construct(private readonly Database $db, string $sql)
    {
        $this->statements = $this->db->parse($sql);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $key = \is_int($param) ? (string) $param : \ltrim($param, ':@$');
        $this->params[$key] = $this->coerce($value, $type);
    }

    public function execute(): Result
    {
        $result = null;
        foreach ($this->statements as $stmt) {
            $result = $this->db->execute($stmt, $this->params);
        }
        return new Result($result);
    }

    private function coerce(mixed $value, ParameterType $type): null|int|float|string|Blob
    {
        if ($value === null || $type === ParameterType::NULL) {
            return null;
        }
        return match ($type) {
            ParameterType::INTEGER => (int) $value,
            ParameterType::BOOLEAN => $value ? 1 : 0,
            ParameterType::LARGE_OBJECT, ParameterType::BINARY => new Blob((string) $value),
            default => $this->coerceByPhpType($value),
        };
    }

    private function coerceByPhpType(mixed $value): null|int|float|string|Blob
    {
        return match (true) {
            \is_bool($value) => $value ? 1 : 0,
            \is_int($value) => $value,
            \is_float($value) => $value,
            $value instanceof Blob => $value,
            default => (string) $value,
        };
    }
}

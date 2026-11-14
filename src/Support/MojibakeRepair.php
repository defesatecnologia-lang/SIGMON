<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Repara textos UTF-8 gravados com codificacao dupla (mojibake).
 *
 * Exemplo:
 *   "Base de AmbulÃ¢ncias e Central de RegulaÃ§Ã£o - SAMU"
 *   -> "Base de Ambulâncias e Central de Regulação - SAMU"
 *
 * A reparacao e conservadora:
 *  - so considera valores que contenham marcadores tipicos de mojibake;
 *  - so altera quando a conversao produz uma string UTF-8 valida;
 *  - valores ASCII ou ja corretos permanecem intactos.
 */
final class MojibakeRepair
{
    /**
     * Tabelas de log/auditoria/sessao/controle que nao devem ser reescritas.
     *
     * @var list<string>
     */
    public const DEFAULT_SKIP_TABLES = [
        'auditoria_buffer',
        'auditoria_eventos',
        'geocode_cache',
        'login_attempts',
        'migrations',
        'rate_limits',
        'sequencia_codigo_publico',
        'user_sessions',
    ];

    /** @var list<string> */
    private const TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];

    private const BATCH_SIZE = 500;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Percorre as colunas de texto das tabelas base e corrige mojibake.
     *
     * @param list<string> $skipTables
     * @return array{scanned:int, repaired:int, tables:array<string,int>, skipped:list<string>}
     */
    public function repair(array $skipTables = self::DEFAULT_SKIP_TABLES, bool $dryRun = false): array
    {
        $skip = array_fill_keys($skipTables, true);
        $result = [
            'scanned' => 0,
            'repaired' => 0,
            'tables' => [],
            'skipped' => [],
        ];

        foreach ($this->textColumns() as $table => $columns) {
            if (isset($skip[$table])) {
                continue;
            }

            try {
                $primaryKey = $this->primaryKeyColumns($table);
            } catch (Throwable $e) {
                $result['skipped'][] = $table . ' (falha ao ler a chave primaria)';
                error_log('[MojibakeRepair] ' . $e->getMessage());
                continue;
            }

            if ($primaryKey === []) {
                $result['skipped'][] = $table . ' (sem chave primaria)';
                continue;
            }

            foreach ($columns as $column) {
                try {
                    $columnResult = $this->repairColumn($table, $column, $primaryKey, $dryRun);
                } catch (Throwable $e) {
                    // Uma coluna/tabela com problema nao deve interromper o reparo
                    // das demais; o operador pode reexecutar manualmente.
                    $result['skipped'][] = $table . '.' . $column . ' (' . $e->getMessage() . ')';
                    error_log(sprintf(
                        '[MojibakeRepair] Falha ao processar %s.%s: %s',
                        $table,
                        $column,
                        $e->getMessage()
                    ));
                    continue;
                }

                $result['scanned'] += $columnResult['scanned'];
                $result['repaired'] += $columnResult['repaired'];

                if ($columnResult['repaired'] > 0) {
                    $result['tables'][$table] = ($result['tables'][$table] ?? 0) + $columnResult['repaired'];
                }
            }
        }

        return $result;
    }

    /**
     * Corrige um valor isolado. Retorna null quando nada deve ser alterado.
     */
    public static function repairValue(string $value): ?string
    {
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || !self::looksLikeMojibake($value)) {
            return null;
        }

        $current = $value;

        // Uma mesma string pode ter sido codificada em UTF-8 mais de uma vez.
        for ($i = 0; $i < 3; $i++) {
            $decoded = self::decodeOnce($current);
            if ($decoded === null) {
                break;
            }
            $current = $decoded;
        }

        return $current === $value ? null : $current;
    }

    /**
     * @return array<string, list<string>>
     */
    private function textColumns(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::TEXT_TYPES), '?'));
        // Aliases em minuscula: o information_schema do MySQL devolve os
        // rotulos em maiusculas, e o codigo abaixo os acessa por nome.
        $sql = "SELECT c.table_name AS table_name, c.column_name AS column_name
                  FROM information_schema.columns c
                  JOIN information_schema.tables t
                    ON t.table_schema = c.table_schema
                   AND t.table_name = c.table_name
                   AND t.table_type = 'BASE TABLE'
                 WHERE c.table_schema = DATABASE()
                   AND c.data_type IN ({$placeholders})
                   AND (c.extra IS NULL OR c.extra NOT LIKE '%GENERATED%')
                 ORDER BY c.table_name, c.ordinal_position";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(self::TEXT_TYPES);

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string)$row['table_name'];
            $columns[$table][] = (string)$row['column_name'];
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function primaryKeyColumns(string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT column_name
               FROM information_schema.key_column_usage
              WHERE table_schema = DATABASE()
                AND table_name = ?
                AND constraint_name = 'PRIMARY'
              ORDER BY ordinal_position"
        );
        $stmt->execute([$table]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<string> $primaryKey
     * @return array{scanned:int, repaired:int}
     */
    private function repairColumn(string $table, string $column, array $primaryKey, bool $dryRun): array
    {
        $tableId = self::quoteIdentifier($table);
        $columnId = self::quoteIdentifier($column);
        $pkColumns = implode(', ', array_map(
            static fn(string $pk): string => self::quoteIdentifier($pk),
            $primaryKey
        ));

        $select = $this->pdo->prepare(
            "SELECT {$pkColumns}, {$columnId} AS `__value`
               FROM {$tableId}
              ORDER BY {$pkColumns}
              LIMIT :limit OFFSET :offset"
        );

        $update = null;
        if (!$dryRun) {
            $where = implode(' AND ', array_map(
                static fn(string $pk): string => self::quoteIdentifier($pk) . ' = ?',
                $primaryKey
            ));
            $update = $this->pdo->prepare(
                "UPDATE {$tableId} SET {$columnId} = ? WHERE {$where}"
            );
        }

        $scanned = 0;
        $repaired = 0;
        $offset = 0;

        while (true) {
            $select->bindValue(':limit', self::BATCH_SIZE, PDO::PARAM_INT);
            $select->bindValue(':offset', $offset, PDO::PARAM_INT);
            $select->execute();
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            $count = count($rows);

            if ($count === 0) {
                break;
            }

            foreach ($rows as $row) {
                $scanned++;
                $value = $row['__value'] ?? null;
                if (!is_string($value)) {
                    continue;
                }

                $fixed = self::repairValue($value);
                if ($fixed === null) {
                    continue;
                }

                $repaired++;

                if ($update === null) {
                    continue;
                }

                $params = [$fixed];
                foreach ($primaryKey as $pk) {
                    $params[] = $row[$pk] ?? null;
                }

                try {
                    $update->execute($params);
                } catch (Throwable $e) {
                    $repaired--;
                    error_log(sprintf(
                        '[MojibakeRepair] Falha ao atualizar %s.%s: %s',
                        $table,
                        $column,
                        $e->getMessage()
                    ));
                }
            }

            $offset += $count;
        }

        $select->closeCursor();

        return ['scanned' => $scanned, 'repaired' => $repaired];
    }

    private static function looksLikeMojibake(string $value): bool
    {
        return str_contains($value, 'Ã')
            || str_contains($value, 'Â')
            || str_contains($value, 'â€')
            || str_contains($value, 'ðŸ');
    }

    private static function decodeOnce(string $value): ?string
    {
        foreach (['ISO-8859-1', 'Windows-1252'] as $charset) {
            $decoded = @iconv('UTF-8', $charset, $value);
            if ($decoded === false || $decoded === $value) {
                continue;
            }
            if (mb_check_encoding($decoded, 'UTF-8')) {
                return $decoded;
            }
        }

        return null;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

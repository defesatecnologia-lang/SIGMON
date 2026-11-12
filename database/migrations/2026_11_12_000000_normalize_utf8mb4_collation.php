<?php

declare(strict_types=1);

/**
 * Migration: Normaliza a collation do banco para utf8mb4_unicode_ci.
 *
 * Problema: o entrypoint oficial do MySQL cria o banco a partir de
 * MYSQL_DATABASE usando o collation padrão do servidor (utf8mb4_0900_ai_ci),
 * antes de executar o `cftv_gml.sql`. O `CREATE DATABASE IF NOT EXISTS ...
 * COLLATE utf8mb4_unicode_ci` do dump vira no-op, e as tabelas que não
 * declaram COLLATE explicitamente herdam 0900_ai_ci — enquanto as que
 * declaram ficam unicode_ci.
 *
 * Efeito: consultas que misturam colunas das duas famílias (UNION/JOIN de
 * texto) falham com "Illegal mix of collations" (erro 1271), quebrando o
 * Relatório Diário e consultas por secretaria.
 *
 * A migration alinha o default do banco e converte todas as tabelas base
 * para utf8mb4_unicode_ci, preservando os dados.
 */
return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $stmt = $pdo->query(
            "SELECT table_name
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_type = 'BASE TABLE'
                AND table_collation IS NOT NULL
                AND table_collation <> 'utf8mb4_unicode_ci'
              ORDER BY table_name"
        );
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt->closeCursor();

        foreach ($tables as $table) {
            $identifier = '`' . str_replace('`', '``', (string)$table) . '`';
            $pdo->exec(
                "ALTER TABLE {$identifier} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        }
    },
    'down' => function (PDO $pdo): void {
        // Normalização não destrutiva: não há como reverter com segurança para
        // a collation anterior (que era inconsistente entre tabelas).
    },
];

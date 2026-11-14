<?php

declare(strict_types=1);

/**
 * Migration: Garante a coluna usuarios.secretaria_id.
 *
 * O vinculo usuario <-> secretaria foi introduzido direto no schema base
 * (config/DB/cftv_gml.sql, commit 8d67ccd). Instalacoes cujo volume MySQL ja
 * existia antes desse recurso nunca receberam a coluna, pois o dump inicial
 * do entrypoint do MySQL so roda com o volume vazio.
 *
 * Sem a coluna, o INSERT de novos usuarios falha — ou, em versoes antigas do
 * handler, o vinculo simplesmente nao e persistido. Esta migration e
 * idempotente e segura para bases que ja possuem a coluna/indice/FK.
 */
return [
    'up' => function (PDO $pdo): void {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'usuarios'
                AND column_name = 'secretaria_id'"
        );
        $stmt->execute();
        $hasColumn = (int)$stmt->fetchColumn() > 0;
        $stmt->closeCursor();

        if (!$hasColumn) {
            $pdo->exec('ALTER TABLE usuarios ADD COLUMN secretaria_id INT NULL AFTER nivel_acesso_id');
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name = 'usuarios'
                AND index_name = 'idx_usuarios_secretaria'"
        );
        $stmt->execute();
        $hasIndex = (int)$stmt->fetchColumn() > 0;
        $stmt->closeCursor();

        if (!$hasIndex) {
            $pdo->exec('ALTER TABLE usuarios ADD INDEX idx_usuarios_secretaria (secretaria_id)');
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM information_schema.table_constraints
              WHERE table_schema = DATABASE()
                AND table_name = 'usuarios'
                AND constraint_name = 'fk_usuarios_secretaria'
                AND constraint_type = 'FOREIGN KEY'"
        );
        $stmt->execute();
        $hasForeignKey = (int)$stmt->fetchColumn() > 0;
        $stmt->closeCursor();

        if ($hasForeignKey) {
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name = 'secretarias'
                AND table_type = 'BASE TABLE'"
        );
        $stmt->execute();
        $hasSecretarias = (int)$stmt->fetchColumn() > 0;
        $stmt->closeCursor();

        if (!$hasSecretarias) {
            return;
        }

        // Nao cria a FK se houver vinculos orfaos (dados legados inconsistentes).
        $orphans = (int)$pdo->query(
            "SELECT COUNT(*)
               FROM usuarios u
               LEFT JOIN secretarias s ON s.id = u.secretaria_id
              WHERE u.secretaria_id IS NOT NULL
                AND s.id IS NULL"
        )->fetchColumn();

        if ($orphans === 0) {
            $pdo->exec(
                'ALTER TABLE usuarios
                   ADD CONSTRAINT fk_usuarios_secretaria
                   FOREIGN KEY (secretaria_id) REFERENCES secretarias(id)
                   ON DELETE SET NULL'
            );
        } else {
            error_log(sprintf(
                '[migration] usuarios.secretaria_id possui %d vinculo(s) orfao(s); FK nao criada.',
                $orphans
            ));
        }
    },
    'down' => function (PDO $pdo): void {
        // Nao remove coluna/indice/FK: isso apagaria os vinculos dos usuarios.
    },
];

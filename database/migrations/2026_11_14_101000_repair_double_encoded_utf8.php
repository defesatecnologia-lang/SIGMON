<?php

declare(strict_types=1);

/**
 * Migration: Repara textos gravados em UTF-8 duplamente codificado.
 *
 * Sintoma tipico:
 *   "Base de AmbulÃ¢ncias e Central de RegulaÃ§Ã£o - SAMU"
 *   em vez de
 *   "Base de Ambulâncias e Central de Regulação - SAMU"
 *
 * A migration percorre as colunas de texto das tabelas base e so altera
 * valores que:
 *   1. contenham marcadores tipicos de mojibake (Ã, Â, â€, ðŸ);
 *   2. resultem em UTF-8 valido apos a conversao.
 *
 * Assim, textos corretos (inclusive com acentos) e valores ASCII permanecem
 * intactos. Tabelas de auditoria/log/sessao sao ignoradas para nao reescrever
 * historico e para manter a migration rapida.
 *
 * Para uma simulacao antes de aplicar, use:
 *   php scripts/repair-mojibake.php --dry-run
 */

require_once __DIR__ . '/../../src/Support/MojibakeRepair.php';

return [
    'up' => function (PDO $pdo): void {
        $repair = new \App\Support\MojibakeRepair($pdo);
        $result = $repair->repair();

        if ($result['repaired'] > 0) {
            error_log(sprintf(
                '[migration] mojibake reparado: %d valor(es) em %d tabela(s).',
                $result['repaired'],
                count($result['tables'])
            ));
        }
    },
    'down' => function (PDO $pdo): void {
        // Reparo de dados nao e reversivel com seguranca.
    },
];

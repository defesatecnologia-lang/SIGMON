<?php

declare(strict_types=1);

/**
 * Repara textos UTF-8 duplamente codificados (mojibake).
 *
 * Uso:
 *   php scripts/repair-mojibake.php --dry-run   # apenas simula
 *   php scripts/repair-mojibake.php             # aplica as correcoes
 *   php scripts/repair-mojibake.php --all       # inclui tabelas de auditoria/log
 *
 * A migration 2026_11_14_101000_repair_double_encoded_utf8 usa a mesma logica
 * automaticamente no bootstrap. Este script permite simular/rodar manualmente.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Support/MojibakeRepair.php';

$dryRun = in_array('--dry-run', $argv, true);
$all = in_array('--all', $argv, true);

$skipTables = $all ? [] : \App\Support\MojibakeRepair::DEFAULT_SKIP_TABLES;

echo "=== Reparo de mojibake UTF-8 ===\n";
echo $dryRun ? "Modo: simulacao (nenhuma alteracao sera gravada)\n" : "Modo: aplicacao\n";
echo 'Tabelas ignoradas: ' . ($all ? '(nenhuma)' : implode(', ', $skipTables)) . "\n\n";

try {
    $pdo = db()->getConnection();
    $repair = new \App\Support\MojibakeRepair($pdo);
    $result = $repair->repair($skipTables, $dryRun);
} catch (Throwable $e) {
    fwrite(STDERR, "ERRO: " . $e->getMessage() . "\n");
    exit(1);
}

if ($result['tables'] === []) {
    echo "Nenhum valor com mojibake encontrado.\n";
} else {
    ksort($result['tables']);
    foreach ($result['tables'] as $table => $count) {
        printf("  %-40s %d valor(es)\n", $table, $count);
    }
}

echo "\n";
printf(
    "Total: %d valor(es) %s em %d tabela(s); %d linha(s) verificada(s).\n",
    $result['repaired'],
    $dryRun ? 'seriam corrigidos' : 'corrigido(s)',
    count($result['tables']),
    $result['scanned']
);

if ($result['skipped'] !== []) {
    echo "Tabelas ignoradas por falta de chave primaria:\n";
    foreach ($result['skipped'] as $table) {
        echo '  - ' . $table . "\n";
    }
}

if ($dryRun) {
    echo "\nNenhuma alteracao foi gravada. Rode sem --dry-run para aplicar.\n";
}

exit(0);

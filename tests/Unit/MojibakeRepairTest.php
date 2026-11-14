<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MojibakeRepair;
use PHPUnit\Framework\TestCase;

/**
 * Testes do reparo de UTF-8 duplamente codificado (mojibake).
 */
final class MojibakeRepairTest extends TestCase
{
    public function testRepairsDoubleEncodedUtf8(): void
    {
        $cases = [
            'Base de AmbulÃ¢ncias e Central de RegulaÃ§Ã£o - SAMU'
                => 'Base de Ambulâncias e Central de Regulação - SAMU',
            'EducaÃ§Ã£o' => 'Educação',
            'SAÃšDE' => 'SAÚDE',
            '1Âº andar' => '1º andar',
            'Aâ€“B' => 'A–B',
            'Teste ðŸ˜…' => 'Teste 😅',
            'NÃ£o hÃ¡ vagas' => 'Não há vagas',
        ];

        foreach ($cases as $input => $expected) {
            self::assertSame($expected, MojibakeRepair::repairValue($input), 'Entrada: ' . $input);
        }
    }

    public function testKeepsAlreadyCorrectValuesUntouched(): void
    {
        $values = [
            'SAMU',
            'Educação e Saúde',
            'SÃO PAULO',
            'Ação, coração e endereço',
            '😅',
            '',
        ];

        foreach ($values as $value) {
            self::assertNull(MojibakeRepair::repairValue($value), 'Nao deveria alterar: ' . $value);
        }
    }

    public function testRepairIsIdempotent(): void
    {
        $fixed = MojibakeRepair::repairValue('Central de RegulaÃ§Ã£o');

        self::assertSame('Central de Regulação', $fixed);
        self::assertNull(MojibakeRepair::repairValue((string)$fixed));
    }
}

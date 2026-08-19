<?php

/**
 * Verifies the loyalty-program register fragment shape.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Ensures lib/Settings/register.d/70-loyalty-program.json declares all 9 schemas.
 */
class LoyaltyRegisterFragmentTest extends TestCase
{
    public function testFragmentDeclaresAllNineSchemas(): void
    {
        $path = __DIR__ . '/../../../lib/Settings/register.d/70-loyalty-program.json';
        $this->assertFileExists($path, 'Loyalty register fragment is missing');

        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data, 'Fragment JSON is invalid');

        $schemas = $data['components']['schemas'] ?? [];
        $expected = [
            'loyaltyProgramme',
            'pointsRule',
            'tierRule',
            'klantLoyaltyAccount',
            'pointsLedgerEntry',
            'redemptionOption',
            'redemption',
            'giftCard',
            'giftCardTransaction',
        ];
        foreach ($expected as $slug) {
            $this->assertArrayHasKey(
                $slug,
                $schemas,
                "Missing loyalty schema: {$slug}"
            );
        }

        // Verify register registration.
        $registers = $data['components']['registers']['pipelinq']['schemas'] ?? [];
        foreach ($expected as $slug) {
            $this->assertContains(
                $slug,
                $registers,
                "Register 'pipelinq' must list schema: {$slug}"
            );
        }
    }//end testFragmentDeclaresAllNineSchemas()

    public function testGiftCardSchemaFlagsPinAsHashed(): void
    {
        $path = __DIR__ . '/../../../lib/Settings/register.d/70-loyalty-program.json';
        $data = json_decode((string) file_get_contents($path), true);

        $pin = $data['components']['schemas']['giftCard']['properties']['pin'] ?? null;
        $this->assertIsArray($pin, 'giftCard.pin property must be defined');
        $description = strtolower((string) ($pin['description'] ?? ''));
        $this->assertStringContainsString('hash', $description, 'PIN must be documented as hashed');
        $this->assertStringContainsString('never', $description, 'PIN documentation must say "never" plaintext');
    }//end testGiftCardSchemaFlagsPinAsHashed()
}//end class

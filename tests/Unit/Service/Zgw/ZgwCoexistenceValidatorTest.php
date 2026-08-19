<?php

/**
 * Unit tests for ZgwCoexistenceValidator.
 *
 * Covers the REQ-ZGW-008 scenarios: a gemeente with both ZGW + StUF
 * write-on raises DoubleWritePathException; a gemeente with only one
 * active write path validates cleanly; a gemeente with only read paths
 * also validates.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\DoubleWritePathException;
use OCA\Pipelinq\Service\Zgw\ZgwCoexistenceValidator;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ZgwCoexistenceValidator.
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-008
 */
class ZgwCoexistenceValidatorTest extends TestCase
{
    /**
     * Build a register-access mock that returns the supplied tables.
     *
     * @param array<string, array<int, array<string, mixed>>> $tables Schema-keyed rows.
     *
     * @return ZgwRegisterAccess
     */
    private function registerAccessWith(array $tables): ZgwRegisterAccess
    {
        $mock = $this->createMock(ZgwRegisterAccess::class);
        $mock->method('findAll')->willReturnCallback(
            static function (string $schema, array $filters) use ($tables): array {
                $rows = $tables[$schema] ?? [];
                $code = (string) ($filters['gemeenteCode'] ?? '');
                if ($code === '') {
                    return $rows;
                }
                return array_values(array_filter($rows, static function (array $row) use ($code): bool {
                    return (string) ($row['gemeenteCode'] ?? '') === $code;
                }));
            }
        );
        return $mock;
    }//end registerAccessWith()


    /**
     * Test: both write paths active → DoubleWritePathException.
     *
     * @return void
     */
    public function testBothWritePathsActiveRaises(): void
    {
        $registers = $this->registerAccessWith([
            ZgwRegisterAccess::SCHEMA_ENDPOINT => [
                ['id' => 'zgw-zo', 'gemeenteCode' => '0637', 'actief' => true, 'readOnly' => false],
            ],
            ZgwCoexistenceValidator::STUF_ENDPOINT_SCHEMA => [
                ['id' => 'stuf-zo', 'gemeenteCode' => '0637', 'write' => 'on'],
            ],
        ]);

        $logger    = $this->createMock(LoggerInterface::class);
        $validator = new ZgwCoexistenceValidator($registers, $logger);

        $this->expectException(DoubleWritePathException::class);
        $validator->validateWritePath('0637');
    }//end testBothWritePathsActiveRaises()


    /**
     * Test: only ZGW write path active → passes.
     *
     * @return void
     */
    public function testZgwOnlyWritePathPasses(): void
    {
        $registers = $this->registerAccessWith([
            ZgwRegisterAccess::SCHEMA_ENDPOINT => [
                ['id' => 'zgw-zo', 'gemeenteCode' => '0637', 'actief' => true, 'readOnly' => false],
            ],
            ZgwCoexistenceValidator::STUF_ENDPOINT_SCHEMA => [
                ['id' => 'stuf-zo', 'gemeenteCode' => '0637', 'write' => 'off'],
            ],
        ]);

        $validator = new ZgwCoexistenceValidator($registers, $this->createMock(LoggerInterface::class));
        $validator->validateWritePath('0637');
        self::assertTrue(true, 'validateWritePath did not throw');
    }//end testZgwOnlyWritePathPasses()


    /**
     * Test: read-only ZGW + write-off StUF → both paths considered read; passes.
     *
     * @return void
     */
    public function testBothReadOnlyPasses(): void
    {
        $registers = $this->registerAccessWith([
            ZgwRegisterAccess::SCHEMA_ENDPOINT => [
                ['id' => 'zgw-zo', 'gemeenteCode' => '0637', 'actief' => true, 'readOnly' => true],
            ],
            ZgwCoexistenceValidator::STUF_ENDPOINT_SCHEMA => [
                ['id' => 'stuf-zo', 'gemeenteCode' => '0637', 'write' => 'off'],
            ],
        ]);

        $validator = new ZgwCoexistenceValidator($registers, $this->createMock(LoggerInterface::class));
        $validator->validateWritePath('0637');
        self::assertTrue(true);
    }//end testBothReadOnlyPasses()


    /**
     * Test: empty gemeente code skips validation (defensive).
     *
     * @return void
     */
    public function testEmptyGemeenteCodeSkipsValidation(): void
    {
        $validator = new ZgwCoexistenceValidator(
            $this->createMock(ZgwRegisterAccess::class),
            $this->createMock(LoggerInterface::class)
        );
        $validator->validateWritePath('');
        self::assertTrue(true);
    }//end testEmptyGemeenteCodeSkipsValidation()


    /**
     * Test: DoubleWritePathException carries the conflicting endpoint ids.
     *
     * @return void
     */
    public function testExceptionCarriesConflictingIds(): void
    {
        $registers = $this->registerAccessWith([
            ZgwRegisterAccess::SCHEMA_ENDPOINT => [
                ['id' => 'zgw-zo', 'gemeenteCode' => '0637', 'actief' => true, 'readOnly' => false],
            ],
            ZgwCoexistenceValidator::STUF_ENDPOINT_SCHEMA => [
                ['id' => 'stuf-zo', 'gemeenteCode' => '0637', 'write' => 'on'],
            ],
        ]);

        $validator = new ZgwCoexistenceValidator($registers, $this->createMock(LoggerInterface::class));
        try {
            $validator->validateWritePath('0637');
            self::fail('expected DoubleWritePathException');
        } catch (DoubleWritePathException $e) {
            self::assertContains('zgw:zgw-zo', $e->conflictEndpointIds);
            self::assertContains('stuf:stuf-zo', $e->conflictEndpointIds);
        }
    }//end testExceptionCarriesConflictingIds()


}//end class

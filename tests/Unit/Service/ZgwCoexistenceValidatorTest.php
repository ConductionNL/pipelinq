<?php

/**
 * Unit tests for ZgwCoexistenceValidator (StUF/ZGW double-write prevention).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Exception\DoubleWritePathException;
use OCA\Pipelinq\Service\ZgwCoexistenceValidator;
use OCA\Pipelinq\Service\ZgwObjectRepository;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ZgwCoexistenceValidator.
 */
class ZgwCoexistenceValidatorTest extends TestCase
{
    /**
     * Build a validator with stubbed ZGW endpoints and an optional StUF ObjectService.
     *
     * @param array<int, array<string, mixed>> $zgwEndpoints The ZGW endpoint objects.
     * @param bool                              $stufInstalled Whether the StUF schema is configured.
     * @param array<int, array<string, mixed>> $stufEndpoints The active StUF endpoint objects.
     *
     * @return ZgwCoexistenceValidator The validator under test.
     */
    private function makeValidator(array $zgwEndpoints, bool $stufInstalled, array $stufEndpoints=[]): ZgwCoexistenceValidator
    {
        $repo = $this->createMock(ZgwObjectRepository::class);
        $repo->method('findBy')->willReturn($zgwEndpoints);
        $repo->method('toArray')->willReturnCallback(static fn(mixed $o): array => (array) $o);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default='') use ($stufInstalled): string {
                if ($key === 'register') {
                    return 'reg-1';
                }

                if ($key === 'stufEndpoint_schema') {
                    return ($stufInstalled === true) ? 'stuf-schema-id' : '';
                }

                return $default;
            }
        );

        $objectService = new class($stufEndpoints) {
            /**
             * @param array<int, array<string, mixed>> $rows The StUF rows to return.
             */
            public function __construct(private array $rows)
            {
            }

            /**
             * @param array<string, mixed> $config The query config (ignored).
             *
             * @return array<int, array<string, mixed>> The stubbed rows.
             */
            public function findAll(array $config): array
            {
                return $this->rows;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        return new ZgwCoexistenceValidator($repo, $appConfig, $container, $this->createMock(LoggerInterface::class));
    }//end makeValidator()

    /**
     * A single ZGW write path passes (no StUF installed).
     *
     * @return void
     */
    public function testSingleZgwWritePathPasses(): void
    {
        $validator = $this->makeValidator(
            [['id' => 'zgw1', 'actief' => true, 'readOnly' => false]],
            false
        );

        $validator->validateWritePath('0637');
        $this->assertTrue(true);
    }//end testSingleZgwWritePathPasses()

    /**
     * Both StUF and ZGW write paths active raises DoubleWritePathException.
     *
     * @return void
     */
    public function testDoubleWritePathRaises(): void
    {
        $validator = $this->makeValidator(
            [['id' => 'zgw1', 'actief' => true, 'readOnly' => false]],
            true,
            [['id' => 'stuf1']]
        );

        $this->expectException(DoubleWritePathException::class);
        $validator->validateWritePath('0637');
    }//end testDoubleWritePathRaises()

    /**
     * ZGW read-only + active StUF does not conflict.
     *
     * @return void
     */
    public function testReadOnlyZgwDoesNotConflict(): void
    {
        $validator = $this->makeValidator(
            [['id' => 'zgw1', 'actief' => true, 'readOnly' => true]],
            true,
            [['id' => 'stuf1']]
        );

        $validator->validateWritePath('0637');
        $this->assertTrue(true);
    }//end testReadOnlyZgwDoesNotConflict()

    /**
     * The raised exception carries the conflicting endpoints and gemeente code.
     *
     * @return void
     */
    public function testExceptionCarriesContext(): void
    {
        $validator = $this->makeValidator(
            [['id' => 'zgw1', 'actief' => true, 'readOnly' => false]],
            true,
            [['id' => 'stuf1']]
        );

        try {
            $validator->validateWritePath('0637');
            $this->fail('Expected DoubleWritePathException');
        } catch (DoubleWritePathException $e) {
            $this->assertSame('0637', $e->getGemeenteCode());
            $this->assertContains('zgw:zgw1', $e->getConflictingEndpoints());
            $this->assertContains('stuf:stuf1', $e->getConflictingEndpoints());
        }
    }//end testExceptionCarriesContext()
}//end class

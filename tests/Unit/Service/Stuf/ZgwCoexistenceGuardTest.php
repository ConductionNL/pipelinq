<?php

/**
 * Unit tests for ZgwCoexistenceGuard.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Stuf;

use OCA\Pipelinq\Service\Stuf\ZgwCoexistenceGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ZgwCoexistenceGuard.
 */
class ZgwCoexistenceGuardTest extends TestCase
{

    /**
     * The guard under test.
     *
     * @var ZgwCoexistenceGuard
     */
    private ZgwCoexistenceGuard $guard;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->guard = new ZgwCoexistenceGuard(logger: $this->createMock(LoggerInterface::class));
    }//end setUp()

    /**
     * With no existing zaak mapping, StUF registration is allowed.
     *
     * @return void
     */
    public function testAllowsWhenNoZaakMapping(): void
    {
        $this->assertTrue($this->guard->mayRegisterZaak(existingMappings: []));
        $this->assertTrue(
            $this->guard->mayRegisterZaak(
                existingMappings: [['externEntiteit' => 'NPS', 'externIdentificatie' => '123']]
            )
        );
    }//end testAllowsWhenNoZaakMapping()

    /**
     * An existing ZAK mapping (StUF or ZGW) blocks a duplicate registration.
     *
     * @return void
     */
    public function testBlocksWhenZaakMappingExists(): void
    {
        $mappings = [['externEntiteit' => 'ZAK', 'externIdentificatie' => 'ZAAK-2026-0008812']];

        $this->assertFalse($this->guard->mayRegisterZaak(existingMappings: $mappings));
        $this->assertSame(
            'ZAAK-2026-0008812',
            $this->guard->existingZaakIdentificatie(existingMappings: $mappings)
        );
    }//end testBlocksWhenZaakMappingExists()

    /**
     * existingZaakIdentificatie returns null when no zaak mapping is present.
     *
     * @return void
     */
    public function testExistingZaakIdentificatieNullWhenNone(): void
    {
        $this->assertNull(
            $this->guard->existingZaakIdentificatie(
                existingMappings: [['externEntiteit' => 'NPS', 'externIdentificatie' => '123']]
            )
        );
    }//end testExistingZaakIdentificatieNullWhenNone()
}//end class

<?php

/**
 * Unit tests for BrcClient (createBesluit + besluitinformatieobject link).
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

use OCA\Pipelinq\Service\AcClient;
use OCA\Pipelinq\Service\BrcClient;
use OCA\Pipelinq\Service\ZgwApiClient;
use OCA\Pipelinq\Service\ZgwObjectRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BrcClient.
 */
class BrcClientTest extends TestCase
{
    /**
     * createBesluit checks besluiten.aanmaken scope and persists a besluit mapping.
     *
     * @return void
     */
    public function testCreateBesluitPersistsMapping(): void
    {
        $api = $this->createMock(ZgwApiClient::class);
        $api->method('callComponent')->willReturn(
            ['status' => 201, 'body' => ['url' => 'https://brc/api/v1/besluiten/b1'], 'headers' => [], 'etag' => 'W/"b"']
        );

        $repo = $this->createMock(ZgwObjectRepository::class);
        $repo->method('save')->willReturnCallback(static fn(string $e, array $d): array => $d);

        $ac = $this->createMock(AcClient::class);
        $ac->expects($this->once())->method('requireScope')
            ->with($this->anything(), $this->anything(), 'https://ztc/besluittypen/verleend', 'besluiten.aanmaken');

        $brc     = new BrcClient($api, $repo, $ac);
        $mapping = $brc->createBesluit(
            ['id' => 'ep1', 'componenten' => ['brc' => 'https://brc/api/v1']],
            ['clientIdentifier' => 'c'],
            ['zgwUrl' => 'https://zrc/zaken/z1', 'pipelinqId' => 'req-9'],
            ['besluittype' => 'https://ztc/besluittypen/verleend', 'datum' => '2026-06-15']
        );

        $this->assertSame('https://brc/api/v1/besluiten/b1', $mapping['zgwUrl']);
        $this->assertSame('besluit', $mapping['zgwResourceType']);
        $this->assertSame('req-9', $mapping['pipelinqId']);
    }//end testCreateBesluitPersistsMapping()
}//end class

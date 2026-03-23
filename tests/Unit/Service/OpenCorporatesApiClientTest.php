<?php

/**
 * Unit tests for OpenCorporatesApiClient.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\OpenCorporatesApiClient;
use OCA\Pipelinq\Service\OpenCorporatesResultMapper;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for OpenCorporatesApiClient.
 */
class OpenCorporatesApiClientTest extends TestCase
{
    /**
     * Test search returns empty for empty keywords.
     *
     * @return void
     */
    public function testSearchReturnsEmptyForNoKeywords(): void
    {
        $clientService = $this->createMock(IClientService::class);
        $logger        = $this->createMock(LoggerInterface::class);
        $resultMapper  = new OpenCorporatesResultMapper();

        $client = new OpenCorporatesApiClient($clientService, $logger, $resultMapper);

        $this->assertSame([], $client->search(['keywords' => []]));
    }//end testSearchReturnsEmptyForNoKeywords()

    /**
     * Test search returns empty with no keywords key.
     *
     * @return void
     */
    public function testSearchReturnsEmptyWithoutKeywordsKey(): void
    {
        $clientService = $this->createMock(IClientService::class);
        $logger        = $this->createMock(LoggerInterface::class);
        $resultMapper  = new OpenCorporatesResultMapper();

        $client = new OpenCorporatesApiClient($clientService, $logger, $resultMapper);

        $this->assertSame([], $client->search([]));
    }//end testSearchReturnsEmptyWithoutKeywordsKey()
}//end class

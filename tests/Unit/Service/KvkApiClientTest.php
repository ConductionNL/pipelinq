<?php

/**
 * Unit tests for KvkApiClient.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\KvkApiClient;
use OCA\Pipelinq\Service\KvkResultMapper;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for KvkApiClient.
 */
class KvkApiClientTest extends TestCase
{
    /**
     * Test search returns empty for empty API key.
     *
     * @return void
     */
    public function testSearchReturnsEmptyForEmptyApiKey(): void
    {
        $clientService = $this->createMock(IClientService::class);
        $logger        = $this->createMock(LoggerInterface::class);
        $resultMapper  = new KvkResultMapper();

        $client = new KvkApiClient($clientService, $logger, $resultMapper);

        $this->assertSame([], $client->search('', ['sbiCodes' => ['62']]));
    }//end testSearchReturnsEmptyForEmptyApiKey()

    /**
     * Test search returns empty for empty SBI codes.
     *
     * @return void
     */
    public function testSearchReturnsEmptyForNoSbiCodes(): void
    {
        $clientService = $this->createMock(IClientService::class);
        $logger        = $this->createMock(LoggerInterface::class);
        $resultMapper  = new KvkResultMapper();

        $client = new KvkApiClient($clientService, $logger, $resultMapper);

        $this->assertSame([], $client->search('api-key', ['sbiCodes' => []]));
    }//end testSearchReturnsEmptyForNoSbiCodes()
}//end class

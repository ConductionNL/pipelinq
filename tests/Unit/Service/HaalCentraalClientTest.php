<?php

/**
 * Unit tests for HaalCentraalClient (config + cert helpers — no HTTP).
 *
 * The full lookup/token-grant paths require an IClient mock that returns
 * configurable IResponse instances; they are exercised in the integration
 * suite. These unit tests cover the configuration-presence helpers + the
 * certificate-expiry parsing.
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#9.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\HaalCentraalException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-003
 */
class HaalCentraalClientTest extends TestCase
{
    /**
     * isConfigured returns false when any required key is missing.
     *
     * @return void
     */
    public function testIsConfiguredFalseWhenUnset(): void
    {
        $client = $this->buildClient(values: []);
        self::assertFalse($client->isConfigured());
    }//end testIsConfiguredFalseWhenUnset()

    /**
     * isConfigured returns true when all four required keys are set.
     *
     * @return void
     */
    public function testIsConfiguredTrueWhenAllSet(): void
    {
        $client = $this->buildClient(values: [
            'brp.client_id'                => 'demo-client',
            'brp.client_secret_encrypted'  => 'cipher',
            'brp.cert_path'                => '/etc/brp/cert.pem',
            'brp.key_path'                 => '/etc/brp/key.pem',
        ]);
        self::assertTrue($client->isConfigured());
    }//end testIsConfiguredTrueWhenAllSet()

    /**
     * Cert expiry returns null when cert path is unset.
     *
     * @return void
     */
    public function testGetCertificateExpiryReturnsNullWhenUnset(): void
    {
        $client = $this->buildClient(values: []);
        self::assertNull($client->getCertificateExpiry());
    }//end testGetCertificateExpiryReturnsNullWhenUnset()

    /**
     * Lookup throws a HaalCentraalException with 503 when OAuth credentials are missing.
     *
     * @return void
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-003-03
     */
    public function testLookupThrowsWhenUnconfigured(): void
    {
        $client = $this->buildClient(values: []);
        $this->expectException(HaalCentraalException::class);
        $client->lookupPersoon('123456782');
    }//end testLookupThrowsWhenUnconfigured()

    /**
     * Build a client with a stubbed IAppConfig that returns the given values.
     *
     * @param array<string,string> $values App-config key→value map.
     *
     * @return HaalCentraalClient
     */
    private function buildClient(array $values): HaalCentraalClient
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig
            ->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') use ($values) {
                return $values[$key] ?? $default;
            });

        return new HaalCentraalClient(
            $this->createMock(IClientService::class),
            $appConfig,
            $this->createMock(ICrypto::class),
            $this->createMock(LoggerInterface::class),
        );
    }//end buildClient()
}//end class

<?php

/**
 * Unit tests for ZtcClient (resolve + cache + invalidate).
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

use OCA\Pipelinq\Exception\ZaaktypeNotInCatalogusException;
use OCA\Pipelinq\Service\ZgwApiClient;
use OCA\Pipelinq\Service\ZtcClient;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ZtcClient.
 */
class ZtcClientTest extends TestCase
{
    /**
     * Endpoint fixture.
     *
     * @return array<string, mixed> The endpoint.
     */
    private function endpoint(): array
    {
        return ['id' => 'ep1', 'componenten' => ['ztc' => 'https://ztc/api/v1']];
    }//end endpoint()

    /**
     * App config returning defaults.
     *
     * @return IAppConfig The mock.
     */
    private function appConfig(): IAppConfig
    {
        $cfg = $this->createMock(IAppConfig::class);
        $cfg->method('getValueString')->willReturnArgument(2);
        return $cfg;
    }//end appConfig()

    /**
     * resolveZaaktype hits the ZTC once then serves from cache.
     *
     * @return void
     */
    public function testResolveZaaktypeCachesAfterFirstHit(): void
    {
        $api = $this->createMock(ZgwApiClient::class);
        $api->expects($this->once())->method('callComponent')->willReturn(
            ['status' => 200, 'body' => ['results' => [['url' => 'https://ztc/api/v1/zaaktypen/evt']]], 'headers' => [], 'etag' => '']
        );

        $ztc = new ZtcClient($api, $this->appConfig());

        $first  = $ztc->resolveZaaktype($this->endpoint(), ['clientIdentifier' => 'c'], 'Evenementenvergunning');
        $second = $ztc->resolveZaaktype($this->endpoint(), ['clientIdentifier' => 'c'], 'Evenementenvergunning');

        $this->assertSame('https://ztc/api/v1/zaaktypen/evt', $first);
        $this->assertSame($first, $second);
    }//end testResolveZaaktypeCachesAfterFirstHit()

    /**
     * An unknown zaaktype raises ZaaktypeNotInCatalogusException.
     *
     * @return void
     */
    public function testUnknownZaaktypeRaises(): void
    {
        $api = $this->createMock(ZgwApiClient::class);
        $api->method('callComponent')->willReturn(['status' => 200, 'body' => ['results' => []], 'headers' => [], 'etag' => '']);

        $ztc = new ZtcClient($api, $this->appConfig());

        $this->expectException(ZaaktypeNotInCatalogusException::class);
        $ztc->resolveZaaktype($this->endpoint(), ['clientIdentifier' => 'c'], 'Onbekend');
    }//end testUnknownZaaktypeRaises()

    /**
     * invalidateCache forces a re-fetch on the next resolve.
     *
     * @return void
     */
    public function testInvalidateCacheForcesRefetch(): void
    {
        $api = $this->createMock(ZgwApiClient::class);
        $api->expects($this->exactly(2))->method('callComponent')->willReturn(
            ['status' => 200, 'body' => ['results' => [['url' => 'https://ztc/api/v1/zaaktypen/evt']]], 'headers' => [], 'etag' => '']
        );

        $ztc = new ZtcClient($api, $this->appConfig());

        $ztc->resolveZaaktype($this->endpoint(), ['clientIdentifier' => 'c'], 'Evenementenvergunning');
        $ztc->invalidateCache($this->endpoint(), 'zaaktypen');
        $ztc->resolveZaaktype($this->endpoint(), ['clientIdentifier' => 'c'], 'Evenementenvergunning');
    }//end testInvalidateCacheForcesRefetch()
}//end class

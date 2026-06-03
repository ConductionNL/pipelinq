<?php

/**
 * Unit tests for AcClient (scope discovery, caching, pre-flight enforcement).
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

use OCA\Pipelinq\Exception\InsufficientScopeException;
use OCA\Pipelinq\Service\AcClient;
use OCA\Pipelinq\Service\ZgwApiClient;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AcClient.
 */
class AcClientTest extends TestCase
{
    /**
     * Endpoint fixture.
     *
     * @return array<string, mixed> The endpoint.
     */
    private function endpoint(): array
    {
        return ['id' => 'ep1', 'componenten' => ['ac' => 'https://ac/api/v1']];
    }//end endpoint()

    /**
     * Client fixture.
     *
     * @return array<string, mixed> The client.
     */
    private function client(): array
    {
        return ['clientIdentifier' => 'pipelinq-zoetermeer'];
    }//end client()

    /**
     * Build an AcClient whose AC returns the given autorisaties payload.
     *
     * @param array<int, array<string, mixed>> $autorisaties The autorisaties for one applicatie.
     *
     * @return AcClient The client under test.
     */
    private function withAutorisaties(array $autorisaties): AcClient
    {
        $api = $this->createMock(ZgwApiClient::class);
        $api->method('callComponent')->willReturn(
            ['status' => 200, 'body' => ['results' => [['autorisaties' => $autorisaties]]], 'headers' => [], 'etag' => '']
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnArgument(2);

        return new AcClient($api, $appConfig, $this->createMock(LoggerInterface::class));
    }//end withAutorisaties()

    /**
     * hasScope is true for a granted zaaktype scope and false otherwise.
     *
     * @return void
     */
    public function testHasScopeReflectsGrant(): void
    {
        $ac = $this->withAutorisaties(
            [['zaaktype' => 'https://ztc/zaaktypen/evt', 'scopes' => ['zaken.lezen', 'zaken.aanmaken']]]
        );

        $this->assertTrue($ac->hasScope($this->endpoint(), $this->client(), 'https://ztc/zaaktypen/evt', 'zaken.aanmaken'));
        $this->assertFalse($ac->hasScope($this->endpoint(), $this->client(), 'https://ztc/zaaktypen/evt', 'zaken.verwijderen'));
    }//end testHasScopeReflectsGrant()

    /**
     * requireScope raises InsufficientScopeException without granting access.
     *
     * @return void
     */
    public function testRequireScopeThrowsWhenMissing(): void
    {
        $ac = $this->withAutorisaties(
            [['zaaktype' => 'https://ztc/zaaktypen/evt', 'scopes' => ['zaken.lezen']]]
        );

        $this->expectException(InsufficientScopeException::class);
        $ac->requireScope($this->endpoint(), $this->client(), 'https://ztc/zaaktypen/evt', 'zaken.aanmaken');
    }//end testRequireScopeThrowsWhenMissing()

    /**
     * Component-level scopes (e.g. documenten.aanmaken) resolve under the wildcard.
     *
     * @return void
     */
    public function testComponentLevelScopeViaWildcard(): void
    {
        $ac = $this->withAutorisaties(
            [['informatieobjecttype' => 'https://ztc/iot/x', 'scopes' => ['documenten.aanmaken']]]
        );

        $this->assertTrue(
            $ac->hasScope($this->endpoint(), $this->client(), '*', AcClient::SCOPE_DOCUMENTEN_AANMAKEN)
        );
    }//end testComponentLevelScopeViaWildcard()

    /**
     * AC unreachability on refresh does not throw (resilient).
     *
     * @return void
     */
    public function testRefreshSwallowsAcFailure(): void
    {
        $api = $this->createMock(ZgwApiClient::class);
        $api->method('callComponent')->willThrowException(new \RuntimeException('AC down'));

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnArgument(2);

        $ac = new AcClient($api, $appConfig, $this->createMock(LoggerInterface::class));

        // Should not throw; with no cache, hasScope is simply false.
        $this->assertFalse($ac->hasScope($this->endpoint(), $this->client(), 'https://ztc/zaaktypen/evt', 'zaken.aanmaken'));
    }//end testRefreshSwallowsAcFailure()
}//end class

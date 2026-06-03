<?php

/**
 * Unit tests for HaalCentraalClient.
 *
 * Mocks the Nextcloud HTTP client (no live RvIG endpoint) to verify the OAuth2
 * token + lookup flow, HAL/JSON normalisation, the 404 = "not found" mapping,
 * the error path, the "not configured" guard, and the ADR-005 invariant that
 * the BSN is sent in the request BODY (never the URL) and never logged.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Bsn\HaalCentraalException;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Trivial in-memory ICache test double.
 */
class BrpFakeCache implements ICache
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get($key)
    {
        return $this->data[$key] ?? null;
    }

    public function set($key, $value, $ttl = 0): bool
    {
        $this->data[$key] = $value;
        return true;
    }

    public function hasKey($key): bool
    {
        return isset($this->data[$key]);
    }

    public function remove($key): bool
    {
        unset($this->data[$key]);
        return true;
    }

    public function clear($prefix = ''): bool
    {
        $this->data = [];
        return true;
    }

    public static function isAvailable(): bool
    {
        return true;
    }
}

/**
 * Tests for HaalCentraalClient (REQ-BSN-003 / 009).
 */
class HaalCentraalClientTest extends TestCase
{
    /**
     * Captured request bodies for assertion (token + lookup).
     *
     * @var array<int, mixed>
     */
    private array $captured = [];

    /**
     * Build a fully-configured client with a scripted HTTP response.
     *
     * @param string $personBody  The JSON body returned for the lookup POST.
     * @param int    $personCode  The HTTP status of the lookup response.
     * @param bool   $configured  Whether to provide full configuration.
     *
     * @return HaalCentraalClient The client under test.
     */
    private function makeClient(string $personBody, int $personCode = 200, bool $configured = true): HaalCentraalClient
    {
        $this->captured = [];

        $tokenResponse = $this->createStub(IResponse::class);
        $tokenResponse->method('getBody')->willReturn(json_encode(['access_token' => 'tok-123']));

        $personResponse = $this->createStub(IResponse::class);
        $personResponse->method('getBody')->willReturn($personBody);

        $client = $this->createMock(IClient::class);
        $calls  = 0;
        $client->method('post')->willReturnCallback(
            function (string $uri, array $options) use (&$calls, $tokenResponse, $personResponse, $personCode) {
                $this->captured[] = ['uri' => $uri, 'options' => $options];
                $calls++;
                if ($calls === 1) {
                    return $tokenResponse;
                }

                if ($personCode >= 400) {
                    throw new \RuntimeException('http '.$personCode, $personCode);
                }

                return $personResponse;
            }
        );

        $clientService = $this->createStub(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $appConfig = $this->createStub(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = '') use ($configured) {
                if ($configured === false) {
                    return $default;
                }

                $map = [
                    'brp.oauth_endpoint'    => 'https://auth.example/token',
                    'brp.personen_endpoint' => 'https://brp.example/personen',
                    'brp.client_id'         => 'cid',
                    'brp.client_secret'     => 'enc:secret',
                    'brp.cert_path'         => '/etc/ssl/client.pem',
                    'brp.key_path'          => '/etc/ssl/client.key',
                    'brp.ca_bundle'         => '',
                ];

                return $map[$key] ?? $default;
            }
        );

        $crypto = $this->createStub(ICrypto::class);
        $crypto->method('decrypt')->willReturn('secret');

        $cacheFactory = $this->createStub(ICacheFactory::class);
        $cacheFactory->method('createLocal')->willReturn(new BrpFakeCache());

        return new HaalCentraalClient(
            $clientService,
            $appConfig,
            $crypto,
            $cacheFactory,
            $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * A full HaalCentraal payload normalises to a BrpPersoon; BSN stays in body.
     *
     * @return void
     */
    public function testSuccessfulLookupNormalisesAndKeepsBsnInBody(): void
    {
        $body = json_encode([
            'personen' => [[
                'naam'      => ['voornamen' => 'Maria Wilhelmina', 'geslachtsnaam' => 'Berg', 'voorvoegsel' => 'van der'],
                'geboorte'  => ['datum' => ['datum' => '1978-03-22'], 'plaats' => ['omschrijving' => 'Utrecht']],
                'geslacht'  => ['omschrijving' => 'vrouw'],
                'verblijfplaats' => ['verblijfadres' => ['officieleStraatnaam' => 'Lange Voorhout', 'huisnummer' => 14, 'postcode' => '2514 EA', 'woonplaats' => "'s-Gravenhage"]],
                'geheimhoudingPersoonsgegevens' => ['indicatieGeheim' => false],
            ]],
        ]);

        $person = $this->makeClient($body)->lookupPersoon('123456782');

        $this->assertNotNull($person);
        $this->assertSame('Maria Wilhelmina', $person->voornamen);
        $this->assertSame('Berg', $person->geslachtsnaam);
        $this->assertSame('van der', $person->voorvoegsel);
        $this->assertSame('***45678*', $person->bsnGemaskeerd);
        $this->assertSame('Lange Voorhout', $person->verblijfplaats['straat']);
        $this->assertFalse($person->heeftGeheimhouding());

        // The BSN must travel in the lookup body, never the URL (REQ-BSN-009).
        $lookup = $this->captured[1];
        $this->assertStringNotContainsString('123456782', $lookup['uri']);
        $this->assertStringContainsString('123456782', (string) $lookup['options']['body']);
    }

    /**
     * A secrecy flag in the payload is reflected on the normalised person.
     *
     * @return void
     */
    public function testSecrecyIndicatorIsNormalised(): void
    {
        $body = json_encode([
            'personen' => [[
                'naam'     => ['voornamen' => 'Jan', 'geslachtsnaam' => 'Jansen'],
                'geboorte' => ['datum' => ['datum' => '1990-01-01']],
                'geslacht' => ['omschrijving' => 'man'],
                'geheimhoudingPersoonsgegevens' => ['indicatieGeheim' => true],
            ]],
        ]);

        $person = $this->makeClient($body)->lookupPersoon('123456782');

        $this->assertNotNull($person);
        $this->assertTrue($person->heeftGeheimhouding());
        $this->assertSame('1', $person->indicatieGeheim);
    }

    /**
     * An empty personen array means "not found" (null), not an error.
     *
     * @return void
     */
    public function testEmptyResultIsNotFound(): void
    {
        $person = $this->makeClient(json_encode(['personen' => []]))->lookupPersoon('123456782');
        $this->assertNull($person);
    }

    /**
     * An upstream 404 is a clean not-found (null), not an exception.
     *
     * @return void
     */
    public function testHttp404IsNotFound(): void
    {
        $person = $this->makeClient('', 404)->lookupPersoon('123456782');
        $this->assertNull($person);
    }

    /**
     * An upstream 503 raises a HaalCentraalException carrying the status.
     *
     * @return void
     */
    public function testHttp503Throws(): void
    {
        try {
            $this->makeClient('', 503)->lookupPersoon('123456782');
            $this->fail('Expected HaalCentraalException');
        } catch (HaalCentraalException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertStringNotContainsString('123456782', $e->getMessage());
        }
    }

    /**
     * An unconfigured client refuses to attempt a lookup.
     *
     * @return void
     */
    public function testUnconfiguredClientThrows(): void
    {
        $this->expectException(HaalCentraalException::class);
        $this->makeClient('', 200, configured: false)->lookupPersoon('123456782');
    }
}//end class

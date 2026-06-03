<?php

/**
 * Unit tests for ZgwApiClient (JWT minting + component calls).
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

use OCA\Pipelinq\Exception\ClockSkewException;
use OCA\Pipelinq\Exception\ZgwBridgeException;
use OCA\Pipelinq\Exception\ZgwResourceNotFoundException;
use OCA\Pipelinq\Service\ZgwApiClient;
use OCA\Pipelinq\Service\ZgwSecretResolver;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ZgwApiClient.
 */
class ZgwApiClientTest extends TestCase
{
    /**
     * The vault secret used across tests.
     *
     * @var string
     */
    private const SECRET = 'super-secret-zgw-key';

    /**
     * Build a ZgwApiClient with a configured HTTP response and resolvable secret.
     *
     * @param IClientService $clientService The (mocked) HTTP client service.
     *
     * @return ZgwApiClient The client under test.
     */
    private function makeClient(IClientService $clientService): ZgwApiClient
    {
        $resolver = $this->createMock(ZgwSecretResolver::class);
        $resolver->method('resolve')->willReturn(self::SECRET);

        return new ZgwApiClient($clientService, $resolver, $this->createMock(LoggerInterface::class));
    }//end makeClient()

    /**
     * The ZgwClient object array fixture.
     *
     * @return array<string, mixed> The client fixture.
     */
    private function clientFixture(): array
    {
        return [
            'clientIdentifier'        => 'pipelinq-zoetermeer',
            'secretKluisRef'          => 'vault://zgw/zoetermeer/client-secret',
            'userId'                  => 'pipelinq',
            'userRepresentation'      => 'Pipelinq backend (Conduction)',
            'tokenLevensduurSeconden' => 3600,
        ];
    }//end clientFixture()

    /**
     * JWT payload contains all required VNG-API-Common claims and verifies HS256.
     *
     * @return void
     */
    public function testMintJwtContainsRequiredClaimsAndVerifies(): void
    {
        $apiClient = $this->makeClient($this->createMock(IClientService::class));

        $jwt   = $apiClient->mintJwt($this->clientFixture());
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $this->assertSame('pipelinq-zoetermeer', $payload['client_id']);
        $this->assertSame('pipelinq-zoetermeer', $payload['iss']);
        $this->assertSame('pipelinq', $payload['user_id']);
        $this->assertSame('Pipelinq backend (Conduction)', $payload['user_representation']);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertLessThanOrEqual(2, abs($payload['iat'] - time()));

        // Verify the HS256 signature with the configured secret.
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], self::SECRET, true)), '+/', '-_'), '=');
        $this->assertSame($expected, $parts[2]);
    }//end testMintJwtContainsRequiredClaimsAndVerifies()

    /**
     * A missing secret raises a clear configuration error, not an unsigned token.
     *
     * @return void
     */
    public function testMintJwtThrowsWhenSecretUnavailable(): void
    {
        $resolver = $this->createMock(ZgwSecretResolver::class);
        $resolver->method('resolve')->willReturn(null);
        $apiClient = new ZgwApiClient(
            $this->createMock(IClientService::class),
            $resolver,
            $this->createMock(LoggerInterface::class)
        );

        $this->expectException(ZgwBridgeException::class);
        $apiClient->mintJwt($this->clientFixture());
    }//end testMintJwtThrowsWhenSecretUnavailable()

    /**
     * Build a mocked IClientService returning the given response for any verb.
     *
     * @param int                   $status  The HTTP status.
     * @param string                $body    The response body.
     * @param array<string, mixed>  $headers The response headers.
     *
     * @return IClientService The mock.
     */
    private function clientServiceReturning(int $status, string $body, array $headers=[]): IClientService
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);
        $response->method('getHeaders')->willReturn($headers);
        $response->method('getHeader')->willReturnCallback(
            static fn(string $key): string => (string) ($headers[$key][0] ?? '')
        );

        $http = $this->createMock(IClient::class);
        $http->method('get')->willReturn($response);
        $http->method('post')->willReturn($response);
        $http->method('patch')->willReturn($response);
        $http->method('put')->willReturn($response);
        $http->method('delete')->willReturn($response);

        $service = $this->createMock(IClientService::class);
        $service->method('newClient')->willReturn($http);

        return $service;
    }//end clientServiceReturning()

    /**
     * A 2xx response returns body + etag from the component call.
     *
     * @return void
     */
    public function testCallComponentReturnsBodyAndEtag(): void
    {
        $service   = $this->clientServiceReturning(
            200,
            json_encode(['url' => 'https://zrc/zaken/1']),
            ['ETag' => ['W/"abc"']]
        );
        $apiClient = $this->makeClient($service);

        $result = $apiClient->callComponent('https://zrc/api/v1', 'GET', '/zaken/1', $this->clientFixture());

        $this->assertSame(200, $result['status']);
        $this->assertSame('https://zrc/zaken/1', $result['body']['url']);
        $this->assertSame('W/"abc"', $result['etag']);
    }//end testCallComponentReturnsBodyAndEtag()

    /**
     * A 403 JWT-timing fault is mapped to ClockSkewException with no retry.
     *
     * @return void
     */
    public function testClockSkewRaisedOnJwtTimingFault(): void
    {
        $service   = $this->clientServiceReturning(403, json_encode(['detail' => 'JWT verlopen']));
        $apiClient = $this->makeClient($service);

        $this->expectException(ClockSkewException::class);
        $apiClient->callComponent('https://zrc/api/v1', 'GET', '/zaken/1', $this->clientFixture());
    }//end testClockSkewRaisedOnJwtTimingFault()

    /**
     * A 404 is mapped to ZgwResourceNotFoundException carrying the URL.
     *
     * @return void
     */
    public function testNotFoundRaisesResourceException(): void
    {
        $service   = $this->clientServiceReturning(404, json_encode(['detail' => 'niet gevonden']));
        $apiClient = $this->makeClient($service);

        $this->expectException(ZgwResourceNotFoundException::class);
        $apiClient->callComponent('https://zrc/api/v1', 'GET', '/zaken/zzz', $this->clientFixture());
    }//end testNotFoundRaisesResourceException()

    /**
     * A 412 is surfaced (not thrown) so the caller can resolve the lock conflict.
     *
     * @return void
     */
    public function testPreconditionFailedSurfacedAsStatus(): void
    {
        $service   = $this->clientServiceReturning(412, json_encode(['detail' => 'stale']));
        $apiClient = $this->makeClient($service);

        $result = $apiClient->callComponent('https://zrc/api/v1', 'PATCH', '/zaken/1', $this->clientFixture(), ['x' => 1]);
        $this->assertSame(412, $result['status']);
    }//end testPreconditionFailedSurfacedAsStatus()
}//end class

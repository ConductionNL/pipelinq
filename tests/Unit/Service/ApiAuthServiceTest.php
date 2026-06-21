<?php

/**
 * Unit tests for ApiAuthService.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ApiAuthService;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApiAuthService.
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-2
 */
class ApiAuthServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var ApiAuthService
     */
    private ApiAuthService $service;

    /**
     * Mock app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Mock secure random.
     *
     * @var ISecureRandom
     */
    private ISecureRandom $secureRandom;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig    = $this->createMock(originalClassName: IAppConfig::class);
        $this->secureRandom = $this->createMock(originalClassName: ISecureRandom::class);
        $logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new ApiAuthService(
            appConfig: $this->appConfig,
            secureRandom: $this->secureRandom,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test that generateToken returns a plaintext token and stores only the hash.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testGenerateTokenReturnsPlaintextOnce(): void
    {
        $this->secureRandom->method('generate')
            ->willReturnOnConsecutiveCalls(
                'abcdefghijklmnop',
                'abcdefghijklmnopqrstuvwxyz012345'
            );

        $storedValue = null;
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->willReturnCallback(
                static function ($app, $key, $value, $sensitive=false) use (&$storedValue) {
                    $storedValue = $value;
                    return true;
                }
            );

        $result = $this->service->generateToken(label: 'Test token');

        $this->assertArrayHasKey(key: 'token', array: $result);
        $this->assertArrayHasKey(key: 'id', array: $result);
        $this->assertArrayHasKey(key: 'label', array: $result);
        $this->assertArrayHasKey(key: 'created', array: $result);
        $this->assertSame(expected: 'Test token', actual: $result['label']);

        $stored = json_decode(json: $storedValue, associative: true);
        $this->assertArrayHasKey(key: 'hash', array: $stored);
        $this->assertArrayNotHasKey(key: 'token', array: $stored);
        $this->assertNotEquals(expected: $result['token'], actual: $stored['hash']);
    }//end testGenerateTokenReturnsPlaintextOnce()

    /**
     * Test that listTokens returns metadata without hashes.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testListTokensReturnsMetadataWithoutHashes(): void
    {
        $tokenData = json_encode(
                [
                    'id'       => 'test-uuid',
                    'label'    => 'My token',
                    'hash'     => 'somehash',
                    'created'  => '2026-01-01T00:00:00+00:00',
                    'lastUsed' => null,
                ]
                );

        $this->appConfig->method('getKeys')
            ->willReturn(['api_token_test-uuid', 'other_key']);

        $this->appConfig->method('getValueString')
            ->willReturn($tokenData);

        $tokens = $this->service->listTokens();

        $this->assertCount(expectedCount: 1, haystack: $tokens);
        $this->assertArrayHasKey(key: 'id', array: $tokens[0]);
        $this->assertArrayHasKey(key: 'label', array: $tokens[0]);
        $this->assertArrayHasKey(key: 'created', array: $tokens[0]);
        $this->assertArrayHasKey(key: 'lastUsed', array: $tokens[0]);
        $this->assertArrayNotHasKey(key: 'hash', array: $tokens[0]);
    }//end testListTokensReturnsMetadataWithoutHashes()

    /**
     * Test that revokeToken deletes the IAppConfig key.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testRevokeTokenDeletesKey(): void
    {
        $this->appConfig->expects($this->once())
            ->method('deleteKey')
            ->with(Application::APP_ID, 'api_token_test-id');

        $this->service->revokeToken(id: 'test-id');
    }//end testRevokeTokenDeletesKey()

    /**
     * Test that validateToken returns true for matching hash.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testValidateTokenReturnsTrueForMatchingHash(): void
    {
        $plaintext = 'mysecrettoken123';
        $hash      = hash('sha256', $plaintext);

        $tokenData = json_encode(
                [
                    'id'       => 'test-uuid',
                    'label'    => 'Test',
                    'hash'     => $hash,
                    'created'  => '2026-01-01T00:00:00+00:00',
                    'lastUsed' => null,
                ]
                );

        $this->appConfig->method('getKeys')
            ->willReturn(['api_token_test-uuid']);

        $this->appConfig->method('getValueString')
            ->willReturn($tokenData);

        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $result = $this->service->validateToken(plaintext: $plaintext);

        $this->assertTrue(condition: $result);
    }//end testValidateTokenReturnsTrueForMatchingHash()

    /**
     * Test that validateToken returns false for non-matching hash.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testValidateTokenReturnsFalseForWrongToken(): void
    {
        $hash = hash('sha256', 'correcttoken');

        $tokenData = json_encode(
                [
                    'id'       => 'test-uuid',
                    'label'    => 'Test',
                    'hash'     => $hash,
                    'created'  => '2026-01-01T00:00:00+00:00',
                    'lastUsed' => null,
                ]
                );

        $this->appConfig->method('getKeys')
            ->willReturn(['api_token_test-uuid']);

        $this->appConfig->method('getValueString')
            ->willReturn($tokenData);

        $result = $this->service->validateToken(plaintext: 'wrongtoken');

        $this->assertFalse(condition: $result);
    }//end testValidateTokenReturnsFalseForWrongToken()

    /**
     * Test that saveOAuthConfig skips the client secret when value is placeholder.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testSaveOAuthConfigSkipsSecretPlaceholder(): void
    {
        $setValueCalls = [];
        $this->appConfig->method('setValueString')
            ->willReturnCallback(
                static function ($app, $key, $value, $sensitive=false) use (&$setValueCalls) {
                    $setValueCalls[] = $key;
                    return true;
                }
            );

        $this->service->saveOAuthConfig(
                config: [
                    'oauth_client_id'     => 'my-client',
                    'oauth_client_secret' => '••••••••',
                ]
                );

        $this->assertContains(needle: 'oauth_client_id', haystack: $setValueCalls);
        $this->assertNotContains(needle: 'oauth_client_secret', haystack: $setValueCalls);
    }//end testSaveOAuthConfigSkipsSecretPlaceholder()

    /**
     * Test that getOAuthConfig returns non-sensitive fields only.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testGetOAuthConfigExcludesSecret(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $config = $this->service->getOAuthConfig();

        $this->assertArrayNotHasKey(key: 'oauth_client_secret', array: $config);
        $this->assertArrayHasKey(key: 'oauth_client_id', array: $config);
        $this->assertArrayHasKey(key: 'oauth_secret_configured', array: $config);
    }//end testGetOAuthConfigExcludesSecret()
}//end class

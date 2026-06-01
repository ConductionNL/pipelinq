<?php

/**
 * ApiAuthService Unit Tests
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ApiAuthService;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ApiAuthService.
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-2.1
 */
class ApiAuthServiceTest extends TestCase
{

    private ApiAuthService $service;

    private IAppConfig&MockObject $appConfig;

    private ISecureRandom&MockObject $secureRandom;


    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);

        $this->service = new ApiAuthService(
            appConfig: $this->appConfig,
            secureRandom: $this->secureRandom,
        );

    }//end setUp()


    /**
     * Test that generateToken creates a token with a random ID, stores only the hash, and returns plaintext once.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testGenerateTokenStoresHashAndReturnsPlaintext(): void
    {
        $fakeId    = 'abcdef1234567890abcdef1234567890';
        $fakePlain = 'thisisafakeplaintexttokenfortestingpurposes12345678';

        $this->secureRandom->expects($this->exactly(2))
            ->method('generate')
            ->willReturnOnConsecutiveCalls($fakeId, $fakePlain);

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with(
                Application::APP_ID,
                'api_token_' . $fakeId,
                $this->callback(function ($json) use ($fakeId, $fakePlain) {
                    $data = json_decode($json, true);
                    return $data['id'] === $fakeId
                        && $data['hash'] === hash('sha256', $fakePlain)
                        && isset($data['created'])
                        && $data['lastUsed'] === null;
                }),
            );

        $result = $this->service->generateToken(label: 'ERP Integration');

        $this->assertEquals($fakeId, $result['id']);
        $this->assertEquals($fakePlain, $result['token']);
        $this->assertEquals('ERP Integration', $result['label']);
        $this->assertArrayHasKey('created', $result);
        $this->assertArrayNotHasKey('hash', $result);

    }//end testGenerateTokenStoresHashAndReturnsPlaintext()


    /**
     * Test that listTokens returns metadata without hashes.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testListTokensReturnsMetadataWithoutHashes(): void
    {
        $tokenData = json_encode([
            'id'       => 'token-uuid-1',
            'label'    => 'ERP Integration',
            'hash'     => 'sha256hashvalue',
            'created'  => '2026-04-16T09:00:00+00:00',
            'lastUsed' => null,
        ]);

        $this->appConfig->expects($this->once())
            ->method('getKeys')
            ->with(Application::APP_ID)
            ->willReturn(['api_token_token-uuid-1', 'oauth_client_id']);

        $this->appConfig->expects($this->once())
            ->method('getValueString')
            ->with(Application::APP_ID, 'api_token_token-uuid-1', '')
            ->willReturn($tokenData);

        $tokens = $this->service->listTokens();

        $this->assertCount(1, $tokens);
        $this->assertEquals('token-uuid-1', $tokens[0]['id']);
        $this->assertEquals('ERP Integration', $tokens[0]['label']);
        $this->assertArrayNotHasKey('hash', $tokens[0]);

    }//end testListTokensReturnsMetadataWithoutHashes()


    /**
     * Test that revokeToken deletes the token from IAppConfig.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testRevokeTokenDeletesKey(): void
    {
        $this->appConfig->expects($this->once())
            ->method('deleteKey')
            ->with(Application::APP_ID, 'api_token_my-token-id');

        $this->service->revokeToken(id: 'my-token-id');

    }//end testRevokeTokenDeletesKey()


    /**
     * Test that validateToken returns true for a matching plaintext token.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testValidateTokenReturnsTrueForMatch(): void
    {
        $plaintext = 'mysecrettoken';
        $hash      = hash('sha256', $plaintext);
        $tokenData = json_encode([
            'id'       => 'token-uuid-1',
            'label'    => 'Test',
            'hash'     => $hash,
            'created'  => '2026-04-16T09:00:00+00:00',
            'lastUsed' => null,
        ]);

        $this->appConfig->expects($this->once())
            ->method('getKeys')
            ->with(Application::APP_ID)
            ->willReturn(['api_token_token-uuid-1']);

        $this->appConfig->expects($this->once())
            ->method('getValueString')
            ->with(Application::APP_ID, 'api_token_token-uuid-1', '')
            ->willReturn($tokenData);

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with(
                Application::APP_ID,
                'api_token_token-uuid-1',
                $this->callback(fn($v) => json_decode($v, true)['lastUsed'] !== null),
            );

        $result = $this->service->validateToken(plaintext: $plaintext);

        $this->assertTrue($result);

    }//end testValidateTokenReturnsTrueForMatch()


    /**
     * Test that validateToken returns false when no token matches.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testValidateTokenReturnsFalseForNoMatch(): void
    {
        $this->appConfig->expects($this->once())
            ->method('getKeys')
            ->with(Application::APP_ID)
            ->willReturn([]);

        $result = $this->service->validateToken(plaintext: 'wrongtoken');

        $this->assertFalse($result);

    }//end testValidateTokenReturnsFalseForNoMatch()


    /**
     * Test that saveOAuthConfig skips updating clientSecret when value is the placeholder.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testSaveOAuthConfigSkipsPlaceholderSecret(): void
    {
        $secretUpdated = false;

        $this->appConfig->method('setValueString')
            ->willReturnCallback(function ($app, $key, $value) use (&$secretUpdated) {
                if ($key === 'oauth_client_secret') {
                    $secretUpdated = true;
                }

                return true;
            });

        $this->service->saveOAuthConfig(config: [
            'clientId'     => 'my-client-id',
            'clientSecret' => '••••••••',
        ]);

        $this->assertFalse($secretUpdated, 'Client secret should not be updated when placeholder is submitted');

    }//end testSaveOAuthConfigSkipsPlaceholderSecret()


    /**
     * Test that getOAuthConfig returns no client secret and includes hasSecret flag.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testGetOAuthConfigExcludesClientSecret(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'oauth_client_secret', '', 'stored-secret-hash'],
                [Application::APP_ID, 'oauth_client_id', '', 'my-client-id'],
                [Application::APP_ID, 'oauth_token_endpoint', '', 'https://auth.example.com/token'],
                [Application::APP_ID, 'oauth_auth_endpoint', '', 'https://auth.example.com/authorize'],
                [Application::APP_ID, 'oauth_scopes', '', 'openid profile'],
                [Application::APP_ID, 'oauth_id_token_forwarding', '0', '1'],
            ]);

        $config = $this->service->getOAuthConfig();

        $this->assertArrayNotHasKey('clientSecret', $config);
        $this->assertTrue($config['hasSecret']);
        $this->assertEquals('my-client-id', $config['clientId']);
        $this->assertTrue($config['idTokenForwarding']);

    }//end testGetOAuthConfigExcludesClientSecret()


    /**
     * Test that saveMcpConfig stores secrets with sensitive flag.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testSaveMcpConfigStoresSensitiveSecrets(): void
    {
        $this->appConfig->expects($this->atLeastOnce())
            ->method('setValueString');

        $this->service->saveMcpConfig(config: [
            'endpoint' => 'https://mcp.example.com',
            'authMode' => 'apikey',
            'apiKey'   => 'my-secret-key',
        ]);

    }//end testSaveMcpConfigStoresSensitiveSecrets()


    /**
     * Test that getMcpConfig returns non-sensitive fields only.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function testGetMcpConfigExcludesSecrets(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'mcp_api_key', '', 'stored-key'],
                [Application::APP_ID, 'mcp_oauth_client_secret', '', ''],
                [Application::APP_ID, 'mcp_endpoint', '', 'https://mcp.example.com'],
                [Application::APP_ID, 'mcp_auth_mode', 'apikey', 'apikey'],
                [Application::APP_ID, 'mcp_oauth_client_id', '', ''],
            ]);

        $config = $this->service->getMcpConfig();

        $this->assertArrayNotHasKey('apiKey', $config);
        $this->assertArrayNotHasKey('oauthClientSecret', $config);
        $this->assertTrue($config['hasApiKey']);
        $this->assertFalse($config['hasOAuthSecret']);
        $this->assertEquals('https://mcp.example.com', $config['endpoint']);

    }//end testGetMcpConfigExcludesSecrets()


}//end class

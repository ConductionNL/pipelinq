<?php

/**
 * Unit tests for StufCredentialResolver.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Stuf
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

namespace OCA\Pipelinq\Tests\Unit\Service\Stuf;

use OCA\Pipelinq\Exception\StufTransportException;
use OCA\Pipelinq\Service\Stuf\StufCredentialResolver;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for StufCredentialResolver.
 */
class StufCredentialResolverTest extends TestCase
{

    /**
     * The credentials manager mock.
     *
     * @var ICredentialsManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private ICredentialsManager $credentialsManager;

    /**
     * The app config mock.
     *
     * @var IAppConfig&\PHPUnit\Framework\MockObject\MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The resolver under test.
     *
     * @var StufCredentialResolver
     */
    private StufCredentialResolver $resolver;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->credentialsManager = $this->createMock(ICredentialsManager::class);
        $this->appConfig          = $this->createMock(IAppConfig::class);
        $this->resolver           = new StufCredentialResolver(
            credentialsManager: $this->credentialsManager,
            appConfig: $this->appConfig,
            logger: $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * A stored vault secret is resolved from the credentials manager.
     *
     * @return void
     */
    public function testResolvesFromCredentialsManager(): void
    {
        $this->credentialsManager->method('retrieve')->willReturn('s3cr3t');

        $this->assertSame('s3cr3t', $this->resolver->resolve(reference: 'vault://stuf/a/b'));
    }//end testResolvesFromCredentialsManager()

    /**
     * An empty reference resolves to null (no secret configured).
     *
     * @return void
     */
    public function testEmptyReferenceReturnsNull(): void
    {
        $this->assertNull($this->resolver->resolve(reference: ''));
    }//end testEmptyReferenceReturnsNull()

    /**
     * A reference without the vault:// prefix is rejected.
     *
     * @return void
     */
    public function testMalformedReferenceThrows(): void
    {
        $this->expectException(StufTransportException::class);
        $this->resolver->resolve(reference: 'http://not-a-vault-ref');
    }//end testMalformedReferenceThrows()

    /**
     * Falls back to a stored IAppConfig secret when the vault has none.
     *
     * @return void
     */
    public function testFallsBackToAppConfig(): void
    {
        $this->credentialsManager->method('retrieve')->willReturn(null);
        $this->appConfig->method('getValueString')->willReturn('fallback-secret');

        $this->assertSame('fallback-secret', $this->resolver->resolve(reference: 'vault://stuf/a/b'));
    }//end testFallsBackToAppConfig()

    /**
     * resolveRequired throws when no secret can be resolved.
     *
     * @return void
     */
    public function testResolveRequiredThrowsWhenMissing(): void
    {
        $this->credentialsManager->method('retrieve')->willReturn(null);
        $this->appConfig->method('getValueString')->willReturn('');

        $this->expectException(StufTransportException::class);
        $this->resolver->resolveRequired(reference: 'vault://stuf/a/b', purpose: 'WSSE password');
    }//end testResolveRequiredThrowsWhenMissing()

    /**
     * Storing a secret routes through the encrypted credentials manager.
     *
     * @return void
     */
    public function testStorePersistsToCredentialsManager(): void
    {
        $this->credentialsManager->expects($this->once())
            ->method('store')
            ->with('', $this->stringContains('stuf'), 'new-secret');

        $this->resolver->store(reference: 'vault://stuf/a/b', secret: 'new-secret');
    }//end testStorePersistsToCredentialsManager()
}//end class

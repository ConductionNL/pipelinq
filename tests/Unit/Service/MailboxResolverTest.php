<?php

/**
 * Unit tests for MailboxResolver.
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
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\EncryptionService;
use OCA\Pipelinq\Service\LogiusConnector;
use OCA\Pipelinq\Service\MailboxResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MailboxResolver caching behaviour.
 */
class MailboxResolverTest extends TestCase
{
    /**
     * The object service mock.
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * The Logius connector mock.
     *
     * @var LogiusConnector
     */
    private LogiusConnector $logius;

    /**
     * The resolver under test.
     *
     * @var MailboxResolver
     */
    private MailboxResolver $resolver;

    /**
     * Set up the resolver with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $container           = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default=''): string {
                $map = ['register' => '1', 'mailboxResolution_schema' => '5'];
                return ($map[$key] ?? $default);
            }
        );

        $encryption = $this->createMock(EncryptionService::class);
        $encryption->method('hashBsn')->willReturn('hash-abc');
        $encryption->method('encrypt')->willReturn('cipher');

        $this->logius = $this->createMock(LogiusConnector::class);

        $this->resolver = new MailboxResolver(
            $container,
            $appConfig,
            $encryption,
            $this->logius,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * A fresh cache hit avoids any Logius call.
     *
     * @return void
     */
    public function testCacheHitSkipsLogius(): void
    {
        $future = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);
        $this->objectService->method('findAll')->willReturn(
            [['bsnHash' => 'hash-abc', 'mailboxAvailable' => true, 'expiresAt' => $future]]
        );

        $this->logius->expects($this->never())->method('checkMailboxExists');

        $result = $this->resolver->resolve('123456782');

        $this->assertTrue($result['mailboxAvailable']);
        $this->assertTrue($result['cached']);
    }//end testCacheHitSkipsLogius()

    /**
     * A cache miss calls Logius and stores the result.
     *
     * @return void
     */
    public function testCacheMissCallsLogiusAndStores(): void
    {
        $this->objectService->method('findAll')->willReturn([]);
        $this->logius->expects($this->once())->method('checkMailboxExists')->willReturn(true);
        $this->objectService->expects($this->once())->method('saveObject')->willReturn([]);

        $result = $this->resolver->resolve('123456782');

        $this->assertTrue($result['mailboxAvailable']);
        $this->assertFalse($result['cached']);
    }//end testCacheMissCallsLogiusAndStores()

    /**
     * An expired cache row triggers a fresh Logius lookup.
     *
     * @return void
     */
    public function testExpiredCacheCallsLogius(): void
    {
        $past = (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM);
        $this->objectService->method('findAll')->willReturn(
            [['bsnHash' => 'hash-abc', 'mailboxAvailable' => true, 'expiresAt' => $past]]
        );
        $this->logius->expects($this->once())->method('checkMailboxExists')->willReturn(false);

        $result = $this->resolver->resolve('123456782');

        $this->assertFalse($result['mailboxAvailable']);
    }//end testExpiredCacheCallsLogius()

    /**
     * A Logius failure degrades to mailbox-unavailable (so dispatch falls back).
     *
     * @return void
     */
    public function testLogiusFailureTreatedAsUnavailable(): void
    {
        $this->objectService->method('findAll')->willReturn([]);
        $this->logius->method('checkMailboxExists')->willThrowException(new \RuntimeException('down'));

        $result = $this->resolver->resolve('123456782');

        $this->assertFalse($result['mailboxAvailable']);
    }//end testLogiusFailureTreatedAsUnavailable()
}//end class

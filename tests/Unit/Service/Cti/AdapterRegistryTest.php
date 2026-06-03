<?php

/**
 * Unit tests for the CTI AdapterRegistry.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use InvalidArgumentException;
use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\Cti\Adapter\CallVoipAdapter;
use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCA\Pipelinq\Service\Cti\CtiCallResult;
use OCA\Pipelinq\Service\Cti\CtiWebhookResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for AdapterRegistry (REQ-CTI-006).
 */
class AdapterRegistryTest extends TestCase
{
    /**
     * The container mock.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
    }//end setUp()

    /**
     * Built-in platforms are registered out of the box.
     *
     * @return void
     */
    public function testBuiltInPlatformsRegistered(): void
    {
        $registry = new AdapterRegistry($this->container);

        $this->assertTrue($registry->has('callvoip'));
        $this->assertTrue($registry->has('ringcentral'));
        $this->assertTrue($registry->has('asterisk'));
        $this->assertFalse($registry->has('twilio'));
    }//end testBuiltInPlatformsRegistered()

    /**
     * Platform lookup is case-insensitive.
     *
     * @return void
     */
    public function testHasIsCaseInsensitive(): void
    {
        $registry = new AdapterRegistry($this->container);

        $this->assertTrue($registry->has('CallVoip'));
    }//end testHasIsCaseInsensitive()

    /**
     * get() resolves the adapter instance via the container.
     *
     * @return void
     */
    public function testGetResolvesAdapter(): void
    {
        $adapter = $this->createMock(CallVoipAdapter::class);
        $this->container->method('get')->with(CallVoipAdapter::class)->willReturn($adapter);

        $registry = new AdapterRegistry($this->container);

        $this->assertSame($adapter, $registry->get('callvoip'));
    }//end testGetResolvesAdapter()

    /**
     * get() throws for an unregistered platform.
     *
     * @return void
     */
    public function testGetThrowsForUnknownPlatform(): void
    {
        $registry = new AdapterRegistry($this->container);

        $this->expectException(InvalidArgumentException::class);
        $registry->get('twilio');
    }//end testGetThrowsForUnknownPlatform()

    /**
     * A custom adapter can be registered with no core changes (extensibility).
     *
     * @return void
     */
    public function testCustomAdapterCanBeRegistered(): void
    {
        $custom = new class implements CtiAdapterInterface {

            /**
             * {@inheritDoc}
             *
             * @return string The platform.
             */
            public function getPlatform(): string
            {
                return 'twilio';
            }

            /**
             * {@inheritDoc}
             *
             * @param string                $rawBody The body.
             * @param array<string, string> $headers The headers.
             * @param array<string, string> $query   The query.
             * @param string                $secret  The secret.
             *
             * @return bool Always true.
             */
            public function verifyWebhookSignature(string $rawBody, array $headers, array $query, string $secret): bool
            {
                return true;
            }

            /**
             * {@inheritDoc}
             *
             * @param array<string, mixed> $payload The payload.
             *
             * @return CtiWebhookResult The result.
             */
            public function handleInboundWebhook(array $payload): CtiWebhookResult
            {
                return new CtiWebhookResult(eventType: 'answered');
            }

            /**
             * {@inheritDoc}
             *
             * @param string $extension    The extension.
             * @param string $targetNumber The target.
             * @param string $callerId     The caller id.
             *
             * @return CtiCallResult The result.
             */
            public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult
            {
                return new CtiCallResult(success: true);
            }
        };

        $this->container->method('get')->willReturn($custom);

        $registry = new AdapterRegistry($this->container);
        $registry->register('twilio', $custom::class);

        $this->assertTrue($registry->has('twilio'));
        $this->assertSame($custom, $registry->get('twilio'));
    }//end testCustomAdapterCanBeRegistered()
}//end class

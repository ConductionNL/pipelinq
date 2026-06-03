<?php

/**
 * Unit tests for ProviderRegistry.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Messaging
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

namespace OCA\Pipelinq\Tests\Unit\Service\Messaging;

use InvalidArgumentException;
use OCA\Pipelinq\Service\Messaging\ChannelProviderInterface;
use OCA\Pipelinq\Service\Messaging\Provider\MessageBirdClient;
use OCA\Pipelinq\Service\Messaging\ProviderRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for the messaging provider registry.
 */
class ProviderRegistryTest extends TestCase
{
    /**
     * Built-in vendors are registered.
     *
     * @return void
     */
    public function testBuiltInVendorsRegistered(): void
    {
        $registry = new ProviderRegistry($this->createMock(ContainerInterface::class));

        $this->assertTrue($registry->has('meta'));
        $this->assertTrue($registry->has('twilio'));
        $this->assertTrue($registry->has('messagebird'));
        $this->assertTrue($registry->has('cm-com'));
        $this->assertFalse($registry->has('unknown'));
    }//end testBuiltInVendorsRegistered()

    /**
     * get() resolves a registered client via the container.
     *
     * @return void
     */
    public function testGetResolvesClient(): void
    {
        $client    = $this->createMock(MessageBirdClient::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($client);

        $registry = new ProviderRegistry($container);

        $this->assertInstanceOf(ChannelProviderInterface::class, $registry->get('messagebird'));
    }//end testGetResolvesClient()

    /**
     * get() throws for an unregistered vendor.
     *
     * @return void
     */
    public function testGetThrowsForUnknownVendor(): void
    {
        $registry = new ProviderRegistry($this->createMock(ContainerInterface::class));

        $this->expectException(InvalidArgumentException::class);
        $registry->get('does-not-exist');
    }//end testGetThrowsForUnknownVendor()

    /**
     * A runtime-registered vendor is resolvable (extensibility).
     *
     * @return void
     */
    public function testRuntimeRegistration(): void
    {
        $client    = $this->createMock(MessageBirdClient::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($client);

        $registry = new ProviderRegistry($container);
        $registry->register('custom', MessageBirdClient::class);

        $this->assertTrue($registry->has('custom'));
        $this->assertInstanceOf(ChannelProviderInterface::class, $registry->get('custom'));
    }//end testRuntimeRegistration()
}//end class

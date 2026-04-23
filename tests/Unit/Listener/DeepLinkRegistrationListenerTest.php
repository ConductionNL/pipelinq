<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Listener\DeepLinkRegistrationListener;
use PHPUnit\Framework\TestCase;

class DeepLinkRegistrationListenerTest extends TestCase
{
    public function testCanBeInstantiated(): void
    {
        $listener = new DeepLinkRegistrationListener();
        $this->assertInstanceOf(DeepLinkRegistrationListener::class, $listener);
    }
}

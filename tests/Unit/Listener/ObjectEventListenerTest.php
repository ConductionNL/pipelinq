<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Listener\ObjectEventListener;
use OCA\Pipelinq\Service\ObjectEventHandlerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ObjectEventListenerTest extends TestCase
{
    public function testHandleSkipsNonObjectEvent(): void
    {
        $handler = $this->createMock(ObjectEventHandlerService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $handler->expects($this->never())->method('handleCreated');
        $listener = new ObjectEventListener($handler, $logger);
        // Calling handle with a wrong event type should do nothing.
        $event = new class extends \OCP\EventDispatcher\Event {};
        $listener->handle($event);
    }
}

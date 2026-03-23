<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Notification;

use OCA\Pipelinq\Notification\Notifier;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\IL10N;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase
{
    private Notifier $notifier;
    protected function setUp(): void
    {
        $factory = $this->createMock(IFactory::class);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $factory->method('get')->willReturn($l10n);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $this->notifier = new Notifier($factory, $urlGenerator);
    }
    public function testGetIdReturnsPipelinq(): void
    {
        $this->assertSame('pipelinq', $this->notifier->getID());
    }
    public function testGetNameReturnsString(): void
    {
        $this->assertIsString($this->notifier->getName());
    }
    public function testPrepareThrowsForWrongApp(): void
    {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('other_app');
        $this->expectException(UnknownNotificationException::class);
        $this->notifier->prepare($notification, 'en');
    }
}

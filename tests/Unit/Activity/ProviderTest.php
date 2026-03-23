<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Activity;

use OCA\Pipelinq\Activity\Provider;
use OCA\Pipelinq\Activity\ProviderSubjectHandler;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase
{
    private Provider $provider;
    protected function setUp(): void
    {
        $factory = $this->createMock(IFactory::class);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $factory->method('get')->willReturn($l10n);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $subjectHandler = $this->createMock(ProviderSubjectHandler::class);
        $this->provider = new Provider($factory, $urlGenerator, $subjectHandler);
    }
    public function testParseThrowsForWrongApp(): void
    {
        $event = $this->createMock(IEvent::class);
        $event->method('getApp')->willReturn('other_app');
        $this->expectException(UnknownActivityException::class);
        $this->provider->parse('en', $event);
    }
}

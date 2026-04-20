<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Activity;

use OCA\Pipelinq\Activity\ProviderSubjectHandler;
use OCP\Activity\IEvent;
use PHPUnit\Framework\TestCase;

class ProviderSubjectHandlerTest extends TestCase
{
    public function testApplySubjectTextSetsSimpleSubject(): void
    {
        $handler = new ProviderSubjectHandler();
        $event = $this->createMock(IEvent::class);
        $event->method('getSubject')->willReturn('lead_created');
        $event->method('getLink')->willReturn('');
        $event->expects($this->once())->method('setParsedSubject');
        $event->expects($this->once())->method('setRichSubject');
        $l = new class {
            public function t(string $text, array $params = []): string {
                return vsprintf($text, $params);
            }
        };
        $handler->applySubjectText($event, $l, ['title' => 'Test Deal']);
    }
}

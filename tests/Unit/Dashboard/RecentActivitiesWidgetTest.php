<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Dashboard;

use OCA\Pipelinq\Dashboard\RecentActivitiesWidget;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class RecentActivitiesWidgetTest extends TestCase
{
    private RecentActivitiesWidget $widget;
    protected function setUp(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $this->widget = new RecentActivitiesWidget($l10n);
    }
    public function testGetIdReturnsCorrectId(): void
    {
        $this->assertSame('pipelinq_recent_activities_widget', $this->widget->getId());
    }
    public function testGetTitleReturnsString(): void
    {
        $this->assertIsString($this->widget->getTitle());
    }
}

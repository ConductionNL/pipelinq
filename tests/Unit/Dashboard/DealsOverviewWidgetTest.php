<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Dashboard;

use OCA\Pipelinq\Dashboard\DealsOverviewWidget;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class DealsOverviewWidgetTest extends TestCase
{
    private DealsOverviewWidget $widget;
    protected function setUp(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $this->widget = new DealsOverviewWidget($l10n);
    }
    public function testGetIdReturnsCorrectId(): void
    {
        $this->assertSame('pipelinq_deals_overview_widget', $this->widget->getId());
    }
    public function testGetTitleReturnsString(): void
    {
        $this->assertIsString($this->widget->getTitle());
    }
}

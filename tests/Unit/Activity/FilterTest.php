<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Activity;

use OCA\Pipelinq\Activity\Filter;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class FilterTest extends TestCase
{
    private Filter $filter;
    protected function setUp(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $this->filter = new Filter($l10n, $urlGenerator);
    }
    public function testGetIdentifierReturnsPipelinq(): void
    {
        $this->assertSame('pipelinq', $this->filter->getIdentifier());
    }
    public function testGetNameReturnsString(): void
    {
        $this->assertIsString($this->filter->getName());
    }
    public function testAllowedAppsReturnsArray(): void
    {
        $this->assertIsArray($this->filter->allowedApps());
    }
    public function testGetPriorityReturnsInt(): void
    {
        $this->assertIsInt($this->filter->getPriority());
    }
}

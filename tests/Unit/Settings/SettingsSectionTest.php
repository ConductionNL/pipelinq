<?php
declare(strict_types=1);
namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Sections\SettingsSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class SettingsSectionTest extends TestCase
{
    private SettingsSection $section;
    protected function setUp(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $this->section = new SettingsSection($l10n, $urlGenerator);
    }
    public function testGetIdReturnsPipelinq(): void
    {
        $this->assertSame('pipelinq', $this->section->getID());
    }
    public function testGetNameReturnsString(): void
    {
        $this->assertIsString($this->section->getName());
    }
    public function testGetPriorityReturnsInt(): void
    {
        $this->assertIsInt($this->section->getPriority());
    }
}

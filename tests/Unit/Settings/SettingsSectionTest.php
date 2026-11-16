<?php

/**
 * Tests for the Pipelinq admin settings section.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Sections\SettingsSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The section the admin settings of Pipelinq sit in: its id, translated
 * name, place in the list and icon.
 *
 * @covers \OCA\Pipelinq\Sections\SettingsSection
 */
class SettingsSectionTest extends TestCase {

	/**
	 * The localisation mock.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N $l10n;

	/**
	 * The URL generator mock.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator $urlGenerator;

	/**
	 * The section under test.
	 *
	 * @var SettingsSection
	 */
	private SettingsSection $section;

	/**
	 * Build the section over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->l10n         = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->section      = new SettingsSection($this->l10n, $this->urlGenerator);
	}//end setUp()

	/**
	 * It is an icon section, so the admin menu can show its icon.
	 *
	 * @return void
	 */
	public function testItIsAnIconSection(): void {
		$this->assertInstanceOf(IIconSection::class, $this->section);
	}//end testItIsAnIconSection()

	/**
	 * The id is the app id, which the admin settings register under.
	 *
	 * @return void
	 */
	public function testTheIdIsTheAppId(): void {
		$this->l10n->expects($this->never())->method('t');

		$this->assertSame('pipelinq', $this->section->getID());
	}//end testTheIdIsTheAppId()

	/**
	 * The name is the translation of "Pipelinq".
	 *
	 * @return void
	 */
	public function testTheNameIsTranslated(): void {
		$this->l10n->expects($this->once())
			->method('t')
			->with('Pipelinq')
			->willReturn('Pipelinq (vertaald)');

		$this->assertSame('Pipelinq (vertaald)', $this->section->getName());
	}//end testTheNameIsTranslated()

	/**
	 * The section sits at priority 76 in the admin list.
	 *
	 * @return void
	 */
	public function testThePriorityIs76(): void {
		$this->assertSame(76, $this->section->getPriority());
	}//end testThePriorityIs76()

	/**
	 * The icon is the app's dark icon, as the URL generator resolves it.
	 *
	 * @return void
	 */
	public function testTheIconIsTheDarkAppIcon(): void {
		$this->urlGenerator->expects($this->once())
			->method('imagePath')
			->with('pipelinq', 'app-dark.svg')
			->willReturn('/custom_apps/pipelinq/img/app-dark.svg');

		$this->assertSame('/custom_apps/pipelinq/img/app-dark.svg', $this->section->getIcon());
	}//end testTheIconIsTheDarkAppIcon()
}//end class

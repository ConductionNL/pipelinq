<?php

/**
 * Unit tests for the Pipelinq admin settings section.
 *
 * The section is what Nextcloud lists in the admin sidebar: its id must match
 * the admin settings page, its name is translated, it sits at a fixed place
 * in the list, and its icon is the app's dark icon.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Sections\SettingsSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The admin settings section.
 *
 * @covers \OCA\Pipelinq\Sections\SettingsSection
 */
class SettingsSectionTest extends TestCase {

	/**
	 * @var IL10N&MockObject
	 */
	private IL10N $l10n;

	/**
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator $urlGenerator;

	private SettingsSection $section;

	/**
	 * Build the section over its two collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->l10n = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->section = new SettingsSection($this->l10n, $this->urlGenerator);
	}//end setUp()

	/**
	 * The id is the app id, which the admin settings page names as its section.
	 *
	 * @return void
	 */
	public function testGetIdReturnsPipelinq(): void {
		$this->assertSame('pipelinq', $this->section->getID());
	}//end testGetIdReturnsPipelinq()

	/**
	 * The name is the translated app name.
	 *
	 * @return void
	 */
	public function testTheNameIsTheTranslatedAppName(): void {
		$this->l10n->expects($this->once())->method('t')->with('Pipelinq')->willReturn('Pipelinq (nl)');

		$this->assertSame('Pipelinq (nl)', $this->section->getName());
	}//end testTheNameIsTheTranslatedAppName()

	/**
	 * The section keeps its fixed place in the admin list.
	 *
	 * @return void
	 */
	public function testThePriorityIsFixed(): void {
		$this->assertSame(76, $this->section->getPriority());
	}//end testThePriorityIsFixed()

	/**
	 * The icon is the app's dark icon, resolved through the URL generator.
	 *
	 * @return void
	 */
	public function testTheIconIsTheAppsDarkIcon(): void {
		$this->urlGenerator->expects($this->once())->method('imagePath')
			->with('pipelinq', 'app-dark.svg')
			->willReturn('/apps/pipelinq/img/app-dark.svg');

		$this->assertSame('/apps/pipelinq/img/app-dark.svg', $this->section->getIcon());
	}//end testTheIconIsTheAppsDarkIcon()
}//end class

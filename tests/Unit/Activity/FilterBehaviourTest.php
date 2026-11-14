<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Activity;

use OCA\Pipelinq\Activity\Filter;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The activity filter's name, icon, priority and the types and apps it lets through.
 *
 * @covers \OCA\Pipelinq\Activity\Filter
 */
class FilterBehaviourTest extends TestCase {
	public function testTheNameIsTranslated(): void {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->expects($this->once())->method('t')->with('Pipelinq')->willReturn('Pijplijn');

		$filter = new Filter(l: $l10n, urlGenerator: $this->createMock(originalClassName: IURLGenerator::class));

		$this->assertSame('Pijplijn', $filter->getName());
	}

	public function testTheIconIsTheAbsoluteAppIcon(): void {
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->expects($this->once())->method('imagePath')->with('pipelinq', 'app.svg')->willReturn('/apps/pipelinq/img/app.svg');
		$urls->expects($this->once())->method('getAbsoluteURL')->with('/apps/pipelinq/img/app.svg')
			->willReturn('https://cloud.example/apps/pipelinq/img/app.svg');

		$filter = new Filter(l: $this->createMock(originalClassName: IL10N::class), urlGenerator: $urls);

		$this->assertSame('https://cloud.example/apps/pipelinq/img/app.svg', $filter->getIcon());
	}

	public function testOnlyPipelinqTypesPassWhateverIsAsked(): void {
		$filter   = new Filter(l: $this->createMock(originalClassName: IL10N::class), urlGenerator: $this->createMock(originalClassName: IURLGenerator::class));
		$expected = ['pipelinq_assignment', 'pipelinq_stage_status', 'pipelinq_notes'];

		$this->assertSame($expected, $filter->filterTypes(types: ['file_created', 'calendar']));
		$this->assertSame($expected, $filter->filterTypes(types: []));
	}

	public function testItListsOnlyPipelinqAtPriorityFifty(): void {
		$filter = new Filter(l: $this->createMock(originalClassName: IL10N::class), urlGenerator: $this->createMock(originalClassName: IURLGenerator::class));

		$this->assertSame(['pipelinq'], $filter->allowedApps());
		$this->assertSame(50, $filter->getPriority());
		$this->assertSame('pipelinq', $filter->getIdentifier());
	}
}

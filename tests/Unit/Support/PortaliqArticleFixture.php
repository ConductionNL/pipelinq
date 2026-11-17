<?php

/**
 * Builds an ArticleService over in-memory portaliq articles.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\Marketing\PortaliqArticleStore;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * For a TestCase: an ArticleService whose articles live in `portaliq`/`article`.
 */
trait PortaliqArticleFixture {

	/**
	 * The service over the given registers.
	 *
	 * @param InMemoryRegisters|null $registers The registers, a fresh empty set when null.
	 * @param bool                   $installed Whether portaliq is installed.
	 *
	 * @return ArticleService The service.
	 */
	private function portaliqArticleService(?InMemoryRegisters $registers = null, bool $installed = true): ArticleService {
		$registers = ($registers ?? new InMemoryRegisters());
		$apps      = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $installed === true && $app === 'portaliq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($registers);

		return new ArticleService(new PortaliqArticleStore($container, $apps, new NullLogger()), new NullLogger());
	}//end portaliqArticleService()
}//end trait

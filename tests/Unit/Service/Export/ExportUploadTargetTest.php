<?php

/**
 * Unit tests for ExportUploadTarget.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\Pipelinq\Service\Export\ExportUploadTarget;
use PHPUnit\Framework\TestCase;

/**
 * ExportUploadTarget carries its values unchanged.
 */
class ExportUploadTargetTest extends TestCase {

	/**
	 * Every constructor value is readable as given.
	 *
	 * @return void
	 */
	public function testKeepsTheValuesItIsGiven(): void {
		$sink = new \stdClass();
		$credentials = ['token' => 'x'];
		$destination = ['type' => 'sftp'];
		$value = new ExportUploadTarget(sink: $sink, credentials: $credentials, destination: $destination);

		self::assertSame($sink, $value->sink);
		self::assertSame($credentials, $value->credentials);
		self::assertSame($destination, $value->destination);
	}//end testKeepsTheValuesItIsGiven()
}//end class

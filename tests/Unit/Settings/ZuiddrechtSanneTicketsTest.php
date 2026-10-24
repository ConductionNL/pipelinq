<?php

/**
 * Example data for DqContact's tickets card: Sanne de Vries has two tickets,
 * an open request about a street party permit on the Parkstraat handled by
 * Pieter Jansen, and a question about waste collection that is resolved.
 * They hang on her client, which carries the shared master entity ref
 * 0f5e0000-0000-4000-8000-000000000012 (feat/ticket-leaf-by-master-entity),
 * so dossiq's tickets card finds them.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Sanne's two tickets.
 */
class ZuiddrechtSanneTicketsTest extends TestCase {

	/**
	 * One open request handled by Pieter, one resolved question, both on her client.
	 *
	 * @return void
	 */
	public function testSanneHasAnOpenRequestAndAResolvedQuestion(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], 'data', 'key');

		$straatfeest = $objects['ticket-sanne-straatfeest'];
		$this->assertSame(
			expected: ['Vraag over vergunning straatfeest Parkstraat', 'request', 'in_progress', '{{cast:pieter.uid}}', '{{object:client-sanne.id}}'],
			actual: [$straatfeest['title'], $straatfeest['ticketType'], $straatfeest['status'], $straatfeest['assignee'], $straatfeest['client']]
		);

		$afval = $objects['ticket-sanne-afvalinzameling'];
		$this->assertSame(
			expected: ['Vraag over afvalinzameling', 'request', 'resolved', '{{object:client-sanne.id}}'],
			actual: [$afval['title'], $afval['ticketType'], $afval['status'], $afval['client']]
		);
	}//end testSanneHasAnOpenRequestAndAResolvedQuestion()
}//end class

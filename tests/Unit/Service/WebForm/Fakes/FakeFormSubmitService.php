<?php

/**
 * Test fake for the website form suite: OpenRegister FormSubmitService::submitAll, recording every call.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes;

use Throwable;

/**
 * Records submitAll() calls and answers as OpenRegister does, or throws what it is told.
 */
class FakeFormSubmitService {

	/**
	 * Every call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * Constructor.
	 *
	 * @param Throwable|null $throw What to throw instead of answering.
	 */
	public function __construct(
		public ?Throwable $throw = null,
	) {
	}//end __construct()

	/**
	 * Submit into the writes' destinations.
	 *
	 * @param array<int, array<string, mixed>> $writes         The writes.
	 * @param array<string, mixed>             $payload        The payload.
	 * @param mixed                            $subject        The subject.
	 * @param string|null                      $idempotencyKey The key.
	 * @param string                           $scope          The scope.
	 *
	 * @return array<string, mixed> The answer.
	 */
	public function submitAll(array $writes, array $payload, mixed $subject = null, ?string $idempotencyKey = null, string $scope = ''): array {
		$this->calls[] = ['writes' => $writes, 'payload' => $payload, 'subject' => $subject, 'idempotencyKey' => $idempotencyKey, 'scope' => $scope];
		if ($this->throw !== null) {
			throw $this->throw;
		}

		$schema = (string)($writes[0]['schema'] ?? '');

		return [
			'reference' => strtoupper($schema) . '-' . count($this->calls),
			'id' => $schema . '-uuid-' . count($this->calls),
			'receivedAt' => '2026-10-11T09:00:00+00:00',
			'confirmation' => [],
			'objects' => [],
		];
	}//end submitAll()
}//end class

<?php

/**
 * Test fake for the website form suite: OpenRegister FormSubmitService::submit, recording every call.
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

use OCA\OpenRegister\Service\Form\FormSubmission;
use Throwable;

/**
 * Records submit() calls and answers as OpenRegister does, or throws what it is told.
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
	 * Submit into the submission's destinations, with OpenRegister's signature.
	 *
	 * @param FormSubmission $submission     The writes, the answers and who submits.
	 * @param string|null    $idempotencyKey The key.
	 *
	 * @return array<string, mixed> The answer.
	 */
	public function submit(FormSubmission $submission, ?string $idempotencyKey = null): array {
		$writes = $submission->writes;
		$this->calls[] = [
			'writes' => $writes,
			'payload' => $submission->payload,
			'subject' => $submission->subject,
			'idempotencyKey' => $idempotencyKey,
			'scope' => $submission->scope,
		];
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
	}//end submit()
}//end class

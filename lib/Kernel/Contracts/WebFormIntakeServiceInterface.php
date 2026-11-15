<?php

/**
 * Contract of WebFormIntakeService
 *
 * The public surface of WebFormIntakeService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use OCA\Pipelinq\Service\WebForm\WebFormSubmitException;

/**
 * Contract of WebFormIntakeService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WebFormIntakeServiceInterface {

	/**
	 * What a visitor reads when the destination cannot take the submit now.
	 *
	 * @var string
	 */
	public const TRY_LATER = 'The form could not be sent right now. Please try again later.';

	/**
	 * Submit answers into the destination of a form already resolved (a Nextcloud Forms binding, the drain).
	 *
	 * @param array<string, mixed> $form           The published form body with its `id`.
	 * @param array<string, mixed> $payload        Field to answer.
	 * @param string|null          $idempotencyKey A key that makes a repeat answer the first time's.
	 *
	 * @return array<string, mixed> The answer: reference, id, receivedAt, confirmation.
	 *
	 * @throws WebFormSubmitException 400 refused, 422 payload refused, 503 try again later.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	public function submit(array $form, array $payload, ?string $idempotencyKey = null): array;

	/**
	 * Submit a website visitor's answers into the destination of the form the key names.
	 *
	 * @param string               $key            The form's key (the website's `source`).
	 * @param array<string, mixed> $payload        The raw submitted fields.
	 * @param string|null          $idempotencyKey The Idempotency-Key, when the caller sent one.
	 *
	 * @return array<string, mixed> The answer: reference, id, receivedAt, confirmation.
	 *
	 * @throws WebFormSubmitException 400 refused, 422 payload refused, 503 try again later.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	public function submitByKey(string $key, array $payload, ?string $idempotencyKey = null): array;
}//end interface

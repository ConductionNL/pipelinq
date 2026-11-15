<?php

/**
 * Pipelinq CallSummaryWriter.
 *
 * Asks hermiq for a short summary of a call transcript, or nothing.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\CallReview
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\CallReview;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Design D2: the summary goes through hermiq, resolved by name at run time so
 * pipelinq runs without it, and an absent or silent hermiq leaves the summary
 * empty rather than inventing one.
 *
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.4
 */
class CallSummaryWriter {

	/**
	 * Hermiq's provider factory, resolved by name at run time.
	 */
	public const PROVIDER_CLASS = 'OCA\\Hermiq\\Service\\Llm\\ProviderFactory';

	/**
	 * Most characters of transcript handed to the model.
	 */
	public const MAX_INPUT = 20000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the lazy hermiq resolve.
	 * @param LoggerInterface    $logger    PSR logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * A summary of at most five sentences, or '' without hermiq or an answer.
	 *
	 * @param string      $transcript The call transcript.
	 * @param string|null $userId     The identity the request acts as.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.4
	 */
	public function summarise(string $transcript, ?string $userId = null): string {
		if (trim($transcript) === '') {
			return '';
		}

		try {
			$factory = $this->container->get(self::PROVIDER_CLASS);
		} catch (Throwable) {
			return '';
		}

		if (is_object($factory) === false || method_exists($factory, 'generateText') === false) {
			return '';
		}

		try {
			$answer = $factory->generateText($this->prompt(transcript: $transcript), $userId, true, null);
		} catch (Throwable $e) {
			$this->logger->info('CallSummaryWriter: hermiq did not answer', ['exception' => $e->getMessage()]);
			return '';
		}

		return trim((string) $answer);
	}//end summarise()

	/**
	 * The instruction and the transcript, cut to MAX_INPUT characters.
	 *
	 * @param string $transcript The call transcript.
	 *
	 * @return string
	 */
	private function prompt(string $transcript): string {
		return "Summarise this phone call between a customer contact centre agent and a caller in at most five sentences, "
			."in the language of the call. Say what the caller wanted and what was agreed. Do not add anything the call does not say.\n\n"
			.mb_substr($transcript, 0, self::MAX_INPUT);
	}//end prompt()
}//end class

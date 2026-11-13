<?php

/**
 * Pipelinq contact line labeller.
 *
 * Asks hermiq which of the lines the contact capture rules could not place is
 * the person's name, the organisation and the job title. Resolved lazily, so
 * without hermiq it labels nothing and the lines go back to the person
 * (clients-contact-capture D1).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-without-hermiq-unplaced-lines-are-handed-to-the-user
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Labels free lines as name, organisation or job title through hermiq, or not at all.
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-without-hermiq-unplaced-lines-are-handed-to-the-user
 */
class ContactLineLabeller {

	/**
	 * Hermiq's language model provider, resolved lazily.
	 *
	 * @var string
	 */
	public const PROVIDER_CLASS = 'OCA\\Hermiq\\Service\\Llm\\ProviderFactory';

	/**
	 * The fields a line may be labelled as.
	 *
	 * @var list<string>
	 */
	private const FIELDS = ['name', 'organisation', 'jobTitle'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The lazy hermiq resolve.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-without-hermiq-unplaced-lines-are-handed-to-the-user
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Which line is the name, the organisation and the job title.
	 *
	 * Only an answer that names one of the given lines word for word is taken,
	 * and a line is given one field at most: the model can label a line, never
	 * invent one.
	 *
	 * @param list<string> $lines  The lines no rule placed.
	 * @param string|null  $userId The user the model is asked for.
	 *
	 * @return array<string, string> Field to line; empty without hermiq or a usable answer.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-without-hermiq-unplaced-lines-are-handed-to-the-user
	 */
	public function labels(array $lines, ?string $userId): array {
		if ($lines === []) {
			return [];
		}

		$provider = $this->provider();
		if ($provider === null) {
			return [];
		}

		try {
			$answer = (string) $provider->generateText($this->prompt(lines: $lines), $userId, true, null);
		} catch (Throwable $e) {
			$this->logger->info('ContactLineLabeller: hermiq did not answer', ['exception' => $e->getMessage()]);
			return [];
		}

		$decoded = json_decode(trim($answer), true);
		if (is_array($decoded) === false) {
			return [];
		}

		$labels = [];
		foreach (self::FIELDS as $field) {
			$line = ($decoded[$field] ?? null);
			if (is_string($line) === true && in_array($line, $lines, true) === true && in_array($line, $labels, true) === false) {
				$labels[$field] = $line;
			}
		}

		return $labels;
	}//end labels()

	/**
	 * Hermiq's provider, or null when hermiq is absent.
	 *
	 * @return object|null The provider.
	 */
	private function provider(): ?object {
		try {
			$provider = $this->container->get(self::PROVIDER_CLASS);
		} catch (Throwable) {
			return null;
		}

		if (is_object($provider) === false || method_exists($provider, 'generateText') === false) {
			return null;
		}

		return $provider;
	}//end provider()

	/**
	 * The labelling prompt.
	 *
	 * @param list<string> $lines The lines.
	 *
	 * @return string The prompt.
	 */
	private function prompt(array $lines): string {
		return implode(
			"\n",
			[
				'These lines come from an email signature or a business card.',
				'Say which line is the person\'s name, which is the organisation and which is the job title.',
				'Answer with JSON only: {"name": "<line or null>", "organisation": "<line or null>", "jobTitle": "<line or null>"}.',
				'Copy a line exactly as given. Use null when no line is that field.',
				'',
				...$lines,
			]
		);
	}//end prompt()
}//end class

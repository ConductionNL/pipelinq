<?php

/**
 * Pipelinq SmsProviderFactory.
 *
 * Instantiates the right concrete SMS provider client given a
 * channelProvider row (vendor + credentials). Keeps SmsAdapter free
 * of vendor branching.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Provider\CmComSmsClient;
use OCA\Pipelinq\Service\Provider\MessageBirdSmsClient;
use OCA\Pipelinq\Service\Provider\SmsProviderClientInterface;
use OCA\Pipelinq\Service\Provider\TwilioSmsClient;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * SmsProviderFactory — channelProvider-row → vendor client.
 *
 * Public entry points:
 * - create(channelProvider) — returns a concrete client matching
 *   the row's `vendor`, or null when the vendor is not yet
 *   supported.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class SmsProviderFactory {
	/**
	 * The client class per supported vendor key.
	 *
	 * @var array<string, class-string<TwilioSmsClient|MessageBirdSmsClient|CmComSmsClient>>
	 */
	private const CLIENT_CLASSES = [
		'twilio' => TwilioSmsClient::class,
		'messagebird' => MessageBirdSmsClient::class,
		'cm-com' => CmComSmsClient::class,
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a vendor client for a channelProvider row.
	 *
	 * @param array<string, mixed> $channelProvider channelProvider row.
	 *
	 * @return SmsProviderClientInterface|null Client or null when the
	 *                                         vendor key is not supported.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function create(array $channelProvider): ?SmsProviderClientInterface {
		$clientClass = (self::CLIENT_CLASSES[(string)($channelProvider['vendor'] ?? '')] ?? null);
		if ($clientClass === null) {
			return null;
		}

		return new $clientClass(
			container: $this->container,
			logger: $this->logger,
			credentials: (array)($channelProvider['credentials'] ?? []),
			fromNumber: (string)($channelProvider['phoneNumber'] ?? ''),
			webhookSecret: (string)($channelProvider['webhookSecret'] ?? ''),
			sourceId: $this->sourceIdOf(channelProvider: $channelProvider),
		);
	}//end create()

	/**
	 * The row's OpenConnector source id (the legacy key as fallback), or null when it has none.
	 *
	 * @param array<string, mixed> $channelProvider channelProvider row.
	 *
	 * @return string|null The source id.
	 */
	private function sourceIdOf(array $channelProvider): ?string {
		$sourceId = (string)($channelProvider['sourceId'] ?? ($channelProvider['openconnectorSourceId'] ?? ''));
		if ($sourceId === '') {
			return null;
		}

		return $sourceId;
	}//end sourceIdOf()
}//end class

<?php

/**
 * Golden test for the ZtcClient omschrijving lookups.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCA\Pipelinq\Service\Zgw\ZgwResourceNotFoundException;
use OCA\Pipelinq\Service\Zgw\ZtcClient;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Every answer (URL or exception), every callComponent() call and every
 * warning, for scripted runs of resolve, cache and invalidate calls against
 * scripted ZTC answers, compared with ZtcLookupGolden.json. Set
 * ZTC_LOOKUP_GOLDEN_RECORD=1 to rewrite the file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\Zgw\ZtcClient
 * @covers \OCA\Pipelinq\Service\Zgw\ZtcLookup
 * @uses   \OCA\Pipelinq\Service\Zgw\BesluittypeNotInCatalogusException
 * @uses   \OCA\Pipelinq\Service\Zgw\ZaaktypeNotInCatalogusException
 * @uses   \OCA\Pipelinq\Service\Zgw\ZgwResourceNotFoundException
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-004
 */
class ZtcLookupGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/ZtcLookupGolden.json';

	private const ZTC = 'https://ztc.example/catalogi/api/v1';

	/**
	 * Calls made in the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$endpoint = ['id' => 'ep-1', 'componenten' => ['ztc' => self::ZTC]];
		$hit = ['body' => ['results' => [
			['url' => self::ZTC . '/x/1', 'omschrijving' => 'Een'],
			['url' => self::ZTC . '/x/2'],
		]]];
		$cases = [
			'zaaktype twice'          => ['answers' => [$hit], 'steps' => [['zaak', 'Een'], ['zaak', 'Een']]],
			'status and rol'          => [
				'answers' => [$hit, $hit, $hit],
				'steps'   => [['status', 'Een', 'ct-1'], ['rol', 'Een', 'ct-1'], ['status', 'Een', 'ct-2'], ['status', 'Een', 'ct-1']],
			],
			'besluittype empty'       => ['answers' => [['body' => ['results' => []]]], 'steps' => [['besluit', 'Geen']]],
			'zaaktype empty'          => ['answers' => [['body' => ['results' => []]]], 'steps' => [['zaak', 'Geen'], ['zaak', 'Geen']]],
			'body is the list'        => ['answers' => [['body' => [['url' => self::ZTC . '/b/1']]]], 'steps' => [['besluit', 'B']]],
			'results not a list'      => ['answers' => [['body' => ['results' => 'x']]], 'steps' => [['zaak', 'X']]],
			'first entry not array'   => ['answers' => [['body' => ['results' => ['x']]]], 'steps' => [['rol', 'X', 'ct']]],
			'first entry without url' => ['answers' => [['body' => ['results' => [['omschrijving' => 'X']]]]], 'steps' => [['zaak', 'X']]],
			'url not a string'        => ['answers' => [['body' => ['results' => [['url' => 42]]]]], 'steps' => [['zaak', 'N']]],
			'no client'               => ['answers' => [], 'client' => null, 'steps' => [['zaak', 'Een']]],
			'no ztc url'              => ['answers' => [], 'endpoint' => ['id' => 'ep-2', 'componenten' => []], 'steps' => [['zaak', 'Een']]],
			'no endpoint id'          => [
				'answers'  => [$hit],
				'endpoint' => ['componenten' => ['ztc' => self::ZTC]],
				'steps'    => [['zaak', 'Een'], ['zaak', 'Een']],
			],
			'not found'               => ['answers' => ['404'], 'steps' => [['status', 'Een', 'ct']]],
			'transport fails'         => ['answers' => ['boom'], 'steps' => [['besluit', 'Een']]],
			'invalidate one type'     => [
				'answers' => [$hit, $hit, $hit],
				'steps'   => [['zaak', 'Een'], ['status', 'Een', 'c'], ['invalidate', 'statustypen'], ['zaak', 'Een'], ['status', 'Een', 'c']],
			],
			'invalidate all'          => ['answers' => [$hit, $hit], 'steps' => [['zaak', 'Een'], ['invalidate', '*'], ['zaak', 'Een']]],
			'invalidate empty type'   => ['answers' => [$hit, $hit], 'steps' => [['zaak', 'Een'], ['invalidate', ''], ['zaak', 'Een']]],
			'invalidate no id'        => [
				'answers'  => [$hit],
				'endpoint' => ['componenten' => ['ztc' => self::ZTC]],
				'steps'    => [['zaak', 'Een'], ['invalidate', '*'], ['zaak', 'Een']],
			],
			'url after status lookup' => [
				'answers' => [$hit],
				'steps'   => [['status', 'Een', 'c'], ['fromUrl', self::ZTC . '/x/1'], ['fromUrl', self::ZTC . '/x/2']],
			],
			'url not cached'          => [
				'answers' => [['body' => ['omschrijving' => 'Direct']], ['body' => []], 'boom'],
				'steps'   => [
					['fromUrl', self::ZTC . '/s/9'],
					['fromUrl', self::ZTC . '/s/8'],
					['fromUrl', 'https://other/s/1'],
					['fromUrl', self::ZTC . '/s/7'],
				],
			],
			'negative ttl'            => ['answers' => [$hit], 'ttl' => -5, 'steps' => [['zaak', 'Een'], ['zaak', 'Een']]],
			'ttl expires'             => ['answers' => [$hit, $hit], 'ttl' => 1, 'steps' => [['zaak', 'Een'], ['sleep'], ['zaak', 'Een']]],
		];

		$actual = [];
		foreach ($cases as $name => $case) {
			$this->calls = [];
			$client = $this->client(case: $case);
			$out = [];
			foreach ($case['steps'] as $step) {
				$out[] = $this->step(client: $client, step: [($case['endpoint'] ?? $endpoint), $step]);
			}

			$actual[$name] = ['out' => $out, 'calls' => $this->calls];
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		if (getenv('ZTC_LOOKUP_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame(expected: json_encode($want, $flags), actual: json_encode(($actual[$name] ?? null), $flags), message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()

	/**
	 * Run one scripted step and describe its outcome.
	 *
	 * @param ZtcClient         $client The client under test.
	 * @param array<int, mixed> $step   The endpoint and the step.
	 *
	 * @return mixed
	 */
	private function step(ZtcClient $client, array $step): mixed {
		[$endpoint, $args] = $step;
		try {
			return match ($args[0]) {
				'zaak'       => $client->resolveZaaktype($endpoint, $args[1]),
				'status'     => $client->resolveStatustype($endpoint, $args[2], $args[1]),
				'rol'        => $client->resolveRoltype($endpoint, $args[2], $args[1]),
				'besluit'    => $client->resolveBesluittype($endpoint, $args[1]),
				'fromUrl'    => ['fromUrl' => $client->resolveOmschrijvingFromUrl($endpoint, $args[1])],
				'invalidate' => $client->invalidateCache($endpoint, $args[1]),
				'sleep'      => $this->waitOneSecond(),
			};
		} catch (Throwable $e) {
			return ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}
	}//end step()

	/**
	 * Return once the clock has moved on by at least one whole second.
	 *
	 * @return null
	 */
	private function waitOneSecond(): null {
		$start = time();
		while ((time() - $start) < 1) {
			usleep(100000);
		}

		return null;
	}//end waitOneSecond()

	/**
	 * A client over the case's scripted ZTC answers.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return ZtcClient
	 */
	private function client(array $case): ZtcClient {
		$answers = $case['answers'];
		$api = $this->createMock(originalClassName: ZgwApiClient::class);
		$api->method('callComponent')->willReturnCallback(
			function (mixed ...$args) use (&$answers): array {
				$this->calls[] = ['callComponent' => $args];
				$answer = array_shift($answers);
				if ($answer === '404') {
					throw new ZgwResourceNotFoundException(url: 'gone');
				}

				if ($answer === null || $answer === 'boom') {
					throw new RuntimeException('transport down');
				}

				return ['status' => 200, 'headers' => [], 'body' => $answer['body']];
			}
		);

		$found = ['clientIdentifier' => 'c-1'];
		if (array_key_exists('client', $case) === true) {
			$found = $case['client'];
		}

		$registers = $this->createMock(originalClassName: ZgwRegisterAccess::class);
		$registers->method('findClientForEndpoint')->willReturnCallback(
			function (array $endpoint) use ($found): ?array {
				$this->calls[] = ['findClientForEndpoint' => $endpoint];
				return $found;
			}
		);

		$ttl = ($case['ttl'] ?? 0);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default) use ($ttl): int {
				if ($ttl === 0) {
					return $default;
				}

				return $ttl;
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = ['warning' => [$message, $context]];
			}
		);

		return new ZtcClient(api: $api, registers: $registers, appConfig: $appConfig, logger: $logger);
	}//end client()
}//end class

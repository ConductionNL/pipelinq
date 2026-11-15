<?php

/**
 * AcClient::refreshScopes: every way an authorisation refresh ends.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\AcClient;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Only this client's grants reach the scope cache, and a failed read keeps what was there.
 */
class AcClientRefreshTest extends TestCase {

	/**
	 * The client over an AC answering as given; the calls are recorded.
	 *
	 * @param mixed                $answer The AC body, or a Throwable.
	 * @param array<int, mixed>    $calls  The calls, filled in.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return AcClient
	 */
	private function client(mixed $answer, array &$calls, ?LoggerInterface $logger = null): AcClient {
		$api = $this->createMock(ZgwApiClient::class);
		$api->method('callComponent')->willReturnCallback(
			static function (string $componentUrl, string $method, string $path, array $client) use ($answer, &$calls): array {
				$calls[] = [$componentUrl, $method, $path, $client];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return ['body' => $answer];
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		return new AcClient($api, $this->createMock(ZgwRegisterAccess::class), $appConfig, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end client()

	/**
	 * The scope cache, as the client holds it.
	 *
	 * @param AcClient $client The client.
	 *
	 * @return array<string, mixed>
	 */
	private function cache(AcClient $client): array {
		return (new \ReflectionProperty(AcClient::class, 'cache'))->getValue($client);
	}//end cache()

	/**
	 * Every way a refresh ends: no endpoint id or no AC reads nothing; a
	 * failed read is logged and keeps the cache; a body that is not a list
	 * caches nothing; results (or the bare body) are filtered to this
	 * client's entries, nested or flat grants are collected per resource
	 * (zaaktype, besluittype, informatieobjecttype, else wildcard), invalid
	 * scopes skipped and duplicates removed.
	 *
	 * @return void
	 */
	public function testEveryWayARefreshEnds(): void {
		$calls = [];
		$client = $this->client([], $calls);
		$client->refreshScopes(endpoint: ['componenten' => ['ac' => 'https://ac']], client: []);
		$client->refreshScopes(endpoint: ['id' => 'e-1', 'componenten' => []], client: []);
		$this->assertSame([], $calls);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('ZGW AC: refreshScopes failed', ['endpoint' => 'slug-1', 'err' => 'down']);
		$client = $this->client(new RuntimeException('down'), $calls, $logger);
		$client->primeCache(endpointId: 'slug-1', scopes: ['*' => ['old']], refreshedAt: 5);
		$client->refreshScopes(endpoint: ['@self' => ['slug' => 'slug-1'], 'componenten' => ['ac' => 'https://ac']], client: ['clientIdentifier' => 'me']);
		$this->assertSame(['slug-1' => ['refreshedAt' => 5, 'scopes' => ['*' => ['old']]]], $this->cache($client));
		$this->assertSame([['https://ac', 'GET', '/autorisaties', ['clientIdentifier' => 'me']]], $calls);

		$client = $this->client('not a list', $calls);
		$client->refreshScopes(endpoint: ['id' => 'e-1', 'componenten' => ['ac' => 'https://ac']], client: ['clientIdentifier' => 'me']);
		$this->assertSame([], $this->cache($client));

		$results = ['results' => [
			'junk',
			['clientIds' => ['other'], 'autorisaties' => [['caseType' => 'zt-x', 'scopes' => ['zaken.lezen']]]],
			['clientIds' => ['me'], 'autorisaties' => [
				['caseType' => 'zt-1', 'scopes' => ['zaken.lezen', 'zaken.lezen', '', 7]],
				['besluittype' => 'bt-1', 'scopes' => ['besluiten.lezen']],
				['informatieobjecttype' => 'iot-1', 'scopes' => 'bad'],
				'junk',
				['scopes' => ['notificaties.consumeren']],
			]],
			['clientIds' => ['other'], 'component' => 'me', 'caseType' => 'zt-2', 'scopes' => ['zaken.aanmaken']],
			['clientIds' => 'not a list', 'caseType' => 'zt-3', 'scopes' => ['zaken.bijwerken']],
			['clientIds' => ['me'], 'autorisaties' => 'bad'],
		]];
		$client = $this->client($results, $calls);
		$client->refreshScopes(endpoint: ['id' => 'e-1', 'componenten' => ['ac' => 'https://ac']], client: ['clientIdentifier' => 'me']);
		$cached = $this->cache($client)['e-1'];
		$this->assertEqualsWithDelta(time(), $cached['refreshedAt'], 5);
		$this->assertSame(['zt-1' => ['zaken.lezen'], 'bt-1' => ['besluiten.lezen'], '*' => ['notificaties.consumeren'], 'zt-2' => ['zaken.aanmaken'], 'zt-3' => ['zaken.bijwerken']], $cached['scopes']);

		$client = $this->client([['caseType' => 'zt-9', 'scopes' => ['a']]], $calls);
		$client->refreshScopes(endpoint: ['id' => 'e-2', 'componenten' => ['ac' => 'https://ac']], client: []);
		$this->assertSame(['zt-9' => ['a']], $this->cache($client)['e-2']['scopes']);
	}//end testEveryWayARefreshEnds()
}//end class

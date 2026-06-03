<?php

/**
 * Pipelinq NaviService.
 *
 * Conversational analytics orchestration for the "Navi" dashboard agent.
 *
 * Deduplication finding (tasks.md#task-0.1): Navi adds NO new data schemas and
 * NO custom LLM plumbing. It REUSES the existing platform/app capabilities —
 *   - OpenRegister `ObjectService` for all data access (RBAC + multitenancy
 *     enabled by default, so a user only ever queries objects they may read);
 *   - OpenRegister `ChatService` + `ContextRetrievalHandler` for optional
 *     natural-language enrichment (used only when a conversational backend is
 *     available; Navi degrades gracefully to a deterministic answer otherwise);
 *   - `@conduction/nextcloud-vue` `CnChartWidget` / `CnTableWidget` for
 *     rendering (frontend), so no chart component is built here.
 * The only custom logic is deterministic intent detection and the cross-module
 * aggregation that turns a natural-language question into a chart/table/text
 * answer. This is server-side and IDOR-safe: aggregation never returns raw
 * objects outside the caller's OpenRegister access scope.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/dashboard/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service that turns a natural-language analytics question into a structured,
 * IDOR-safe dashboard answer.
 *
 * @spec openspec/changes/dashboard/tasks.md#task-1.2
 */
class NaviService
{
    /**
     * Recognised intents, mapped from keyword groups in detectIntent().
     *
     * @var array<int, string>
     */
    public const INTENTS = ['trend', 'breakdown', 'conversion', 'count', 'unknown'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container        The DI container (lazy OR lookup).
     * @param AnalyticsService   $analyticsService Shared aggregation maths.
     * @param IAppConfig         $appConfig        The app config.
     * @param LoggerInterface    $logger           The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private AnalyticsService $analyticsService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Process a natural-language analytics query for a user.
     *
     * @param string $query  The raw user query.
     * @param string $userId The querying user id (for scope/logging).
     *
     * @return array<string, mixed> The structured Navi response.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-1.2
     */
    public function processQuery(string $query, string $userId): array
    {
        $query = trim($query);
        if ($query === '') {
            return $this->textResponse(
                message: 'Stel een vraag over je leads, verzoeken of contactmomenten.',
                followUps: $this->defaultFollowUps()
            );
        }

        $intent = $this->detectIntent(query: $query);
        if ($intent === 'unknown') {
            return $this->textResponse(
                message: 'Ik begreep de vraag niet helemaal. Probeer bijvoorbeeld een vraag over '
                    .'het aantal leads, de conversie of een verdeling per status.',
                followUps: $this->defaultFollowUps()
            );
        }

        try {
            $rawData = $this->buildContext(intent: $intent, params: ['query' => $query]);
        } catch (\Throwable $e) {
            $this->logger->warning('[NaviService] context build failed', ['exception' => $e->getMessage()]);
            return $this->textResponse(
                message: 'De analysegegevens zijn op dit moment niet beschikbaar. Probeer het later opnieuw.',
                followUps: []
            );
        }

        return $this->formatResponse(
            llmResponse: $this->enrich(query: $query, intent: $intent, rawData: $rawData, userId: $userId),
            rawData: $rawData
        );
    }//end processQuery()

    /**
     * Classify a query into an intent using deterministic keyword matching.
     *
     * @param string $query The user query.
     *
     * @return string One of INTENTS.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-1.2
     */
    public function detectIntent(string $query): string
    {
        $needle = mb_strtolower($query);

        $matches = static function (array $keywords) use ($needle): bool {
            foreach ($keywords as $keyword) {
                if (str_contains($needle, $keyword) === true) {
                    return true;
                }
            }

            return false;
        };

        $isTrend = $matches(['trend', 'over tijd', 'verloop', 'per maand', 'per week', 'ontwikkeling']);
        if ($isTrend === true) {
            return 'trend';
        }

        $isConversion = $matches(['conversie', 'conversion', 'gewonnen', 'won', 'closing', 'win rate', 'winratio']);
        if ($isConversion === true) {
            return 'conversion';
        }

        $isBreakdown = $matches(['verdeling', 'per status', 'per categorie', 'breakdown', 'groepeer', 'verdeeld']);
        if ($isBreakdown === true) {
            return 'breakdown';
        }

        $isCount = $matches(['hoeveel', 'aantal', 'count', 'totaal', 'how many', 'number of']);
        if ($isCount === true) {
            return 'count';
        }

        return 'unknown';
    }//end detectIntent()

    /**
     * Build the aggregated context for an intent.
     *
     * Delegates the actual maths to AnalyticsService so the aggregation logic
     * is shared with the Unified Analytics panel (no duplication).
     *
     * @param string               $intent The detected intent.
     * @param array<string, mixed> $params Query parameters (e.g. the raw query).
     *
     * @return array<string, mixed> The structured raw data for formatResponse.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-1.2
     */
    public function buildContext(string $intent, array $params): array
    {
        // Derive the period from the natural-language query so a phrase like
        // "dit kwartaal" narrows the aggregation window accordingly.
        $period = $this->detectPeriod(query: (string) ($params['query'] ?? ''));

        switch ($intent) {
            case 'trend':
                $trends = $this->analyticsService->getTrends(metric: 'leads', period: $period);
                return [
                    'resultType' => 'chart',
                    'chartType'  => 'line',
                    'label'      => 'Leads per periode',
                    'series'     => $trends['series'],
                ];

            case 'breakdown':
                $trends = $this->analyticsService->getTrends(metric: 'requests-by-category', period: $period);
                return [
                    'resultType' => 'chart',
                    'chartType'  => 'bar',
                    'label'      => 'Verzoeken per categorie',
                    'series'     => $trends['series'],
                ];

            case 'conversion':
                $overview = $this->analyticsService->getOverview(period: $period);
                return [
                    'resultType' => 'table',
                    'label'      => 'Conversie',
                    'rows'       => [
                        ['metric' => 'Conversieratio', 'value' => $overview['leadConversionRate'].'%'],
                        ['metric' => 'Contactmomenten', 'value' => (string) $overview['contactMomentVolume']],
                    ],
                ];

            case 'count':
            default:
                $funnels = $this->analyticsService->getFunnels();
                return [
                    'resultType' => 'table',
                    'label'      => 'Overzicht aantallen',
                    'rows'       => [
                        ['metric' => 'Leads totaal', 'value' => (string) $funnels['leadFunnel']['total']],
                        ['metric' => 'Leads open', 'value' => (string) $funnels['leadFunnel']['open']],
                        ['metric' => 'Leads gewonnen', 'value' => (string) $funnels['leadFunnel']['won']],
                        ['metric' => 'Verzoeken totaal', 'value' => (string) $funnels['requestFunnel']['total']],
                    ],
                ];
        }//end switch
    }//end buildContext()

    /**
     * Detect a reporting period from the natural-language query.
     *
     * @param string $query The user query.
     *
     * @return string One of week|month|quarter|year (defaults to month).
     */
    private function detectPeriod(string $query): string
    {
        $needle = mb_strtolower($query);
        if (str_contains($needle, 'week') === true) {
            return 'week';
        }

        if (str_contains($needle, 'kwartaal') === true || str_contains($needle, 'quarter') === true) {
            return 'quarter';
        }

        if (str_contains($needle, 'jaar') === true || str_contains($needle, 'year') === true) {
            return 'year';
        }

        return 'month';
    }//end detectPeriod()

    /**
     * Shape the final API response from the raw aggregated data.
     *
     * Empty data sets collapse to a human-readable text response so the widget
     * never renders an empty chart or table.
     *
     * @param array<string, mixed> $llmResponse Optional natural-language summary.
     * @param array<string, mixed> $rawData     The aggregated raw data.
     *
     * @return array<string, mixed> The final response payload.
     *
     * @spec openspec/changes/dashboard/tasks.md#task-1.2
     */
    public function formatResponse(array $llmResponse, array $rawData): array
    {
        $resultType   = (string) ($rawData['resultType'] ?? 'text');
        $textResponse = (string) ($llmResponse['text'] ?? '');

        if ($resultType === 'chart') {
            $series = ($rawData['series'] ?? []);
            if (count($series) === 0) {
                return $this->textResponse(
                    message: 'Er zijn nog geen gegevens voor deze vraag in de geselecteerde periode.',
                    followUps: $this->defaultFollowUps()
                );
            }

            return [
                'resultType'         => 'chart',
                'chartData'          => [
                    'type'   => (string) ($rawData['chartType'] ?? 'bar'),
                    'label'  => (string) ($rawData['label'] ?? ''),
                    'labels' => array_map(static fn (array $point) => (string) $point['date'], $series),
                    'values' => array_map(static fn (array $point) => (float) $point['value'], $series),
                ],
                'textResponse'       => $textResponse,
                'suggestedFollowUps' => $this->defaultFollowUps(),
            ];
        }//end if

        if ($resultType === 'table') {
            $rows = ($rawData['rows'] ?? []);
            if (count($rows) === 0) {
                return $this->textResponse(
                    message: 'Er zijn nog geen gegevens voor deze vraag.',
                    followUps: $this->defaultFollowUps()
                );
            }

            return [
                'resultType'         => 'table',
                'tableData'          => [
                    'label'   => (string) ($rawData['label'] ?? ''),
                    'columns' => ['metric', 'value'],
                    'rows'    => array_values($rows),
                ],
                'textResponse'       => $textResponse,
                'suggestedFollowUps' => $this->defaultFollowUps(),
            ];
        }

        $message = 'Geen resultaat.';
        if ($textResponse !== '') {
            $message = $textResponse;
        }

        return $this->textResponse(
            message: $message,
            followUps: $this->defaultFollowUps()
        );
    }//end formatResponse()

    /**
     * Optionally enrich the deterministic answer with a natural-language
     * summary from the OpenRegister ChatService.
     *
     * The deterministic aggregation answer is always authoritative; this method
     * only adds an optional prose summary on top. It is gated behind the
     * `navi_chat_conversation` app-config key (a persisted ChatService
     * conversation id), because ChatService::processMessage requires a
     * conversation that is bound to a configured LLM agent — wiring that is an
     * administrator/runtime concern. When the key is absent, or the call fails
     * for any reason, this returns an empty summary so Navi falls back to the
     * deterministic answer. It never throws.
     *
     * @param string               $query   The user query.
     * @param string               $intent  The detected intent.
     * @param array<string, mixed> $rawData The aggregated raw data.
     * @param string               $userId  The querying user id.
     *
     * @return array{text: string} The (possibly empty) natural-language summary.
     */
    private function enrich(string $query, string $intent, array $rawData, string $userId): array
    {
        $conversationId = (int) $this->appConfig->getValueString(Application::APP_ID, 'navi_chat_conversation', '0');
        if ($conversationId <= 0) {
            return ['text' => ''];
        }

        try {
            $chatService = $this->container->get('OCA\OpenRegister\Service\ChatService');

            // Hand the deterministic aggregation to the LLM as grounding context
            // so any prose summary is anchored to the real, scope-safe numbers
            // rather than free-form generation.
            $context = [
                'intent'     => $intent,
                'aggregates' => $rawData,
            ];

            $result = $chatService->processMessage(
                conversationId: $conversationId,
                userId: $userId,
                userMessage: $query,
                selectedViews: [],
                selectedTools: [],
                ragSettings: [],
                context: $context
            );

            $text = '';
            if (is_array($result) === true && isset($result['message']) === true && is_string($result['message']) === true) {
                $text = $result['message'];
            }

            return ['text' => $text];
        } catch (\Throwable $e) {
            $this->logger->debug('[NaviService] chat enrichment unavailable', ['exception' => $e->getMessage()]);
            return ['text' => ''];
        }//end try
    }//end enrich()

    /**
     * Build a text-only response payload.
     *
     * @param string             $message   The human-readable message.
     * @param array<int, string> $followUps Suggested follow-up questions.
     *
     * @return array<string, mixed> The text response payload.
     */
    private function textResponse(string $message, array $followUps): array
    {
        return [
            'resultType'         => 'text',
            'textResponse'       => $message,
            'suggestedFollowUps' => array_slice($followUps, 0, 3),
        ];
    }//end textResponse()

    /**
     * The default set of suggested follow-up questions.
     *
     * @return array<int, string> Up to three suggestions.
     */
    private function defaultFollowUps(): array
    {
        return [
            'Hoeveel leads zijn er deze maand gewonnen?',
            'Toon de verdeling van verzoeken per categorie',
            'Wat is de trend van leads over tijd?',
        ];
    }//end defaultFollowUps()
}//end class

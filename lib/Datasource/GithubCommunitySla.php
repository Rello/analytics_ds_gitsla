<?php
/**
 * Analytics
 *
 * SPDX-FileCopyrightText: 2019-2025 Marcel Scherello
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Analytics_DS_GitSLA\Datasource;

use OCA\Analytics\Datasource\IDatasource;
use OCA\Analytics\Datasource\IReportTemplateProvider;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class GithubCommunitySla implements IDatasource, IReportTemplateProvider {
	private const CACHE_TTL_SECONDS = 60;
	private const TIMELINE_BATCH_SIZE = 20;
	private const NEEDS_TRIAGE_LABEL = '0. Needs triage';

	private LoggerInterface $logger;
	private IL10N $l10n;

	public function __construct(
		IL10N $l10n,
		LoggerInterface $logger
	) {
		$this->l10n = $l10n;
		$this->logger = $logger;
	}

	/**
	 * @return string Display Name of the data source
	 */
	public function getName(): string {
		return 'GitHub Community SLAs';
	}

	/**
	 * @return int digit unique data source id
	 */
	public function getId(): int {
		return 01;
	}

	/**
	 * @return array available options of the data source
	 */
	public function getTemplate(): array {
		$template = [];
		$template[] = [
			'id' => 'token',
			'name' => $this->l10n->t('Personal access token'),
			'placeholder' => $this->l10n->t('optional')
		];
		$template[] = [
			'id' => 'repo',
			'name' => $this->l10n->t('Repositories'),
			'placeholder' => 'owner/repo1,owner/repo2'
		];
		$template[] = [
			'id' => 'exclude',
			'name' => $this->l10n->t('Exclude authors'),
			'placeholder' => 'user1,user2'
		];
		$template[] = [
			'id' => 'sla',
			'name' => $this->l10n->t('SLA days'),
			'placeholder' => '14',
			'type' => 'number'
		];
		$template[] = [
			'id' => 'days',
			'name' => $this->l10n->t('Updated since (days)'),
			'placeholder' => '30',
			'type' => 'number'
		];
		return $template;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getReportTemplates(): array {
		return [
			'github_sla_monthly' => [
				'name' => 'Community SLA Monthly',
				'report' => [
					'name' => 'Community SLA Monthly',
					'subheader' => 'Realtime data from GitHub',
					'parent' => '0',
					'type' => 991,
					'dataset' => 0,
					'link' => '{"repo":"nextcloud/desktop", "sla": 14, "days": 90}',
					'visualization' => 'table',
					'chart' => '',
					'dimension1' => '',
					'dimension2' => '',
					'value' => '',
				],
				'options' => [
					'chartoptions' => '{"__analytics_gui":{"version":2,"model":"kpiModel","doughnutLabelStyle":"percentage"}}',
					'dataoptions' => '[]',
					'filteroptions' => '{"timeAggregation":{"dimension":"3","grouping":"month","mode":"summation"},"drilldown":{"2":false,"4":false,"5":false}}',
					'tableoptions' => '{"order":[[2,"desc"]],"formatLocales":false,"calculatedColumns":"{\"operation\":\"percentage\",\"columns\":[3,4],\"title\":\"Achievement\"}"}',
				],
			],
		];
	}

	/**
	 * Read the Data
	 * @param $option
	 * @return array available options of the data source
	 */
	public function readData($option): array {
		$data = [];
		$cache = $this->getCacheMetadata($option);

		if ($cache['notModified'] === true) {
			return [
				'header' => [],
				'dimensions' => [],
				'data' => [],
				'rawdata' => null,
				'error' => 0,
				'cache' => $cache,
			];
		}

		$repositories = isset($option['repo']) && $option['repo'] !== '' ? array_map('trim', explode(',', $option['repo'])) : [];
		$excludedAuthors = isset($option['exclude']) && $option['exclude'] !== '' ? array_map('trim', explode(',', $option['exclude'])) : [];
		$excludedAuthorLookup = array_fill_keys($excludedAuthors, true);
		$slaDays = isset($option['sla']) && (int)$option['sla'] > 0 ? (int)$option['sla'] : 14;
		$daysFilter = isset($option['days']) && (int)$option['days'] > 0 ? (int)$option['days'] : 30;
		$sinceDate = date(DATE_ATOM, time() - ($daysFilter * 86400));
		$nowTimestamp = time();
		$curlHandle = $this->initGraphqlCurlHandle($option);
		if ($curlHandle === false) {
			return $this->buildDatasourceErrorResponse(['http_code' => 500, 'data' => []]);
		}

		try {

			foreach ($repositories as $repo) {
				[$owner, $name] = explode('/', $repo, 2);

			$issuesQuery = <<<'GRAPHQL'
query($owner: String!, $name: String!, $after: String, $since: DateTime) {
  repository(owner: $owner, name: $name) {
    issues(first: 100, after: $after, orderBy: {field: UPDATED_AT, direction: DESC}, states: [OPEN, CLOSED], filterBy: { since: $since }) {
      nodes {
        number
        createdAt
        updatedAt
        closedAt
        author { login }
        timelineItems(first: 100, itemTypes: [UNLABELED_EVENT]) {
          nodes {
            __typename
            ... on UnlabeledEvent { createdAt label { name } }
          }
          pageInfo {
            hasNextPage
            endCursor
          }
        }
      }
      pageInfo {
        hasNextPage
        endCursor
      }
    }
  }
}
GRAPHQL;

				$pullsQuery = <<<'GRAPHQL'
query($owner: String!, $name: String!, $after: String) {
  repository(owner: $owner, name: $name) {
    pullRequests(first: 100, after: $after, orderBy: {field: UPDATED_AT, direction: DESC}, states: [OPEN, MERGED, CLOSED]) {
      nodes {
        number
        createdAt
        mergedAt
        closedAt
        updatedAt
        author { login }
      }
      pageInfo {
        hasNextPage
        endCursor
      }
    }
  }
}
GRAPHQL;

			// Fetch issues with pagination
			$issuesAfter = null;
			do {
				$variables = ['owner' => $owner, 'name' => $name, 'after' => $issuesAfter, 'since' => $sinceDate];
					$curlResult = $this->getGraphqlData($issuesQuery, $variables, $option, $curlHandle);
				if ($curlResult['http_code'] < 200 || $curlResult['http_code'] >= 300 || isset($curlResult['data']['errors'])) {
					return $this->buildDatasourceErrorResponse($curlResult);
				}
					$repoData = $curlResult['data']['data']['repository'];
					$issuesEdge = $repoData['issues'];
					$pendingIssueTimelines = [];
					$pendingIssueData = [];

					foreach ($issuesEdge['nodes'] as $issue) {
						// Keep recency semantics aligned with "Updated since (days)".
						if (($issue['updatedAt'] ?? '') < $sinceDate) {
							continue;
						}
						$issueAuthor = $issue['author']['login'] ?? null;
						if ($issueAuthor !== null && isset($excludedAuthorLookup[$issueAuthor])) {
							continue;
						}
						$completedAt = $this->findNeedsTriageUnlabeledAt($issue['timelineItems']['nodes']);
						$eventsPageInfo = $issue['timelineItems']['pageInfo'] ?? [];

						if ($completedAt === '' && ($eventsPageInfo['hasNextPage'] ?? false)) {
							$afterTimeline = $eventsPageInfo['endCursor'] ?? null;
							if ($afterTimeline !== null) {
								$issueNumber = (int)$issue['number'];
								$pendingIssueTimelines[$issueNumber] = ['after' => $afterTimeline];
								$pendingIssueData[$issueNumber] = [
									'createdAt' => $issue['createdAt'],
									'createdTimestamp' => $this->toTimestamp($issue['createdAt']),
									'closedAt' => $issue['closedAt'] ?? '',
								];
								continue;
							}
						}

						if ($completedAt === '' && isset($issue['closedAt']) && $issue['closedAt'] !== null) {
							$completedAt = $issue['closedAt'];
						}
						$days = $this->daysBetweenTimestamps(
							$this->toTimestamp($issue['createdAt']),
							$completedAt !== '' ? $this->toTimestamp($completedAt) : $nowTimestamp
						);
					$slaMet = $days <= $slaDays;
					$data[] = [
						$repo,
						'issue',
						(int)$issue['number'],
						$issue['createdAt'],
						$completedAt,
						$days,
						$slaMet ? 1 : 0,
							1
						];
					}

					if ($pendingIssueTimelines !== []) {
						$resolvedTimelineDates = $this->resolveIssueTimelineDatesBatched($owner, $name, $pendingIssueTimelines, $option, $curlHandle);
						foreach ($pendingIssueData as $issueNumber => $issueData) {
							$completedAt = $resolvedTimelineDates[$issueNumber] ?? '';
							if ($completedAt === '' && $issueData['closedAt'] !== '' && $issueData['closedAt'] !== null) {
								$completedAt = $issueData['closedAt'];
							}
							$days = $this->daysBetweenTimestamps(
								$issueData['createdTimestamp'],
								$completedAt !== '' ? $this->toTimestamp($completedAt) : $nowTimestamp
							);
							$slaMet = $days <= $slaDays;
							$data[] = [
								$repo,
								'issue',
								(int)$issueNumber,
								$issueData['createdAt'],
								$completedAt,
								$days,
								$slaMet ? 1 : 0,
								1
							];
						}
					}
					$issuesAfter = $issuesEdge['pageInfo']['endCursor'] ?? null;
				} while ($issuesEdge['pageInfo']['hasNextPage']);

			// Fetch pull requests with pagination
			$prsAfter = null;
			$continuePaging = true;
			do {
				$variables = ['owner' => $owner, 'name' => $name, 'after' => $prsAfter];
					$curlResult = $this->getGraphqlData($pullsQuery, $variables, $option, $curlHandle);
				if ($curlResult['http_code'] < 200 || $curlResult['http_code'] >= 300 || isset($curlResult['data']['errors'])) {
					return $this->buildDatasourceErrorResponse($curlResult);
				}
				$repoData = $curlResult['data']['data']['repository'];
				$prsEdge = $repoData['pullRequests'];

				$pageHasRecent = false;
					foreach ($prsEdge['nodes'] as $pr) {
						$isRecent = ($pr['updatedAt'] >= $sinceDate);
						if (!$isRecent) {
							continue;
						}
						$pageHasRecent = true;
						$prAuthor = $pr['author']['login'] ?? null;
						if ($prAuthor !== null && isset($excludedAuthorLookup[$prAuthor])) {
							continue;
						}
						$completedAt = $pr['mergedAt'] ?? $pr['closedAt'] ?? '';
						$days = $this->daysBetweenTimestamps(
							$this->toTimestamp($pr['createdAt']),
							$completedAt !== '' ? $this->toTimestamp($completedAt) : $nowTimestamp
						);
						$slaMet = $days <= $slaDays;
						$data[] = [
							$repo,
						'pr',
						(int)$pr['number'],
						$pr['createdAt'],
						$completedAt,
						$days,
						$slaMet ? 1 : 0,
						1
					];
				}
				if (!$pageHasRecent) {
					$continuePaging = false;
				}
				$prsAfter = $prsEdge['pageInfo']['endCursor'] ?? null;
				$morePrPages = ($prsEdge['pageInfo']['hasNextPage'] ?? false) && $continuePaging;
			} while ($morePrPages);
			}
		} finally {
			curl_close($curlHandle);
		}

		$dimensions = [
			$this->l10n->t('Repository'),
			$this->l10n->t('Type'),
			$this->l10n->t('Number'),
			$this->l10n->t('Created'),
			$this->l10n->t('Completed'),
			$this->l10n->t('Days'),
		];

		$keyFigures = [
			$this->l10n->t('SLA met'),
			$this->l10n->t('Total')
		];

		$header = [
		];


		return [
			'header' => [...$dimensions, ...$keyFigures],
			'dimensions' => $dimensions,
			'keyFigures' => $keyFigures,
			'data' => $data,
			'rawdata' => [],
			'error' => 0,
			'cache' => $cache,
		];
	}

	private function getCacheMetadata($option): array {
		$currentCacheKey = 'gcs-' . (string)floor(time() / self::CACHE_TTL_SECONDS);
		$clientCacheKey = isset($option['cacheKey']) ? trim((string)$option['cacheKey'], '"') : '';

		return [
			'cacheable' => true,
			'key' => $currentCacheKey,
			'notModified' => ($clientCacheKey !== '' && $clientCacheKey === $currentCacheKey),
		];
	}

	protected function getGraphqlData(string $query, array $variables, array $option, $curlHandle = null): array {
		$closeHandle = false;
		if ($curlHandle === null) {
			$curlHandle = $this->initGraphqlCurlHandle($option);
			$closeHandle = true;
		}

		if ($curlHandle === false) {
			$curlResult = '';
			$http_code = 500;
		} else {
			curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode(['query' => $query, 'variables' => $variables]));
			$curlResult = curl_exec($curlHandle);
			$http_code = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
			if ($closeHandle) {
				curl_close($curlHandle);
			}
		}
		$curlResult = json_decode($curlResult, true);
		return ['data' => $curlResult, 'http_code' => $http_code];
	}

	private function initGraphqlCurlHandle(array $option) {
		$ch = curl_init('https://api.github.com/graphql');
		if ($ch === false) {
			return false;
		}

		$headers = [
			'Content-Type: application/json',
			'User-Agent: AnalyticsApp'
		];
		if (isset($option['token']) && $option['token'] !== '') {
			$headers[] = 'Authorization: bearer ' . $option['token'];
		}

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_ENCODING, '');
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		return $ch;
	}

	private function buildDatasourceErrorResponse(array $curlResult): array {
		$httpCode = $curlResult['http_code'] ?? 0;
		$userMessage = [];

		if ($httpCode === 401) {
			$userMessage = $this->l10n->t('Missing or invalid personal access token');
		} elseif ($httpCode === 403) {
			$userMessage = $this->l10n->t('Rate limit exceeded');
		}

		return [
			'header' => [],
			'dimensions' => [],
			'data' => $userMessage,
			'rawdata' => $curlResult,
			'error' => 'HTTP response code: ' . $httpCode,
		];
	}

	private function toTimestamp(string $date): int {
		$timestamp = strtotime($date);
		return $timestamp === false ? 0 : $timestamp;
	}

	private function daysBetweenTimestamps(int $fromTimestamp, int $toTimestamp): int {
		return intdiv(abs($toTimestamp - $fromTimestamp), 86400);
	}

	/**
	 * @param array<int, array{after: string}> $pendingIssueTimelines
	 * @param resource|\CurlHandle $curlHandle
	 * @return array<int, string>
	 */
	private function resolveIssueTimelineDatesBatched(string $owner, string $name, array $pendingIssueTimelines, array $option, $curlHandle): array {
		$resolved = [];

		while ($pendingIssueTimelines !== []) {
			$nextPending = [];
			$pendingIssueNumbers = array_keys($pendingIssueTimelines);

			foreach (array_chunk($pendingIssueNumbers, self::TIMELINE_BATCH_SIZE) as $chunkIssueNumbers) {
				[$query, $variables, $queryIssueAliasMap] = $this->buildIssueTimelineBatchQuery($owner, $name, $chunkIssueNumbers, $pendingIssueTimelines);
					$eventsResult = $this->getGraphqlData($query, $variables, $option, $curlHandle);
				if ($eventsResult['http_code'] < 200 || $eventsResult['http_code'] >= 300 || isset($eventsResult['data']['errors'])) {
					$this->logger->warning('GitHub issue timeline batch fetch failed', ['result' => $eventsResult]);
					continue;
				}

				$repositoryData = $eventsResult['data']['data']['repository'] ?? [];
				foreach ($queryIssueAliasMap as $alias => $issueNumber) {
					$timelineItems = $repositoryData[$alias]['timelineItems'] ?? null;
					if ($timelineItems === null) {
						continue;
					}

					$completedAt = $this->findNeedsTriageUnlabeledAt($timelineItems['nodes'] ?? []);
					if ($completedAt !== '') {
						$resolved[$issueNumber] = $completedAt;
						continue;
					}

					$pageInfo = $timelineItems['pageInfo'] ?? [];
					$hasNextPage = $pageInfo['hasNextPage'] ?? false;
					$endCursor = $pageInfo['endCursor'] ?? null;
					if ($hasNextPage && $endCursor !== null) {
						$nextPending[$issueNumber] = ['after' => $endCursor];
					}
				}
			}

			$pendingIssueTimelines = $nextPending;
		}

		return $resolved;
	}

	/**
	 * @param array<int, int> $issueNumbers
	 * @param array<int, array{after: string}> $pendingIssueTimelines
	 * @return array{0: string, 1: array<string, mixed>, 2: array<string, int>}
	 */
	private function buildIssueTimelineBatchQuery(string $owner, string $name, array $issueNumbers, array $pendingIssueTimelines): array {
		$variableDefinitions = ['$owner: String!', '$name: String!'];
		$variables = ['owner' => $owner, 'name' => $name];
		$issueQueries = [];
		$queryIssueAliasMap = [];

		foreach ($issueNumbers as $index => $issueNumber) {
			$afterVarName = 'after' . $index;
			$alias = 'issue' . $issueNumber;
			$variableDefinitions[] = '$' . $afterVarName . ': String';
			$variables[$afterVarName] = $pendingIssueTimelines[$issueNumber]['after'] ?? null;
			$queryIssueAliasMap[$alias] = $issueNumber;
			$issueQueries[] = $alias . ': issue(number: ' . $issueNumber . ') {'
				. ' timelineItems(first: 100, after: $' . $afterVarName . ', itemTypes: [UNLABELED_EVENT]) {'
				. ' nodes { __typename ... on UnlabeledEvent { createdAt label { name } } }'
				. ' pageInfo { hasNextPage endCursor }'
				. ' }'
				. ' }';
		}

		$query = 'query(' . implode(', ', $variableDefinitions) . ') {'
			. ' repository(owner: $owner, name: $name) {'
			. implode(' ', $issueQueries)
			. ' }'
			. '}';

		return [$query, $variables, $queryIssueAliasMap];
	}

	/**
	 * @param array<int, array<string, mixed>> $events
	 */
	private function findNeedsTriageUnlabeledAt(array $events): string {
		foreach ($events as $event) {
			if ($event['__typename'] === 'UnlabeledEvent' && isset($event['label']['name']) && strcasecmp($event['label']['name'], self::NEEDS_TRIAGE_LABEL) === 0) {
				return (string)$event['createdAt'];
			}
		}
		return '';
	}
}

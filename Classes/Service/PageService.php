<?php

namespace Mobasoft\ContentRadar\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageService
{
    private const DEFAULT_YELLOW_THRESHOLD = 180;
    private const DEFAULT_RED_THRESHOLD = 365;

    public function getPagesWithAge(): array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');

        $queryBuilder = $connection->createQueryBuilder();

        $result = $queryBuilder
            ->select('uid', 'pid', 'title', 'tstamp', 'sys_language_uid', 'l10n_parent')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('hidden', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $now = time();
        $settings = $this->getSettings();

        $incomingCounts = $this->getIncomingCounts($result);
        $duplicateGroups = $this->buildDuplicateGroups($result);

        foreach ($result as &$page) {
            $ageDays = (int)(($now - (int)$page['tstamp']) / 86400);

            $page['age'] = $ageDays;
            $page['status'] = $this->getStatus($ageDays, $settings);

            $incoming = $incomingCounts[$page['uid']] ?? 0;

            $page['incoming'] = $incoming;
            $page['is_leaf'] = ($incoming === 0);

            ['title' => $languageTitle, 'flag' => $languageFlag] = $this->resolveLanguageMeta(
                (int)$page['uid'],
                (int)$page['sys_language_uid'],
                (int)$page['l10n_parent']
            );
            $page['language'] = $languageTitle;
            $page['language_flag'] = $languageFlag;

            $page['score'] = $this->calculateScore(
                $page['age'],
                $page['is_leaf']
            );
            $page['score_breakdown'] = $this->buildScoreBreakdown($page['age'], $page['is_leaf'], $settings);
            $duplicateKey = $this->buildDuplicateKey((string)($page['title'] ?? ''), (int)$page['sys_language_uid']);
            $page['duplicate_key'] = $duplicateKey;
            $page['duplicate_count'] = isset($duplicateGroups[$duplicateKey]) ? count($duplicateGroups[$duplicateKey]) : 0;
            $page['duplicate_matches'] = array_values(array_filter(
                $duplicateGroups[$duplicateKey] ?? [],
                fn(array $candidate): bool => (int)$candidate['uid'] !== (int)$page['uid']
            ));
            $page['has_duplicates'] = $page['duplicate_count'] > 1;
        }

        return $result;
    }

    public function getPageByUid(int $pageUid): ?array
    {
        foreach ($this->getPagesWithAge() as $page) {
            if ((int)$page['uid'] === $pageUid) {
                return $page;
            }
        }

        return null;
    }

    public function getSettings(): array
    {
        $defaults = [
            'yellowThreshold' => self::DEFAULT_YELLOW_THRESHOLD,
            'redThreshold' => self::DEFAULT_RED_THRESHOLD,
        ];

        try {
            $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
            $configuration = $extensionConfiguration->get('content_radar');

            return [
                'yellowThreshold' => max(1, (int)($configuration['yellowThreshold'] ?? $defaults['yellowThreshold'])),
                'redThreshold' => max(1, (int)($configuration['redThreshold'] ?? $defaults['redThreshold'])),
            ];
        } catch (\Throwable) {
            return $defaults;
        }
    }

    public function getStatus(int $age, ?array $settings = null): string
    {
        $settings = $settings ?? $this->getSettings();

        if ($age > (int)$settings['redThreshold']) {
            return 'red';
        }
        if ($age > (int)$settings['yellowThreshold']) {
            return 'yellow';
        }
        return 'green';
    }

    public function buildScoreBreakdown(int $age, bool $isLeaf, ?array $settings = null): array
    {
        $settings = $settings ?? $this->getSettings();
        $yellowThreshold = (int)$settings['yellowThreshold'];
        $redThreshold = (int)$settings['redThreshold'];

        $ageRatio = min(1, max(0, $age / max(1, $redThreshold)));
        $agePenalty = (int)round($ageRatio * 100);
        $leafPenalty = $isLeaf ? 10 : 0;

        $baseScore = 100;
        $score = max(0, $baseScore - $agePenalty - $leafPenalty);

        return [
            'baseScore' => $baseScore,
            'agePenalty' => $agePenalty,
            'leafPenalty' => $leafPenalty,
            'score' => $score,
            'status' => $this->getStatus($age, $settings),
            'thresholds' => [
                'yellow' => $yellowThreshold,
                'red' => $redThreshold,
            ],
            'messages' => [
                'age' => $age > $redThreshold
                    ? 'Age exceeds red threshold'
                    : ($age > $yellowThreshold ? 'Age exceeds yellow threshold' : 'Age is within the healthy range'),
                'leaf' => $isLeaf ? 'Page has no child pages' : 'Page has child pages',
            ],
        ];
    }

    public function findSimilarPages(array $page, array $pages, int $limit = 5): array
    {
        $currentKey = $this->buildDuplicateKey((string)($page['title'] ?? ''), (int)($page['sys_language_uid'] ?? 0));
        $matches = [];

        foreach ($pages as $candidate) {
            if ((int)($candidate['uid'] ?? 0) === (int)($page['uid'] ?? 0)) {
                continue;
            }

            $candidateKey = $this->buildDuplicateKey((string)($candidate['title'] ?? ''), (int)($candidate['sys_language_uid'] ?? 0));
            if ($candidateKey !== $currentKey) {
                continue;
            }

            $matches[] = $candidate;
        }

        usort($matches, fn(array $left, array $right): int => ($right['score'] ?? 0) <=> ($left['score'] ?? 0));

        return array_slice($matches, 0, $limit);
    }

    private function getIncomingCounts(array $pages): array
    {
        $counts = [];

        foreach ($pages as $page) {
            $parentId = (int)$page['pid'];

            if (!isset($counts[$parentId])) {
                $counts[$parentId] = 0;
            }

            $counts[$parentId]++;
        }

        return $counts;
    }

    private function calculateScore(int $age, bool $isLeaf): int
    {
        $breakdown = $this->buildScoreBreakdown($age, $isLeaf);
        return (int)$breakdown['score'];
    }

    private function resolveLanguageMeta(int $pageId, int $languageId, int $l10nParent): array
    {
        try {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $sitePageId = $l10nParent > 0 ? $l10nParent : $pageId;
            $site = $siteFinder->getSiteByPageId($sitePageId);
            $language = $site->getLanguageById($languageId);
            return [
                'title' => $language->getTitle(),
                'flag' => $language->getFlagIdentifier() ?: 'flags-multiple',
            ];
        } catch (\Throwable $e) {
            return [
                'title' => 'unknown',
                'flag' => 'flags-multiple',
            ];
        }
    }

    public function toCsv(array $pages): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['uid', 'title', 'language', 'age', 'incoming', 'is_leaf', 'score', 'status', 'tstamp']);

        foreach ($pages as $page) {
            fputcsv($handle, [
                $page['uid'] ?? '',
                $page['title'] ?? '',
                $page['language'] ?? '',
                $page['age'] ?? '',
                $page['incoming'] ?? '',
                !empty($page['is_leaf']) ? '1' : '0',
                $page['score'] ?? '',
                $page['status'] ?? '',
                $page['tstamp'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    private function buildDuplicateGroups(array $pages): array
    {
        $groups = [];

        foreach ($pages as $page) {
            $key = $this->buildDuplicateKey((string)($page['title'] ?? ''), (int)($page['sys_language_uid'] ?? 0));
            if ($key === '') {
                continue;
            }

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }

            $groups[$key][] = $page;
        }

        return array_filter($groups, static fn(array $group): bool => count($group) > 1);
    }

    private function buildDuplicateKey(string $title, int $languageId): string
    {
        $normalizedTitle = strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? ''));
        $normalizedTitle = preg_replace('/[^\p{L}\p{N} ]/u', '', $normalizedTitle) ?? '';

        return $normalizedTitle !== '' ? $languageId . ':' . $normalizedTitle : '';
    }
}

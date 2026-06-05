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
        // Basis: Alter
        $score = 100 - ($age / 365 * 100);

        // Begrenzen
        $score = max(0, min(100, $score));

        // Leaf-Malus
        if ($isLeaf) {
            $score -= 10;
        }

        return max(0, (int)$score);
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
}

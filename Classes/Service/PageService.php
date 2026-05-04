<?php

namespace Mobasoft\ContentRadar\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageService
{
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

        $incomingCounts = $this->getIncomingCounts($result);

        foreach ($result as &$page) {
            $ageDays = (int)(($now - (int)$page['tstamp']) / 86400);

            $page['age'] = $ageDays;
            $page['status'] = $this->getStatus($ageDays);

            $incoming = $incomingCounts[$page['uid']] ?? 0;

            $page['incoming'] = $incoming;
            $page['is_leaf'] = ($incoming === 0);

            $page['language'] = $this->resolveLanguageLabel(
                (int)$page['uid'],
                (int)$page['sys_language_uid'],
                (int)$page['l10n_parent']
            );

            $page['score'] = $this->calculateScore(
                $page['age'],
                $page['is_leaf']
            );
        }

        return $result;
    }

    private function getStatus(int $age): string
    {
        if ($age > 365) {
            return 'red';
        }
        if ($age > 180) {
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

    private function getLanguageLabel(int $lang): string
    {
        return match ($lang) {
            0 => 'default',
            default => 'translation'
        };
    }

    private function resolveLanguageLabel(int $pageId, int $languageId, int $l10nParent): string
    {
        try {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $sitePageId = $l10nParent > 0 ? $l10nParent : $pageId;
            $site = $siteFinder->getSiteByPageId($sitePageId);
            $language = $site->getLanguageById($languageId);
            return $language->getTitle();
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }
}

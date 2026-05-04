<?php

namespace Mobasoft\ContentRadar\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageService
{
    public function getPagesWithAge(): array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');

        $queryBuilder = $connection->createQueryBuilder();

        $result = $queryBuilder
            ->select('uid', 'pid', 'title', 'tstamp')
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
}

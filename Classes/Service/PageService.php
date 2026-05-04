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
            ->select('uid', 'title', 'tstamp')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('hidden', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $now = time();

        foreach ($result as &$page) {
            $ageDays = (int)(($now - (int)$page['tstamp']) / 86400);

            $page['age'] = $ageDays;
            $page['status'] = $this->getStatus($ageDays);
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
}

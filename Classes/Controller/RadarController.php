<?php

namespace Mobasoft\ContentRadar\Controller;

use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Psr\Http\Message\ResponseInterface;
use Mobasoft\ContentRadar\Service\PageService;

class RadarController extends ActionController
{
    public function __construct(
        protected PageService $pageService
    ) {}

    public function indexAction(): ResponseInterface
    {
        $pages = $this->pageService->getPagesWithAge();
        $request = $this->request;
        $sort = $request->hasArgument('sort') ? $request->getArgument('sort') : 'age_desc';
        $filter = $request->hasArgument('filter') ? $request->getArgument('filter') : null;

        $summary = $this->buildSummary($pages);
        $pageGroups = $this->groupByDefaultPage($pages, $sort, $filter);

        $this->view->assignMultiple([
            'summary' => $summary,
            'pageGroups' => $pageGroups,
            'sort' => $sort,
            'filter' => $filter,
        ]);

        return $this->htmlResponse();
    }

    private function groupByDefaultPage(array $pages, string $sort, ?string $filter): array
    {
        $pagesByUid = [];
        foreach ($pages as $page) {
            $pagesByUid[(int)$page['uid']] = $page;
        }

        $groups = [];
        foreach ($pages as $page) {
            $pageUid = (int)$page['uid'];
            $isTranslation = (int)$page['sys_language_uid'] > 0 && (int)$page['l10n_parent'] > 0;
            $defaultUid = $isTranslation ? (int)$page['l10n_parent'] : $pageUid;

            if (!isset($groups[$defaultUid])) {
                $defaultPage = $pagesByUid[$defaultUid] ?? $page;
                $groups[$defaultUid] = [
                    'defaultPage' => $defaultPage,
                    'translations' => [],
                ];
            }

            if ($isTranslation) {
                $groups[$defaultUid]['translations'][] = $page;
            }
        }

        foreach ($groups as &$group) {
            usort(
                $group['translations'],
                fn($a, $b) => ($a['sys_language_uid'] <=> $b['sys_language_uid']) ?: ($b['score'] <=> $a['score'])
            );
        }
        unset($group);

        $groups = array_values($groups);

        // Filter: keep parent row when any translation matches.
        if ($filter === 'critical') {
            $groups = array_values(array_filter($groups, function (array $group): bool {
                if (($group['defaultPage']['status'] ?? 'green') !== 'green') {
                    return true;
                }
                foreach ($group['translations'] as $translation) {
                    if (($translation['status'] ?? 'green') !== 'green') {
                        return true;
                    }
                }
                return false;
            }));
        } elseif ($filter === 'leaf') {
            $groups = array_values(array_filter($groups, function (array $group): bool {
                if (!empty($group['defaultPage']['is_leaf'])) {
                    return true;
                }
                foreach ($group['translations'] as $translation) {
                    if (!empty($translation['is_leaf'])) {
                        return true;
                    }
                }
                return false;
            }));
        }

        usort($groups, function (array $a, array $b) use ($sort): int {
            $left = $a['defaultPage'];
            $right = $b['defaultPage'];

            return match ($sort) {
                'score_desc' => ($right['score'] <=> $left['score']) ?: ($right['age'] <=> $left['age']),
                'score_asc' => ($left['score'] <=> $right['score']) ?: ($left['age'] <=> $right['age']),
                'age_desc' => ($right['age'] <=> $left['age']) ?: ($right['score'] <=> $left['score']),
                'age_asc' => ($left['age'] <=> $right['age']) ?: ($left['score'] <=> $right['score']),
                'incoming_desc' => ($right['incoming'] <=> $left['incoming']) ?: ($right['score'] <=> $left['score']),
                'incoming_asc' => ($left['incoming'] <=> $right['incoming']) ?: ($left['score'] <=> $right['score']),
                default => 0,
            };
        });

        return $groups;
    }

    private function buildSummary(array $pages): array
    {
        $totalPages = count($pages);
        $defaultPages = count(array_filter($pages, fn($page) => (int)$page['sys_language_uid'] === 0));
        $translationPages = $totalPages - $defaultPages;
        $languagesCount = count(array_unique(array_map(fn($page) => (string)$page['language'], $pages)));

        $oldestPage = null;
        $newestPage = null;

        foreach ($pages as $page) {
            if ($oldestPage === null || (int)$page['age'] > (int)$oldestPage['age']) {
                $oldestPage = $page;
            }
            if ($newestPage === null || (int)$page['age'] < (int)$newestPage['age']) {
                $newestPage = $page;
            }
        }

        return [
            'totalPages' => $totalPages,
            'defaultPages' => $defaultPages,
            'translationPages' => $translationPages,
            'languagesCount' => $languagesCount,
            'oldestPageTitle' => $oldestPage['title'] ?? '',
            'oldestPageAge' => $oldestPage['age'] ?? 0,
            'oldestChangeDate' => isset($oldestPage['tstamp']) ? date('Y-m-d', (int)$oldestPage['tstamp']) : '',
            'newestPageTitle' => $newestPage['title'] ?? '',
            'newestPageAge' => $newestPage['age'] ?? 0,
            'newestChangeDate' => isset($newestPage['tstamp']) ? date('Y-m-d', (int)$newestPage['tstamp']) : '',
        ];
    }
}

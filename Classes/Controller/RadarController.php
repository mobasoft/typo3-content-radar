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
        $sort = $request->hasArgument('sort') ? $request->getArgument('sort') : 'language_grouped';
        $filter = $request->hasArgument('filter') ? $request->getArgument('filter') : null;

        // Filter
        if ($filter === 'critical') {
            $pages = array_filter($pages, function ($page) {
                return $page['status'] !== 'green';
            });
        }

        if ($filter === 'leaf') {
            $pages = array_filter($pages, fn($p) => $p['is_leaf']);
        }

        // Sortierung
        switch ($sort) {
            case 'score_desc':
                usort($pages, fn($a, $b) =>
                ($b['score'] <=> $a['score'])
                    ?: ($b['age'] <=> $a['age'])
                );
                break;
            case 'score_asc':
                usort($pages, fn($a, $b) => $a['score'] <=> $b['score']);
                break;
            case 'age_desc':
                usort($pages, fn($a, $b) =>
                ($b['age'] <=> $a['age'])
                    ?: ($b['score'] <=> $a['score'])
                );
                break;
            case 'language_asc':
            case 'language_grouped':
                usort($pages, fn($a, $b) =>
                ($a['sys_language_uid'] <=> $b['sys_language_uid'])
                    ?: ($b['score'] <=> $a['score'])
                );
                break;
        }

        $this->view->assignMultiple([
            'pages' => $pages,
            'sort' => $sort,
            'filter' => $filter,
        ]);

        return $this->htmlResponse();
    }
}

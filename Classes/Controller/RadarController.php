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
        $sort = $request->hasArgument('sort') ? $request->getArgument('sort') : null;
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
        if ($sort === 'age_desc') {
            usort($pages, fn($a, $b) => $b['age'] <=> $a['age']);
        }

        $this->view->assignMultiple([
            'pages' => $pages,
            'sort' => $sort,
            'filter' => $filter,
        ]);

        return $this->htmlResponse();
    }
}

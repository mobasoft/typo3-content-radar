<?php

return [
    'web_contentradar' => [
        'parent' => 'web',
        'position' => ['after' => 'info'],
        'access' => 'user',
        'workspaces' => 'live',
        'iconIdentifier' => 'content-radar-module',
        'labels' => 'LLL:EXT:content_radar/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'ContentRadar',
        'controllerActions' => [
            \Mobasoft\ContentRadar\Controller\RadarController::class => [
                'index',
            ],
        ],
    ],
];

<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Content Radar',
    'description' => 'Identify outdated and orphaned pages',
    'category' => 'backend',
    'author' => 'Steffen Scheibe',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.0.0-13.9.99',
        ],
    ],
];

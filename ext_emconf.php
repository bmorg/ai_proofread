<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Proof Reader',
    'description' => 'AI-assisted proofreading of TYPO3 page content',
    'category' => 'be',
    'author' => 'bmorg',
    'author_email' => 'contact@bmorg.io',
    'state' => 'alpha',
    'version' => '0.4.0',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-13.4.99',
        ],
    ],
    // Required for non-Composer ("classic") installations — mirrors composer.json.
    'autoload' => [
        'psr-4' => [
            'Bmorg\\AiProofread\\' => 'Classes/',
        ],
    ],
];

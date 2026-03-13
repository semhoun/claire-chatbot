<?php

//@see https://github.com/nunomaduro/phpinsights/blob/master/phpinsights.php

declare(strict_types=1);

use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenNormalClasses;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenTraits;
use NunoMaduro\PhpInsights\Domain\Sniffs\ForbiddenSetterSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Files\LineLengthSniff;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousExceptionNamingSniff;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousInterfaceNamingSniff;
use SlevomatCodingStandard\Sniffs\Commenting\InlineDocCommentDeclarationSniff;
use SlevomatCodingStandard\Sniffs\Functions\FunctionLengthSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\DisallowMixedTypeHintSniff;
use SlevomatCodingStandard\Sniffs\Variables\DisallowSuperGlobalVariableSniff;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Preset
    |--------------------------------------------------------------------------
    |
    | This option controls the default preset that will be used by PHP Insights
    | to make your code reliable, simple, and clean. However, you can always
    | adjust the `Metrics` and `Insights` below in this configuration file.
    |
    | Supported: "default", "laravel", "symfony", "magento2", "drupal", "wordpress"
    |
    */

    'preset' => 'default',

    /*
    |--------------------------------------------------------------------------
    | IDE
    |--------------------------------------------------------------------------
    |
    | This options allow to add hyperlinks in your terminal to quickly open
    | files in your favorite IDE while browsing your PhpInsights report.
    |
    | Supported: "textmate", "macvim", "emacs", "sublime", "phpstorm",
    | "atom", "vscode".
    |
    | If you have another IDE that is not in this list but which provide an
    | url-handler, you could fill this config with a pattern like this:
    |
    | myide://open?url=file://%f&line=%l
    |
    */

    'ide' => null,

    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may adjust all the various `Insights` that will be used by PHP
    | Insights. You can either add, remove or configure `Insights`. Keep in
    | mind, that all added `Insights` must belong to a specific `Metric`.
    |
    */

    'exclude' => [
        'var',
    ],

    'add' => [
        \NunoMaduro\PhpInsights\Domain\Metrics\Code\Comments::class => [
            \PhpCsFixer\Fixer\Phpdoc\PhpdocSummaryFixer::class,
        ],
    ],

    'remove' => [
        SlevomatCodingStandard\Sniffs\ControlStructures\DisallowEmptySniff::class,
        SlevomatCodingStandard\Sniffs\Functions\UnusedParameterSniff::class,
        ForbiddenSetterSniff::class,
        DisallowMixedTypeHintSniff::class,
        SuperfluousInterfaceNamingSniff::class,
        SuperfluousExceptionNamingSniff::class,
        ForbiddenTraits::class,
        FunctionLengthSniff::class,
        ForbiddenNormalClasses::class,
        InlineDocCommentDeclarationSniff::class,
        DisallowSuperGlobalVariableSniff::class,
    ],

    'config' => [
        LineLengthSniff::class => [
            'lineLimit' => 80,
            'absoluteLineLimit' => 120,
            'ignoreComments' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Requirements
    |--------------------------------------------------------------------------
    |
    | Here you may define a level you want to reach per `Insights` category.
    | When a score is lower than the minimum level defined, then an error
    | code will be returned. This is optional and individually defined.
    |
    */

    'requirements' => [
        'min-quality' => 90.0,
        'min-architecture' => 80.0,
        'min-style' => 96.0,
    ],
];

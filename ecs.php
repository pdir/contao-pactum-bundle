<?php

declare(strict_types=1);

use Contao\EasyCodingStandard\Set\SetList;
use PhpCsFixer\Fixer\Comment\HeaderCommentFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withSets([SetList::CONTAO])
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/contao',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withConfiguredRule(HeaderCommentFixer::class, [
        'header' => "This file is part of Pactum – AVV/DPA for Contao.\n\n(c) pdir GmbH <https://pdir.de>\n\n@license LGPL-3.0-or-later",
    ])
    ->withParallel()
    ->withSpacing(lineEnding: "\n")
    ->withCache(sys_get_temp_dir().'/ecs/pactum-bundle')
;

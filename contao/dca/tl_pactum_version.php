<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

use Contao\DataContainer;
use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_pactum_version'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable' => 'tl_pactum_template',
        // Copying would bypass the "new draft from version" logic, see VersionDuplicator
        'notCopyable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid,language,status' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_PARENT,
            'fields' => ['version_number DESC'],
            'headerFields' => ['title', 'contractor'],
            'panelLayout' => 'filter;limit',
            'disableGrouping' => true,
        ],
        'label' => [
            'fields' => ['version_number', 'language', 'status', 'change_note'],
            'format' => 'v%s · %s · %s <span class="label-info">%s</span>',
        ],
        'operations' => [
            'edit',
            'publish' => [
                'route' => 'pactum_backend_version_publish',
                'icon' => 'visible.svg',
                'prefetch' => false,
            ],
            'new_draft' => [
                'route' => 'pactum_backend_version_duplicate',
                'icon' => 'copy.svg',
                'prefetch' => false,
            ],
            'preview' => [
                'route' => 'pactum_backend_version_preview',
                'icon' => 'show.svg',
            ],
            'delete',
            'show',
        ],
    ],
    'palettes' => [
        'default' => '{version_legend},language,valid_from,change_note;{content_legend},content;{annex_legend},annexes',
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'pid' => [
            'foreignKey' => 'tl_pactum_template.title',
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'version_number' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'language' => [
            'filter' => true,
            'inputType' => 'select',
            'eval' => ['mandatory' => true, 'chosen' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(64) NOT NULL default ''",
        ],
        'valid_from' => [
            'inputType' => 'text',
            'eval' => ['rgxp' => 'date', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'change_note' => [
            'inputType' => 'textarea',
            'eval' => ['tl_class' => 'clr', 'style' => 'height:60px'],
            'sql' => 'text NULL',
        ],
        'content' => [
            'inputType' => 'textarea',
            'eval' => ['mandatory' => true, 'rte' => 'tinyMCE', 'helpwizard' => true],
            'explanation' => 'pactumPlaceholders',
            'sql' => 'mediumtext NULL',
        ],
        'annexes' => [
            'inputType' => 'checkboxWizard',
            'foreignKey' => 'tl_pactum_annex.title',
            'eval' => ['multiple' => true, 'tl_class' => 'clr'],
            'sql' => 'blob NULL',
            'relation' => ['type' => 'hasMany', 'load' => 'lazy'],
        ],
        'status' => [
            'filter' => true,
            'sql' => "varchar(16) NOT NULL default 'draft'",
        ],
        // The following fields are written exclusively by the VersionPublisher
        'rendered_content' => [
            'sql' => 'mediumtext NULL',
        ],
        'annex_snapshot' => [
            'sql' => 'mediumtext NULL',
        ],
        'content_hash' => [
            'sql' => "char(64) NOT NULL default ''",
        ],
        'published_at' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'published_by' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
    ],
];

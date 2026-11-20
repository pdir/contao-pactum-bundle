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

$GLOBALS['TL_DCA']['tl_pactum_annex'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ctable' => ['tl_pactum_subprocessor'],
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTED,
            'fields' => ['type', 'title'],
            'flag' => DataContainer::SORT_ASC,
            'panelLayout' => 'filter;search,limit',
        ],
        'label' => [
            'fields' => ['title', 'type'],
            'format' => '%s <span class="label-info">[%s]</span>',
        ],
        'operations' => [
            'edit',
            'children',
            'copy',
            'delete',
            'show',
        ],
    ],
    'palettes' => [
        'default' => '{annex_legend},type,title;{content_legend},content',
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'type' => [
            'filter' => true,
            'inputType' => 'select',
            'options' => ['tom', 'subprocessor', 'custom'],
            'reference' => &$GLOBALS['TL_LANG']['tl_pactum_annex']['types'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(16) NOT NULL default 'tom'",
        ],
        'title' => [
            'search' => true,
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'content' => [
            'inputType' => 'textarea',
            'eval' => ['rte' => 'tinyMCE', 'tl_class' => 'clr'],
            'sql' => 'mediumtext NULL',
        ],
    ],
];

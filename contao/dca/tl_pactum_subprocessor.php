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

$GLOBALS['TL_DCA']['tl_pactum_subprocessor'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable' => 'tl_pactum_annex',
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_PARENT,
            'fields' => ['sorting'],
            'headerFields' => ['title', 'type'],
            'panelLayout' => 'search,limit',
        ],
        'label' => [
            'fields' => ['name', 'service', 'location'],
            'format' => '%s – %s <span class="label-info">[%s]</span>',
        ],
        'operations' => ['edit', 'copy', 'cut', 'delete', 'show'],
    ],
    'palettes' => [
        'default' => '{subprocessor_legend},name,address,service;{location_legend},location,transfer_basis',
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'pid' => [
            'foreignKey' => 'tl_pactum_annex.title',
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'sorting' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'name' => [
            'search' => true,
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'address' => [
            'inputType' => 'textarea',
            'eval' => ['mandatory' => true, 'tl_class' => 'clr', 'style' => 'height:60px'],
            'sql' => 'text NULL',
        ],
        'service' => [
            'search' => true,
            'inputType' => 'textarea',
            'eval' => ['mandatory' => true, 'tl_class' => 'clr', 'style' => 'height:60px'],
            'sql' => 'text NULL',
        ],
        'location' => [
            'inputType' => 'select',
            'options' => ['eu', 'third_country'],
            'reference' => &$GLOBALS['TL_LANG']['tl_pactum_subprocessor']['locations'],
            'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(16) NOT NULL default 'eu'",
        ],
        'transfer_basis' => [
            'inputType' => 'text',
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
    ],
];

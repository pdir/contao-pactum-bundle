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

$GLOBALS['TL_DCA']['tl_pactum_announcement_recipient'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable' => 'tl_pactum_announcement',
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'notSortable' => true,
        'backendSearchIgnore' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index',
                'agreement' => 'index',
                'token_hash' => 'unique',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_PARENT,
            'fields' => ['company'],
            'headerFields' => ['template', 'sent_at', 'effective_at', 'recipient_count', 'objection_count'],
            'panelLayout' => 'filter;search,limit',
        ],
        'label' => [
            'fields' => ['company', 'email', 'mail_status', 'objected_at'],
        ],
        'operations' => [
            'show',
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'pid' => [
            'foreignKey' => 'tl_pactum_announcement.id',
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'agreement' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'company' => ['search' => true, 'sql' => "varchar(255) NOT NULL default ''"],
        'signatory_name' => ['search' => true, 'sql' => "varchar(255) NOT NULL default ''"],
        'email' => ['search' => true, 'sql' => "varchar(255) NOT NULL default ''"],
        'language' => ['sql' => "varchar(64) NOT NULL default ''"],
        'token_hash' => ['sql' => 'char(64) NULL'],
        'mail_status' => [
            'filter' => true,
            'reference' => &$GLOBALS['TL_LANG']['tl_pactum_announcement_recipient']['mail_statuses'],
            'sql' => "varchar(16) NOT NULL default 'pending'",
        ],
        'objected_at' => [
            'eval' => ['rgxp' => 'datim'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'objection_name' => ['sql' => "varchar(255) NOT NULL default ''"],
        'objection_subprocessors' => ['sql' => 'text NULL'],
        'objection_reason' => ['sql' => 'text NULL'],
    ],
];

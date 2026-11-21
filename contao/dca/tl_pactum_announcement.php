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

$GLOBALS['TL_DCA']['tl_pactum_announcement'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ctable' => ['tl_pactum_announcement_recipient'],
        // Announcements are evidence: they are created via "Announce change" on a draft
        // version and cannot be edited, copied or deleted in the back end
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'backendSearchIgnore' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'uuid' => 'unique',
                'template' => 'index',
                'version' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTED,
            'fields' => ['sent_at'],
            'flag' => DataContainer::SORT_MONTH_DESC,
            'panelLayout' => 'filter;sort,limit',
        ],
        'label' => [
            'fields' => ['template', 'version', 'sent_at', 'effective_at', 'recipient_count', 'objection_count'],
            'showColumns' => true,
        ],
        'operations' => [
            'children',
            'show',
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'uuid' => ['sql' => "char(36) NOT NULL default ''"],
        'template' => [
            'filter' => true,
            'foreignKey' => 'tl_pactum_template.title',
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'version' => [
            'foreignKey' => "tl_pactum_version.CONCAT('v', version_number, ' (', language, ')')",
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'language' => ['sql' => "varchar(64) NOT NULL default ''"],
        'changes' => ['sql' => 'mediumtext NULL'],
        'notice_days' => ['sql' => 'smallint(5) unsigned NOT NULL default 0'],
        'sent_at' => [
            'sorting' => true,
            'flag' => DataContainer::SORT_MONTH_DESC,
            'eval' => ['rgxp' => 'datim'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'effective_at' => [
            'eval' => ['rgxp' => 'datim'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'created_by' => [
            'foreignKey' => 'tl_user.name',
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'recipient_count' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'objection_count' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
    ],
];

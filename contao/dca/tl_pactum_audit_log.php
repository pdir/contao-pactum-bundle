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

// Append-only audit log. It has no back end module of its own; entries are shown
// in the agreement/invitation detail views. Only IDs, never personal data.
$GLOBALS['TL_DCA']['tl_pactum_audit_log'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'backendSearchIgnore' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'invitation' => 'index',
                'agreement' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTED,
            'fields' => ['created_at'],
            'flag' => DataContainer::SORT_DAY_DESC,
        ],
        'label' => [
            'fields' => ['event', 'created_at'],
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'created_at' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'invitation' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'agreement' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'event' => ['sql' => "varchar(64) NOT NULL default ''"],
        'actor' => ['sql' => "varchar(64) NOT NULL default ''"],
        'context' => ['sql' => 'text NULL'],
    ],
];

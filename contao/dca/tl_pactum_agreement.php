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

$GLOBALS['TL_DCA']['tl_pactum_agreement'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        // Agreements are evidence: no create/edit/copy/delete in the back end
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'backendSearchIgnore' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'uuid' => 'unique',
                'invitation' => 'unique',
                'template' => 'index',
                'version' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTED,
            'fields' => ['consented_at'],
            'flag' => DataContainer::SORT_MONTH_DESC,
            'panelLayout' => 'filter;sort,search,limit',
        ],
        'label' => [
            'fields' => ['company', 'signatory_name', 'template', 'version', 'consented_at', 'mail_status'],
            'showColumns' => true,
        ],
        'operations' => [
            'detail' => [
                'route' => 'pactum_backend_agreement_detail',
                'icon' => 'show.svg',
                'primary' => true,
            ],
            'pdf' => [
                'route' => 'pactum_backend_agreement_pdf',
                'icon' => 'download.svg',
                'prefetch' => false,
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'uuid' => ['sql' => "char(36) NOT NULL default ''"],
        'invitation' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'template' => [
            'filter' => true,
            'foreignKey' => 'tl_pactum_template.title',
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'version' => [
            'filter' => true,
            'foreignKey' => "tl_pactum_version.CONCAT('v', version_number, ' (', language, ')')",
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'language' => ['filter' => true, 'sql' => "varchar(64) NOT NULL default ''"],
        'version_hash' => ['sql' => "char(64) NOT NULL default ''"],
        'document_hash' => ['sql' => "char(64) NOT NULL default ''"],
        'document_html' => ['sql' => 'mediumtext NULL'],
        'pdf_path' => ['sql' => "varchar(255) NOT NULL default ''"],
        'pdf_hash' => ['sql' => "char(64) NOT NULL default ''"],
        'company' => ['search' => true, 'sql' => "varchar(255) NOT NULL default ''"],
        'street' => ['sql' => "varchar(255) NOT NULL default ''"],
        'postal' => ['sql' => "varchar(32) NOT NULL default ''"],
        'city' => ['sql' => "varchar(255) NOT NULL default ''"],
        'country' => ['sql' => "varchar(64) NOT NULL default ''"],
        'signatory_name' => ['search' => true, 'sql' => "varchar(255) NOT NULL default ''"],
        'signatory_role' => ['sql' => "varchar(255) NOT NULL default ''"],
        'signatory_email' => ['search' => true, 'sql' => "varchar(255) NOT NULL default ''"],
        'optin_at' => [
            'eval' => ['rgxp' => 'datim'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'consented_at' => [
            'sorting' => true,
            'flag' => DataContainer::SORT_MONTH_DESC,
            'eval' => ['rgxp' => 'datim'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'consent_texts' => ['sql' => 'text NULL'],
        'ip' => ['sql' => "varchar(64) NOT NULL default ''"],
        'user_agent' => ['sql' => "varchar(512) NOT NULL default ''"],
        'ip_delete_after' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'mail_status' => [
            'filter' => true,
            'reference' => &$GLOBALS['TL_LANG']['tl_pactum_agreement']['mail_statuses'],
            'sql' => "varchar(16) NOT NULL default 'pending'",
        ],
    ],
];

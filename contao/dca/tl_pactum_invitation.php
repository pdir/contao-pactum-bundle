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

$GLOBALS['TL_DCA']['tl_pactum_invitation'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'notCopyable' => true,
        // Contains personal data: never index it in the back end search
        'backendSearchIgnore' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'token_hash' => 'unique',
                'status' => 'index',
                'template' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTED,
            'fields' => ['created_at'],
            'flag' => DataContainer::SORT_MONTH_DESC,
            'panelLayout' => 'filter;sort,search,limit',
        ],
        'label' => [
            'fields' => ['company', 'template', 'status', 'expires_at'],
            'showColumns' => true,
        ],
        'operations' => [
            'edit',
            'link' => [
                'route' => 'pactum_backend_invitation_link',
                'icon' => 'share.svg',
                'prefetch' => false,
            ],
            'extend' => [
                'route' => 'pactum_backend_invitation_extend',
                'icon' => 'sync.svg',
                'prefetch' => false,
            ],
            'revoke' => [
                'route' => 'pactum_backend_invitation_revoke',
                'icon' => 'unpublished.svg',
                'prefetch' => false,
            ],
            'audit' => [
                'route' => 'pactum_backend_invitation_audit',
                'icon' => 'diff.svg',
            ],
            'delete',
        ],
    ],
    'palettes' => [
        'default' => '{invitation_legend},template,language,expires_at;{prefill_legend},prefill_company,prefill_email',
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'created_at' => [
            'sorting' => true,
            'flag' => DataContainer::SORT_MONTH_DESC,
            'eval' => ['rgxp' => 'datim'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'created_by' => [
            'foreignKey' => 'tl_user.name',
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'template' => [
            'filter' => true,
            'sorting' => true,
            'inputType' => 'select',
            'foreignKey' => 'tl_pactum_template.title',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
            'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'language' => [
            'filter' => true,
            'inputType' => 'select',
            'eval' => ['mandatory' => true, 'chosen' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(64) NOT NULL default ''",
        ],
        'expires_at' => [
            'sorting' => true,
            'flag' => DataContainer::SORT_DAY_DESC,
            'inputType' => 'text',
            'eval' => ['rgxp' => 'date', 'datepicker' => true, 'tl_class' => 'w50 wizard'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'status' => [
            'filter' => true,
            'reference' => &$GLOBALS['TL_LANG']['tl_pactum_invitation']['statuses'],
            'sql' => "varchar(16) NOT NULL default 'open'",
        ],
        'prefill_company' => [
            'search' => true,
            'inputType' => 'text',
            'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'prefill_email' => [
            'search' => true,
            'inputType' => 'text',
            'eval' => ['rgxp' => 'email', 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        // SHA-256 of the invitation token; the token itself is never stored
        'token_hash' => ['sql' => 'char(64) NULL'],
        'company' => ['search' => true, 'sql' => "varchar(255) NOT NULL default ''"],
        'street' => ['sql' => "varchar(255) NOT NULL default ''"],
        'postal' => ['sql' => "varchar(32) NOT NULL default ''"],
        'city' => ['sql' => "varchar(255) NOT NULL default ''"],
        'country' => ['sql' => "varchar(64) NOT NULL default ''"],
        'signatory_name' => ['search' => true, 'sql' => "varchar(255) NOT NULL default ''"],
        'signatory_role' => ['sql' => "varchar(255) NOT NULL default ''"],
        'signatory_email' => ['search' => true, 'sql' => "varchar(255) NOT NULL default ''"],
        'optin_identifier' => ['sql' => 'varchar(64) NULL'],
        'optin_requested_at' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'optin_confirmed_at' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'mail_status' => ['sql' => "varchar(16) NOT NULL default ''"],
        // Prepared for re-consent (1.1): agreement that is replaced by this invitation
        'previous_agreement' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'anonymized_at' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
    ],
];

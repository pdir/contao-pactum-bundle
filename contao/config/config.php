<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

// Contao 5.7/6.0 still registers DCA based back end modules via
// $GLOBALS['BE_MOD']; there is no service based alternative for DC_Table modules
// yet. Access to each module is granted separately via the regular user/group
// module permissions.
$GLOBALS['BE_MOD']['pactum'] = [
    'pactum_templates' => [
        'tables' => ['tl_pactum_template', 'tl_pactum_version'],
    ],
    'pactum_annexes' => [
        'tables' => ['tl_pactum_annex', 'tl_pactum_subprocessor'],
    ],
    'pactum_invitations' => [
        'tables' => ['tl_pactum_invitation'],
    ],
    'pactum_agreements' => [
        'tables' => ['tl_pactum_agreement'],
    ],
    'pactum_contractors' => [
        'tables' => ['tl_pactum_contractor'],
    ],
];

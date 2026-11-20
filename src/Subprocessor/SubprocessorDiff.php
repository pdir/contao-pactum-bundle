<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Subprocessor;

use Pdir\PactumBundle\Domain\Subprocessor;

final class SubprocessorDiff
{
    /**
     * @param list<Subprocessor> $old
     * @param list<Subprocessor> $new
     */
    public function compare(array $old, array $new): SubprocessorChanges
    {
        $oldByName = [];

        foreach ($old as $sub) {
            $oldByName[mb_strtolower($sub->name)] = $sub;
        }

        $added = [];
        $changed = [];
        $unchanged = 0;

        foreach ($new as $sub) {
            $key = mb_strtolower($sub->name);

            if (!isset($oldByName[$key])) {
                $added[] = $sub;
            } elseif ($oldByName[$key]->toArray() !== $sub->toArray()) {
                $changed[] = $sub;
            } else {
                ++$unchanged;
            }

            unset($oldByName[$key]);
        }

        $key = static fn (Subprocessor $s): string => mb_strtolower($s->name);
        $reordered = array_map($key, $old) !== array_map($key, $new);

        return new SubprocessorChanges($added, $changed, array_values($oldByName), $unchanged, $reordered);
    }
}

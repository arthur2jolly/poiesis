<?php

namespace App\Core\Models\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * @property string $statut
 */
trait HasStatusTransitions
{
    /** @var array<string, array<int, string>> */
    private static array $allowedTransitions = [
        'draft' => ['open'],
        'open' => ['closed'],
        'closed' => ['open'],
    ];

    public function transitionStatus(string $newStatus): void
    {
        $current = $this->statut;
        $allowed = self::$allowedTransitions[$current] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'statut' => ["Transition from '{$current}' to '{$newStatus}' is not allowed."],
            ]);
        }

        $this->statut = $newStatus;
        $this->save();
    }
}

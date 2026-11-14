<?php

namespace App\Exceptions;

use App\Models\ChangeRequest;
use RuntimeException;

/**
 * A stored change could not be applied (again): its data no longer validates or no longer exists.
 */
class ReplayFailed extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  validation errors by field, when that was the cause
     */
    public function __construct(public readonly ChangeRequest $item, string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /**
     * "ODP-01: Kode sudah dipakai." for messages to the user.
     */
    public function describe(): string
    {
        return ($this->item->subject_label ?: 'Perubahan #'.$this->item->id).': '.$this->getMessage();
    }
}

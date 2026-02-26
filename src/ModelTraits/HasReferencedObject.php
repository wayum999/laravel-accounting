<?php

declare(strict_types=1);

namespace App\Accounting\ModelTraits;

use Illuminate\Database\Eloquent\Model;

/**
 * Provides polymorphic reference linking to any Eloquent model.
 *
 * Models using this trait must have `ref_class` (string) and `ref_class_id` (int)
 * columns on their table.
 */
trait HasReferencedObject
{
    /**
     * Retrieve the referenced Eloquent model.
     */
    public function getReferencedObject(): ?Model
    {
        if (! $this->ref_class) {
            return null;
        }

        return (new $this->ref_class)->find($this->ref_class_id);
    }

    /**
     * Associate this record with a referenced Eloquent model.
     */
    public function referencesObject(Model $object): static
    {
        $this->update([
            'ref_class'    => $object::class,
            'ref_class_id' => $object->id,
        ]);

        return $this;
    }
}

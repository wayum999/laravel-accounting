<?php

declare(strict_types=1);

namespace App\Accounting\ModelTraits;

use App\Accounting\Models\AuditEntry;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasAuditLog
{
    public static function bootHasAuditLog(): void
    {
        static::created(function ($model) {
            if (!static::isAuditEnabled()) {
                return;
            }

            AuditEntry::record(
                $model,
                'created',
                null,
                static::filterAuditValues($model->getAttributes())
            );
        });

        static::updated(function ($model) {
            if (!static::isAuditEnabled()) {
                return;
            }

            $changes = $model->getChanges();
            $original = array_intersect_key($model->getOriginal(), $changes);

            $filtered = static::filterAuditValues($changes);
            $filteredOriginal = static::filterAuditValues($original);

            if (empty($filtered)) {
                return;
            }

            AuditEntry::record(
                $model,
                'updated',
                $filteredOriginal,
                $filtered
            );
        });

        static::deleted(function ($model) {
            if (!static::isAuditEnabled()) {
                return;
            }

            AuditEntry::record(
                $model,
                'deleted',
                static::filterAuditValues($model->getAttributes()),
                null
            );
        });

        // Support for SoftDeletes restore
        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                if (!static::isAuditEnabled()) {
                    return;
                }

                AuditEntry::record(
                    $model,
                    'restored',
                    null,
                    static::filterAuditValues($model->getAttributes())
                );
            });
        }
    }

    public function auditEntries(): MorphMany
    {
        return $this->morphMany(AuditEntry::class, 'auditable');
    }

    public function getAuditHistory(): \Illuminate\Database\Eloquent\Collection
    {
        return AuditEntry::forModel($this)->get();
    }

    private static function isAuditEnabled(): bool
    {
        return config('accounting.audit.enabled', true);
    }

    private static function filterAuditValues(array $values): array
    {
        $excludeFields = config('accounting.audit.exclude_fields', [
            'updated_at', 'created_at', 'deleted_at',
        ]);

        return array_diff_key($values, array_flip($excludeFields));
    }
}

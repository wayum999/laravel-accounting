<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditEntry extends Model
{
    public $timestamps = false;

    protected $table = 'accounting_audit_entries';

    protected $guarded = [];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Audit entries are immutable - prevent updates and deletes.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            return false;
        });

        static::deleting(function () {
            return false;
        });
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get all audit entries for a specific model instance.
     */
    public static function forModel(Model $model): Builder
    {
        return static::where('auditable_type', $model::class)
            ->where('auditable_id', $model->getKey())
            ->orderByDesc('created_at');
    }

    /**
     * Get all audit entries for a model type.
     */
    public static function forModelType(string $class): Builder
    {
        return static::where('auditable_type', $class)
            ->orderByDesc('created_at');
    }

    /**
     * Get all audit entries by a specific user.
     */
    public static function byUser(int $userId): Builder
    {
        return static::where('user_id', $userId)
            ->orderByDesc('created_at');
    }

    /**
     * Get audit entries within a date range.
     */
    public static function between(Carbon $from, Carbon $to): Builder
    {
        return static::whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at');
    }

    /**
     * Record an audit entry.
     */
    public static function record(
        Model $model,
        string $event,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null
    ): self {
        return static::create([
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'event' => $event,
            'user_id' => static::resolveUserId(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => static::resolveIpAddress(),
            'user_agent' => static::resolveUserAgent(),
            'metadata' => $metadata,
        ]);
    }

    private static function resolveUserId(): ?int
    {
        if (function_exists('auth') && auth()->check()) {
            return (int) auth()->id();
        }
        return null;
    }

    private static function resolveIpAddress(): ?string
    {
        if (function_exists('request')) {
            try {
                return request()?->ip();
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    private static function resolveUserAgent(): ?string
    {
        if (function_exists('request')) {
            try {
                return request()?->userAgent();
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }
}

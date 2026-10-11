<?php

namespace App\Models;

use App\Audit\AuditCategory;
use App\Audit\AuditEvent;
use App\Audit\AuditSeverity;
use App\Events\ActivityLogged;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity as BaseActivity;
use Throwable;

/**
 * @property string $severity
 * @property string|null $causer_label
 * @property string|null $subject_label
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property string|null $origin
 */
class Activity extends BaseActivity
{
    protected static function booted(): void
    {
        static::created(function (Activity $activity): void {
            if (! config('audit.broadcast')) {
                return;
            }

            try {
                ActivityLogged::dispatch($activity);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function category(): ?AuditCategory
    {
        return AuditCategory::tryFrom((string) $this->log_name);
    }

    public function severityLevel(): AuditSeverity
    {
        return AuditSeverity::tryFrom((string) $this->severity) ?? AuditSeverity::Info;
    }

    public function eventLabel(): string
    {
        return AuditEvent::labelFor($this->event);
    }

    public function subjectAlias(): ?string
    {
        return self::aliasFor($this->subject_type);
    }

    public static function aliasFor(?string $class): ?string
    {
        if ($class === null) {
            return null;
        }

        $alias = array_search($class, (array) config('audit.subjects', []), true);

        return is_string($alias) ? $alias : null;
    }

    public static function classFor(string $alias): ?string
    {
        $class = config("audit.subjects.{$alias}");

        return is_string($class) ? $class : null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->where('description', 'like', $like)
                ->orWhere('causer_label', 'like', $like)
                ->orWhere('subject_label', 'like', $like)
                ->orWhere('ip_address', 'like', $like);
        });
    }

    /**
     * @param  Builder<self>  $query
     * @param  string|list<string>  $categories
     * @return Builder<self>
     */
    public function scopeForCategory(Builder $query, string|array $categories): Builder
    {
        return $query->whereIn('log_name', (array) $categories);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBetween(Builder $query, CarbonInterface|string|null $from, CarbonInterface|string|null $to): Builder
    {
        if ($from !== null && $from !== '') {
            $query->where('created_at', '>=', Carbon::parse($from));
        }

        if ($to !== null && $to !== '') {
            $query->where('created_at', '<=', Carbon::parse($to));
        }

        return $query;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSubjectAlias(Builder $query, string $alias, int|string|null $id = null): Builder
    {
        $class = self::classFor($alias);

        if ($class === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('subject_type', $class);

        if ($id !== null && $id !== '') {
            $query->where('subject_id', $id);
        }

        return $query;
    }

    /**
     * Activities the user caused or that were performed on them.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInvolving(Builder $query, Model $model): Builder
    {
        return $query->where(function (Builder $query) use ($model): void {
            $query->where(function (Builder $query) use ($model): void {
                $query->where('causer_type', $model->getMorphClass())
                    ->where('causer_id', $model->getKey());
            })->orWhere(function (Builder $query) use ($model): void {
                $query->where('subject_type', $model->getMorphClass())
                    ->where('subject_id', $model->getKey());
            });
        });
    }
}

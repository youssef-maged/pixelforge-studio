<?php

namespace App\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Nano;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use stdClass;

/**
 * Operator numbers per UTC day and per user: what users were charged, what Pixelforge charged us, and the profit.
 *
 * A generation belongs to the UTC day it *completed* (Pixelforge's own completed_at), which is how
 * Pixelforge bills it, so each day can be compared one-to-one with GET /v1/usage.
 */
final class AdminReport
{
    public const MAX_DAYS = 92; // the longest range GET /v1/usage accepts

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?string $search = null,
    ) {}

    /**
     * Per-day totals for the whole range, oldest first, including days with no activity.
     *
     * @return array<string, array<string, mixed>>
     */
    public function days(): array
    {
        $rows = $this->finished()
            ->selectRaw('date(generations.completed_at) as day, '.self::TOTALS)
            ->groupByRaw('date(generations.completed_at)')
            ->toBase()
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $days = [];
        foreach (CarbonPeriod::create($this->from, $this->to) as $date) {
            $days[$date->toDateString()] = ['date' => $date->toDateString(), ...self::figures($rows->get($date->toDateString()))];
        }

        return $days;
    }

    /** Per-user totals over the range (or one day), as plain rows for paginating; map them with userRow(). */
    public function users(string $sort = 'charged', string $direction = 'desc', ?string $day = null): QueryBuilder
    {
        return $this->finished()
            ->when($day, fn (Builder $query) => $query->whereRaw('date(generations.completed_at) = ?', [$day]))
            ->selectRaw('users.id as user_id, users.name, users.email, '.self::TOTALS)
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByRaw(match ($sort) {
                'cost' => 'cost',
                'profit' => 'coalesce(sum(generations.charge_nano), 0) - coalesce(sum(generations.provider_cost_nano), 0)',
                'generations' => 'succeeded',
                default => 'charged',
            }.' '.($direction === 'asc' ? 'asc' : 'desc'))
            ->orderBy('users.id')
            ->toBase();
    }

    /** @return array<string, mixed> */
    public static function userRow(stdClass $row): array
    {
        return ['user_id' => $row->user_id, 'name' => $row->name, 'email' => $row->email, ...self::figures($row)];
    }

    /** @return array<string, mixed> */
    public static function figures(?stdClass $row): array
    {
        $charged = (int) ($row->charged ?? 0);
        $cost = (int) ($row->cost ?? 0);

        return [
            'succeeded' => (int) ($row->succeeded ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'charged_nano' => $charged,
            'cost_nano' => $cost,
            'charged' => Nano::toDecimal($charged),
            'cost' => Nano::toDecimal($cost),
            'profit' => Nano::toDecimal($charged - $cost),
        ];
    }

    /** Succeeded (billed), failed after Pixelforge accepted it, and rejected before it did (never reached Pixelforge's usage). */
    private const TOTALS = "count(*) filter (where generations.status = 'succeeded') as succeeded,
        count(*) filter (where generations.status = 'failed' and generations.provider_id is not null) as failed,
        count(*) filter (where generations.status = 'failed' and generations.provider_id is null) as rejected,
        coalesce(sum(generations.charge_nano), 0) as charged,
        coalesce(sum(generations.provider_cost_nano), 0) as cost";

    /** @return Builder<Generation> */
    private function finished(): Builder
    {
        return Generation::query()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->whereIn('generations.status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
            ->whereBetween('generations.completed_at', [$this->from->startOfDay(), $this->to->endOfDay()])
            ->when($this->search, fn (Builder $query, string $search) => $query->where(
                fn (Builder $query) => $query->whereLike('users.name', "%{$search}%")->orWhereLike('users.email', "%{$search}%"),
            ));
    }
}

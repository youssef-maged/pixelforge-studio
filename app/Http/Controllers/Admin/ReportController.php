<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminReportRequest;
use App\Pixelforge\PixelforgeClient;
use App\Reports\AdminReport;
use App\Support\Nano;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ReportController extends Controller
{
    public function __construct(private PixelforgeClient $pixelforge) {}

    public function index(AdminReportRequest $request): Response
    {
        $report = $request->report();
        $pixelforge = $this->pixelforgeUsage($report, $request->boolean('refresh'));
        $days = $this->reconcile($report->days(), $pixelforge['days'] ?? null, $report->search !== null);

        // Quiet days (nothing on our side or Pixelforge's) stay in the charts but not in the table.
        $active = $days->reverse()->values()->reject(fn (array $day) => $day['succeeded'] + $day['failed'] + $day['rejected'] === 0
            && ($day['pixelforge_generations'] ?? 0) === 0)->values();
        $listed = $active->when($request->boolean('mismatches'), fn (Collection $rows) => $rows->where('match', false)->values());
        $page = LengthAwarePaginator::resolveCurrentPage();

        $cumulative = 0;

        return Inertia::render('Admin/Report', [
            'filters' => [
                'from' => $report->from->toDateString(),
                'to' => $report->to->toDateString(),
                'q' => $report->search,
                'mismatches' => $request->boolean('mismatches'),
                'tab' => $request->input('tab', 'days'),
                'sort' => $request->input('sort', 'charged'),
                'dir' => $request->input('dir', 'desc'),
            ],
            'summary' => [
                ...AdminReport::figures((object) [
                    'succeeded' => $days->sum('succeeded'),
                    'failed' => $days->sum('failed'),
                    'rejected' => $days->sum('rejected'),
                    'charged' => $days->sum('charged_nano'),
                    'cost' => $days->sum('cost_nano'),
                ]),
                'verified' => $pixelforge !== null,
                'filtered' => $report->search !== null,
                'checked_at' => $pixelforge['checked_at'] ?? null,
                'mismatched_days' => $days->where('match', false)->count(),
                'quiet_days' => $days->count() - $active->count(),
                'pixelforge_cost' => isset($pixelforge) ? Nano::toDecimal($days->sum('pixelforge_cost_nano')) : null,
            ],
            'days' => new LengthAwarePaginator(
                $listed->forPage($page, 31)->values(), $listed->count(), 31, $page,
                ['path' => $request->url(), 'query' => $request->query()],
            ),
            'users' => fn () => $report->users($request->input('sort', 'charged'), $request->input('dir', 'desc'))
                ->paginate(25, pageName: 'users_page')->withQueryString()
                ->through(fn (stdClass $row) => AdminReport::userRow($row)),
            'dayUsers' => Inertia::optional(fn () => $request->filled('day')
                ? $report->users(day: $request->input('day'))->paginate(25, pageName: 'day_page')->withQueryString()
                    ->through(fn (stdClass $row) => AdminReport::userRow($row))
                : null),
            // Chart geometry only: floats never feed back into money.
            'chart' => $days->values()->map(function (array $day) use (&$cumulative) {
                $cumulative += $day['charged_nano'] - $day['cost_nano'];

                return [
                    'date' => $day['date'],
                    'cost' => $day['cost_nano'] / 1e9,
                    'profit' => ($day['charged_nano'] - $day['cost_nano']) / 1e9,
                    'cumulative_profit' => $cumulative / 1e9,
                    'succeeded' => $day['succeeded'],
                    'failed' => $day['failed'],
                    'rejected' => $day['rejected'],
                    'mismatch' => $day['match'] === false,
                ];
            }),
            'topUsers' => fn () => $report->users()->limit(8)->get()
                ->map(fn (stdClass $row) => AdminReport::userRow($row)),
        ]);
    }

    /** The current view as CSV, with exact 9-decimal amounts. */
    public function export(AdminReportRequest $request): StreamedResponse
    {
        $report = $request->report();
        $byUser = $request->input('tab') === 'users';
        $rows = $byUser
            ? $report->users($request->input('sort', 'charged'), $request->input('dir', 'desc'))->get()->map(fn (stdClass $row) => [$row->name, $row->email, ...array_values(self::csvFigures(AdminReport::figures($row)))])
            : $this->reconcile($report->days(), $this->pixelforgeUsage($report)['days'] ?? null, $report->search !== null)
                ->reverse()->map(fn ($day) => [$day['date'], ...array_values(self::csvFigures($day)), $day['pixelforge_cost'] ?? '', match ($day['match']) {
                    true => 'yes', false => 'no', default => ''
                }]);

        $header = $byUser
            ? ['user', 'email', 'succeeded', 'failed', 'rejected', 'charged_usd', 'pixelforge_cost_usd', 'profit_usd']
            : ['date_utc', 'succeeded', 'failed', 'rejected', 'charged_usd', 'pixelforge_cost_usd', 'profit_usd', 'pixelforge_reported_usd', 'matches_pixelforge'];

        $name = sprintf('pixelforge-report-%s-%s-%s.csv', $byUser ? 'users' : 'days', $report->from->toDateString(), $report->to->toDateString());

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w') ?: throw new RuntimeException('Could not open the CSV output stream.');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /**
     * Pixelforge's own record for this API key (GET /v1/usage), cached for 5 minutes.
     * Null when Pixelforge can't be reached, so the page says "couldn't verify" instead of failing.
     *
     * @return array{days: array<string, array<string, mixed>>, checked_at: string}|null
     */
    private function pixelforgeUsage(AdminReport $report, bool $refresh = false): ?array
    {
        $key = "pixelforge.usage.{$report->from->toDateString()}.{$report->to->toDateString()}";
        if ($refresh) {
            Cache::forget($key);
        }

        try {
            return Cache::remember($key, 300, fn () => [
                'days' => array_column((array) $this->pixelforge->usage($report->from->toDateString(), $report->to->toDateString())->throw()->json('data'), null, 'date'),
                'checked_at' => now()->toIso8601ZuluString(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Put Pixelforge's figure next to ours for each day. A match only means something for all users together,
     * so a user filter leaves it blank.
     *
     * @param  array<string, array<string, mixed>>  $days
     * @param  array<string, array<string, mixed>>|null  $usage
     * @return Collection<string, non-empty-array<string, mixed>>
     */
    private function reconcile(array $days, ?array $usage, bool $filtered): Collection
    {
        $reconciled = [];
        foreach ($days as $date => $day) {
            $theirs = $usage[$day['date']] ?? null;
            $theirCost = $theirs ? Nano::fromDecimal($theirs['cost_usd']) : null;

            $reconciled[$date] = [
                ...$day,
                'pixelforge_cost_nano' => $theirCost,
                'pixelforge_cost' => $theirCost === null ? null : Nano::toDecimal($theirCost),
                'pixelforge_generations' => $theirs['generations'] ?? null,
                'match' => $theirs === null || $filtered ? null : ($theirCost === $day['cost_nano'] && $theirs['generations'] === $day['succeeded']),
                'delta' => $theirCost === null || $filtered ? null : Nano::toDecimal($day['cost_nano'] - $theirCost),
            ];
        }

        return collect($reconciled);
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    private static function csvFigures(array $figures): array
    {
        return [
            'succeeded' => $figures['succeeded'], 'failed' => $figures['failed'], 'rejected' => $figures['rejected'],
            'charged' => $figures['charged'], 'cost' => $figures['cost'], 'profit' => $figures['profit'],
        ];
    }
}

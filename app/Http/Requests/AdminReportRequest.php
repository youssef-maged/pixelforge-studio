<?php

namespace App\Http\Requests;

use App\Reports\AdminReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminReportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
            'mismatches' => ['nullable', 'boolean'],
            'tab' => ['nullable', 'in:days,users'],
            'sort' => ['nullable', 'in:charged,cost,profit,generations'],
            'dir' => ['nullable', 'in:asc,desc'],
            'day' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->any() && $this->rangeStart()->diffInDays($this->rangeEnd()) >= AdminReport::MAX_DAYS) {
                $validator->errors()->add('from', 'Pick a range of at most '.AdminReport::MAX_DAYS.' days, the most Pixelforge reports at once.');
            }
        }];
    }

    public function report(): AdminReport
    {
        return new AdminReport($this->rangeStart(), $this->rangeEnd(), $this->string('q')->trim()->value() ?: null);
    }

    /** UTC dates; the default is the last 30 days, ending today. */
    public function rangeStart(): CarbonImmutable
    {
        return $this->filled('from') ? CarbonImmutable::parse($this->input('from'), 'UTC') : $this->rangeEnd()->subDays(29);
    }

    public function rangeEnd(): CarbonImmutable
    {
        return $this->filled('to') ? CarbonImmutable::parse($this->input('to'), 'UTC') : CarbonImmutable::now('UTC')->startOfDay();
    }
}

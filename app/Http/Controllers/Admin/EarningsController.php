<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Employee;
use App\Models\Topic;
use App\Support\Earnings;
use App\Support\EarningsReport;
use App\Support\Month;
use App\Support\RateMatrix;
use Illuminate\Http\Request;

/** Admin view of what every employee earned, broken down by channel. */
class EarningsController extends Controller
{
    public function index(Request $request)
    {
        // "all" is this page's own extra value for all-time figures; anything
        // else has to be a real month, so a typo cannot silently show the wrong
        // numbers. A cleared picker submits "", which falls back to this month.
        $request->validate([
            'month' => ['nullable', 'regex:/^(all|\d{4}-(0[1-9]|1[0-2]))$/'],
        ], ['month.regex' => 'Pick a real month, or "all".']);

        $allTime = $request->query('month') === 'all';
        $month = $allTime ? null : Month::resolve($request->input('month'));

        $channels = Channel::orderBy('sort_order')->orderBy('id')->get();

        return view('admin.earnings', [
            'report' => EarningsReport::build($channels, $month),
            'channels' => $channels,
            'rates' => RateMatrix::forChannels($channels),
            'allTime' => $allTime,
        ]);
    }

    /** One employee's own earnings, by day/week/month/year — every channel, unrestricted. */
    public function show(Employee $employee)
    {
        $ledger = Topic::where('completed_by', $employee->id)->where('is_done', true)
            ->whereNotNull('completed_at')->get(['id', 'completed_at', 'earned_amount']);

        return view('admin.employee-earnings', [
            'employee' => $employee,
            'summary' => Earnings::summary($ledger),
            'history' => Earnings::history($ledger),
        ]);
    }
}

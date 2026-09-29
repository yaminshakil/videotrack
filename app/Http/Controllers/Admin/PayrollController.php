<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bonus;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Topic;
use App\Support\Money;
use App\Support\Month;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Monthly payroll: each month runs from the 1st to the last day (30/31/28/29).
 * Earned = amounts locked in when topics were completed, plus any bonus the admin
 * added; paid = payments recorded here. Owed = earned + bonus - paid.
 */
class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $month = Month::resolve($request->input('month'));
        [$start, $end] = Month::range($month);

        $employees = Employee::orderBy('name')->orderBy('id')->get();

        $completed = Topic::with('channel')
            ->where('is_done', true)->whereNotNull('completed_by')->whereNotNull('completed_at')
            ->orderByDesc('completed_at')->get()
            ->groupBy('completed_by');

        $payments = Payment::orderByDesc('paid_on')->orderByDesc('id')->get()->groupBy('employee_id');

        $bonuses = Bonus::orderByDesc('id')->get()->groupBy('employee_id');

        $rows = $employees->map(function (Employee $e) use ($completed, $payments, $bonuses, $month, $start, $end) {
            $ledger = $completed->get($e->id, collect());
            $pays   = $payments->get($e->id, collect());
            $bonusRows = $bonuses->get($e->id, collect());

            $topics = $ledger->filter(fn ($t) => $t->completed_at->between($start, $end))->values();
            $earned = round((float) $topics->sum('earned_amount'), 2);
            $monthPayments = $pays->where('period', $month)->values();
            $paid   = round((float) $monthPayments->sum('amount'), 2);
            $monthBonuses = $bonusRows->where('period', $month)->values();
            $bonus  = round((float) $monthBonuses->sum('amount'), 2);

            // Unpaid balance carried over from earlier months.
            $arrears = round(
                (float) $ledger->filter(fn ($t) => $t->completed_at < $start)->sum('earned_amount')
                + (float) $bonusRows->where('period', '<', $month)->sum('amount')
                - (float) $pays->filter(fn ($p) => $p->period < $month)->sum('amount'),
                2
            );

            $due = round($earned + $bonus - $paid, 2);

            return [
                'employee' => $e,
                'topics'   => $topics,
                'earned'   => $earned,
                'bonus'    => $bonus,
                'bonuses'  => $monthBonuses,
                'paid'     => $paid,
                'due'      => $due,
                'arrears'  => $arrears,
                'payments' => $monthPayments,
                'status'   => match (true) {
                    $earned == 0 && $bonus == 0 && $paid == 0 => 'none',
                    $due < 0                   => 'over',
                    $due == 0                  => 'paid',
                    $paid > 0                  => 'partial',
                    default                    => 'unpaid',
                },
            ];
        });

        return view('admin.payroll', [
            'month'       => $month,
            'start'       => $start,
            'end'         => $end,
            'prevMonth'   => $start->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth'   => $start->copy()->addMonthNoOverflow()->format('Y-m'),
            'isCurrent'   => $month >= now()->format('Y-m'),
            'rows'        => $rows,
            'totalEarned' => (float) $rows->sum('earned'),
            'totalBonus'  => (float) $rows->sum('bonus'),
            'totalPaid'   => (float) $rows->sum('paid'),
            'totalDue'    => (float) $rows->sum('due'),
            'totalArrears' => (float) $rows->sum(fn ($r) => max($r['arrears'], 0)),
            'history'     => $this->history($completed, $payments, $bonuses),
        ]);
    }

    /** Record a payment for one employee for one month. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'period'      => ['required', 'date_format:Y-m'],
            'amount'      => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'paid_on'     => ['required', 'date', 'before_or_equal:today'],
            'note'        => ['nullable', 'string', 'max:255'],
        ], [
            'paid_on.before_or_equal' => 'The payment date cannot be in the future.',
            'amount.gt'               => 'Enter an amount greater than zero.',
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $due = $this->dueFor($employee->id, $data['period']);
        $amount = round((float) $data['amount'], 2);

        if ($amount > $due + 0.001) {
            return redirect()->route('admin.payroll', ['month' => $data['period']])
                ->withInput()
                ->withErrors(['amount' => $due > 0
                    ? "{$employee->name} is only owed " . Money::tk($due) . ' for that month — you entered ' . Money::tk($amount) . '.'
                    : "{$employee->name} has nothing left to be paid for that month."]);
        }

        Payment::create([
            'employee_id' => $employee->id,
            'period'      => $data['period'],
            'amount'      => $amount,
            'paid_on'     => $data['paid_on'],
            'note'        => trim((string) ($data['note'] ?? '')) ?: null,
        ]);

        return redirect()->route('admin.payroll', ['month' => $data['period']])
            ->with('ok', 'Recorded ' . Money::tk($amount) . " paid to {$employee->name}.");
    }

    /** Undo a payment recorded by mistake. */
    public function destroy(Payment $payment)
    {
        $payment->delete();

        return redirect()->route('admin.payroll', ['month' => $payment->period])
            ->with('ok', 'Payment removed.');
    }

    /**
     * Give one employee a bonus. It always lands in the current month — a bonus is a
     * reward for now, not a correction to a month already settled — and it raises what
     * they are owed rather than recording money handed over, so it still gets paid
     * through the normal payment form.
     */
    public function storeBonus(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'amount'      => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'note'        => ['required', 'string', 'max:255'],
        ], [
            'amount.gt'     => 'Enter an amount greater than zero.',
            'note.required' => 'Say why the bonus was given.',
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $period   = now()->format('Y-m');
        $amount   = round((float) $data['amount'], 2);

        Bonus::create([
            'employee_id' => $employee->id,
            'period'      => $period,
            'amount'      => $amount,
            'note'        => trim($data['note']),
        ]);

        return redirect()->route('admin.payroll', ['month' => $period])
            ->with('ok', 'Bonus of ' . Money::tk($amount) . " for {$employee->name} — now owed on top of their earnings.");
    }

    /** Undo a bonus given by mistake. */
    public function destroyBonus(Bonus $bonus)
    {
        $bonus->delete();

        return redirect()->route('admin.payroll', ['month' => $bonus->period])
            ->with('ok', 'Bonus removed.');
    }

    /** What is still owed to an employee for a month (earned + bonus minus paid for it). */
    private function dueFor(int $employeeId, string $period): float
    {
        [$start, $end] = Month::range($period);

        $earned = (float) Topic::where('completed_by', $employeeId)->where('is_done', true)
            ->whereBetween('completed_at', [$start, $end])->sum('earned_amount');
        $bonus = (float) Bonus::where('employee_id', $employeeId)->where('period', $period)->sum('amount');
        $paid = (float) Payment::where('employee_id', $employeeId)->where('period', $period)->sum('amount');

        return round($earned + $bonus - $paid, 2);
    }

    /** Last 12 months, newest first: earned / bonus / paid / still due across all employees. */
    private function history($completed, $payments, $bonuses): array
    {
        $earnedBy = $completed->flatten(1)
            ->groupBy(fn ($t) => $t->completed_at->format('Y-m'))
            ->map(fn ($items) => (float) $items->sum('earned_amount'));
        $bonusBy = $bonuses->flatten(1)
            ->groupBy('period')
            ->map(fn ($items) => (float) $items->sum('amount'));
        $paidBy = $payments->flatten(1)
            ->groupBy('period')
            ->map(fn ($items) => (float) $items->sum('amount'));

        return $earnedBy->keys()->merge($bonusBy->keys())->merge($paidBy->keys())->push(now()->format('Y-m'))
            ->unique()->sortDesc()->take(12)->values()
            ->map(function (string $m) use ($earnedBy, $bonusBy, $paidBy) {
                $earned = round($earnedBy->get($m, 0.0), 2);
                $bonus  = round($bonusBy->get($m, 0.0), 2);
                $paid   = round($paidBy->get($m, 0.0), 2);

                return [
                    'month'  => $m,
                    'label'  => Carbon::createFromFormat('!Y-m', $m)->format('F Y'),
                    'earned' => $earned,
                    'bonus'  => $bonus,
                    'paid'   => $paid,
                    'due'    => round($earned + $bonus - $paid, 2),
                ];
            })->all();
    }
}

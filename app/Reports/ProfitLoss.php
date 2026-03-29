<?php

namespace App\Reports;

use App\Abstracts\Report;
use App\Models\Banking\Transaction;
use App\Models\Document\Document;
use App\Utilities\Date;
use App\Utilities\Recurring;

class ProfitLoss extends Report
{
    public $default_name = 'reports.profit_loss';

    public $category = 'general.accounting';

    public $icon = 'favorite_border';

    public $type = 'detail';

    public $chart = false;

    public $net_profit = [];

    public function setViews()
    {
        parent::setViews();
        $this->views['detail.content.header'] = 'reports.profit_loss.content.header';
        $this->views['detail.content.footer'] = 'reports.profit_loss.content.footer';
        $this->views['detail.table.header'] = 'reports.profit_loss.table.header';
        $this->views['detail.table.row'] = 'reports.profit_loss.table.row';
        $this->views['detail.table.footer'] = 'reports.profit_loss.table.footer';
    }

    public function setTables()
    {
        $this->tables = [
            'income' => trans_choice('general.incomes', 1),
            'expense' => trans_choice('general.expenses', 2),
        ];
    }

    public function setData()
    {
        $income_transactions = $this->applyFilters(
            model: Transaction::with('recurring')->income()->isNotTransfer(),
            args: ['date_field' => 'paid_at', 'model_type' => 'income'],
        );
        $expense_transactions = $this->applyFilters(
            model: Transaction::with('recurring')->expense()->isNotTransfer(),
            args: ['date_field' => 'paid_at', 'model_type' => 'expense'],
        );

        switch ($this->getBasis()) {
            case 'cash':
                // Incomes
                $incomes = $income_transactions->get();
                $this->setTotals($incomes, 'paid_at', false, 'income', false);

                // Expenses
                $expenses = $expense_transactions->get();
                $this->setTotals($expenses, 'paid_at', false, 'expense', false);

                break;
            default:
                // Invoices
                $invoices = $this->applyFilters(
                    model: Document::invoice()->with('recurring', 'totals', 'transactions', 'items')->accrued(),
                    args: ['date_field' => 'issued_at', 'model_type' => 'invoice'],
                )->get();
                Recurring::reflect($invoices, 'issued_at');
                $this->setTotals($invoices, 'issued_at', false, 'income', false);

                // Incomes
                $incomes = $income_transactions->isNotDocument()->get();
                Recurring::reflect($incomes, 'paid_at');
                $this->setTotals($incomes, 'paid_at', false, 'income', false);

                // Bills
                $bills = $this->applyFilters(
                    model: Document::bill()->with('recurring', 'totals', 'transactions', 'items')->accrued(),
                    args: ['date_field' => 'issued_at', 'model_type' => 'bill'],
                )->get();
                Recurring::reflect($bills, 'issued_at');
                $this->setTotals($bills, 'issued_at', false, 'expense', false);

                // Expenses
                $expenses = $expense_transactions->isNotDocument()->get();
                Recurring::reflect($expenses, 'paid_at');
                $this->setTotals($expenses, 'paid_at', false, 'expense', false);

                break;
        }

        $this->setNetProfit();
    }

    public function setNetProfit(): void
    {
        foreach ($this->footer_totals as $table => $dates) {
            foreach ($dates as $date => $total) {
                if (!isset($this->net_profit[$date])) {
                    $this->net_profit[$date] = 0;
                }

                if ($table == 'income') {
                    $this->net_profit[$date] += $total;

                    continue;
                }

                $this->net_profit[$date] -= $total;
            }
        }
    }

    public function array(): array
    {
        $data = parent::array();

        $net_profit = $this->net_profit;

        if ($this->has_money) {
            $net_profit = array_map(fn($value) => money($value)->format(), $net_profit);
        }

        $data['net_profit'] = $net_profit;

        return $data;
    }

    public function getFields(): array
    {
        return [
            $this->getBasisField(),
            $this->getPercentageField(),
        ];
    }

    public function getPercentageField(): array
    {
        return [
            'type'     => 'select',
            'name'     => 'show_percentage',
            'title'    => trans('reports.percentage_of_income'),
            'icon'     => 'percent',
            'values' => [
                'yes' => trans('general.yes'),
                'no' => trans('general.no'),
            ],
            'selected' => 'no',
            'attributes' => [
                'required' => 'required',
            ],
        ];
    }

    public function showPercentage(): bool
    {
        return $this->getSearchStringValue('show_percentage', $this->getSetting('show_percentage')) === 'yes';
    }

    public function getPercentageOfIncome(string $date, float|int $cell_value): ?string
    {
        if (! $this->showPercentage()) {
            return null;
        }

        $income_total = $this->footer_totals['income'][$date] ?? 0;

        if ($income_total == 0) {
            return null;
        }

        $pct = round($cell_value / $income_total * 100, 1);

        return setting('localisation.percent_position') == 'after'
            ? $pct . '%'
            : '%' . $pct;
    }

    public function getDrillDownUrl(string $date, int|string $id): string
    {
        [$date_start, $date_end] = $this->getDateRangeForDrillDown($date);

        $group = $this->getGroup();

        // category_id:519 paid_at>=2026-03-01 paid_at<=2026-03-29
        $search = implode(
            separator: ' ',
            array: [
                "{$group}_id:{$id}",
                "paid_at>={$date_start}",
                "paid_at<={$date_end}",
            ],
        );

        return route('transactions.index') . '?list_records=all&search=' . $search;
    }

    private function getDateRangeForDrillDown(string $date): array
    {
        switch ($this->getPeriod()) {
            case 'yearly':
                $range = [
                    trim($date) . '-01-01',
                    trim($date) . '-12-31',
                ];

                break;
            case 'quarterly':
                [$d_start, $d_end] = array_map('trim', explode(' - ', $date, 2));

                $range = [
                    Date::createFromFormat('M Y', $d_start)->startOfMonth()->format('Y-m-d'),
                    Date::createFromFormat('M Y', $d_end)->endOfMonth()->format('Y-m-d'),
                ];

                break;
            case 'weekly':
                [$d_start, $d_end] = array_map('trim', explode(' - ', $date, 2));

                $range = [
                    Date::createFromFormat('d M Y', $d_start)->startOfDay()->format('Y-m-d'),
                    Date::createFromFormat('d M Y', $d_end)->endOfDay()->format('Y-m-d'),
                ];

                break;
            default: // monthly
                $range = [
                    Date::createFromFormat('M Y', $date)->startOfMonth()->format('Y-m-d'),
                    Date::createFromFormat('M Y', $date)->endOfMonth()->format('Y-m-d'),
                ];

                break;
        }

        // Clamp to report's start_date/end_date if present
        $report_start = request('start_date');
        $report_end = request('end_date');

        if ($report_start && $report_end) {
            $range[0] = max($range[0], $report_start);
            $range[1] = min($range[1], $report_end);
        }

        return $range;
    }
}

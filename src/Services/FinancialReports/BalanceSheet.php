<?php

declare(strict_types=1);

namespace App\Accounting\Services\FinancialReports;

use App\Accounting\Enums\AccountCategory;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use Carbon\Carbon;

class BalanceSheet
{
    /**
     * Generate a balance sheet as of a specific date.
     *
     * Reports the balances of Asset, Liability, and Equity accounts at a point in time.
     * The sheet balances when total_assets === total_liabilities + total_equity, which
     * holds after closing entries have been posted for the period.
     *
     * @return array{as_of: string, currency: string, assets: array, liabilities: array, equity: array, total_assets: int, total_liabilities: int, total_equity: int, is_balanced: bool}
     */
    public static function generate(?Carbon $asOf = null, string $currency = 'USD'): array
    {
        $asOf = $asOf ?? Carbon::now();

        $sections = [
            'assets'      => AccountCategory::ASSET,
            'liabilities' => AccountCategory::LIABILITY,
            'equity'      => AccountCategory::EQUITY,
        ];

        $result = [
            'as_of'    => $asOf->toDateString(),
            'currency' => $currency,
        ];

        $totals = [];

        foreach ($sections as $key => $category) {
            $typeIds = AccountType::where('type', $category->value)->pluck('id');

            $accounts = Account::whereIn('account_type_id', $typeIds)
                ->where('currency', $currency)
                ->where('is_active', true)
                ->with('accountType')
                ->get();

            $rows = [];
            $sectionTotal = 0;

            foreach ($accounts as $account) {
                $balance = $account->getBalanceOn($asOf);
                $balanceAmount = (int) $balance->getAmount();

                if ($balanceAmount === 0) {
                    continue;
                }

                $sectionTotal += $balanceAmount;
                $rows[] = [
                    'account_id'     => $account->id,
                    'account_number' => $account->number,
                    'account_name'   => $account->name,
                    'account_type'   => $account->accountType?->name,
                    'balance'        => $balanceAmount,
                ];
            }

            $result[$key]  = $rows;
            $totals[$key]  = $sectionTotal;
        }

        $result['total_assets']      = $totals['assets'];
        $result['total_liabilities'] = $totals['liabilities'];
        $result['total_equity']      = $totals['equity'];
        $result['is_balanced']       = $totals['assets'] === ($totals['liabilities'] + $totals['equity']);

        return $result;
    }
}

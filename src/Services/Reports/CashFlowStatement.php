<?php

declare(strict_types=1);

namespace App\Accounting\Services\Reports;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use App\Accounting\Models\LedgerEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CashFlowStatement
{
    /**
     * Generate a cash flow statement using the direct method.
     *
     * Categorizes cash flows by the type of the contra-account in each transaction:
     * - Operating: income/expense accounts (day-to-day business)
     * - Investing: asset accounts (equipment purchases, etc.)
     * - Financing: liability/equity accounts (loans, owner investment)
     *
     * Contra-account type is resolved via a selective JOIN rather than eager-loading
     * the full journalEntry.ledgerEntries.account graph.
     *
     * @param  Account|null  $cashAccount  Specific cash/bank account. If null, uses all bank-type assets.
     * @return array{operating: array, investing: array, financing: array, net_cash_flow: int, beginning_balance: int, ending_balance: int, period_start: string, period_end: string, currency: string}
     */
    public static function generate(Carbon $from, Carbon $to, ?Account $cashAccount = null, string $currency = 'USD'): array
    {
        // Determine which accounts are "cash" accounts
        $cashAccountIds = self::getCashAccountIds($cashAccount, $currency);

        if (empty($cashAccountIds)) {
            return self::emptyReport($from, $to, $currency);
        }

        $startOfDay = $from->copy()->startOfDay();
        $endOfDay = $to->copy()->endOfDay();

        // Fetch cash entries with a single representative contra-account type resolved via a
        // correlated subquery. Using a JOIN instead produces N rows per cash entry when the
        // journal has N contra lines (e.g. cash DR / rev1 CR / rev2 CR → two rows, both
        // carrying the full cash_effect, doubling the total). The correlated subquery picks
        // exactly one contra per cash entry, avoiding the duplication.
        $cashEntries = DB::table('accounting_ledger_entries as cash_entry')
            ->whereIn('cash_entry.account_id', $cashAccountIds)
            ->where('cash_entry.is_posted', true)
            ->whereBetween('cash_entry.post_date', [$startOfDay, $endOfDay])
            ->select(
                'cash_entry.journal_entry_id',
                'cash_entry.memo',
                'cash_entry.post_date',
                DB::raw('cash_entry.debit - cash_entry.credit as cash_effect'),
                DB::raw('(SELECT a.type
                          FROM accounting_ledger_entries le
                          JOIN accounting_accounts a ON a.id = le.account_id
                          WHERE le.journal_entry_id = cash_entry.journal_entry_id
                            AND le.id != cash_entry.id
                          ORDER BY le.id
                          LIMIT 1) as contra_type'),
            )
            ->get();

        $operating = [];
        $investing = [];
        $financing = [];

        foreach ($cashEntries as $row) {
            $cashEffect = (int) $row->cash_effect;

            if ($cashEffect === 0) {
                continue;
            }

            $detail = [
                'journal_entry_id' => $row->journal_entry_id,
                'memo' => $row->memo,
                'amount' => $cashEffect,
                'date' => $row->post_date ? Carbon::parse($row->post_date)->toDateString() : null,
            ];

            $contraType = $row->contra_type;

            match ($contraType) {
                AccountType::REVENUE->value, AccountType::EXPENSE->value,
                AccountType::OTHER_INCOME->value, AccountType::OTHER_EXPENSE->value => $operating[] = $detail,
                AccountType::ASSET->value => $investing[] = $detail,
                AccountType::LIABILITY->value, AccountType::EQUITY->value => $financing[] = $detail,
                // Unknown contra type: default to operating (safe fallback)
                default => $operating[] = $detail,
            };
        }

        $totalOperating = array_sum(array_column($operating, 'amount'));
        $totalInvesting = array_sum(array_column($investing, 'amount'));
        $totalFinancing = array_sum(array_column($financing, 'amount'));

        // Beginning and ending cash balances
        $beginningBalance = self::getCashBalance($cashAccountIds, $from->copy()->subDay());
        $endingBalance = self::getCashBalance($cashAccountIds, $to);

        return [
            'operating' => $operating,
            'investing' => $investing,
            'financing' => $financing,
            'total_operating' => $totalOperating,
            'total_investing' => $totalInvesting,
            'total_financing' => $totalFinancing,
            'net_cash_flow' => $totalOperating + $totalInvesting + $totalFinancing,
            'beginning_balance' => $beginningBalance,
            'ending_balance' => $endingBalance,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'currency' => $currency,
        ];
    }

    /**
     * Get the IDs of cash/bank accounts.
     */
    private static function getCashAccountIds(?Account $cashAccount, string $currency): array
    {
        if ($cashAccount) {
            return [$cashAccount->id];
        }

        return Account::where('type', AccountType::ASSET)
            ->where('sub_type', AccountSubType::BANK)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Calculate total cash balance across given account IDs as of a date.
     */
    private static function getCashBalance(array $accountIds, Carbon $asOf): int
    {
        $row = DB::table('accounting_ledger_entries')
            ->whereIn('account_id', $accountIds)
            ->where('is_posted', true)
            ->where('post_date', '<=', $asOf->copy()->endOfDay())
            ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->first();

        // Cash is an asset (debit-normal)
        return $row
            ? (int) $row->total_debit - (int) $row->total_credit
            : 0;
    }

    private static function emptyReport(Carbon $from, Carbon $to, string $currency): array
    {
        return [
            'operating' => [],
            'investing' => [],
            'financing' => [],
            'total_operating' => 0,
            'total_investing' => 0,
            'total_financing' => 0,
            'net_cash_flow' => 0,
            'beginning_balance' => 0,
            'ending_balance' => 0,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'currency' => $currency,
        ];
    }
}

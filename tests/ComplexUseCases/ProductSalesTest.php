<?php

declare(strict_types=1);

namespace Tests\ComplexUseCases;

use Carbon\Carbon;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Transaction;
use Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * This test demonstrates a more complex real-world scenario of a company selling products.
 * It includes:
 * - Test-only models (Product, Sale, etc.)
 * - Setting up account types for different aspects of the business
 * - Recording product sales with proper accounting entries
 * - Tracking inventory and cost of goods sold
 *
 * Note: All models in this test are defined within the test namespace and are not part of the main codebase.
 */

// Test-only models
class Product extends Model
{
    protected $fillable = ['name', 'sku', 'price', 'cost'];

    public function sales()
    {
        return $this->hasMany(SaleItem::class);
    }
}

class Sale extends Model
{
    protected $fillable = ['customer_name', 'sale_date'];

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}

class SaleItem extends Model
{
    protected $fillable = ['sale_id', 'product_id', 'quantity', 'unit_price'];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

class Payment extends Model
{
    protected $fillable = ['sale_id', 'amount', 'payment_method', 'transaction_date'];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}

class ProductSalesTest extends TestCase
{
    protected AccountType $cashAccountType;
    protected AccountType $arAccountType;
    protected AccountType $inventoryAccountType;
    protected AccountType $cogsAccountType;
    protected AccountType $salesAccountType;
    protected AccountType $taxPayableAccountType;

    protected Account $cashAccount;
    protected Account $arAccount;
    protected Account $inventoryAccount;
    protected Account $cogsAccount;
    protected Account $salesAccount;
    protected Account $taxPayableAccount;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup test database tables for our models
        $this->createTestTables();

        // Initialize accounting account types and accounts
        $this->setupAccounts();
    }

    protected function createTestTables(): void
    {
        // Create tables for our test models
        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('sku')->unique();
                $table->decimal('price', 10, 2);
                $table->decimal('cost', 10, 2);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sales')) {
            Schema::create('sales', function (Blueprint $table) {
                $table->id();
                $table->string('customer_name');
                $table->dateTime('sale_date');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sale_items')) {
            Schema::create('sale_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_id')->constrained()->onDelete('cascade');
                $table->foreignId('product_id')->constrained()->onDelete('restrict');
                $table->integer('quantity');
                $table->decimal('unit_price', 10, 2);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_id')->constrained()->onDelete('cascade');
                $table->decimal('amount', 10, 2);
                $table->string('payment_method'); // e.g., 'cash', 'credit_card', 'bank_transfer'
                $table->dateTime('transaction_date');
                $table->timestamps();
            });
        }
    }

    protected function setupAccounts(): void
    {
        // Create account types for our business
        $this->cashAccountType = AccountType::create(['name' => 'Cash', 'type' => 'asset']);
        $this->arAccountType = AccountType::create(['name' => 'Accounts Receivable', 'type' => 'asset']);
        $this->inventoryAccountType = AccountType::create(['name' => 'Inventory', 'type' => 'asset']);
        $this->cogsAccountType = AccountType::create(['name' => 'Cost of Goods Sold', 'type' => 'expense']);
        $this->salesAccountType = AccountType::create(['name' => 'Sales Revenue', 'type' => 'income']);
        $this->taxPayableAccountType = AccountType::create(['name' => 'Sales Tax Payable', 'type' => 'liability']);

        // Initialize accounts linked to their account types
        $this->cashAccount = $this->cashAccountType->accounts()->create([
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $this->arAccount = $this->arAccountType->accounts()->create([
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 2,
        ]);

        $this->inventoryAccount = $this->inventoryAccountType->accounts()->create([
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 3,
        ]);

        $this->cogsAccount = $this->cogsAccountType->accounts()->create([
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 4,
        ]);

        $this->salesAccount = $this->salesAccountType->accounts()->create([
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 5,
        ]);

        $this->taxPayableAccount = $this->taxPayableAccountType->accounts()->create([
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 6,
        ]);

        // Add initial capital to the business
        $this->recordInitialCapital(10000.00);
    }

    protected function recordInitialCapital(float $amount): void
    {
        $equityAccountType = AccountType::create(['name' => 'Owner\'s Equity', 'type' => 'equity']);
        $equityAccount = $equityAccountType->accounts()->create([
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 7,
        ]);

        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $transaction->addDollarTransaction($this->cashAccount, 'debit', $amount, 'Initial capital investment');
        $transaction->addDollarTransaction($equityAccount, 'credit', $amount, 'Owner\'s capital');
        $transaction->commit();
    }

    public function testProductSaleWithCashPayment()
    {
        // Create a test product
        $product = Product::create([
            'name' => 'Premium Widget',
            'sku' => 'WIDGET-001',
            'price' => 99.99,
            'cost' => 45.00,
        ]);

        // Add initial inventory (10 units)
        $this->addToInventory($product, 10, 45.00);

        // Create a sale
        $sale = Sale::create([
            'customer_name' => 'Test Customer',
            'sale_date' => now(),
        ]);

        // Add items to the sale (2 units of the product)
        $sale->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 99.99,
        ]);

        // Record the sale in accounting
        $this->recordSale($sale, 'cash');

        // Verify inventory was reduced
        $this->assertEquals(8, $this->getInventoryCount($product->id));

        // Verify accounting entries using the new sign convention.
        // Account::getBalance() now respects account type:
        //   - Asset (debit-normal): balance = debits - credits  → positive for net debit
        //   - Income (credit-normal): balance = credits - debits → positive for net credit
        //   - Expense (debit-normal): balance = debits - credits → positive for net debit

        $cashBalance = $this->cashAccount->getCurrentBalanceInDollars();
        $salesBalance = $this->salesAccount->getCurrentBalanceInDollars();
        $cogsBalance = $this->cogsAccount->getCurrentBalanceInDollars();

        // Cash (asset, debit-normal): net debit → positive balance
        $this->assertGreaterThan(0, $cashBalance, 'Cash balance should be positive after a cash sale (debit-normal asset)');

        // Sales (income, credit-normal): net credit → positive balance
        $this->assertGreaterThan(0, $salesBalance, 'Sales balance should be positive (credit-normal income)');

        // COGS (expense, debit-normal): net debit → positive balance
        $this->assertEquals(90.00, $cogsBalance, 'COGS should be 2 units * $45 cost = $90 (debit-normal expense)');
    }

    public function testProductSaleWithCreditPayment()
    {
        // Create a test product
        $product = Product::create([
            'name' => 'Deluxe Widget',
            'sku' => 'WIDGET-002',
            'price' => 199.99,
            'cost' => 85.00,
        ]);

        // Add initial inventory (5 units)
        $this->addToInventory($product, 5, 85.00);

        // Create a sale with credit terms
        $sale = Sale::create([
            'customer_name' => 'Credit Customer',
            'sale_date' => now(),
        ]);

        // Add items to the sale (3 units of the product)
        $sale->items()->create([
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price' => 199.99,
        ]);

        // Record the sale in accounting (on credit)
        $this->recordSale($sale, 'credit');

        // Verify inventory was reduced
        $this->assertEquals(2, $this->getInventoryCount($product->id), 'Inventory count should be 2 after selling 3 out of 5');

        // Verify accounting entries using the new sign convention.
        // Account::getBalance() now respects account type:
        //   - Asset (debit-normal): balance = debits - credits  → positive for net debit
        //   - Income (credit-normal): balance = credits - debits → positive for net credit
        //   - Expense (debit-normal): balance = debits - credits → positive for net debit

        $arBalance = $this->arAccount->getCurrentBalanceInDollars();
        $salesBalance = $this->salesAccount->getCurrentBalanceInDollars();
        $cogsBalance = $this->cogsAccount->getCurrentBalanceInDollars();

        // AR (asset, debit-normal): net debit → positive balance
        $this->assertGreaterThan(0, $arBalance, 'AR balance should be positive after a credit sale (debit-normal asset)');

        // Sales (income, credit-normal): net credit → positive balance
        $this->assertGreaterThan(0, $salesBalance, 'Sales balance should be positive (credit-normal income)');

        // COGS (expense, debit-normal): net debit → positive balance
        $this->assertEquals(255.00, $cogsBalance, 'COGS should be 3 units * $85 cost = $255 (debit-normal expense)');
    }

    protected function addToInventory(Product $product, int $quantity, float $unitCost): void
    {
        $totalCost = $quantity * $unitCost;

        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $memo = "Add {$quantity} units of {$product->name} to inventory";
        $transaction->addDollarTransaction($this->inventoryAccount, 'debit', $totalCost, $memo);
        $transaction->addDollarTransaction($this->cashAccount, 'credit', $totalCost, "Paid for {$quantity} units of {$product->name} inventory");
        $transaction->commit();
    }

    protected function recordSale(Sale $sale, string $paymentMethod = 'cash'): void
    {
        $subtotal = $sale->items->sum(function ($item) {
            return $item->quantity * $item->unit_price;
        });

        $taxRate = 0.08; // 8% sales tax
        $taxAmount = $subtotal * $taxRate;
        $totalAmount = $subtotal + $taxAmount;

        $transaction = Transaction::newDoubleEntryTransactionGroup();

        // Debit Cash or Accounts Receivable
        if ($paymentMethod === 'cash') {
            $transaction->addDollarTransaction(
                $this->cashAccount,
                'debit',
                $totalAmount,
                "Sale #{$sale->id} - {$sale->customer_name}"
            );
        } else {
            $transaction->addDollarTransaction(
                $this->arAccount,
                'debit',
                $totalAmount,
                "Sale #{$sale->id} - {$sale->customer_name} (on credit)"
            );
        }

        // Credit Sales Revenue
        $transaction->addDollarTransaction(
            $this->salesAccount,
            'credit',
            $subtotal,
            "Sale #{$sale->id} - Revenue"
        );

        // Credit Sales Tax Payable
        if ($taxAmount > 0) {
            $transaction->addDollarTransaction(
                $this->taxPayableAccount,
                'credit',
                $taxAmount,
                "Sale #{$sale->id} - Sales Tax"
            );
        }

        $transaction->commit();

        // Record cost of goods sold
        $this->recordCogs($sale);
    }

    protected function recordCogs(Sale $sale): void
    {
        $totalCogs = 0;

        foreach ($sale->items as $item) {
            $cogs = $item->quantity * $item->product->cost;
            $totalCogs += $cogs;
        }

        if ($totalCogs > 0) {
            $transaction = Transaction::newDoubleEntryTransactionGroup();

            // Include product names in the memo for better tracking
            $productNames = $sale->items->map(fn($item) => $item->product->name)->unique()->implode(', ');

            // Debit COGS (expense increases with debit)
            $transaction->addDollarTransaction(
                $this->cogsAccount,
                'debit',
                $totalCogs,
                "COGS for Sale #{$sale->id} - Products: {$productNames}"
            );

            // Credit Inventory (asset decreases with credit)
            $transaction->addDollarTransaction(
                $this->inventoryAccount,
                'credit',
                $totalCogs,
                "Inventory reduction for Sale #{$sale->id} - Products: {$productNames}"
            );

            $transaction->commit();
        }
    }

    protected function getInventoryCount(int $productId): int
    {
        $product = Product::find($productId);

        // Get all inventory additions for this product
        $inventoryAdditions = $this->inventoryAccount->journalEntries()
            ->where('memo', 'like', '%' . $product->name . '%')
            ->where('debit', '>', 0)
            ->sum('debit');

        // Get all inventory deductions (COGS) for this product
        $inventoryDeductions = $this->cogsAccount->journalEntries()
            ->where('memo', 'like', '%' . $product->name . '%')
            ->sum('debit');

        // Convert from cents to dollars and calculate units
        $inventoryDollars = ($inventoryAdditions - $inventoryDeductions) / 100;

        // Calculate current inventory in units
        return (int) ($inventoryDollars / $product->cost);
    }
}

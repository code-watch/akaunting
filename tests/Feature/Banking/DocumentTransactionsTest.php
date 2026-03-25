<?php

namespace Tests\Feature\Banking;

use App\Jobs\Banking\CreateBankingDocumentTransaction;
use App\Jobs\Document\CreateDocument;
use App\Models\Document\Document;
use Tests\Feature\FeatureTestCase;

class DocumentTransactionsTest extends FeatureTestCase
{
    /**
     * Regression test for https://github.com/akaunting/akaunting/issues/3350
     *
     * When a bill is in one currency (e.g. USD) and the payment is submitted
     * in a different currency (e.g. TRY), the amount check must still throw
     * an exception if the converted amount exceeds the remaining bill balance.
     *
     * Before the fix the `else` branch silently marked the bill as "paid"
     * instead of throwing – this test would have failed against the buggy code.
     */
    public function testItShouldPreventOverPaymentInDifferentCurrency(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/over_payment|passes the total/i');

        $bill = $this->createBill('USD', 1);

        // Pay in TRY with the same rate (1:1) so the converted USD value is
        // identical to the TRY amount – clearly over the bill total.
        $this->dispatch(new CreateBankingDocumentTransaction($bill, [
            'amount'          => $bill->amount + 1,
            'currency_code'   => 'TRY',
            'currency_rate'   => 1,
            'type'            => 'expense',
        ]));
    }

    /**
     * A partial payment in a different currency should succeed and leave
     * the bill in "partial" status.
     */
    public function testItShouldAllowPartialPaymentInDifferentCurrency(): void
    {
        $bill = $this->createBill('USD', 1);

        // Pay roughly half the bill amount in TRY (rate 1:1 → same USD value).
        $half = (int) floor($bill->amount / 2);

        // Skip the test when the bill amount rounds down to 0 (shouldn't happen
        // in practice, but guards against factory edge-cases in CI).
        if ($half <= 0) {
            $this->markTestSkipped('Bill amount too small for a meaningful partial-payment test.');
        }

        $transaction = $this->dispatch(new CreateBankingDocumentTransaction($bill, [
            'amount'          => $half,
            'currency_code'   => 'TRY',
            'currency_rate'   => 1,
            'type'            => 'expense',
        ]));

        $bill->refresh();

        $this->assertSame('partial', $bill->status);
        $this->assertNotNull($transaction->id);
    }

    /**
     * A full payment in a different currency should succeed and mark
     * the bill as "paid".
     */
    public function testItShouldAllowFullPaymentInDifferentCurrency(): void
    {
        $bill = $this->createBill('USD', 1);

        $transaction = $this->dispatch(new CreateBankingDocumentTransaction($bill, [
            'amount'          => $bill->amount,
            'currency_code'   => 'TRY',
            'currency_rate'   => 1,
            'type'            => 'expense',
        ]));

        $bill->refresh();

        $this->assertSame('paid', $bill->status);
        $this->assertNotNull($transaction->id);
    }

    /**
     * Same-currency over-payment must also throw (pre-existing behaviour).
     */
    public function testItShouldPreventOverPaymentInSameCurrency(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/over_payment|passes the total/i');

        $bill = $this->createBill('USD', 1);

        $this->dispatch(new CreateBankingDocumentTransaction($bill, [
            'amount'        => $bill->amount + 1,
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'type'          => 'expense',
        ]));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a bill in the given currency with a known status of "received"
     * (i.e. no payments yet) so the full amount is still outstanding.
     */
    private function createBill(string $currency_code, float $currency_rate): Document
    {
        $request = Document::factory()->bill()->items()->raw([
            'currency_code' => $currency_code,
            'currency_rate' => $currency_rate,
            'status'        => 'received',
        ]);

        return $this->dispatch(new CreateDocument($request));
    }
}

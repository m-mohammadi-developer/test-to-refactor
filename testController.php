<?php

namespace Modules\Membership\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Membership\Entities\Membership;
use Modules\Membership\Entities\Transaction;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Invoice;

use Shetabit\Payment\Facade\Payment;

class PaymentController extends Controller
{

    /**
     * @param Request $request
     * @param Membership $membership
     * @return RedirectResponse
     */
    public function purchase(Request $request, Membership $membership)
    {
        $amount = $membership->price;
        $description = 'پرداخت حق عضویت باشگاه آفتاب به مبلغ ' . $amount;

        if ($membership->status != Membership::STATUS['wait_for_pay']) {
            return back()->with('error', 'درخواست شما هنوز به تایید نرسیده است');
        }

        try {
            // create invoice
            $invoice = new Invoice();
            $invoice->amount($amount);

            // save payment details in database as Transaction
            // if transaction has been created before no need to create it again
            $transaction = Transaction::where('membership_id', $membership->id)->first(); // maybe use findOrCreate ??
            if (!$transaction) {
                $transaction = Transaction::create([
                    'user_id' => auth()->id(),
                    'membership_id' => $membership->id,
                    'price' => $invoice->getAmount(),
                ]);
            }
            // for transactions with status success no need to pay
            if ($transaction->status == Transaction::STATUS['success']) {
                return redirect()->route('membership.purchase.verify', $transaction->id);
            }
            $callbackUrl = route('membership.purchase.verify', $transaction->id);

            // create payment and it's details
            $payment = Payment::callbackUrl($callbackUrl);
            $payment->config('description', $description);

            // pay and redirect to bank page
            $payment->purchase($invoice, function ($driver, $transactionId) use ($transaction) {
                $transaction->transaction_id = $transactionId;
                $transaction->save();
            });

            return $payment->pay()->render();

        } catch (\Exception $e) {
            Log::error($e->getMessage());

            return back()->with('error', $e->getMessage());
        }

    }

    /**
     * @param Request $request
     * @param Transaction $transaction
     * @return Application|Factory|View
     */
    public function verify(Request $request, Transaction $transaction)
    {
        $result = [];

        // if transactions has been paid there is no need to verify it again
        if ($transaction->status == $transaction::STATUS['success']) {
            $result['status'] = 'success';
            $result['message'] = 'این تراکنش پرداخت شده است';
            return view('membership::payment.result', ['result' => $result]);
        }

        try {
            DB::beginTransaction();

            $membership = Membership::find($transaction->membership_id);
            $result['membership'] = $membership;

            // throw an error if payment is invalid
            $receipt = Payment::amount($transaction->price)
                ->transactionId($transaction->transaction_id)
                ->verify();

            $transaction->status = $transaction::STATUS['success'];

            $result['status'] = 'success';
            $result['message'] = 'پرداخت شما با موفقیت ثبت شد';

            // save transaction and change membership status
            $membership->status = Membership::STATUS['paid'];
            $transaction->save();
            $membership->save();

            DB::commit();
        } catch (InvalidPaymentException $exception) {
            $transaction->status = $transaction::STATUS['failed'];

            $result['status'] = "failed";
            $result['message'] = $exception->getMessage();

            DB::rollBack();
            // save failed status even the changes are rolled back
            $transaction->save();
        }

        return view('membership::payment.result', ['result' => $result]);
    }

}


<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionService
{
    public function createTransaction($senderId, $receiverId, $amount, $feeRate)
    {
        return DB::transaction(function () use ($senderId, $receiverId, $amount, $feeRate) {

            $this->clearPendingDistributions($receiverId);

            $sender = Account::findOrFail($senderId);
            $receiver = Account::findOrFail($receiverId);

            $transaction = Transactions::create([
                'sender_id' => $senderId,
                'receiver_id' => $receiverId,
                'amount' => $amount,
                'fee_rate' => $feeRate,
                'is_distributed' => false
            ]);

            // Calculate fee
            $fee = $amount * ($feeRate / 100);

            // Update sender's balance and obligations
            $sender->balance -= ($amount + $fee);
            $sender->link_obligation += $amount;
            $sender->save();
            $sender->public_rate = $this->calculateNewPR($sender->refresh());
            $sender->value = $sender->balance + $sender->auxiliary - $sender->link_income + $sender->link_obligation;
            $sender->save();

            // Update receiver's balance, auxiliary, and link income
            $receiver->balance += $amount;
            $receiver->auxiliary += $fee;
            $receiver->link_income += $amount;
            $receiver->value = $receiver->balance + $receiver->auxiliary - $receiver->link_income + $receiver->link_obligation;
            $receiver->save();

            // Check if receiver needs to distribute
            $this->triggerDistributions($receiver->refresh(), $sender->refresh());
            // Ensure all distributions are cleared before creating a new transaction
            return $transaction;
        });
    }

    private function calculateNewPR($user): float|int
    {
        $totalAmount = $user->sentTransactions->sum('amount');
        $sumProd = $user->sentTransactions->sum(function ($transaction) {
            return $transaction->amount * $transaction->fee_rate;
        });

        Log::info($totalAmount . ' Total sum of Lo');
        Log::info('Amount * Fee ' . $sumProd);

        if ($totalAmount == 0) {
            return 0;
        } else {
            Log::info('PR ' . $sumProd / $totalAmount);
            return $sumProd / $totalAmount;
        }
    }

    private function triggerDistributions($user, $sender): void
    {
        $distributionsToTrigger = [$user];

        while (!empty($distributionsToTrigger)) {
            $currentUser = array_pop($distributionsToTrigger);

            if ($currentUser->auxiliary > 0 && $currentUser->balance >= $currentUser->value) {
                Log::info($currentUser->name . ' is distributing');
                $totalPR = DB::table('transactions')
                    ->join('accounts', 'transactions.sender_id', '=', 'accounts.id')
                    ->where('transactions.receiver_id', $currentUser->id)
                    ->select('transactions.sender_id', DB::raw('SUM(accounts.public_rate) as total_public_rate'))
                    ->groupBy('transactions.sender_id')
                    ->get()
                    ->sum('total_public_rate');

                // Taking transactions with fee rate > 0 and distribution status false
               /* $trxCount = Transactions::where('receiver_id', $currentUser->id)
                    ->where('fee_rate', '>', 0)
                    ->where('is_distributed', false)
                    ->whereColumn('sender_id', '!=', 'receiver_id')
                    ->count();*/


                $trxCount = $sender->trxCount + 1;
                Log::info($currentUser->trigger . '+1 ==' . $trxCount);

                if ($totalPR > 0 && ($trxCount == $currentUser->trigger + 1)) {
                    $currentUser->trigger *= 2;
                    $distributionAmount = $currentUser->auxiliary;
                    $currentUser->link_income -= $distributionAmount;
                    $senders = Transactions::where('receiver_id', $currentUser->id)
                        ->select('sender_id')
                        ->distinct()
                        ->get();

                    $senders->each(function ($sender) use ($currentUser, $distributionAmount, $totalPR, &$distributionsToTrigger) {
                        $participant = Account::find($sender->sender_id);
                        if ($participant) {
                            Log::info('Distributing to ' . $participant->name);
                            $remainingLo = $participant->link_obligation;
                            $share = min($distributionAmount * ($participant->public_rate / $totalPR), $remainingLo);
                            Log::info('His/Her share ' . $share);
                            $participant->auxiliary += $share;
                            $participant->link_obligation -= $share;
                            $participant->trxCount += 1;
                            $participant->save();

                            $transaction = Transactions::where('sender_id', $participant->id)
                                ->where('receiver_id', $currentUser->id)
                                ->where('is_distributed', false)
                                ->orderBy('created_at', 'desc')
                                ->first();

                            if ($transaction) {
                                $transaction->amount -= $share;
                                $transaction->is_distributed = $transaction->amount > 0;
                                $transaction->save();
                            }

                            $participant->refresh();
                            if ($participant->auxiliary > 0 && $participant->balance < $participant->value) {
                                $participant->balance += $participant->auxiliary;
                                $participant->auxiliary = 0;
                                $participant->save();
                            }

                            $participant->refresh();
                            $participant->value = $participant->balance + $participant->auxiliary - $participant->link_income + $participant->link_obligation;
                            $participant->save();

                            $currentUser->auxiliary -= $share;
                            $currentUser->value = $currentUser->balance + $currentUser->auxiliary - $currentUser->link_income + $currentUser->link_obligation;
                            $currentUser->save();

                            // Check if the participant needs to distribute
                            if ($participant->trxCount >= $participant->trigger) {
                                Log::info($participant->name . ' needs to distribute');
                                $distributionsToTrigger[] = $participant;
                            }
                        }
                    });
                }
            }
        }
    }

    private function clearPendingDistributions($receiver): void
    {
        $senders = Transactions::where('receiver_id', $receiver->id)
            ->select('sender_id')
            ->distinct()
            ->get();

        foreach ($senders as $sender) {
            $account = Account::where('sender_id', $sender->sender_id)
                ->whereRaw('trxCount >= trigger')
                ->get();

            $this->triggerDistributions($account, $sender->refresh());
        }
    }
}

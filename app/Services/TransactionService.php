<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionService
{
    public function createTransaction($senderId, $receiverId, $amount, $feeRate)
    {
        return DB::transaction(function () use ($senderId, $receiverId, $amount, $feeRate) {
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
            $receiver->trxCount += 1; // Increment receiver's trxCount
            $receiver->value = $receiver->balance + $receiver->auxiliary - $receiver->link_income + $receiver->link_obligation;
            $receiver->save();

            /* // Check if receiver needs to distribute
             $this->triggerDistributions($receiver->refresh(), $sender->refresh());*/
            $this->clearPendingDistributions();

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


    private function clearPendingDistributions(): void
    {
        $accounts = Account::whereColumn('trxCount', 'trigger')
            ->get();

        foreach ($accounts as $account) {
            Log::info('distributor ' . $account->name);
            $this->Distribute($account);
        }
    }

    private function Distribute($account): void
    {
        Log::info('Distributing for account: ' . $account->id);

        $distributionAmount = $account->auxiliary;
        $initialLinkIncome = $account->link_income;

        // Get all participants (senders to the account and the account itself)
        $senders = Transactions::where('receiver_id', $account->id)
            /*->where('link_obligation', '>', 0)*/
            ->select('sender_id')
            ->distinct()
            ->get();

        // Log senders for debugging
        Log::info('Senders found: ' . $senders->count());
        foreach ($senders as $sender) {
            Log::info('Sender ID: ' . $sender->sender_id);
        }

        // List to hold actual participants
        $actualParticipants = collect();

        $senders->each(function ($sender) use ($actualParticipants) {
            $participant = Account::find($sender->sender_id);
            if ($participant && $participant->balance < $participant->value) {
                $actualParticipants->push($participant);
                Log::info('Eligible participant added: ' . $participant->id . ' - ' . $participant->name);
            } else {
                Log::info('Participant not eligible: ' . $participant->id . ' - ' . $participant->name);
            }
        });

        // Also consider the account itself as a potential participant
        if ($account->balance < $account->value) {
            $actualParticipants->push($account);
            Log::info('Account itself added as participant: ' . $account->id . ' - ' . $account->name);
        }

        // Log actual participants for debugging
        Log::info('Total actual participants: ' . $actualParticipants->count());
        foreach ($actualParticipants as $participant) {
            Log::info('Participant ID: ' . $participant->id . ', Public Rate: ' . $participant->public_rate);
        }

        if ($actualParticipants->isEmpty()) {
            Log::warning('No eligible participants found for account: ' . $account->id);
        }

        // Collect IDs of actual participants
        $participantIds = $actualParticipants->pluck('id')->toArray();
        Log::info('Participant IDs: ' . implode(', ', $participantIds));

        // Calculate totalPR using the provided query
        $totalPR = DB::table('accounts')
            ->whereIn('id', $participantIds)
            ->sum('public_rate');

        Log::info('Total PR calculated: ' . $totalPR);

        // Track whether any distributions occurred
        $distributionOccurred = false;

        // Only proceed if totalPR is greater than zero
        if ($totalPR > 0) {
            // Calculate and distribute share for each participant
            foreach ($actualParticipants as $participant) {
                $share = $distributionAmount * ($participant->public_rate / $totalPR);
                if ($share > 0) {
                    $distributionOccurred = true;
                    $this->createDistributionTransaction($account, $participant, $share);
                }
            }
        } else {
            Log::warning('Total PR is zero, distribution skipped for account: ' . $account->id);
        }

        // Reset the account's trxCount after distribution
        //$account->value = $account->balance + $account->auxiliary - $account->link_income + $account->link_obligation;
        $account->trxCount = 0;
        $account->save();
    }

    private function createDistributionTransaction($account, $participant, $share): void
    {
        Log::info('Creating distribution transaction from ' . $account->id . ' to ' . $participant->id);

        // Handle case where the participant is the same as the account
        if ($participant->id == $account->id) {
            $participant->balance += $share;
            $participant->auxiliary -= $share;
            //$participant->value = $participant->balance + $participant->auxiliary - $participant->link_income + $participant->link_obligation;
            $participant->save();
        } else {
            // Update participant's auxiliary and Lo
            $share = min($share, $participant->link_obligation); // Ensure share does not exceed current link_obligation
            $participant->auxiliary += $share;
            $participant->link_obligation -= $share;
            $participant->trxCount += 1;
            $participant->public_rate = $this->calculateNewPR($participant);
            //$participant->value = $participant->balance + $participant->auxiliary - $participant->link_income + $participant->link_obligation;
            $participant->save();

            $account->auxiliary -= $share;
            $account->link_income -= $share;
            //$account->value = $account->balance + $account->auxiliary - $account->link_income + $account->link_obligation;
            $account->save();
        }
    }

}

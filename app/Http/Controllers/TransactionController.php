<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transactions;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{

    private \App\Services\TransactionService $transactionService;

    public function __construct()
    {
        $this->transactionService = new \App\Services\TransactionService();
    }

    public function all(): JsonResponse
    {
        return response()->json([Transactions::all()]);
    }


    public function transfer(Request $request): JsonResponse
    {
        $request->validate([
            'sender_id' => 'required|exists:accounts,id',
            'receiver_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'fee_rate' => 'required|numeric|min:0|max:100'
        ]);

        $transaction = $this->transactionService->createTransaction(
            $request->sender_id,
            $request->receiver_id,
            $request->amount,
            $request->fee_rate
        );

        return response()->json($transaction, 201);
    }
    /*public function transfer(Request $request): JsonResponse
    {
        $request->validate([
            'sender_id' => 'required|exists:accounts,id',
            'receiver_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0',
            'fee' => 'required|numeric|min:0',
        ]);

        $sender = Account::findOrFail($request->sender_id);
        $receiver = Account::findOrFail($request->receiver_id);
        $amount = $request->amount;
        $fee = $request->fee;

        // Calculate total deduction from sender's balance
        $totalDeduction = $amount + $fee;

        // Check if sender has sufficient balance
        if ($sender->balance < $totalDeduction) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        // Update sender's balance and Lo
        $sender->balance -= $totalDeduction;
        $sender->Lo += $amount;

        // Update receiver's balance and Li
        $receiver->balance += $amount;
        $receiver->Li += $amount;

        // Update auxiliary balance for receiver based on fee
        $receiver->auxiliary_balance += $fee;

        // Save changes to sender and receiver accounts
        $sender->save();
        $receiver->save();

        // Record the transaction
        Transactions::create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'amount' => $amount,
            'fee' => $fee,
        ]);

        // Process the transaction to update auxiliary balances and distribution
        $this->processTransaction($receiver, $sender);

        // Calculate and update metabalance for both sender and receiver
        $sender->metabalance = $this->calculateMetabalance($sender);
        $receiver->metabalance = $this->calculateMetabalance($receiver);

        // Save changes to sender and receiver after updating metabalance
        $sender->save();
        $receiver->save();

        // Log successful transfer
        Log::info("Transfer successful. Sender: {$sender->id}, Receiver: {$receiver->id}");

        // Return response
        return response()->json(['message' => 'Transfer successful.', 'sender' => $sender, 'receiver' => $receiver], 200);
    }

    private function processTransaction(Account $distributor): void
    {
        // Identify all participants
        $participants = Account::where('id', '!=', $distributor->id)
            ->whereRaw('balance < metabalance')
            ->get();

        // Calculate total PR
        $totalPR = $participants->sum('PR');

        // Check if total PR is not zero
        if ($totalPR != 0) {
            // Distribute auxiliary balance
            foreach ($participants as $participant) {
                $share = $distributor->auxiliary_balance * ($participant->PR / $totalPR);

                if ($participant->id == $distributor->id) {
                    // Add to balance if participant is the distributor
                    $participant->balance += $share;
                } else {
                    // Add to auxiliary balance and weaken link if participant is not the distributor
                    $participant->auxiliary_balance += $share;
                    $participant->Li -= $share;
                    $participant->Lo -= $share;
                }

                // Save changes to participant
                $participant->save();
            }
        }

        // Reset distributor's auxiliary balance after distribution
        $distributor->auxiliary_balance = 0;
        $distributor->save();
    }
    private function calculateMetabalance(Account $user): float
    {
        return $user->balance + $user->auxiliary_balance + $user->Lo - $user->Li;
    }*/
}

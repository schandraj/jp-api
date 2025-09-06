<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class UpdateOldTransactions extends Command
{
    protected $signature = 'transactions:update-old';
    protected $description = 'Update transactions older than 12 hours';

    public function handle()
    {
        $threshold = Carbon::now()->subHours(12);
        $updated = Transaction::where('created_at', '<', $threshold)
            ->where('status', '=', 'pending') // Avoid re-updating
            ->update(['status' => 'failed']);

        $this->info("Updated {$updated} transaction(s) older than 12 hours.");

        return 0;
    }
}

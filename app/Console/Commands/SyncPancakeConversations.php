<?php

namespace App\Console\Commands;

use App\Services\PcInquiries;
use Illuminate\Console\Command;

class SyncPancakeConversations extends Command
{
    protected $signature = 'pancake:conversations';

    protected $description = 'Fetch the PC Report pages\' conversations that changed since the last run (tags added, numbers given), for pages synced at least once';

    public function handle(PcInquiries $inquiries): int
    {
        $failed = false;

        foreach (PcInquiries::pages() as $page) {
            try {
                if ($page->conversations_synced_at !== null) {
                    $this->info("{$page->name}: {$inquiries->refreshChanges($page)} changed.");
                }
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("{$page->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

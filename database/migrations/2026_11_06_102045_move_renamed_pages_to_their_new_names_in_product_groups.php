<?php

use App\Models\PancakePage;
use App\Support\ProductGroups;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Pages renamed in Settings → Pancake Pages on Nov 6, 2026, before a rename carried over: the name still saved in
     * their product group and PC Report => their Pancake page ID. Each moves to whatever the page is called now.
     *
     * @var array<string, string>
     */
    private array $renamedPages = [
        'Trusted Eye Care' => '119962371080557',
        'Pterygium Eye Supp' => '1172567645931439',
        'Sinuxyl Nasal Care' => '464125443455939',
        'Clearsight Eye Drop' => '1219523551234706',
    ];

    public function up(): void
    {
        foreach ($this->renamedPages as $savedName => $pageId) {
            $currentName = PancakePage::where('page_id', $pageId)->value('name');

            if ($currentName !== null && $currentName !== $savedName) {
                ProductGroups::renamePage($savedName, $currentName);
            }
        }
    }

    /**
     * The old names only ever pointed at nothing, so there is nothing to put back.
     */
    public function down(): void
    {
        //
    }
};

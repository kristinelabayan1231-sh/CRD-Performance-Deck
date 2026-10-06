<?php
use App\Models\{User, Role, Lead, Product, LeadTransfer};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

abort_unless(config('database.connections.mysql.database') === 'crd_demo', 500, 'Refusing to seed: not the demo database.');
mt_srand(20261006);

$role = fn ($slug) => Role::firstWhere('slug', $slug)->id;
$owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'name' => 'Kristine Labayan', 'role_id' => $role('super-admin'), 'is_active' => true, 'last_login_at' => now()->subMinutes(5)]);
$sup = User::create(['email' => 'mia.torres@example.com', 'name' => 'Mia Torres', 'role_id' => $role('cra-supervisor'), 'is_active' => true, 'granted_by' => $owner->id, 'last_login_at' => now()->subHours(2)]);
$cras = collect(['Anna Cruz', 'Ben Reyes', 'Carla Santos', 'Dan Lopez'])->map(fn ($n) => User::create([
    'email' => strtolower(str_replace(' ', '.', $n)).'@example.com', 'name' => $n, 'role_id' => $role('cra'), 'is_active' => true,
    'granted_by' => $owner->id, 'last_login_at' => now()->subMinutes(mt_rand(10, 300)),
]));
User::create(['email' => 'new.hire@example.com', 'display_name' => 'New Hire', 'role_id' => $role('user'), 'is_active' => true, 'granted_by' => $owner->id]);
Role::create(['slug' => 'marketing-analyst', 'name' => 'Marketing Analyst', 'description' => 'Reads tracker numbers', 'permissions' => ['segmentation.view', 'segmentation.view_all']]);

foreach ([['Pterygium', null], ['Clearsight', 'Clear Sight'], ['AudiCure', null], ['Sinuxyl', 'Sinuvex'], ['Ginseng', null], ['CanPro', null]] as [$n, $k]) {
    Product::create(['name' => $n, 'keywords' => $k, 'created_by' => $owner->id]);
}
$rawProducts = [['Pterygium Drops', 'Pterygium'], ['Pterygium Eye Drops', 'Pterygium'], ['Clear Sight 3.0', 'Clearsight'], ['AudiCure', 'AudiCure'], ['Sinuxyl Nasal Inhaler', 'Sinuxyl'], ['Ginseng Serum', 'Ginseng'], ['CanPro', 'CanPro']];

$first = ['Maria', 'Jose', 'Ana', 'Mark', 'Grace', 'Paolo', 'Liza', 'Ramon', 'Joy', 'Carlo', 'Ella', 'Nestor', 'Rina', 'Allan', 'Bea', 'Edwin', 'Fe', 'Gino', 'Hazel', 'Ivan'];
$last = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Ramos', 'Aquino', 'Castillo', 'Villanueva', 'Dela Cruz', 'Navarro', 'Rivera'];
$pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
$today = Lead::today();
$n = 0;

for ($d = 7; $d >= 0; $d--) {
    $day = $today->subDays($d);
    foreach ($cras as $ci => $cra) {
        $count = $d === 0 ? 10 : mt_rand(8, 10);
        for ($i = 0; $i < $count; $i++) {
            $n++;
            [$raw, $prod] = $pick($rawProducts);
            $qty = $pick([1, 1, 2, 2, 4, 5]);
            $handled = $d === 0 ? $i < 4 + $ci : mt_rand(1, 100) <= 82;
            $status = $handled ? $pick(['active', 'active', 'busy_callback', 'reminders_ffup', 'repeat_purchase', 'pjr_drop_call', 'inactive', 'blocked']) : null;
            $repeat = $status ? $pick(['yes', 'no', 'no', 'reserve', null]) : null;
            $feedback = $status ? $pick(['purchased', 'still_have_stocks', 'no_budget', 'currently_using', 'not_interested', null]) : null;
            Lead::create([
                'order_id' => 'DEMO'.str_pad($n, 5, '0', STR_PAD_LEFT), 'tracking_number' => 'JT00'.mt_rand(10000000, 99999999),
                'customer_name' => $pick($first).' '.$pick($last), 'phone_number' => '917'.mt_rand(1000000, 9999999),
                'product_name' => $prod, 'product_raw' => $raw, 'qty' => $qty,
                'delivered_date' => $day->subDays($qty * 15 - 1)->toDateString(), 'consumption_days' => 15,
                'est_out_of_stock_date' => $day->toDateString(), 'lead_type' => mt_rand(1, 100) <= 30 ? 'crd' : 'new',
                'assigned_to' => $cra->id, 'assigned_at' => $day->setTime(6, 0),
                'status' => $status, 'status_updated_by' => $status ? $cra->id : null,
                'status_updated_at' => $status ? CarbonImmutable::parse($day->toDateString().' '.mt_rand(9, 17).':'.str_pad(mt_rand(0, 59), 2, '0', STR_PAD_LEFT), 'Asia/Manila') : null,
                'repeat_purchase' => $repeat, 'customer_tag' => $status ? $pick(['hot', 'warm', 'cold', 'high_value', 'repeat', 'canpro_hot', null]) : null,
                'contact_date' => $status ? $day->toDateString() : null, 'contact_time' => $status ? $pick(['09', '10', '11', '13', '14', '15', '16']) : null,
                'feedback' => $feedback, 'callback_date' => $status === 'busy_callback' ? $day->addDays(2)->toDateString() : null,
            ]);
        }
    }
}

// A couple of notes and a past transfer, so the guide can show them.
$noteLead = Lead::whereDate('est_out_of_stock_date', $today)->where('assigned_to', $cras[0]->id)->orderBy('customer_name')->first();
$noteLead->update(['notes' => "Prefers calls after 5 PM.\nAsk about the 2-bottle promo.", 'notes_updated_by' => $cras[0]->id, 'notes_updated_at' => now()->subHour()]);
$moved = Lead::carryOver($today)->where('assigned_to', $cras[1]->id)->first();
LeadTransfer::create(['lead_id' => $moved->id, 'from_user_id' => $cras[1]->id, 'to_user_id' => $cras[2]->id, 'transferred_by' => $sup->id]);
$moved->update(['assigned_to' => $cras[2]->id]);

Cache::put('segmentation.sync.'.$today->toDateString(), ['at' => now()->subMinutes(3)->toIso8601String(), 'found' => 40], now()->addDays(2));
echo "demo seeded: users=".User::count()." leads=".Lead::count()." carry-over=".Lead::carryOver()->count()."\n";

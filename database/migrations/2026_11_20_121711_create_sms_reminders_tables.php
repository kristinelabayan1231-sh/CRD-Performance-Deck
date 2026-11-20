<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SMS Reminders: each CRA sees and sends their own customers' texts, supervisors see every CRA and edit the
     * templates and settings.
     *
     * @var array<string, list<string>>
     */
    private array $grants = [
        'cra' => ['sms_reminders.view'],
        'cra-supervisor' => ['sms_reminders.view', 'sms_reminders.view_all', 'sms_reminders.manage'],
        'cra-team-lead' => ['sms_reminders.view', 'sms_reminders.view_all'],
    ];

    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_type')->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('restock_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('phone_key', 20);
            $table->string('phone_number', 40);
            $table->string('customer_name')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('products');
            $table->string('customer_type');
            $table->date('run_out_on');
            $table->date('remind_on');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('excluded_reason')->nullable();
            $table->foreignId('status_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sms_template_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->unique(['phone_key', 'remind_on']);
            $table->index(['remind_on', 'user_id', 'status']);
        });

        Schema::create('sms_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('restock_reminder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_key', 20)->index();
            $table->string('phone_number', 40);
            $table->text('body');
            $table->string('kind');
            $table->timestamp('received_at');
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('do_not_texts', function (Blueprint $table) {
            $table->id();
            $table->string('phone_key', 20)->unique();
            $table->string('reason');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('sms_auto_send')->default(false);
        });

        foreach ($this->grants as $slug => $permissions) {
            $role = DB::table('roles')->where('slug', $slug)->first();

            if ($role) {
                $current = json_decode($role->permissions ?? '[]', true) ?: [];
                DB::table('roles')->where('id', $role->id)->update([
                    'permissions' => json_encode(array_values(array_unique([...$current, ...$permissions]))),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->whereIn('slug', array_keys($this->grants))->get() as $role) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_diff($current, ['sms_reminders.view', 'sms_reminders.view_all', 'sms_reminders.manage']))),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sms_auto_send');
        });

        Schema::dropIfExists('do_not_texts');
        Schema::dropIfExists('sms_replies');
        Schema::dropIfExists('restock_reminders');
        Schema::dropIfExists('sms_templates');
    }
};

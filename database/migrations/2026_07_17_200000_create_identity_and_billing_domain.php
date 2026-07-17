<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('candidate')->after('email_verified_at')->index();
            $table->string('status')->default('pending')->after('role')->index();
            $table->string('locale', 8)->default('de')->after('status');
            $table->string('timezone')->default('Europe/Berlin')->after('locale');
            $table->timestamp('last_active_at')->nullable()->after('remember_token')->index();
            $table->timestamp('suspended_at')->nullable()->after('last_active_at');
            $table->text('blocked_reason')->nullable()->after('suspended_at');
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->unsignedSmallInteger('active_jobs_limit')->nullable();
            $table->unsignedSmallInteger('seat_limit')->nullable();
            $table->unsignedInteger('ai_credits_monthly')->nullable();
            $table->unsignedSmallInteger('job_boosts_per_term')->nullable();
            $table->unsignedSmallInteger('visa_credits_per_term')->nullable();
            $table->boolean('is_enterprise')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->string('stripe_product_id')->nullable()->unique();
            $table->string('stripe_price_id')->nullable()->unique();
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value');
            $table->timestamps();
            $table->unique(['plan_id', 'key']);
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('current_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('phone')->nullable();
            $table->string('vat_id')->nullable();
            $table->string('industry')->nullable()->index();
            $table->unsignedInteger('employee_count')->nullable();
            $table->char('country_code', 2)->default('DE')->index();
            $table->string('city')->nullable()->index();
            $table->string('postal_code')->nullable();
            $table->string('address_line1')->nullable();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->json('benefits')->nullable();
            $table->json('branding')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('stripe_id')->nullable()->unique();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('subscription_status')->nullable()->index();
            $table->timestamp('subscription_started_at')->nullable();
            $table->timestamp('subscription_renews_at')->nullable()->index();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('subscription_ends_at')->nullable();
            $table->timestamp('last_active_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();
            $table->index(['subscription_id', 'stripe_price']);
        });

        Schema::create('company_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('viewer');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });

        Schema::create('company_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->char('country_code', 2)->default('DE');
            $table->string('city');
            $table->string('postal_code')->nullable();
            $table->string('address_line1')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_headquarters')->default(false);
            $table->timestamps();
        });

        Schema::create('company_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('company_team_members', function (Blueprint $table) {
            $table->foreignId('company_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_membership_id')->constrained()->cascadeOnDelete();
            $table->primary(['company_team_id', 'company_membership_id']);
        });

        Schema::create('company_usage_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('ai_credits_used')->default(0);
            $table->unsignedInteger('job_boosts_used')->default(0);
            $table->unsignedInteger('visa_credits_used')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'starts_at', 'ends_at'], 'company_usage_period_unique');
        });

        Schema::create('entitlement_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('resource')->index();
            $table->integer('amount');
            $table->string('source');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_ledgers');
        Schema::dropIfExists('company_usage_periods');
        Schema::dropIfExists('company_team_members');
        Schema::dropIfExists('company_teams');
        Schema::dropIfExists('company_locations');
        Schema::dropIfExists('company_memberships');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('plans');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role',
                'status',
                'locale',
                'timezone',
                'last_active_at',
                'suspended_at',
                'blocked_reason',
            ]);
        });
    }
};

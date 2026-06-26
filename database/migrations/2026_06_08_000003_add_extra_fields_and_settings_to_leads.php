<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'city')) {
                $table->string('city')->nullable()->after('address');
            }
            if (! Schema::hasColumn('leads', 'state')) {
                $table->string('state')->nullable()->after('city');
            }
            if (! Schema::hasColumn('leads', 'country')) {
                $table->string('country')->nullable()->after('state');
            }
            if (! Schema::hasColumn('leads', 'pin_code')) {
                $table->string('pin_code', 20)->nullable()->after('country');
            }
            if (! Schema::hasColumn('leads', 'referral_name')) {
                $table->string('referral_name')->nullable()->after('pin_code');
            }
            if (! Schema::hasColumn('leads', 'industry_type')) {
                $table->string('industry_type')->nullable()->after('referral_name');
            }
            if (! Schema::hasColumn('leads', 'business_type')) {
                $table->string('business_type')->nullable()->after('industry_type');
            }
            if (! Schema::hasColumn('leads', 'product_service_interested_in')) {
                $table->string('product_service_interested_in')->nullable()->after('business_type');
            }
            if (! Schema::hasColumn('leads', 'budget')) {
                $table->string('budget')->nullable()->after('product_service_interested_in');
            }
            if (! Schema::hasColumn('leads', 'documents')) {
                $table->text('documents')->nullable()->after('budget');
            }
            if (! Schema::hasColumn('leads', 'annual_turnover')) {
                $table->string('annual_turnover')->nullable()->after('documents');
            }
            if (! Schema::hasColumn('leads', 'gst_number')) {
                $table->string('gst_number')->nullable()->after('annual_turnover');
            }
            if (! Schema::hasColumn('leads', 'requirement')) {
                $table->text('requirement')->nullable()->after('gst_number');
            }
        });

        if (! Schema::hasTable('lead_field_settings')) {
            Schema::create('lead_field_settings', function (Blueprint $table) {
                $table->id();
                $table->string('field_key')->unique();
                $table->string('label');
                $table->boolean('active')->default(true);
                $table->boolean('required')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        $fields = [
            ['field_key' => 'address', 'label' => 'Address', 'sort_order' => 10],
            ['field_key' => 'city', 'label' => 'City', 'sort_order' => 20],
            ['field_key' => 'state', 'label' => 'State', 'sort_order' => 30],
            ['field_key' => 'country', 'label' => 'Country', 'sort_order' => 40],
            ['field_key' => 'pin_code', 'label' => 'PIN Code', 'sort_order' => 50],
            ['field_key' => 'referral_name', 'label' => 'Referral Name', 'sort_order' => 60],
            ['field_key' => 'industry_type', 'label' => 'Industry Type', 'sort_order' => 70],
            ['field_key' => 'business_type', 'label' => 'Business Type', 'sort_order' => 80],
            ['field_key' => 'product_service_interested_in', 'label' => 'Product/Service Interested In', 'sort_order' => 90],
            ['field_key' => 'budget', 'label' => 'Budget', 'sort_order' => 100],
            ['field_key' => 'documents', 'label' => 'Documents', 'sort_order' => 110],
            ['field_key' => 'annual_turnover', 'label' => 'Annual Turnover', 'sort_order' => 120],
            ['field_key' => 'gst_number', 'label' => 'GST Number', 'sort_order' => 130],
            ['field_key' => 'requirement', 'label' => 'Requirement', 'sort_order' => 140],
        ];

        foreach ($fields as $field) {
            if (DB::table('lead_field_settings')->where('field_key', $field['field_key'])->exists()) {
                continue;
            }

            DB::table('lead_field_settings')->insert([
                ...$field,
                'active' => true,
                'required' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_field_settings');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'city',
                'state',
                'country',
                'pin_code',
                'referral_name',
                'industry_type',
                'business_type',
                'product_service_interested_in',
                'budget',
                'documents',
                'annual_turnover',
                'gst_number',
                'requirement',
            ]);
        });
    }
};

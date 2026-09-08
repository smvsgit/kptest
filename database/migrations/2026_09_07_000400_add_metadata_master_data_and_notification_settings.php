<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_data_values', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index();
            $table->string('name', 180);
            $table->string('code', 80)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('master_data_values')->nullOnDelete();
            $table->json('aliases')->nullable();
            $table->json('extra')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['type', 'name']);
            $table->index(['type', 'is_active', 'sort_order'], 'master_type_active_sort_idx');
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable()->after('department_id')->index();
            $table->foreignId('country_id')->nullable()->after('year')->constrained('master_data_values')->nullOnDelete();
            $table->foreignId('state_id')->nullable()->after('country_id')->constrained('master_data_values')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->after('state_id')->constrained('master_data_values')->nullOnDelete();
            $table->foreignId('mandir_id')->nullable()->after('city_id')->constrained('master_data_values')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->after('mandir_id')->constrained('master_data_values')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->after('event_id')->constrained('master_data_values')->nullOnDelete();
            $table->foreignId('language_id')->nullable()->after('person_id')->constrained('master_data_values')->nullOnDelete();
            $table->foreignId('media_type_id')->nullable()->after('language_id')->constrained('master_data_values')->nullOnDelete();
            $table->text('description')->nullable()->after('technical_metadata');
            $table->text('internal_remarks')->nullable()->after('description');
            $table->string('source_type', 30)->default('local')->after('internal_remarks')->index();
            $table->string('asset_status', 30)->default('active')->after('source_type')->index();
            $table->index(['year', 'event_id', 'city_id', 'language_id'], 'media_metadata_filter_idx');
        });

        $now = now();
        $seed = [
            ['type'=>'language','name'=>'Gujarati','code'=>'gu'],
            ['type'=>'language','name'=>'Hindi','code'=>'hi'],
            ['type'=>'language','name'=>'English','code'=>'en'],
            ['type'=>'media_type','name'=>'Image','code'=>'image'],
            ['type'=>'media_type','name'=>'Video','code'=>'video'],
            ['type'=>'media_type','name'=>'Audio','code'=>'audio'],
            ['type'=>'media_type','name'=>'Document','code'=>'document'],
        ];
        foreach ($seed as $i => $row) {
            DB::table('master_data_values')->insert($row + [
                'aliases'=>json_encode([]), 'extra'=>json_encode([]), 'is_active'=>true,
                'sort_order'=>$i, 'created_at'=>$now, 'updated_at'=>$now,
            ]);
        }

        DB::table('system_settings')->updateOrInsert(['key'=>'metadata.settings'], [
            'value'=>json_encode([
                'required_fields'=>['year','event_id','city_id','language_id'],
                'person_required'=>false,
                'allow_free_tags'=>true,
                'years_min'=>1950,
                'years_max'=>(int) date('Y') + 2,
            ], JSON_UNESCAPED_UNICODE), 'created_at'=>$now, 'updated_at'=>$now,
        ]);

        $searchRow = DB::table('system_settings')->where('key','search.settings')->first();
        if ($searchRow) {
            $search = json_decode($searchRow->value, true) ?: [];
            $search['exact_fields'] = array_values(array_unique(array_merge($search['exact_fields'] ?? ['id'], ['year'])));
            DB::table('system_settings')->where('key','search.settings')->update(['value'=>json_encode($search, JSON_UNESCAPED_UNICODE),'updated_at'=>$now]);
        }

        DB::table('system_settings')->updateOrInsert(['key'=>'notification.channels'], [
            'value'=>json_encode([
                'portal'=>['enabled'=>true],
                'email'=>['enabled'=>false,'provider'=>'smtp','host'=>'','port'=>587,'encryption'=>'tls','username'=>'','password_set'=>false,'from_address'=>'','from_name'=>'SMVS Karyalay Portal'],
                'whatsapp'=>['enabled'=>false,'provider'=>'meta-cloud-api','endpoint'=>'','phone_number_id'=>'','business_account_id'=>'','token_set'=>false,'sender'=>''],
                'sms'=>['enabled'=>false,'provider'=>'generic-http','endpoint'=>'','api_key_set'=>false,'sender_id'=>''],
                'events'=>[
                    'access_request_created'=>['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
                    'access_request_decided'=>['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
                    'broken_link_detected'=>['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
                    'storage_warning'=>['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
                    'security_alert'=>['portal'=>true,'email'=>false,'whatsapp'=>false,'sms'=>false],
                ],
            ], JSON_UNESCAPED_UNICODE), 'created_at'=>$now, 'updated_at'=>$now,
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', ['metadata.settings','notification.channels'])->delete();
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex('media_metadata_filter_idx');
            foreach (['country_id','state_id','city_id','mandir_id','event_id','person_id','language_id','media_type_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['year','description','internal_remarks','source_type','asset_status']);
        });
        Schema::dropIfExists('master_data_values');
    }
};

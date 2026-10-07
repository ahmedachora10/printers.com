<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 170 — عدّة طلبات توصيل للفاتورة الواحدة.
 *
 * كان طلب التوصيل أعمدةً على `service_invoices` (تاسك 93)، فلا يتّسع لأكثر من
 * سائقٍ وعنوانٍ واحد. صار لكل طلبٍ صفٌّ بسائقه وعنوانه وشريحته وقيمته.
 *
 * `service_invoices.shipping_fee` يبقى = مجموع قيم الطلبات، فكل من يقرأ المبلغ
 * وحده (الضريبة، العمولة، الولاء، المرتجع، التقارير) لا يتغيّر.
 *
 * والتسوية مع السائق (تاسك 111) تصير لكل طلب: `expenses.service_invoice_shipment_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_invoice_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('delivery_providers')->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('delivery_zones')->nullOnDelete();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->foreignId('customer_address_id')->nullable()->constrained('customer_addresses')->nullOnDelete();
            // لقطةٌ نصّية: تعديل دفتر العميل لاحقاً لا يغيّر ما طُبع.
            $table->text('address')->nullable();
            $table->decimal('fee', 12, 2)->default(0);
            $table->timestamps();
        });

        // الطلب القائم = صفٌّ واحد بنفس قيمه. التوصيل كان «مزوّدٌ أو شريحة» (تاسك 109).
        DB::table('service_invoices')
            ->where(fn ($q) => $q->whereNotNull('shipping_provider_id')->orWhereNotNull('shipping_zone_id'))
            ->orderBy('id')
            ->chunk(500, function ($invoices) {
                DB::table('service_invoice_shipments')->insert($invoices->map(fn ($i) => [
                    'service_invoice_id' => $i->id,
                    'provider_id' => $i->shipping_provider_id,
                    'zone_id' => $i->shipping_zone_id,
                    'distance_km' => $i->shipping_distance_km,
                    'customer_address_id' => $i->customer_address_id,
                    'address' => $i->shipping_address,
                    'fee' => $i->shipping_fee,
                    'created_at' => $i->created_at,
                    'updated_at' => $i->updated_at,
                ])->all());
            });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('service_invoice_shipment_id')->nullable()->after('service_invoice_id')
                ->constrained('service_invoice_shipments')->nullOnDelete();
        });

        // التسويات القائمة تتبع الطلب الوحيد لفاتورتها.
        DB::table('expenses')
            ->whereNotNull('delivery_provider_id')
            ->whereNotNull('service_invoice_id')
            ->update(['service_invoice_shipment_id' => DB::raw(
                '(select id from service_invoice_shipments where service_invoice_shipments.service_invoice_id = expenses.service_invoice_id limit 1)'
            )]);

        Schema::table('service_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_provider_id');
            $table->dropConstrainedForeignId('shipping_zone_id');
            $table->dropConstrainedForeignId('customer_address_id');
            $table->dropColumn(['shipping_distance_km', 'shipping_address']);
        });
    }

    /** يعيد الأعمدة بالطلب الأوّل لكل فاتورة — الطلبات الإضافية لا مكان لها في الشكل القديم. */
    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->foreignId('shipping_provider_id')->nullable()->after('shipping_fee')
                ->constrained('delivery_providers')->nullOnDelete();
            $table->foreignId('shipping_zone_id')->nullable()->after('shipping_provider_id')
                ->constrained('delivery_zones')->nullOnDelete();
            $table->decimal('shipping_distance_km', 8, 2)->nullable()->after('shipping_zone_id');
            $table->foreignId('customer_address_id')->nullable()->after('shipping_distance_km')
                ->constrained('customer_addresses')->nullOnDelete();
            $table->text('shipping_address')->nullable()->after('customer_address_id');
        });

        DB::table('service_invoice_shipments')->orderBy('id')->get()->unique('service_invoice_id')
            ->each(fn ($s) => DB::table('service_invoices')->where('id', $s->service_invoice_id)->update([
                'shipping_provider_id' => $s->provider_id,
                'shipping_zone_id' => $s->zone_id,
                'shipping_distance_km' => $s->distance_km,
                'customer_address_id' => $s->customer_address_id,
                'shipping_address' => $s->address,
            ]));

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_invoice_shipment_id');
        });

        Schema::dropIfExists('service_invoice_shipments');
    }
};

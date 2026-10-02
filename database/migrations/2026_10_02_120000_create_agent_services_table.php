<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تاسك 153: عمولة صاحب العمولة (المندوب) لكل خدمة فرع، يحدّدها مدير الفرع أو
     * مدير النظام. وجود الصفّ يقفل القيمة على الموظف في نقطة البيع؛ وغيابه يتركها
     * له يكتبها يدوياً كما قبل هذا التاسك.
     */
    public function up(): void
    {
        Schema::create('agent_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_service_id')->constrained('branch_services')->cascadeOnDelete();
            // LineAgentCommissionTypeEnum: percentage | fixed | per_sqm
            $table->string('commission_type', 20);
            $table->decimal('commission_value', 12, 2);
            $table->timestamps();

            $table->unique(['agent_id', 'branch_service_id']);
            $table->index('branch_service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_services');
    }
};

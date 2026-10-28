<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('salaries', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('department_id');
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // Pay period
            $table->date('pay_period');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');

            // Employment snapshot at time of payroll
            $table->enum('employment_type', ['permanent', 'contract', 'probation', 'intern', 'part_time']);
            $table->unsignedTinyInteger('grade');
            $table->string('designation', 48);
            $table->string('cost_center', 12);
            $table->char('currency', 3)->default('BDT');

            // Attendance
            $table->unsignedTinyInteger('working_days');
            $table->unsignedTinyInteger('present_days');
            $table->unsignedTinyInteger('leave_days');
            $table->unsignedTinyInteger('absent_days');

            // Earnings
            $table->decimal('basic_salary', 10, 2);
            $table->decimal('house_rent', 10, 2);
            $table->decimal('medical_allowance', 10, 2);
            $table->decimal('transport_allowance', 10, 2);
            $table->decimal('other_allowance', 10, 2)->default(0);
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->decimal('overtime_rate', 8, 2)->default(0);
            $table->decimal('overtime_amount', 10, 2)->default(0);
            $table->decimal('bonus', 10, 2)->default(0);
            $table->decimal('commission', 10, 2)->default(0);
            $table->decimal('gross_salary', 12, 2);

            // Deductions
            $table->decimal('income_tax', 10, 2)->default(0);
            $table->decimal('provident_fund', 10, 2)->default(0);
            $table->decimal('insurance', 10, 2)->default(0);
            $table->decimal('loan_deduction', 10, 2)->default(0);
            $table->decimal('absence_deduction', 10, 2)->default(0);
            $table->decimal('total_deduction', 12, 2);
            $table->decimal('net_salary', 12, 2);

            // Payment
            $table->enum('payment_method', ['bank_transfer', 'mobile_banking', 'cheque', 'cash']);
            $table->string('bank_account', 24)->nullable();
            $table->enum('status', ['draft', 'pending', 'approved', 'paid', 'on_hold', 'cancelled']);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('adjustments')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'pay_period']);
            $table->index(['department_id', 'pay_period']);
            $table->index(['status', 'pay_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salaries');
    }
};

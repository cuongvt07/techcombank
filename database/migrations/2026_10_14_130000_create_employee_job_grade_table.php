<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_job_grade', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_grade_id')->constrained()->cascadeOnDelete();
            $table->primary(['employee_id', 'job_grade_id']);
        });

        DB::table('employees')
            ->whereNotNull('job_grade_id')
            ->select(['id', 'job_grade_id'])
            ->orderBy('id')
            ->chunk(500, function ($employees) {
                DB::table('employee_job_grade')->insertOrIgnore(
                    $employees->map(fn ($employee) => [
                        'employee_id' => $employee->id,
                        'job_grade_id' => $employee->job_grade_id,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_job_grade');
    }
};

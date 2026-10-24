<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Backfill employee departments from their assigned job title.
 *
 * Run with:
 * php artisan db:seed --class=BackfillEmployeeDepartmentsFromJobTitleSeeder
 *
 * This is intentionally not registered in DatabaseSeeder: it is a one-time,
 * idempotent data correction and must not run as part of normal demo seeding.
 */
class BackfillEmployeeDepartmentsFromJobTitleSeeder extends Seeder
{
    public function run(): void
    {
        $updated = 0;

        DB::transaction(function () use (&$updated): void {
            Employee::query()
                ->whereNull('department_id')
                ->whereNotNull('job_title_id')
                ->with('jobTitle:id,department_id')
                ->chunkById(200, function ($employees) use (&$updated): void {
                    foreach ($employees as $employee) {
                        $departmentId = $employee->jobTitle?->department_id;

                        if (! $departmentId) {
                            continue;
                        }

                        $employee->department_id = $departmentId;
                        $employee->save();
                        $updated++;
                    }
                });
        });

        $remaining = Employee::query()->whereNull('department_id')->count();

        $this->command?->info("Employees assigned from job title: {$updated}");
        $this->command?->info("Employees still without a department: {$remaining}");
    }
}

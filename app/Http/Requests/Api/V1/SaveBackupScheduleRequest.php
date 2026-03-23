<?php

namespace App\Http\Requests\Api\V1;

use Cron\CronExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveBackupScheduleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\BackupSchedule|null $schedule */
        $schedule = $this->route('backup_schedule');
        $scheduleId = $schedule?->id;

        return [
            'name' => ['required', 'string', 'max:255', 'unique:backup_schedules,name,'.$scheduleId],
            'expression' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateCronExpression($validator);
        });
    }

    private function validateCronExpression(Validator $validator): void
    {
        $expression = $this->input('expression');

        if (! is_string($expression) || ! CronExpression::isValidExpression($expression)) {
            $validator->errors()->add('expression', 'The expression is not a valid cron expression.');
        }
    }
}

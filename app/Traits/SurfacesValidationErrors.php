<?php

namespace App\Traits;

use Illuminate\Validation\ValidationException;

/**
 * Reports validation failures on long forms. Inline field errors alone are
 * easy to miss when the button that triggered them sits several screens below
 * the offending field, so a failure also raises a toast and asks the browser
 * to bring the first invalid field into view.
 *
 * Requires the {@see Toast} trait for the error() helper.
 */
trait SurfacesValidationErrors
{
    /**
     * Run an action that validates, reporting any failure before letting
     * Livewire populate the error bag as usual:
     *
     *     if ($this->withValidationFeedback(fn () => $this->form->store())) {
     *         // ...
     *     }
     *
     * @template TResult
     *
     * @param  callable(): TResult  $action
     * @return TResult
     *
     * @throws ValidationException
     */
    protected function withValidationFeedback(callable $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            $this->reportValidationFailure($e);

            throw $e;
        }
    }

    private function reportValidationFailure(ValidationException $e): void
    {
        $errors = $e->errors();
        $count = count($errors);

        /** @var string|null $firstMessage */
        $firstMessage = collect($errors)->flatten()->first();

        $this->error(
            title: trans_choice(
                '{1} :count field needs your attention|[2,*] :count fields need your attention',
                $count,
                ['count' => $count],
            ),
            description: $firstMessage,
        );

        $this->dispatch('validation-failed', field: array_key_first($errors));
    }
}

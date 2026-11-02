<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The "Close gate" form on the gate page: when the gate is closed and why.
 */
class CreateGateRestrictionRequest extends FormRequest
{
    /**
     * The app has no user accounts, so anyone who can open the gate page may close the gate.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_at.after' => 'The end must be after the start.',
        ];
    }

    /**
     * The form works in UTC, like every time shown in the app.
     */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('starts_at'), 'UTC');
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('ends_at'), 'UTC');
    }
}

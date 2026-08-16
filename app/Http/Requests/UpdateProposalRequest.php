<?php

// app/Http/Requests/UpdateProposalRequest.php

namespace App\Http\Requests;

use App\Data\UpdateProposalData;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateProposalRequest extends FormRequest
{
    // 404, not 403 — mirrors ProposalController::show's own existence-hiding
    // scope. This must live here, not in the controller body:
    // ValidatesWhenResolvedTrait::validateResolved() calls passesAuthorization()
    // before it builds the validator, so a guard placed after validation in the
    // controller never runs for a payload that fails validation — a forbidden
    // real id would 422 while a fake id 404s, leaking existence.
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('proposal'));
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:8', 'max:120'],
            'description' => ['sometimes', 'required', 'string', 'min:40', 'max:20000'],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) && ! is_string($value)) {
                        $fail("The {$attribute} field must be a tag id or name.");
                    }
                },
                'max:40',
            ],
            'attachment' => ['sometimes', 'nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return ['attachment.max' => 'The attachment must be 4 MB or smaller.'];
    }

    public function toData(): UpdateProposalData
    {
        return new UpdateProposalData(
            title: $this->has('title') ? $this->string('title')->trim()->value() : null,
            description: $this->has('description') ? $this->string('description')->trim()->value() : null,
            // has() and not input() — an explicitly sent [] must reach the
            // action as [] ("clear them"), while an absent key reaches it as
            // null ("leave them alone"). input('tags', null) cannot tell those apart.
            tags: $this->has('tags') ? array_values($this->input('tags', [])) : null,
            attachment: $this->file('attachment'),
        );
    }
}

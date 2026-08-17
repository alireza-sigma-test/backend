<?php

namespace App\Http\Requests;

use App\Data\UpdateProposalData;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateProposalRequest extends FormRequest
{
    // 404, not 403, mirroring ProposalController::show. It must live here rather than
    // in the controller: passesAuthorization() runs before validation, so a guard
    // placed after it would let a forbidden real id 422 while a fake id 404s.
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
            // No `nullable`, so an explicit null 422s instead of being a silent no-op.
            // Clearing an attachment goes through DELETE /proposals/{id}/attachment.
            'attachment' => ['sometimes', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'attachment.max' => 'The attachment must be 4 MB or smaller.',
            'attachment.file' => 'The attachment cannot be null — use DELETE /proposals/{id}/attachment to remove it.',
        ];
    }

    public function toData(): UpdateProposalData
    {
        return new UpdateProposalData(
            title: $this->has('title') ? $this->string('title')->trim()->value() : null,
            description: $this->has('description') ? $this->string('description')->trim()->value() : null,
            // has(), not input(): an explicit [] means "clear them" and an absent key
            // means "leave them alone". input('tags', null) cannot tell those apart.
            tags: $this->has('tags') ? array_values($this->input('tags', [])) : null,
            attachment: $this->file('attachment'),
        );
    }
}

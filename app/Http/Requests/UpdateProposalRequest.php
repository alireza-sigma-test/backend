<?php

// app/Http/Requests/UpdateProposalRequest.php

namespace App\Http\Requests;

use App\Data\UpdateProposalData;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProposalRequest extends FormRequest
{
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

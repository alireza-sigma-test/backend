<?php
// app/Http/Requests/StoreProposalRequest.php
namespace App\Http\Requests;

use App\Data\ProposalData;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreProposalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:8', 'max:120'],
            'description' => ['required', 'string', 'min:40', 'max:20000'],
            'tags' => ['sometimes', 'array', 'max:10'],
            // Ids and free-text names share this array. A plain `string` rule
            // would reject genuine tag ids: `$tag->id` round-trips through a
            // JSON request body as a native integer, and Laravel's `string`
            // rule is a strict is_string() check. `max:40` still bounds
            // length correctly for either type — it only switches to numeric
            // comparison when a `numeric`/`integer` rule is also present on
            // the field, which this one deliberately omits.
            'tags.*' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) && ! is_string($value)) {
                        $fail("The {$attribute} field must be a tag id or name.");
                    }
                },
                'max:40',
            ],
            // Extension AND sniffed MIME type — an attacker renaming
            // payload.exe to slides.pdf fails the second rule.
            'attachment' => ['sometimes', 'nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return ['attachment.max' => 'The attachment must be 4 MB or smaller.'];
    }

    public function toData(): ProposalData
    {
        return new ProposalData(
            title: $this->string('title')->trim()->value(),
            description: $this->string('description')->trim()->value(),
            tags: array_values($this->input('tags', [])),
            attachment: $this->file('attachment'),
        );
    }
}

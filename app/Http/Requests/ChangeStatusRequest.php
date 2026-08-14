<?php

// app/Http/Requests/ChangeStatusRequest.php

namespace App\Http\Requests;

use App\Data\StatusChangeData;
use App\Enums\ProposalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(ProposalStatus::values())],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function toData(): StatusChangeData
    {
        return new StatusChangeData(
            status: ProposalStatus::from($this->string('status')->value()),
            note: $this->filled('note') ? $this->string('note')->trim()->value() : null,
        );
    }
}

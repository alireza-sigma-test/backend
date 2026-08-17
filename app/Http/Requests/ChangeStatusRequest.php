<?php

namespace App\Http\Requests;

use App\Data\StatusChangeData;
use App\Enums\ProposalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ChangeStatusRequest extends FormRequest
{
    // 404, not 403 — mirrors ProposalController::show's own existence-hiding
    // scope. Must run in authorize(), which fires before the validator (see
    // ValidatesWhenResolvedTrait::validateResolved()) — a controller-body guard
    // never executes for a payload that fails validation first.
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

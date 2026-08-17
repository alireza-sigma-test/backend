<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexNotificationRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'unread_only' => ['sometimes', 'boolean'],
            // No `max:50` here, matching IndexProposalRequest: the repository's
            // min($perPage, 50) is the single enforcement point, so per_page=500
            // clamps rather than 422s.
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function unreadOnly(): bool
    {
        return $this->boolean('unread_only');
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 15);
    }
}

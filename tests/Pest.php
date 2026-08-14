<?php

// tests/Pest.php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

// UploadedFile::fake()->create() writes a ZERO-BYTE file. Media Library sniffs
// the real bytes, so an empty file resolves to application/x-empty and a
// PDF-only collection rejects it — even though Laravel's `mimetypes` rule
// passes, because that reads the *declared* mime. Real content is required.
// ->size() then reports whatever size the validation rules need.
function fakePdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n%fake pdf for testing\n".str_repeat('a', 200),
    );
}

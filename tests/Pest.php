<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

// UploadedFile::fake()->create() writes a zero-byte file, which Media Library sniffs
// as application/x-empty and a PDF-only collection rejects — even though the
// `mimetypes` rule passes on the declared mime. Real content is required.
function fakePdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n%fake pdf for testing\n".str_repeat('a', 200),
    );
}

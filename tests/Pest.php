<?php

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Contract');

/** Yaniti sozlesme kontrolu icin yazar: scripts/contract/run.ts packages/core ayristiricilarindan gecirir. */
function contract(string $name, string $parser, mixed $response, array $meta = []): void
{
    file_put_contents(storage_path('contract/'.$name.'.json'), json_encode(['parser' => $parser, 'response' => $response, 'meta' => $meta ?: new stdClass], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

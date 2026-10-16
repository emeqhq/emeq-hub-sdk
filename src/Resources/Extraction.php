<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Http\Request\Extraction\GetRunRequest;
use Emeq\HubSdk\Http\Request\Extraction\PutHintsRequest;
use Emeq\HubSdk\Http\Request\Extraction\PutProfileVersionRequest;
use Emeq\HubSdk\Http\Request\Extraction\StartFileRunRequest;
use Emeq\HubSdk\Http\Request\Extraction\StartTextRunRequest;

class Extraction extends Resource
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function putProfileVersion(string $key, int $version, array $schema, ?string $instructions = null): array
    {
        $response = $this->connector->send(new PutProfileVersionRequest($key, $version, $schema, $instructions));

        return $this->json($response->json());
    }

    /**
     * @deprecated Hints have no measured effect on extraction and will be removed in a later release.
     *
     * @return array<string, mixed>
     */
    public function putHints(?string $hints, ?string $accountId = null): array
    {
        $response = $this->connector->send(new PutHintsRequest(
            hints: $hints,
            accountId: $this->resolveAccountId($accountId),
        ));

        return $this->json($response->json());
    }

    /** @return array<string, mixed> */
    public function runText(string $profile, int $version, string $text, string $idempotencyKey, ?string $accountId = null): array
    {
        $response = $this->connector->send(new StartTextRunRequest(
            profile: $profile,
            version: $version,
            text: $text,
            accountId: $this->resolveAccountId($accountId),
            idempotencyKey: $idempotencyKey,
        ));

        return $this->json($response->json());
    }

    /** @return array<string, mixed> */
    public function runFile(string $profile, int $version, string $contents, string $filename, string $idempotencyKey, ?string $accountId = null): array
    {
        $response = $this->connector->send(new StartFileRunRequest(
            profile: $profile,
            version: $version,
            contents: $contents,
            filename: $filename,
            accountId: $this->resolveAccountId($accountId),
            idempotencyKey: $idempotencyKey,
        ));

        return $this->json($response->json());
    }

    /** @return array<string, mixed> */
    public function getRun(string $runId, ?string $accountId = null): array
    {
        $response = $this->connector->send(new GetRunRequest(
            runId: $runId,
            accountId: $this->resolveAccountId($accountId),
        ));

        return $this->json($response->json());
    }
}

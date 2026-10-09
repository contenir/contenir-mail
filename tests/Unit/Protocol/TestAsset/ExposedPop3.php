<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\TestAsset;

use Contenir\Mail\Protocol\Pop3;

/**
 * Exposes the protected response API that subclasses of Protocol\Pop3 build on.
 */
final class ExposedPop3 extends Pop3
{
    /**
     * @return array{string, string}
     */
    public function response(): array
    {
        $response = $this->readRemoteResponse();

        return [$response->status(), $response->message()];
    }
}

<?php

declare(strict_types=1);

namespace Contenir\Mail\Header\Exception;

use Contenir\Mail\Exception;

final class InvalidArgumentException extends Exception\InvalidArgumentException implements ExceptionInterface {}

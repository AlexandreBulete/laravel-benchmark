<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Exceptions;

use RuntimeException;

/**
 * Exception thrown when benchmarks are attempted in production environment
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class ProductionEnvironmentException extends RuntimeException {}


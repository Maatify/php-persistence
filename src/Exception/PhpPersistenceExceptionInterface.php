<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Exception;

use Throwable;

/**
 * Marker interface for every exception defined by this package.
 *
 * It lets a consumer catch package-defined failures with a single type:
 *
 *     catch (PhpPersistenceExceptionInterface $e) { ... }
 *
 * The package propagates external throwables, such as {@see \PDOException},
 * unchanged. They do not implement this interface and must not be expected to.
 * Every exception class added to this package implements it directly or
 * through its base type.
 */
interface PhpPersistenceExceptionInterface extends Throwable {}

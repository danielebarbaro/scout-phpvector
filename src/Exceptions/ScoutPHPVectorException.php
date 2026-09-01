<?php

declare(strict_types=1);

namespace DanieleBarbaro\ScoutPHPVector\Exceptions;

use Laravel\Scout\Exceptions\ScoutException;

/**
 * Base exception for every failure raised by this engine.
 *
 * It extends Scout's own exception so applications that already catch
 * ScoutException keep working when they switch to the phpvector driver.
 */
class ScoutPHPVectorException extends ScoutException {}

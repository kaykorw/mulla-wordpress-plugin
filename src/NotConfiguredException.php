<?php

declare(strict_types=1);

namespace MullaPayments;

use MullaPayments\Vendor\Mulla\Exceptions\MullaException;

/**
 * The plugin has no valid secret key for the active mode (or an invalid gateway URL).
 */
final class NotConfiguredException extends MullaException {}

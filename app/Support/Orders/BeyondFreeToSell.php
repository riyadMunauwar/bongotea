<?php

namespace App\Support\Orders;

use InvalidArgumentException;

/**
 * A User's Order refused for wanting more than is Free to Sell (ADR-0037),
 * or let past it with no reason given. Its own type so the Order screens can
 * show it as a refusal when stock moved between the form's check and the save.
 */
class BeyondFreeToSell extends InvalidArgumentException {}

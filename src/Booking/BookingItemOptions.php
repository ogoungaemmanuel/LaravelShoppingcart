<?php

namespace Xslaincart\Shoppingcart\Booking;

use Illuminate\Support\Collection;

class BookingItemOptions extends Collection
{
    /**
     * Get the option by the given key.
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->get($key);
    }
}
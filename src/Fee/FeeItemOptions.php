<?php

namespace Xslaincart\Shoppingcart\Fee;

use Illuminate\Support\Collection;

class FeeItemOptions extends Collection
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
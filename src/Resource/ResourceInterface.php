<?php

namespace Iza\Moderare\Resource;

interface ResourceInterface
{
    /**
     * Get the data.
     *
     * @return array|ArrayIterator
     */
    public function getData();

    /**
     * Get the validator.
     *
     * @return callable|string
     */
    public function getValidator();
}

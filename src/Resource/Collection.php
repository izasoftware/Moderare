<?php

namespace Iza\Moderare\Resource;

use ArrayIterator;

class Collection extends ResourceAbstract
{
    /**
     * A collection of data.
     *
     * @var array|ArrayIterator
     */
    protected $data;

    /**
     * A callable to process the data attached to this resource.
     *
     * @var callable|string
     */
    protected $validator;

}

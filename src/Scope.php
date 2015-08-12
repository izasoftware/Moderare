<?php

namespace Iza\Moderare;

use InvalidArgumentException;
use Iza\Moderare\Resource\Collection;
use Iza\Moderare\Resource\Item;
use Iza\Moderare\Resource\ResourceInterface;

/**
 * Scope
 *
 * The scope class acts as a tracker, relating a specific resource in a specific
 * context. For example, the same resource could be attached to multiple scopes.
 * There are root scopes, parent scopes and child scopes.
 */
class Scope
{
    /**
     * @var array
     */
    protected $availableIncludes = array();

    /**
     * @var string
     */
    protected $scopeIdentifer;

    /**
     * @var \Iza\Moderare\Engine
     */
    protected $manager;

    /**
     * @var ResourceInterface
     */
    protected $resource;

    /**
     * @var bool
     */
    protected $success = false;

    /**
     * @var array
     */
    protected $parentScopes = array();

    /**
     * Create a new scope instance.
     *
     * @param Engine $manager
     * @param ResourceInterface $resource
     * @param string $scopeIdentifer
     *
     * @return void
     */
    public function __construct(Engine $manager, ResourceInterface $resource, $scopeIdentifer = null)
    {
        $this->manager = $manager;
        $this->resource = $resource;
        $this->scopeIdentifer = $scopeIdentifer;
    }

    /**
     * Embed a scope as a child of the current scope.
     *
     * @internal
     *
     * @param string $scopeIdentifier
     * @param ResourceInterface $resource
     *
     * @return \Iza\Moderare\Scope
     */
    public function embedChildScope($scopeIdentifier, $resource)
    {
        return $this->manager->validateData($resource, $scopeIdentifier, $this);
    }

    /**
     * Get the current identifier.
     *
     * @return string
     */
    public function getScopeIdentifier()
    {
        return $this->scopeIdentifer;
    }

    /**
     * Get the unique identifier for this scope.
     *
     * @param string $appendIdentifier
     *
     * @return string
     */
    public function getIdentifier($appendIdentifier = null)
    {
        $identifierParts = array_merge($this->parentScopes, array($this->scopeIdentifer, $appendIdentifier));

        return implode('.', array_filter($identifierParts));
    }

    /**
     * Getter for parentScopes.
     *
     * @return mixed
     */
    public function getParentScopes()
    {
        return $this->parentScopes;
    }

    /**
     * Getter for manager.
     *
     * @return \Iza\Moderare\Engine
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * Push Parent Scope.
     *
     * Push a scope identifier into parentScopes
     *
     * @internal
     *
     * @param string $identifierSegment
     *
     * @return int Returns the new number of elements in the array.
     */
    public function pushParentScope($identifierSegment)
    {
        return array_push($this->parentScopes, $identifierSegment);
    }

    /**
     * Set parent scopes.
     *
     * @internal
     *
     * @param string[] $parentScopes Value to set.
     *
     * @return $this
     */
    public function setParentScopes($parentScopes)
    {
        $this->parentScopes = $parentScopes;

        return $this;
    }

    /**
     * Validate the current scope
     *
     * @return array
     */
    public function validate()
    {
        list($rawData, $rawIncludedData) = $this->execute();

        if (!$this->success) {
            foreach ($rawIncludedData as $key => $bag) {
                if (!empty($bag)) {
                    $rawData->add('includes', $bag);
                }
            }
        }

        return $this->success ? true : $rawData;
    }

    /**
     * Execute the resources validator and return the result.
     *
     * @internal
     *
     * @return array
     */
    protected function execute()
    {
        $validator = $this->resource->getValidator();
        $data = $this->resource->getData();

        $transformedData = $includedData = array();

        if ($this->resource instanceof Item) {
            list($transformedData, $includedData[]) = $this->fireValidator($validator, $data);
        } elseif ($this->resource instanceof Collection) {
            foreach ($data as $value) {
                list($transformedData[], $includedData[]) = $this->fireValidator($validator, $value);
            }
        } else {
            throw new InvalidArgumentException(
                'Argument $resource should be an instance of Iza\Moderare\Resource\Item'
                . ' or Iza\Moderare\Resource\Collection'
            );
        }

        return array($transformedData, $includedData);
    }

    /**
     * Fire the main validator.
     *
     * @internal
     *
     * @param ValidatorAbstract|callable $validator
     * @param mixed $data
     *
     * @return MessageBag|bool
     */
    protected function fireValidator($validator, $data)
    {
        $includedValidation = array();
        $output = null;

        if (is_callable($validator)) {
            try {
                $output = call_user_func($validator, $data);
            } catch (\Exception $e) {
                $output = new MessageBag([$e->getMessage()]);
            }
        } elseif ($validator instanceof ValidatorAbstract) {
            try {
                $output = $validator->validate($data);
            } catch (\Exception $e) {
                $output = new MessageBag([$e->getMessage()]);
            }
        }

        if (is_string($output)) {
            $output = new MessageBag([$output]);
        }

        if (is_array($output)) {
            $output = new MessageBag($output);
        }

        if (is_bool($output) && $output) {
            $this->success = true;
            $output = new  MessageBag();
        } else {
            $this->failValidation();
        }

        if ($this->transformerHasIncludes($validator)) {
            $includedValidation = $this->fireIncludedValidators($validator, $data);
        }

        return array($output, $includedValidation);
    }

    public function failValidation()
    {
        $this->success = false;

        foreach ($this->parentScopes as $parent) {
            $parent->failValidation();
        }
    }

    /**
     * Fire the included validators.
     *
     * @internal
     *
     * @param \Iza\Moderare\ValidatorAbstract $validator
     * @param mixed $data
     *
     * @return array
     */
    protected function fireIncludedValidators($validator, $data)
    {
        $this->availableIncludes = $validator->getAvailableIncludes();

        return $validator->processIncludedResources($this, $data) ?: array();
    }

    /**
     * Determine if a validator has any available includes.
     *
     * @internal
     *
     * @param ValidatorAbstract|callable $validator
     *
     * @return bool
     */
    protected function transformerHasIncludes($transformer)
    {
        if (!$transformer instanceof ValidatorAbstract) {
            return false;
        }

        $defaultIncludes = $transformer->getDefaultIncludes();
        $availableIncludes = $transformer->getAvailableIncludes();

        return !empty($defaultIncludes) || !empty($availableIncludes);
    }
}

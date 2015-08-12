<?php

namespace Iza\Moderare;

use App\Validators\ValidatorAbstract;
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
     * @return \League\Fractal\Scope
     */
    public function embedChildScope($scopeIdentifier, $resource)
    {
        return $this->manager->createData($resource, $scopeIdentifier, $this);
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
     * Is Requested.
     *
     * Check if - in relation to the current scope - there are children in the request.
     *
     * @internal
     *
     * @param string $checkScopeSegment
     *
     * @return bool Returns the new number of elements in the array.
     */
    public function isPosted($checkScopeSegment)
    {
        if ($this->parentScopes) {
            $scopeArray = array_slice($this->parentScopes, 1);
            array_push($scopeArray, $this->scopeIdentifer, $checkScopeSegment);
        } else {
            $scopeArray = array($checkScopeSegment);
        }

        $scopeString = implode('.', (array)$scopeArray);

        $checkAgainstArray = $this->manager->getRequestedIncludes();

        return in_array($scopeString, $checkAgainstArray);
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
        list($rawData, $rawIncludedData) = $this->executeResourceTransformers();

        $serializer = $this->manager->getSerializer();

        $data = $this->serializeResource($serializer, $rawData);

        // If the serializer wants the includes to be side-loaded then we'll
        // serialize the included data and merge it with the data.
        if ($serializer->sideloadIncludes()) {
            $includedData = $serializer->includedData($this->resource, $rawIncludedData);

            $data = array_merge($data, $includedData);
        }

        if ($this->resource instanceof Collection) {

        }


        // Pull out all of OUR metadata and any custom meta data to merge with the main level data
        $meta = $serializer->meta($this->resource->getMeta());

        return array_merge($data, $meta);
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
        $transformer = $this->resource->getValidator();
        $data = $this->resource->getData();

        $transformedData = $includedData = array();

        if ($this->resource instanceof Item) {
            list($transformedData, $includedData[]) = $this->fireValidator($transformer, $data);
        } elseif ($this->resource instanceof Collection) {
            foreach ($data as $value) {
                list($transformedData[], $includedData[]) = $this->fireValidator($transformer, $value);
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
     * @return array
     */
    protected function fireValidator($validator, $data)
    {
        $includedValidation = array();

        if (is_callable($validator)) {
            $mainValidation = call_user_func($validator, $data);
        } else {
            $mainValidation = $validator->validate($data);
        }

        if ($this->transformerHasIncludes($validator)) {
            $includedValidation = $this->fireIncludedValidators($validator, $data);
        }

        return array($mainValidation, $includedValidation);
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
     * @param ValidatorAbstract|callable $transformer
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

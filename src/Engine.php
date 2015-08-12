<?php

namespace Iza\Moderare;

use Iza\Moderare\Resource\ResourceInterface;

class Engine
{
    /**
     * Array of scope identifiers for resources to include.
     *
     * @var array
     */
    protected $requestedIncludes = array();

    /**
     * Array containing modifiers as keys and an array value of params.
     *
     * @var array
     */
    protected $includeParams = array();

    /**
     * The character used to separate modifier parameters.
     *
     * @var string
     */
    protected $paramDelimiter = '|';

    /**
     * Upper limit to how many levels of included data are allowed.
     *
     * @var int
     */
    protected $recursionLimit = 10;

    /**
     * Validate Data.
     *
     * Main method to kick this all off. Make a resource then pass it over, and use toArray()
     *
     * @param ResourceInterface $resource
     * @param string $scopeIdentifier
     * @param Scope $parentScopeInstance
     *
     * @return Scope
     */
    public function validateData(ResourceInterface $resource, $scopeIdentifier = null, Scope $parentScopeInstance = null)
    {
        $scopeInstance = new Scope($this, $resource, $scopeIdentifier);

        // Update scope history
        if ($parentScopeInstance !== null) {
            // This will be the new children list of parents (parents parents, plus the parent)
            $scopeArray = $parentScopeInstance->getParentScopes();
            $scopeArray[] = $parentScopeInstance->getScopeIdentifier();

            $scopeInstance->setParentScopes($scopeArray);
        }

        return $scopeInstance;
    }

    /**
     * Get Include Params.
     *
     * @param string $include
     *
     * @return \Iza\Moderare\ParamBag|null
     */
    public function getIncludeParams($include)
    {
        if (!isset($this->includeParams[$include])) {
            return;
        }

        $params = $this->includeParams[$include];

        return new ParamBag($params);
    }

    /**
     * Get Requested Includes.
     *
     * @return array
     */
    public function getRequestedIncludes()
    {
        return $this->requestedIncludes;
    }

    /**
     * Parse Include String.
     *
     * @param array|string $includes Array or csv string of resources to include
     *
     * @return $this
     */
    public function parseIncludes($includes)
    {
        // Wipe these before we go again
        $this->requestedIncludes = $this->includeParams = array();

        if (is_string($includes)) {
            $includes = explode(',', $includes);
        }

        if (!is_array($includes)) {
            throw new \InvalidArgumentException(
                'The parseIncludes() method expects a string or an array. ' . gettype($includes) . ' given'
            );
        }

        foreach ($includes as $include) {
            list($includeName, $allModifiersStr) = array_pad(explode(':', $include, 2), 2, null);

            // Trim it down to a cool level of recursion
            $includeName = $this->trimToAcceptableRecursionLevel($includeName);

            if (in_array($includeName, $this->requestedIncludes)) {
                continue;
            }
            $this->requestedIncludes[] = $includeName;

            // No Params? Bored
            if ($allModifiersStr === null) {
                continue;
            }

            // Matches multiple instances of 'something(foo,bar,baz)' in the string
            // I guess it ignores : so you could use anything, but probably dont do that
            preg_match_all('/([\w]+)\(([^\)]+)\)/', $allModifiersStr, $allModifiersArr);

            // [0] is full matched strings...
            $modifierCount = count($allModifiersArr[0]);

            $modifierArr = array();

            for ($modifierIt = 0; $modifierIt < $modifierCount; $modifierIt++) {
                // [1] is the modifier
                $modifierName = $allModifiersArr[1][$modifierIt];

                // and [2] is delimited params
                $modifierParamStr = $allModifiersArr[2][$modifierIt];

                // Make modifier array key with an array of params as the value
                $modifierArr[$modifierName] = explode($this->paramDelimiter, $modifierParamStr);
            }

            $this->includeParams[$includeName] = $modifierArr;
        }

        // This should be optional and public someday, but without it includes would never show up
        $this->autoIncludeParents();

        return $this;
    }

    /**
     * Set Recursion Limit.
     *
     * @param int $recursionLimit
     *
     * @return $this
     */
    public function setRecursionLimit($recursionLimit)
    {
        $this->recursionLimit = $recursionLimit;

        return $this;
    }

    /**
     * Auto-include Parents
     *
     * Look at the requested includes and automatically include the parents if they
     * are not explicitly requested. E.g: [foo, bar.baz] becomes [foo, bar, bar.baz]
     *
     * @internal
     *
     * @return void
     */
    protected function autoIncludeParents()
    {
        $parsed = array();

        foreach ($this->requestedIncludes as $include) {
            $nested = explode('.', $include);

            $part = array_shift($nested);
            $parsed[] = $part;

            while (count($nested) > 0) {
                $part .= '.' . array_shift($nested);
                $parsed[] = $part;
            }
        }

        $this->requestedIncludes = array_values(array_unique($parsed));
    }

    /**
     * Trim to Acceptable Recursion Level
     *
     * Strip off any requested resources that are too many levels deep, to avoid DiCaprio being chased
     * by trains or whatever the hell that movie was about.
     *
     * @internal
     *
     * @param string $includeName
     *
     * @return string
     */
    protected function trimToAcceptableRecursionLevel($includeName)
    {
        return implode('.', array_slice(explode('.', $includeName), 0, $this->recursionLimit));
    }
}
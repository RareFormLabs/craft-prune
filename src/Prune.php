<?php

namespace rareform;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQuery;
use craft\htmlfield\HtmlFieldData;

use yii\caching\TagDependency;

/**
 * Helper class for pruning data according to a definition
 * 
 * Supports various formats for pruning data:
 * - Simple array of fields: ["title", "author", "body"]
 * - Object syntax: {title: true, body: true}
 * - Nested relations: {author: ["username", "email"]}
 * - Deep nesting: {author: {username: true, profile: {bio: true}}}
 * - Special directives with $ prefix: {$limit: 10}
 * - Matrix block types with _ prefix: {_body: {body: true}}
 */
class Prune
{
  /**
   * Prunes data according to the provided definition
   * 
   * @param mixed $data Data to be pruned (object or array of objects)
   * @param mixed $pruneDefinition Definition that determines what data to keep
   * @return array Pruned data
   */
  public function pruneData($data, $pruneDefinition): array
  {
    // Convert non-array data to array for processing
    if (!is_array($data) || empty($data)) {
      $data = [$data];
    }
    
    $pruneDefinition = $this->normalizePruneDefinition($pruneDefinition);
    $prunedData = [];

    foreach ($data as $index => $object) {
      // Step into each element (or object) and prune it according to the $pruneDefinition
      $prunedData[$index] = $this->pruneObject($object, $pruneDefinition);
    }

    return $prunedData;
  }

  /**
   * Normalizes the prune definition to a consistent format
   * 
   * @param mixed $pruneDefinition Definition to normalize
   * @return array Normalized definition
   */
  private function normalizePruneDefinition($pruneDefinition): array
  {
    // If $pruneDefinition is a string, convert it to an array
    if (is_string($pruneDefinition)) {
      $pruneDefinition = json_decode($pruneDefinition, true);
      // If JSON parsing failed, treat as a single field name
      if (json_last_error() !== JSON_ERROR_NONE) {
        $pruneDefinition = [$pruneDefinition => true];
      }
    }

    // If $pruneDefinition is a non-associative array,
    // Convert it to an associative array with { item: true }
    if (is_array($pruneDefinition) && !$this->isAssociativeArray($pruneDefinition)) {
      $pruneDefinition = array_fill_keys($pruneDefinition, true);
    } else if (!is_array($pruneDefinition) || empty($pruneDefinition)) {
      $pruneDefinition = [$pruneDefinition => true];
    }

    // Loop over each item in $pruneDefinition and recursively normalize each item
    foreach ($pruneDefinition as $key => $value) {
      if (is_bool($value) || is_int($value) || is_string($value) || is_null($value) || is_float($value)) {
        continue;
      }
      if (is_array($value) || is_object($value)) {
        $pruneDefinition[$key] = $this->normalizePruneDefinition($value);
      } else {
        $pruneDefinition[$key] = true; // Default to true for unrecognized values
      }
    }
    
    return $pruneDefinition;
  }

  /**
   * Prunes an object according to the provided definition
   * 
   * @param mixed $object Object to prune
   * @param array $pruneDefinition Definition of what to include
   * @return array Pruned object data or error information
   */
  public function pruneObject($object, $pruneDefinition): array
  {
    if (!is_object($object)) {
      return ['error' => '$object is not an object'];
    }

    // Extract specials from pruneDefinition
    list($pruneDefinition, $specials) = $this->extractSpecials($pruneDefinition);

    // For ElementQuery, handle all elements returned by the query
    if ($object instanceof ElementQuery) {
      return $this->processElementQuery($object, $pruneDefinition, $specials);
    }

    // For other objects, handle them directly
    return $this->processPruneDefinition($object, $pruneDefinition);
  }

  /**
   * Extracts special directives from the prune definition
   * 
   * @param mixed $pruneDefinition Original prune definition
   * @return array [pruneDefinition, specials]
   */
  private function extractSpecials($pruneDefinition): array
  {
    // If $pruneDefinition is not an array, return it as-is
    if (!is_array($pruneDefinition)) return [$pruneDefinition, []];
    
    $specials = [];
    foreach ($pruneDefinition as $key => $value) {
        if (strpos($key, '$') === 0) {  // Special keys start with '$'
            $specials[substr($key, 1)] = $value;
            unset($pruneDefinition[$key]);
        }
    }
    return [$pruneDefinition, $specials];
  }

  /**
   * Processes an ElementQuery according to the prune definition
   * 
   * @param ElementQuery $elementQuery Query to process
   * @param array $pruneDefinition Definition determining what to keep
   * @param array $specials Special directives
   * @return array Processed data
   */
  private function processElementQuery($elementQuery, $pruneDefinition, $specials = []): array
  {
    // Apply any special directives to the query before fetching results
    $elementQuery = $this->applySpecials($elementQuery, $specials);

    $cacheKey = md5(serialize([$elementQuery, $pruneDefinition]));

    $cachedResult = Craft::$app->getCache()->get($cacheKey) ?: null;
    if ($cachedResult !== null) {
      return $cachedResult;
    }

    $result = [];
    foreach ($elementQuery->all() as $element) {
      $result[] = $this->processPruneDefinition($element, $pruneDefinition);
    }

    $dependency = new TagDependency();
    $dependency->tags[] = 'pruneData';
    Craft::$app->getCache()->set($cacheKey, $result, null, $dependency);

    return $result;
  }

  /**
   * Processes an object according to the prune definition
   * 
   * @param object $object Object to process
   * @param array $pruneDefinition Definition determining what to keep
   * @return array Processed data
   */
  private function processPruneDefinition($object, $pruneDefinition, array &$relatedElementIds = []): array
  {
        $pruningElement = $object instanceof Element && isset($object->id);
        $cacheKey = null;
        // Read from cache if possible
        if ($pruningElement) {
            try {
                $cacheKey = md5('prune:' . get_class($object) . ':' . $object->id . ':' . serialize($pruneDefinition));
                $cached = Craft::$app->getCache()->get($cacheKey);
                if ($cached !== false) {
                    return $cached;
                }
            } catch (\Exception $e) {
                // Log cache read error and continue with normal processing
                Craft::error('Cache read failed: ' . $e->getMessage(), __METHOD__);
            }
        }

        $result = [];
        foreach ($pruneDefinition as $field => $details) {
            // Extract specials from pruneDefinition
            list($details, $specials) = $this->extractSpecials($details);
            $result[$field] = $this->getProperty($object, $field, $details, $specials, $relatedElementIds);
        }

        // --- Caching result with TagDependency if $object is an Element ---
            $elementIds = array_merge([$object->id], $relatedElementIds);
            $tags = array_map(function($id) { return 'element::' . $id; }, array_unique($elementIds));
            $dependency = new TagDependency(['tags' => $tags]);
            Craft::$app->getCache()->set($cacheKey, $result, null, $dependency);
        if ($pruningElement) {
        }
        // ---------------------------------------------------------------

        return $result;
    }

  /**
   * Gets a property from an object according to the prune definition
   * 
   * @param object $object Object to get property from
   * @param string $definitionHandle Property name
   * @param mixed $definitionValue Definition determining what to keep
   * @param array $specials Special directives
   * @return mixed Property value, possibly pruned
   */
  private function getProperty($object, $definitionHandle, $definitionValue, $specials = [], array &$relatedElementIds = [])
  {
    if ($definitionValue == false) return null;

    if (!is_object($object)) return null;
    $fieldValue = $this->getFieldValue($object, $definitionHandle, $specials);

    // Handle Laravel Collection or Craft ElementCollection
    if (
      (is_object($fieldValue) && 
        (is_a($fieldValue, 'Illuminate\\Support\\Collection') || is_a($fieldValue, 'craft\\elements\\ElementCollection')))
    ) {
      $fieldValue = $fieldValue->all();
    }

    if (is_scalar($fieldValue) || is_null($fieldValue)) {
      return $fieldValue;
    }

    if (is_array($fieldValue)) {
      $isArrayOfElements = !empty($fieldValue);
      foreach ($fieldValue as $item) {
        if (!($item instanceof Element)) {
          $isArrayOfElements = false;
          break;
        }
      }
      if ($isArrayOfElements) {
        // Add all element IDs to relatedElementIds
        foreach ($fieldValue as $el) {
          if ($el instanceof Element && isset($el->id)) {
            $relatedElementIds[] = $el->id;
          }
        }
        return $this->processElementArray($fieldValue, $definitionValue);
      }
      return $fieldValue;
    }

    if ($fieldValue instanceof Element) {
      if (isset($fieldValue->id)) {
        $relatedElementIds[] = $fieldValue->id;
      }
      return $this->pruneObject($fieldValue, $definitionValue);
    }

    if ($fieldValue instanceof ElementQuery) {
      $relatedElementObjectPruneDefinition = array();
      if (is_array($definitionValue)) {
        if ($this->isAssociativeArray($definitionValue)) {
          $relatedElementObjectPruneDefinition = $definitionValue;
        } else {
          foreach ($definitionValue as $nestedPropertyKey) {
            $relatedElementObjectPruneDefinition[$nestedPropertyKey] = true;
          }
        }
      } else {
        $relatedElementObjectPruneDefinition[$definitionValue] = true;
      }
      return $this->pruneObject($fieldValue, $relatedElementObjectPruneDefinition);
    }

    if (is_object($fieldValue)) {
      if ($fieldValue instanceof HtmlFieldData) {
        return $fieldValue;
      }
      if ($definitionValue === true) {
        return $this->serializeObject($fieldValue);
      }
      return $this->pruneObject($fieldValue, $definitionValue);
    }

    return $fieldValue;
  }

  /**
   * Process an array of Element objects (usually Matrix blocks)
   * 
   * @param array $elements Array of Element objects to process
   * @param mixed $definitionValue Definition determining what to keep
   * @return array Processed array of elements
   */
  private function processElementArray($elements, $definitionValue): array
  {
    // Accept arrays or collections
    if (
      is_object($elements) && 
      (is_a($elements, 'Illuminate\\Support\\Collection') || is_a($elements, 'craft\\elements\\ElementCollection'))
    ) {
      $elements = $elements->all();
    }
    $result = [];
    
    // Check if we're dealing with Matrix block types (keys with _ prefix)
    $hasMatrixTypeDefinitions = $this->isAssociativeArray($definitionValue) && 
                                $this->allArrayKeysAreUnderscored($definitionValue);
    
    foreach ($elements as $key => $element) {
      // If we have specific Matrix block type definitions
      if ($hasMatrixTypeDefinitions) {
        $processed = false;
        
        // Try to find a matching definition for this block type
        foreach ($definitionValue as $underscoredElementType => $typePruneDefinition) {
          if (isset($element->type) && $element->type->handle === ltrim($underscoredElementType, '_')) {
            $result[$key] = $this->pruneObject($element, $typePruneDefinition);
            $processed = true;
            break;
          }
        }
        
        // If no specific definition was found for this block type,
        // Skip the block entirely
        if (!$processed) {
          continue;
        }
      } else {
        // Standard case - apply same definition to all elements
        $result[$key] = $this->pruneObject($element, $definitionValue);
      }
    }
    
    return $result;
  }

  /**
   * Serializes an object for output
   * 
   * @param object $object Object to serialize
   * @return mixed Serialized object data
   */
  public function serializeObject($object)
  {
    // Safely check if serialize() exists before calling it
    if (method_exists($object, 'serialize')) {
      return $object->serialize();
    } elseif (method_exists($object, 'toArray')) {
      // Try toArray() as a common alternative
      return $object->toArray();
    } elseif (method_exists($object, '__toString')) {
      // Try __toString() as a fallback
      return (string)$object;
    } else {
      // If no serialization method exists, convert to array of properties
      return get_object_vars($object);
    }
  }

  /**
   * Gets a field value from an object
   * 
   * @param object $object Object to get value from
   * @param string $definitionHandle Field name
   * @param array $specials Special directives
   * @return mixed Field value
   */
  function getFieldValue($object, $definitionHandle, $specials = [])
  {
    $fieldValue = null;

    // IMPORTANT: This is the original implementation - it uses isset() which is key for Craft models
    if (is_object($object) && isset($object->$definitionHandle)) {
      $fieldValue = $object->$definitionHandle;
    } else {
      try {
        $fieldValue = $object[$definitionHandle];
      } catch (\Throwable $e) {
        // Skip if array access fails
      }
    }

    // Special handling for Craft Elements and ElementQueries
    if (($fieldValue instanceof Element) && method_exists($object, 'canGetProperty') && $object->canGetProperty($definitionHandle)) {
      $fieldValue = $object->$definitionHandle;
    } else if ($fieldValue instanceof ElementQuery) {
      $methodCall = $object->$definitionHandle;
      $methodCall = $this->applySpecials($methodCall, $specials);
      $fieldValue = $methodCall->all();
    } else if (isset($object, $definitionHandle)) {
      $fieldValue = $object->$definitionHandle;
    }

    return $fieldValue;
  }

  /**
   * Checks if an array is associative
   * 
   * @param mixed $arr Array to check
   * @return bool True if array is associative
   */
  function isAssociativeArray($arr): bool
  {
    // Non-arrays can't be associative
    if (!is_array($arr)) return false;
    
    // Empty arrays are considered associative by convention
    if (empty($arr)) return true;
    
    // If keys are not sequential integers starting from 0, it's associative
    return array_keys($arr) !== range(0, count($arr) - 1);
  }

  /**
   * Checks if all array keys are underscored
   * 
   * @param array $arr Array to check
   * @return bool True if all keys start with underscore
   */
  private function allArrayKeysAreUnderscored($arr): bool
  {
    if (!is_array($arr) || empty($arr)) {
      return false;
    }
    
    $keys = array_keys($arr);
    foreach ($keys as $key) {
      if (!is_string($key) || strpos($key, '_') !== 0) {
        return false;
      }
    }
    return true;
  }

  /**
   * Applies special directives to a method call
   * 
   * @param mixed $methodCall Method call to modify
   * @param array $specials Special directives to apply
   * @return mixed Modified method call
   */
  private function applySpecials($methodCall, $specials)
  {
    foreach ($specials as $specialHandle => $specialValue) {
      if (method_exists($methodCall, $specialHandle)) {
        $methodCall = $methodCall->$specialHandle($specialValue);
      }
    }
    return $methodCall;
  }
}

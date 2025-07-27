# Copilot Instructions for Craft Prune

This project is a Composer package for the Craft CMS ecosystem, focused on pruning fields or property values from objects, especially Craft Elements and related data structures.

## Architecture & Key Concepts

- **Main Class:** All pruning logic is in `src/Prune.php` (`rareform\Prune`). This class provides methods to recursively prune data from objects/arrays according to a flexible definition.
- **Prune Definitions:** Prune definitions can be arrays, associative arrays, or deeply nested objects. Special keys:
  - `$` prefix: Special query directives (e.g., `$limit` for limiting results)
  - `_` prefix: Matrix block type selectors (e.g., `_body` for a specific block type)
- **Supported Types:** Handles Craft Elements, ElementQuery, Laravel Collections, and arrays. Related elements are recursively pruned.
- **Caching:** Uses Craft's cache with tag dependencies for efficient repeated pruning of Elements.

## Usage Patterns

- Use `Prune->pruneData($data, $pruneDefinition)` for batch pruning, or `Prune->pruneObject($object, $pruneDefinition)` for a single object.
- Prune definitions can be simple (list of fields) or complex (nested, with special keys). See `README.md` for examples.
- For Matrix fields, use underscored keys to target block types and `$` keys for query traits.

## Developer Workflows

- **Install:** `composer install`
- **Test:** No test suite is present by default. Add tests as needed.
- **Debug:** Use `Craft::dd()` or `Craft::error()` for debugging in Craft context.
- **Dependencies:** Relies on Craft CMS, Yii, and may interact with Laravel Collections if present.

## Project Conventions

- **Field Access:** Always use `getFieldValue()` for property/field access to support both object and array-like data.
- **Serialization:** Use `serializeObject()` to convert objects to arrays/strings for output.
- **Specials Extraction:** Use `extractSpecials()` to separate special query directives from prune definitions.
- **Matrix Handling:** Use `processElementArray()` for arrays of Elements, especially for Matrix blocks.

## Key Files

- `src/Prune.php`: All core logic and patterns are here.
- `README.md`: Usage examples and documentation.

## Example

```php
$pruned = (new Prune())->pruneData($entry, [
  'title' => true,
  'author' => ['username', 'email'],
  'contentBlocks' => [
    '$limit' => 5,
    '_body' => ['body' => true],
  ],
]);
```

## When in Doubt

- Follow the patterns in `src/Prune.php`.
- Reference the `README.md` for usage and definition structure.
- Use Craft's cache/tag dependency patterns for performance.

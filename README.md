# Craft Prune

A simple Composer package for the Craft CMS ecosystem to prune fields or property values from objects.

## Installation

You can install the package via composer:

```bash
composer require rareform/craft-prune
```

## Usage

```php
use rareform\Prune;

// Basic usage: simply pass an array of fields
$prunedData = new Prune($data, ["title", "author", "body", "url", "featuredImage"]);

// Advanced object syntax
$prunedData = new Prune($data, [
    'title' => true,
    'id' => true,
    'uri' => true,
    // Related fields simple array syntax
    'author' => ['username', 'email'],
    // Related fields object syntax
    'mainImage' => [
        'url' => true,
        'uploader' => [
            // Nested related fields
            'email' => true,
            'username' => true,
        ],
    ],
    // Matrix fields
    'contentBlocks' => [
        // Denote query traits with $ prefix
        '$limit' => 10,
        // Designate distinct prune fields per type with _ prefix
        '_body' => [
            'body' => true,
            'intro' => true,
        ],
        '_fullWidthImage' => [
            'image' => ['url', 'alt'],
        ],
    ],
    // Plain arrays, such as a JSON or Table field's value
    'profile' => [
        'value' => [
            'hometown' => true,
            'school' => ['name', 'city'],
        ],
    ],
]);
```

### Plain arrays

A field whose value is a plain array (a JSON field's `value`, a Table field's rows) is narrowed like anything else:

- An associative array keeps only the keys the definition names. A key the array doesn't have comes back as `null`.
- A list has the definition applied to each item, so `'rows' => ['name', 'city']` keeps those two keys on every row.
- `true` keeps the array whole.

Before 0.4.2 a nested definition on a plain array was ignored and the whole array was returned, including keys the definition left out. If you prune JSON or Table fields that hold anything private, upgrade. Results cached by earlier versions are not reused.

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
]);
```

# Sprites

8-bit pixel art sprite generator using Cellular Automata, suitable for avatars.

A PHP port of [danielgjackson/sprite](https://github.com/danielgjackson/sprite) (JavaScript), which is itself based on
[Sprites-as-a-Service](https://github.com/ljvmiranda921/sprites-as-a-service) by Lj V. Miranda. For the same seed,
this produces byte-for-byte the same `.bmp` data as the JavaScript version.

## Install

```
composer require gazugafan/sprites
```

Requires PHP 8.0 or later. `ext-mbstring` (or `iconv`) is recommended so non-ASCII seeds hash identically to the JavaScript original.

## Usage

```php
<?php
require 'vendor/autoload.php';

use Gazugafan\Sprites\Sprite;

// Raw .bmp data (a binary string)
$bmp = Sprite::generate($seed)->magnify(12)->asBitmapData();

// A data: URI, with a transparent background
$uri = Sprite::generate($seed)->magnify(12)->asDataUri(true);

// Serve it directly
header('Content-Type: image/bmp');
echo Sprite::generate($seed)->magnify(12)->asBitmapData(true);
```

`generate($seed, $monochrome = false)` takes a UTF-8 string seed; with no seed the current time is used.

When displaying magnified pixel art in a browser, use the CSS:

```css
img { image-rendering: pixelated; }
```

Note: 32-bit (alpha) BMPs are understood by browsers and most image libraries, but not by PHP's GD extension.
Use `asBitmapData(false)` if you need to load the result with GD.

## Licence

MIT. Original work copyright (c) 2020 Daniel Jackson; see [LICENSE](LICENSE).

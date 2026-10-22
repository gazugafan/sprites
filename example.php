<?php
// Example usage of Sprite.php.
//
//   Web:  php -S localhost:8000   then open  http://localhost:8000/example.php?seed=hello
//   CLI:  php example.php hello > hello.bmp

// Run `composer install` (or `composer dump-autoload`) first.
require __DIR__ . '/vendor/autoload.php';

use Gazugafan\Sprites\Sprite;

if (PHP_SAPI === 'cli') {
	$seed = $argv[1] ?? null;
	fwrite(STDOUT, Sprite::generate($seed)->magnify(12)->asBitmapData(true));
	exit;
}

$seed = (isset($_GET['seed']) && $_GET['seed'] != '') ? (string)$_GET['seed'] : null;

// Serve the image itself (transparent background): example.php?seed=hello&img=1
if (isset($_GET['img'])) {
	header('Content-Type: image/bmp');
	echo Sprite::generate($seed)->magnify(12)->asBitmapData(true);
	exit;
}

// Or embed it in the page as a data: URI
$uri = Sprite::generate($seed)->magnify(12)->asDataUri(true);
?>
<!doctype html>
<meta charset="utf-8">
<title>Sprite</title>
<style>img { image-rendering: pixelated; }</style>
<form>
	Seed: <input name="seed" value="<?= htmlspecialchars($seed ?? '', ENT_QUOTES) ?>" placeholder="random">
	<button>Generate</button>
</form>
<img src="<?= $uri ?>" alt="sprite">

<?php
declare(strict_types=1);

namespace Gazugafan\Sprites;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 8-bit pixel sprite generator (suitable for avatars).
 *
 * PHP port of the JavaScript "sprite" library by Daniel (Dan) Jackson:
 *   https://github.com/danielgjackson/sprite
 *
 * Which is itself based on the technique used by Lj V. Miranda in
 * "Sprites-as-a-Service": https://github.com/ljvmiranda921/sprites-as-a-service
 *
 * The port is deliberately faithful: for the same seed it produces byte-for-byte
 * the same .bmp data as the JavaScript original (the hash and PRNG emulate
 * JavaScript's 32-bit integer semantics and UTF-16 string handling).
 *
 * Usage:
 *   use Gazugafan\Sprites\Sprite;
 *
 *   $bmp = Sprite::generate($seed)->magnify(12)->asBitmapData();   // raw .bmp bytes
 *   $uri = Sprite::generate($seed)->asDataUri();                   // data: URI
 *
 * MIT License
 *
 * Copyright (c) 2020 Daniel Jackson
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
class Sprite
{
	public int $width;
	public int $height;

	/** @var array<int, int|float|array> Cell values, row-major. */
	public array $values;

	/** @var array<int, array{0:int,1:int,2:int,3:int}> Gradient stops as [R,G,B,A]. */
	public array $colors;

	public ?string $seed;

	public function __construct(int $width, int $height, ?array $values = null, ?string $seed = null)
	{
		$this->colors = [[0, 0, 0, 0xff], [0xff, 0xff, 0xff, 0xff]];
		$this->width = $width;
		$this->height = $height;
		$this->values = ($values === null)
			? array_fill(0, $width * $height, 0)
			: array_values($values); // copy
		$this->seed = $seed;
	}

	// ------------------------------------------------------------------
	// Hash / PRNG (JavaScript 32-bit integer semantics)
	// ------------------------------------------------------------------

	/** Wrap to a signed 32-bit integer (JavaScript's ToInt32). */
	private static function toInt32(int $v): int
	{
		$v &= 0xffffffff;
		return ($v >= 0x80000000) ? $v - 0x100000000 : $v;
	}

	/** The UTF-16 code units of a UTF-8 string, as JavaScript's charCodeAt() would see them. */
	private static function utf16Units(string $key): array
	{
		if ($key === '') {
			return [];
		}
		if (function_exists('mb_convert_encoding')) {
			$utf16 = mb_convert_encoding($key, 'UTF-16LE', 'UTF-8');
		} elseif (function_exists('iconv')) {
			$utf16 = iconv('UTF-8', 'UTF-16LE//IGNORE', $key);
		} else {
			return array_values(unpack('C*', $key)); // Fallback: bytes (identical for ASCII)
		}
		return ($utf16 === false || $utf16 === '') ? [] : array_values(unpack('v*', $utf16));
	}

	/** Jenkins's "one_at_a_time" hash; returns an unsigned 32-bit value. */
	private static function hash(string $key): int
	{
		$m = 0xffffffff;
		$hash = 0;
		foreach (self::utf16Units($key) as $c) {
			$hash = ($hash + $c) & $m;
			$hash = ($hash + (($hash << 10) & $m)) & $m;
			$hash ^= ($hash >> 6);
		}
		$hash = ($hash + (($hash << 3) & $m)) & $m;
		$hash ^= ($hash >> 11);
		$hash = ($hash + (($hash << 15) & $m)) & $m;
		return $hash;
	}

	/** mwc1616 PRNG: seed state. */
	private static function rngSeed(int $value): array
	{
		return [($value >> 16) & 0xffff, $value & 0xffff];
	}

	/** mwc1616 PRNG: advance the state (by reference) and return the next value (signed 32-bit). */
	private static function rngNext(array &$state): int
	{
		// Both states stay below 2^31, so no 32-bit wrapping is needed here.
		$state[0] = 18030 * ($state[0] & 0xffff) + ($state[0] >> 16);
		$state[1] = 30903 * ($state[1] & 0xffff) + ($state[1] >> 16);
		return self::toInt32(($state[0] << 16) + ($state[1] & 0xffff));
	}

	// ------------------------------------------------------------------
	// Bitmap encoding
	// ------------------------------------------------------------------

	/**
	 * Generate .bmp file data from a list of [R,G,B] or [R,G,B,A] pixels.
	 * Opaque images use a 24-bit BITMAPCOREHEADER; images with alpha use a
	 * 32-bit BITMAPV4HEADER (required for Firefox to honour the alpha channel).
	 */
	private static function bitmapGenerate(array $data, int $width, int $height, bool $alpha = false): string
	{
		$bitsPerPixel = $alpha ? 32 : 24;
		$fileHeaderSize = 14;
		$bmpHeaderSize = $alpha ? 108 : 12;                                // BITMAPV4HEADER : BITMAPCOREHEADER
		$stride = 4 * intdiv($width * intdiv($bitsPerPixel + 7, 8) + 3, 4); // Byte width of each line
		$biSizeImage = $stride * abs($height);
		$bfOffBits = $fileHeaderSize + $bmpHeaderSize;
		$bfSize = $bfOffBits + $biSizeImage;

		// 14-byte BITMAPFILEHEADER
		$out = 'BM'
			. pack('V', $bfSize)    // bfSize
			. pack('v', 0)          // bfReserved1
			. pack('v', 0)          // bfReserved2
			. pack('V', $bfOffBits); // bfOffBits

		if (!$alpha) {
			// 12-byte BITMAPCOREHEADER
			$out .= pack('V', $bmpHeaderSize)
				. pack('v', $width)
				. pack('v', $height & 0xffff)
				. pack('v', 1)              // biPlanes
				. pack('v', $bitsPerPixel); // biBitCount
		} else {
			// 108-byte BITMAPV4HEADER
			$out .= pack('V', $bmpHeaderSize)
				. pack('V', $width)
				. pack('V', $height & 0xffffffff)
				. pack('v', 1)              // biPlanes
				. pack('v', $bitsPerPixel)  // biBitCount
				. pack('V', 3)              // biCompression (BI_BITFIELDS)
				. pack('V', $biSizeImage)
				. pack('V', 2835)           // biXPelsPerMeter
				. pack('V', 2835)           // biYPelsPerMeter
				. pack('V', 0)              // biClrUsed
				. pack('V', 0)              // biClrImportant
				. pack('V', 0x00ff0000)     // bRedMask
				. pack('V', 0x0000ff00)     // bGreenMask
				. pack('V', 0x000000ff)     // bBlueMask
				. pack('V', 0xff000000)     // bAlphaMask
				. 'Win '                    // bCSType
				. str_repeat("\0", 36)      // CIEXYZTRIPLE (unused for "Win ")
				. pack('V', 0)              // bGammaRed
				. pack('V', 0)              // bGammaGreen
				. pack('V', 0);             // bGammaBlue
		}

		// Pixels: rows are stored bottom-up, each padded to the stride.
		$rows = [];
		for ($y = 0; $y < $height; $y++) {
			$row = '';
			for ($x = 0; $x < $width; $x++) {
				$value = $data[$y * $width + $x];
				$row .= chr($value[2] & 0xff) . chr($value[1] & 0xff) . chr($value[0] & 0xff); // B, G, R
				if ($alpha) {
					$row .= chr($value[3] & 0xff); // A
				}
			}
			$rows[$height - 1 - $y] = str_pad($row, $stride, "\0");
		}
		ksort($rows);

		return $out . implode('', $rows);
	}

	// ------------------------------------------------------------------
	// Cells
	// ------------------------------------------------------------------

	public function fill(int|float|array $value): void
	{
		$this->values = array_fill(0, count($this->values), $value);
	}

	public function fillRandom(string $seed = ''): void
	{
		$rngState = self::rngSeed(self::hash($seed));
		$this->fill(0);
		$count = count($this->values);
		for ($i = 0; $i < $count; $i++) {
			$this->values[$i] = self::rngNext($rngState) & 1;
		}
	}

	public function getCell(int $x, int $y): int|float|array
	{
		return $this->values[$y * $this->width + $x];
	}

	public function setCell(int $x, int $y, int|float|array $value): void
	{
		$this->values[$y * $this->width + $x] = $value;
	}

	public function setColors(array $colors): void
	{
		$this->colors = array_values($colors); // copy
	}

	// ------------------------------------------------------------------
	// Transformations (each returns a new Sprite)
	// ------------------------------------------------------------------

	/** Count of connected cells. */
	public function connectedCells(): Sprite
	{
		$output = new Sprite($this->width, $this->height);
		for ($oy = 0; $oy < $this->height; $oy++) {
			for ($ox = 0; $ox < $this->width; $ox++) {
				$sum = 0;
				for ($iy = -1; $iy <= 1; $iy++) {
					for ($ix = -1; $ix <= 1; $ix++) {
						$y = $oy + $iy;
						$x = $ox + $ix;
						if ($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height) {
							continue;
						}
						if ($iy === 0 && $ix === 0) {
							continue; // ignore middle cell
						}
						$sum += $this->getCell($x, $y);
					}
				}
				$output->setCell($ox, $oy, $sum);
			}
		}
		return $output;
	}

	/** Evolve a new board following "game of life" rules. */
	public function evolve(int $numIterations = 1, float $extinction = 0.125, float $survival = 0.375): Sprite
	{
		$output = new Sprite($this->width, $this->height, $this->values, $this->seed);
		$scale = 8; // count of connected cells
		$survivalCount = floor($survival * $scale);

		for ($iteration = 0; $iteration < $numIterations; $iteration++) {
			$connected = $output->connectedCells();
			foreach ($output->values as $i => $current) {
				$c = $connected->values[$i];
				$reproduction = ($current == 0) && ($c <= $extinction * $scale);
				$stasis = ($current == 1) && ($c == 2 || $c == $survivalCount);
				$output->values[$i] = ($reproduction || $stasis) ? 1 : 0;
			}
		}
		return $output;
	}

	/** Duplicate the board mirrored around the vertical. */
	public function unfold(): Sprite
	{
		$output = new Sprite(2 * $this->width, $this->height, null, $this->seed);
		for ($y = 0; $y < $this->height; $y++) {
			for ($x = 0; $x < $this->width; $x++) {
				$value = $this->getCell($x, $y);
				$output->setCell($x, $y, $value);
				$output->setCell($this->width * 2 - 1 - $x, $y, $value);
			}
		}
		return $output;
	}

	/** Pad around the board. */
	public function pad(int $padding = 1, int|float $value = 1): Sprite
	{
		$output = new Sprite($this->width + 2 * $padding, $this->height + 2 * $padding, null, $this->seed);
		$output->fill($value);
		for ($y = 0; $y < $this->height; $y++) {
			for ($x = 0; $x < $this->width; $x++) {
				$output->setCell($x + $padding, $y + $padding, $this->getCell($x, $y));
			}
		}
		return $output;
	}

	/** Outline. */
	public function outline(): Sprite
	{
		$output = new Sprite($this->width, $this->height, null, $this->seed);
		$output->fill(0.5);
		for ($y = 0; $y < $this->height; $y++) {
			for ($x = 0; $x < $this->width; $x++) {
				$value = $this->getCell($x, $y);
				if ($value == 0) {
					$output->setCell($x, $y, 0);
					if ($x > 0) {
						$output->setCell($x - 1, $y, $this->getCell($x - 1, $y) == 1 ? 1 : 0);
					}
					if ($x < $this->width - 1) {
						$output->setCell($x + 1, $y, $this->getCell($x + 1, $y) == 1 ? 1 : 0);
					}
					if ($y > 0) {
						$output->setCell($x, $y - 1, $this->getCell($x, $y - 1) == 1 ? 1 : 0);
					}
					// NOTE: the original compares against the width here (not the height).
					// Kept as-is for fidelity; generated sprites are square so it makes no difference.
					if ($y < $this->width - 1) {
						$output->setCell($x, $y + 1, $this->getCell($x, $y + 1) == 1 ? 1 : 0);
					}
				}
			}
		}
		return $output->pad(1, 0.5);
	}

	/** Vertical gradient. */
	public function gradient(): Sprite
	{
		$output = new Sprite($this->width, $this->height, null, $this->seed);
		for ($y = 0; $y < $this->height; $y++) {
			for ($x = 0; $x < $this->width; $x++) {
				$v1 = $this->getCell($x, $y);
				$v0 = ($y > 0) ? $this->getCell($x, $y - 1) : $v1;
				$v2 = ($y < $this->height - 1) ? $this->getCell($x, $y + 1) : $v1;
				$output->setCell($x, $y, (($v2 - $v1) + ($v1 - $v0)) / 2);
			}
		}
		return $output;
	}

	/** Rescale values. */
	public function rescale(float $rangeMin = 0.2, float $rangeMax = 0.25): Sprite
	{
		// Same initial values as the original (Number.MAX_VALUE and Number.MIN_VALUE, the latter being the
		// smallest positive double, not the most negative).
		$min = PHP_FLOAT_MAX;
		$max = 4.9e-324;
		foreach ($this->values as $v) {
			if ($v < $min) {
				$min = $v;
			}
			if ($v > $max) {
				$max = $v;
			}
		}
		$output = new Sprite($this->width, $this->height, null, $this->seed);
		$output->values = array_map(
		// fdiv() keeps JavaScript's IEEE semantics (no exception if max == min)
			fn($value) => fdiv($value - $min, $max - $min) * ($rangeMax - $rangeMin) + $rangeMin,
			$this->values
		);
		return $output;
	}

	/** Combine outline with gradient. */
	public function combine(Sprite $gradient): Sprite
	{
		$output = new Sprite($this->width, $this->height, null, $this->seed);
		$output->values = array_map(
			fn($value, $i) => ($value == 0) ? $gradient->values[$i] : $value,
			$this->values,
			array_keys($this->values)
		);
		return $output;
	}

	/**
	 * Color with gradient stops.
	 *
	 * @param array<int, array{0:int,1:int,2:int,3:int}> $stops
	 * @return array<int, array{0:int,1:int,2:int,3:int}> One [R,G,B,A] per cell.
	 */
	public function createColorData(array $stops): array
	{
		$last = count($stops) - 1;
		$output = [];
		foreach ($this->values as $value) {
			$v = $value;
			if ($v < 0) {
				$v = 0;
			}
			if ($v > 1) {
				$v = 1;
			}
			$stopIndex = (int)floor($v * $last);
			$stopProp = ($v - ($stopIndex / $last)) * $last;
			$value0 = $stops[$stopIndex];
			$value1 = ($stopIndex + 1 >= count($stops)) ? $value0 : $stops[$stopIndex + 1];
			$result = [0, 0, 0, 0xff];
			for ($i = 0; $i < 4; $i++) {
				$result[$i] = (int)floor($value0[$i] * (1 - $stopProp) + $value1[$i] * $stopProp);
			}
			$output[] = $result;
		}
		return $output;
	}

	/** Enlarge each cell by 'scale' times. */
	public function magnify(int $scale = 15): Sprite
	{
		$output = new Sprite($this->width * $scale, $this->height * $scale, null, $this->seed);
		$output->setColors($this->colors);
		for ($y = 0; $y < $output->height; $y++) {
			$sy = intdiv($y, $scale);
			for ($x = 0; $x < $output->width; $x++) {
				$output->setCell($x, $y, $this->getCell(intdiv($x, $scale), $sy));
			}
		}
		return $output;
	}

	/**
	 * Debug output a board as text ('#' = 0, ' ' = 1, '.' = 0.5, otherwise <value>).
	 *
	 * @return string[] The lines (also echoed, one per line, when $print is true).
	 */
	public function debugOutput(bool $print = true): array
	{
		$lines = [];
		for ($y = 0; $y < $this->height; $y++) {
			$line = [];
			for ($x = 0; $x < $this->width; $x++) {
				$value = $this->getCell($x, $y);
				if (is_array($value)) {
					$line[] = json_encode($value);
				} elseif ($value == 0) {
					$line[] = '#';
				} elseif ($value == 1) {
					$line[] = ' ';
				} elseif ($value == 0.5) {
					$line[] = '.';
				} else {
					$line[] = sprintf('<%.2f>', $value);
				}
			}
			$lines[] = implode('', $line);
		}
		if ($print) {
			echo implode("\n", $lines), "\n";
		}
		return $lines;
	}

	// ------------------------------------------------------------------
	// Generation and output
	// ------------------------------------------------------------------

	/**
	 * Generate a sprite from a seed (a UTF-8 string). With no seed, the current time is used
	 * (as an ISO 8601 string, as the original does).
	 */
	public static function generate(?string $seed = null, bool $monochrome = false): Sprite
	{
		if ($seed === null) {
			$seed = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
		}

		$board = new Sprite(4, 8, null, $seed);
		$board->fillRandom($seed);
		$board = $board->evolve();
		$board = $board->unfold();
		$board = $board->pad();
		$outline = $board->outline();
		$gradient = $outline->gradient();
		$rescaled = $gradient->rescale();
		$combined = $outline->combine($rescaled);

		$colors = [];
		if (!$monochrome) {
			$colors[] = [0x00, 0x00, 0x00, 0xff];
			$colors[] = [0xf2, 0xf2, 0xf2, 0xff];
			$rngState = self::rngSeed(self::hash($seed));
			$randomByte = function () use (&$rngState): int {
				return self::rngNext($rngState) & 0xff;
			};
			for ($i = 0; $i < 3; $i++) {
				// Array literal elements are evaluated left to right, as in the original.
				$colors[] = [$randomByte(), $randomByte(), $randomByte(), 0xff];
			}
			$colors = array_reverse($colors);
			$colors[2][3] = 0x00; // Transparent background (when generating as alpha)
		} else {
			$colors[] = [0x00, 0x00, 0x00, 0xff]; // inside
			$colors[] = [0x00, 0x00, 0x00, 0x00]; // background (transparent when using alpha)
			$colors[] = [0xff, 0xff, 0xff, 0xff]; // outline
		}
		$combined->setColors($colors);

		return $combined;
	}

	/** The sprite as raw .bmp file data (a binary string). */
	public function asBitmapData(bool $alpha = false): string
	{
		$colorData = $this->createColorData($this->colors);
		return self::bitmapGenerate($colorData, $this->width, $this->height, $alpha);
	}

	/** The sprite as a `data:image/bmp;base64,...` URI. */
	public function asDataUri(bool $alpha = false): string
	{
		return 'data:image/bmp;base64,' . base64_encode($this->asBitmapData($alpha));
	}
}

<?php

namespace App\Services\Boq;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use Throwable;

/** Restricted decimal arithmetic for G9:G732. Never executes Excel macros, links, or PHP eval. */
class BoqVolumeFormula
{
    private array $cache = [];

    private array $visiting = [];

    private int $operations = 0;

    public function __construct(private array $rows) {}

    public function quantity(int $row): string
    {
        try {
            $number = $this->cell($row, 0);
            if ($number->isLessThan(0) || $number->isGreaterThan('999999999999.999')) {
                throw new InvalidArgumentException('Volume harus 0 atau positif, maksimal 12 digit bulat.');
            }

            return (string) $number->toScale(3)->strippedOfTrailingZeros();
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            throw new InvalidArgumentException('Volume tidak dapat dihitung: pembagian nol atau hasil melebihi 3 desimal. Gunakan ROUND atau perbaiki rumus.');
        }
    }

    private function cell(int $row, int $depth): BigRational
    {
        if ($row < 9 || $row > 732 || $depth > 64) {
            throw new InvalidArgumentException('Referensi volume hanya diperbolehkan pada G9:G732, maksimal 64 tingkat.');
        }
        if (isset($this->cache[$row])) {
            return $this->cache[$row];
        }
        if (isset($this->visiting[$row])) {
            throw new InvalidArgumentException('Rumus volume memiliki referensi berulang (circular).');
        }
        $cell = $this->rows[$row][6] ?? ['value' => '', 'type' => 'n', 'formula' => false];
        if ($cell['type'] === 'e') {
            throw new InvalidArgumentException('Sel volume mengandung error Excel.');
        }
        $this->visiting[$row] = true;
        try {
            if ($cell['formula']) {
                $expression = trim($cell['expression'] ?? '');
                if (! $expression || ($cell['shared_formula'] ?? false) || strlen($expression) > 1024) {
                    throw new InvalidArgumentException('Rumus kosong/shared/terlalu panjang belum didukung. Simpan volume sebagai angka langsung.');
                }
                $tokens = $this->tokens(ltrim($expression, '='));
                $position = 0;
                $result = $this->expression($tokens, $position, $depth + 1);
                if ($position !== count($tokens)) {
                    throw new InvalidArgumentException('Sintaks rumus volume belum didukung.');
                }
            } else {
                $value = trim($cell['value']);
                if ($value === '') {
                    $value = '0';
                }
                if (! preg_match('/^[+-]?\d{1,12}(?:\.\d{1,3})?$/D', $value)) {
                    throw new InvalidArgumentException('Volume angka langsung maksimal 12 digit bulat dan 3 desimal.');
                }
                $result = BigRational::of($value);
            }

            return $this->cache[$row] = $result;
        } finally {
            unset($this->visiting[$row]);
        }
    }

    private function tokens(string $expression): array
    {
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($expression)) {
            if (! preg_match('/\G\s*(\$?G\$?[1-9]\d*|ROUND\b|(?:\d{1,15}(?:\.\d{1,6})?|\.\d{1,6})|[()+*\/,%-])/Ai', $expression, $match, 0, $offset)) {
                if (trim(substr($expression, $offset)) === '') {
                    break;
                }
                throw new InvalidArgumentException('Rumus hanya mendukung angka, referensi G9:G732, + − × ÷ %, kurung, dan ROUND.');
            }
            $tokens[] = strtoupper(str_replace('$', '', $match[1]));
            $offset += strlen($match[0]);
            if (count($tokens) > 256) {
                throw new InvalidArgumentException('Rumus volume terlalu kompleks.');
            }
        }

        return $tokens;
    }

    private function expression(array $tokens, int &$position, int $depth): BigRational
    {
        $value = $this->product($tokens, $position, $depth);
        while (in_array($tokens[$position] ?? '', ['+', '-'], true)) {
            $operator = $tokens[$position++];
            $right = $this->product($tokens, $position, $depth);
            $value = $this->bound($operator === '+' ? $value->plus($right) : $value->minus($right));
        }

        return $value;
    }

    private function product(array $tokens, int &$position, int $depth): BigRational
    {
        $value = $this->primary($tokens, $position, $depth);
        while (in_array($tokens[$position] ?? '', ['*', '/'], true)) {
            $operator = $tokens[$position++];
            $right = $this->primary($tokens, $position, $depth);
            $value = $this->bound($operator === '*' ? $value->multipliedBy($right) : $value->dividedBy($right));
        }

        return $value;
    }

    private function primary(array $tokens, int &$position, int $depth): BigRational
    {
        if ($depth > 64 || ++$this->operations > 20000) {
            throw new InvalidArgumentException('Batas perhitungan rumus volume terlampaui.');
        }
        $token = $tokens[$position++] ?? '';
        if (in_array($token, ['+', '-'], true)) {
            $value = $this->primary($tokens, $position, $depth + 1);

            return $token === '-' ? $value->negated() : $value;
        }
        if ($token === '(') {
            $value = $this->expression($tokens, $position, $depth + 1);
            $this->expect($tokens, $position, ')');
        } elseif ($token === 'ROUND') {
            $this->expect($tokens, $position, '(');
            $value = $this->expression($tokens, $position, $depth + 1);
            $this->expect($tokens, $position, ',');
            $digits = $this->expression($tokens, $position, $depth + 1)->toInt();
            $this->expect($tokens, $position, ')');
            if ($digits < -6 || $digits > 6) {
                throw new InvalidArgumentException('ROUND mendukung presisi −6 hingga 6.');
            }
            $factor = BigRational::of(10)->power(max(0, -$digits));
            $value = $value->dividedBy($factor)->toScale(max(0, $digits), RoundingMode::HalfUp)->toBigRational()->multipliedBy($factor);
        } elseif (preg_match('/^G([1-9]\d*)$/D', $token, $match)) {
            $value = $this->cell((int) $match[1], $depth + 1);
        } elseif (is_numeric($token)) {
            $value = BigRational::of(str_starts_with($token, '.') ? '0'.$token : $token);
        } else {
            throw new InvalidArgumentException('Rumus volume tidak lengkap atau belum didukung.');
        }
        while (($tokens[$position] ?? '') === '%') {
            $position++;
            $value = $value->dividedBy(100);
        }

        return $this->bound($value);
    }

    private function expect(array $tokens, int &$position, string $expected): void
    {
        if (($tokens[$position++] ?? '') !== $expected) {
            throw new InvalidArgumentException('Sintaks rumus volume tidak valid; perlu '.$expected.'.');
        }
    }

    private function bound(BigRational $value): BigRational
    {
        if ($value->abs()->isGreaterThan('999999999999999999')) {
            throw new InvalidArgumentException('Hasil antara rumus volume terlalu besar.');
        }

        return $value;
    }
}

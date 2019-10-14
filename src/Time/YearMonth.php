<?php declare(strict_types = 1);
/**
 * This file is part of the Dogma library (https://github.com/paranoiq/dogma)
 *
 * Copyright (c) 2012 Vlasta Neubauer (@paranoiq)
 *
 * For the full copyright and license information read the file 'license.md', distributed with this source code
 */

namespace Dogma\Time;

use Dogma\Check;
use Dogma\Comparable;
use Dogma\Equalable;
use Dogma\Str;
use Dogma\StrictBehaviorMixin;
use Dogma\Time\Format\DateTimeFormatter;
use function explode;
use function implode;

class YearMonth implements Comparable, Equalable
{
    use StrictBehaviorMixin;

    public const DEFAULT_FORMAT = 'Y-m';

    /** @var string */
    private $value;

    /**
     * @param string|\Dogma\Time\Date|\DateTimeInterface $value
     */
    public function __construct($value)
    {
        if ($value instanceof Date || $value instanceof \DateTimeInterface) {
            $this->value = $value->format(self::DEFAULT_FORMAT);
            return;
        }

        try {
            $dateTime = new DateTime($value);
            $this->value = $dateTime->format(self::DEFAULT_FORMAT);
        } catch (\Throwable $e) {
            throw new InvalidDateTimeException($value, $e);
        }
    }

    public static function createFromIntValue(int $value): self
    {
        Check::range($value, 1, 999912);

        $value = Str::padLeft((string) $value, 6, '0');

        return new static(substr($value, 0, 4) . '-' . substr($value, 4, 2));
    }

    public static function createFromComponents(int $year, int $month): self
    {
        return new static($year . '-' . $month . '-01');
    }

    /**
     * @param self $other
     * @return int
     */
    public function compare(Comparable $other): int
    {
        Check::instance($other, self::class);

        return $this->value <=> $other->value;
    }

    /**
     * @param self $other
     * @return bool
     */
    public function equals(Equalable $other): bool
    {
        Check::instance($other, self::class);

        return $this->value === $other->value;
    }

    public function getYear(): int
    {
        return (int) explode('-', $this->value)[0];
    }

    public function getMonth(): int
    {
        return (int) explode('-', $this->value)[1];
    }

    public function getMonthEnum(): Month
    {
        return Month::get((int) explode('-', $this->value)[1]);
    }

    public function getIntValue(): int
    {
        return (int) implode('', explode('-', $this->value));
    }

    public function getStart(): Date
    {
        return new Date($this->value);
    }

    public function getEnd(): Date
    {
        return $this->getStart()->modify('last day of this month');
    }

    public function format(string $format = self::DEFAULT_FORMAT, ?DateTimeFormatter $formatter = null): string
    {
        if ($formatter === null) {
            return $this->getStart()->format($format);
        } else {
            return $formatter->format($this->getStart(), $format);
        }
    }

    public function next(): self
    {
        return new static($this->getStart()->modify('+1 month'));
    }

    public function previous(): self
    {
        return new static($this->getStart()->modify('-1 month'));
    }

}

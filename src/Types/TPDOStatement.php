<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Types;

use Psalm\Context;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Union;

class TPDOStatement extends TNamedObject
{
    protected const PROP_ZERO = '__psalm_rowCountZero';
    protected const PROP_FETCHED = '__psalm_rowCountFetched';

    /** @var bool */
    public $executed = false;

    /** @var ?bool */
    public $hasRows;

    /** @var ?TSqlSelectString */
    public $sqlString;

    public function getKey(bool $include_extra = true): string
    {
        if ($include_extra && $this->extra_types) {
            return $this->value . $this->getQueryKey() . '&' . implode('&', $this->extra_types);
        }

        return $this->value;
    }

    public function getId(bool $nested = false): string
    {
        if ($this->extra_types) {
            return $this->value . $this->getQueryKey() . '&' . implode(
                '&',
                array_map(
                    static function ($type) {
                        return $type->getId(true);
                    },
                    $this->extra_types
                )
            );
        }

        return $this->was_static
            ? $this->value . $this->getQueryKey() . '&static'
            : $this->value . $this->getQueryKey();
    }


    public function canBeFullyExpressedInPhp(int $php_major_version, int $php_minor_version): bool
    {
        return false;
    }

    private function getQueryKey(): string
    {
        $parts = [
            $this->sqlString ? ':SQL(' . $this->sqlString->value . ')' : null,
            $this->executed ? ':executed' : null,
            $this->hasRows === null ? null : ($this->hasRows ? ':hasRows' : ':zeroRows'),
        ];

        return implode('', array_filter($parts));
    }

    public function syncFromContext(Context $context, ?string $var_id): void
    {
        if ($var_id === null) {
            return;
        }

        $zeroType = $context->vars_in_scope[$var_id . '->' . self::PROP_ZERO] ?? new Union([new TBool()]);
        $countFetchedType = $context->vars_in_scope[$var_id . '->' . self::PROP_FETCHED] ?? new Union([new TBool()]);

        if (!$countFetchedType->isTrue()) {
            $this->hasRows = null;
            return;
        }

        $this->hasRows = !$zeroType->isTrue();
    }

    public function syncToContext(Context $context, ?string $var_id): void
    {
        if ($var_id === null) {
            return;
        }

        if ($this->hasRows === null) {
            $zeroType = new Union([new TBool()]);
            $countFetchedType = new Union([new TBool()]);
        } else {
            $zeroType = new Union([$this->hasRows ? new TFalse() : new TTrue()]);
            $countFetchedType = new Union([new TTrue()]);
        }

        $context->vars_in_scope[$var_id . '->' . self::PROP_ZERO] = $zeroType;
        $context->vars_possibly_in_scope[$var_id . '->' . self::PROP_ZERO] = true;

        $context->vars_in_scope[$var_id . '->' . self::PROP_FETCHED] = $countFetchedType;
        $context->vars_possibly_in_scope[$var_id . '->' . self::PROP_FETCHED] = true;
    }
}

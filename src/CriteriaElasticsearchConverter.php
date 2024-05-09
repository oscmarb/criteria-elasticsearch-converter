<?php

namespace Oscmarb\CriteriaElasticsearchConverter;

use Oscmarb\Criteria\Criteria;
use Oscmarb\Criteria\Filter\Condition\ConditionFilter;
use Oscmarb\Criteria\Filter\Condition\FilterOperator;
use Oscmarb\Criteria\Filter\Filter;
use Oscmarb\Criteria\Filter\Logic\AndFilter;
use Oscmarb\Criteria\Filter\Logic\OrFilter;
use Oscmarb\Criteria\Order\CriteriaOrders;
use Oscmarb\Criteria\Pagination\CriteriaLimit;
use Oscmarb\Criteria\Pagination\CriteriaOffset;

class CriteriaElasticsearchConverter
{
    private const RANGE_OPERATOR_CONVERSIONS = [
        FilterOperator::LTE => 'lte',
        FilterOperator::LT => 'lt',
        FilterOperator::GTE => 'gte',
        FilterOperator::GT => 'gt',
    ];

    private array $body;

    /**
     * @param array<string, string> $criteriaToMapFields
     */
    public static function create(string $index, array $criteriaToMapFields): self
    {
        return new self($index, $criteriaToMapFields);
    }

    /**
     * @param array<string, string> $criteriaToMapFields
     */
    protected function __construct(string $index, private array $criteriaToMapFields)
    {
        $this->body = ['index' => $index];
    }

    public function convert(Criteria $criteria): array
    {
        $filters = $criteria->filters()->values();

        if (1 >= count($filters)) {
            $filter = array_values($filters)[0] ?? null;
        } else {
            $filter = AndFilter::create(...$filters);
        }

        $offset = $criteria->offset();
        $limit = $criteria->limit();

        if (null !== $offset) {
            $this->body = array_merge(
                $this->body,
                $this->formatOffset($offset)
            );
        }

        if (null !== $limit) {
            $this->body = array_merge(
                $this->body,
                $this->formatLimit($limit)
            );
        }

        $query = $this->formatFilter($filter);

        if (false === array_key_exists('bool', $query)) {
            $query = ['bool' => ['must' => $query]];
        }

        if (false === empty($query)) {
            $this->body = array_merge(
                $this->body,
                ['query' => $query]
            );
        }

        return array_merge(
            $this->body,
            $this->formatOrders($criteria->orders())
        );
    }

    protected function formatOffset(CriteriaOffset $offset): array
    {
        return ['from' => $offset->value()];
    }

    protected function formatLimit(CriteriaLimit $limit): array
    {
        return ['size' => $limit->value()];
    }

    protected function formatFilter(Filter $filter): array
    {
        if (true === $filter instanceof AndFilter) {
            return $this->formatAnd($filter);
        }

        if (true === $filter instanceof OrFilter) {
            return $this->formatOr($filter);
        }

        if (true === $filter instanceof ConditionFilter) {
            return $this->formatCondition($filter);
        }

        throw new \RuntimeException('Unknown filter type');
    }

    protected function formatAnd(AndFilter $filter): array
    {
        return [
            'bool' => ['must' => array_map(fn(Filter $filter) => $this->formatFilter($filter), $filter->filters())],
        ];
    }

    protected function formatOr(OrFilter $filter): array
    {
        return [
            'bool' => ['should' => array_map(fn(Filter $filter) => $this->formatFilter($filter), $filter->filters())],
        ];
    }

    protected function formatCondition(ConditionFilter $filter): array
    {
        $value = $filter->value()->value();
        $field = $this->mapFieldValue($filter->field()->value());

        if (true === $filter->operator()->isIn()) {
            $this->ensureValueIsAnArray($value);

            return [
                'terms' => [$field => $value],
            ];
        }

        if (true === $filter->operator()->isNotIn()) {
            $this->ensureValueIsAnArray($value);

            return [
                'bool' => ['must_not' => ['terms' => [$field => $value]]],
            ];
        }

        if (true === is_array($value)) {
            throw new \RuntimeException('Unexpected array value');
        }

        if (true === $filter->operator()->isEqual()) {
            if (null === $value) {
                return ['bool' => ['must_not' => ['exists' => ['field' => $field]]]];
            }

            return ['term' => [$field => $value]];
        }

        if (true === $filter->operator()->isNotEqual()) {
            if (null === $value) {
                return ['exists' => ['field' => $field]];
            }

            return ['bool' => ['must_not' => ['term' => [$field => $value]]]];
        }

        if (null === $value) {
            throw new \RuntimeException('Unexpected null value');
        }

        if (true === $this->isRangeOperator($filter)) {
            return [
                'range' => [$field => [self::RANGE_OPERATOR_CONVERSIONS[$filter->operator()->value()] => $value]],
            ];
        }

        if (true === $filter->operator()->isContains()) {
            return [
                'wildcard' => [$field => "*$value*"],
            ];
        }

        if (true === $filter->operator()->isEndsWith()) {
            return [
                'wildcard' => [$field => "$value*"],
            ];
        }

        if (true === $filter->operator()->isStartsWith()) {
            return [
                'prefix' => [$field => $value],
            ];
        }

        throw new \RuntimeException('Unexpected condition filter operator');
    }

    protected function formatOrders(CriteriaOrders $orders): array
    {
        if (true === $orders->isEmpty()) {
            return [];
        }

        $indexedOrders = [];

        foreach ($orders->values() as $order) {
            $indexedOrders[] = [$this->mapFieldValue($order->orderBy()->value()) => ['order' => $order->orderType()->value()]];
        }

        return [
            'sort' => $indexedOrders,
        ];
    }

    private function mapFieldValue(string $value): string
    {
        return \array_key_exists($value, $this->criteriaToMapFields)
            ? $this->criteriaToMapFields[$value]
            : $value;
    }

    private function isRangeOperator(ConditionFilter $filter): bool
    {
        return array_key_exists($filter->operator()->value(), self::RANGE_OPERATOR_CONVERSIONS);
    }

    private function ensureValueIsAnArray(mixed $value): void
    {
        if (false === is_array($value)) {
            throw new \RuntimeException('Operator value should receive an array value');
        }
    }
}
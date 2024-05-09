<?php

namespace Oscmarb\CriteriaElasticsearchConverter\Tests;

use Oscmarb\Criteria\CriteriaBuilder;
use Oscmarb\Criteria\Filter\Condition\ConditionFilterFactory;
use Oscmarb\Criteria\Filter\Logic\OrFilter;
use Oscmarb\CriteriaElasticsearchConverter\CriteriaElasticsearchConverter;
use PHPUnit\Framework\TestCase;

final class CriteriaElasticsearchConverterTest extends TestCase
{
    public function testShouldConvertCriteriaToElasticSearchQuery(): void
    {
        $fieldMappings = [
            'childField' => 'first_child_table.field',
            'secondChildField' => 'second_child_table.field',
            'oldOrder' => 'newOrder',
        ];
        $converter = CriteriaElasticsearchConverter::create('index_name', $fieldMappings);

        $criteria = CriteriaBuilder::create()
            ->addFilter(
                OrFilter::create(
                    ConditionFilterFactory::createEqual('childField', 'value'),
                    ConditionFilterFactory::createEqual('secondChildField', null),
                )
            )
            ->addFilter(ConditionFilterFactory::createNotEqual('oldOrder', null))
            ->addFilter(ConditionFilterFactory::createIn('value', ['1', '2']))
            ->addFilter(ConditionFilterFactory::createNotIn('value', [1, 2]))
            ->addFilter(ConditionFilterFactory::createContains('value', 'value'))
            ->addFilter(ConditionFilterFactory::createStartsWith('value', 'value'))
            ->addFilter(ConditionFilterFactory::createEndsWith('value', 'value'))
            ->addFilter(ConditionFilterFactory::createEqual('value', 'value'))
            ->addFilter(ConditionFilterFactory::createNotEqual('value', 'value'))
            ->addFilter(ConditionFilterFactory::createGt('value', 1))
            ->addFilter(ConditionFilterFactory::createGte('value', 1))
            ->addFilter(ConditionFilterFactory::createLt('value', 1))
            ->addFilter(ConditionFilterFactory::createLte('value', 1))
            ->addAscOrder('oldOrder')
            ->addDescOrder('regularField')
            ->setLimit(20)
            ->setOffset(5)
            ->createCriteria();

        self::assertEquals(self::sanitizeJson($this->expectedJsonQuery()), self::sanitizeJson(\Safe\json_encode($converter->convert($criteria))));
    }

    private function expectedJsonQuery(): string
    {
        return '{
  "index": "index_name", 
  "from": 5,
  "size": 20,
  "query": {
    "bool": {
      "must": [
        {"bool": {
          "should": [
            {"term": {"first_child_table.field": "value"}},
            {"bool": {"must_not": {"exists": {"field": "second_child_table.field"}}}}
          ]
        }},
        {"exists": {"field": "newOrder"}},
        {"terms": {"value": ["1", "2"]}},
        {"bool": {"must_not": {"terms": {"value": [1, 2]}}}},
        {"wildcard": {"value": "*value*"}},
        {"prefix": {"value": "value"}},
        {"wildcard": {"value": "value*"}},
        {"term": {"value": "value"}},
        {"bool": {"must_not": {"term": {"value": "value"}}}},
        {"range": {"value": {"gt": 1}}},
        {"range": {"value": {"gte": 1}}},
        {"range": {"value": {"lt": 1}}},
        {"range": {"value": {"lte": 1}}}
      ]
    }
  },
  "sort": [
    {"newOrder": {"order": "asc"}},
    {"regularField": {"order": "desc"}}
  ]
}
        ';
    }

    private static function sanitizeJson(string $json): string
    {
        return str_replace(["\n", "\t", ' '], '', $json);
    }
}
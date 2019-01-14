<?php

namespace Wangzd\OpenSearch;

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use OpenSearch\Client\DocumentClient;
use OpenSearch\Client\OpenSearchClient;
use OpenSearch\Client\SuggestClient;
use OpenSearch\Util\SuggestParamsBuilder;
use OpenSearch\Client\SearchClient;
use OpenSearch\Generated\Common\OpenSearchResult;
use OpenSearch\Util\SearchParamsBuilder;

class OpenSearchEngine extends Engine
{
    protected $client;
    protected $documentClient;
    protected $searchClient;
    protected $suggestClient;
    protected $config;
    protected $suggestName;
    protected $appName;

    public function __construct(Repository $config)
    {
        $accessKeyID          = $config->get('scout.opensearch.accessKey');
        $accessKeySecret      = $config->get('scout.opensearch.accessSecret');
        $host                 = $config->get('scout.opensearch.host');
        $option['debug']      = $config->get('scout.opensearch.debug');
        $option['timeout']    = $config->get('scout.opensearch.timeout');


        $this->appName        = $config->get('scout.opensearch.appName');
        $this->suggestName    = $config->get('scout.opensearch.suggestName');

        $this->client         = new OpenSearchClient($accessKeyID, $accessKeySecret, $host, $option);
        $this->documentClient = new DocumentClient($this->client);
        $this->searchClient   = new SearchClient($this->client);
        $this->suggestClient  = new SuggestClient($this->client);
    }

    public function update($models){}

    public function delete($models){}

    public function search(Builder $builder){}

    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->getOpenSearch($builder, ($page - 1) * $perPage, $perPage);
    }

    public function mapIds($results)
    {
        $result = $this->checkResults($results);
        if (array_get($result, 'result.num', 0) === 0) {
            return collect();
        }

        return collect(array_get($result, 'result.items'))->pluck('fields.id')->values();
    }

    /**
     * @param mixed  $results
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return Collection|\Illuminate\Support\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        $result = $this->checkResults($results);
        if (array_get($result, 'result.num', 0) === 0) {
            return collect();
        }
        $keys   = collect(array_get($result, 'result.items'))->pluck('fields.' . $model->getSearchableFields())->values()->all();
        $models = $model->whereIn($model->getQualifiedKeyName(), $keys)->get()->keyBy($model->getKeyName());
        $res = collect(array_get($result, 'result.items'))->map(function ($item) use ($model, $models) {
            $key = $item['fields'][$model->getSearchableFields()]; // todo
            if (isset($models[$key])) {
                return $models[$key];
            }
        })->filter()->values();

        return $res;
    }

    /**
     * @param mixed $results
     *
     * @return mixed
     */
    public function getTotalCount($results)
    {
        $result = $this->checkResults($results);

        return array_get($result, 'result.total', 0);
    }

    /**
     * Get open-search-result
     *
     * @param Builder $builder
     * @param         $from
     * @param         $count
     *
     * @return OpenSearchResult
     */
    protected function getOpenSearch(Builder $builder, $from, $count)
    {
        $params = new SearchParamsBuilder();
        $params->setStart($from);
        $params->setHits($count);
        $params->setAppName($this->appName);
        //设置查询query

        if ($builder->index) {
            $params->setQuery("$builder->index:'$builder->query'");
        } else {
            $params->setQuery("default:'{$builder->query}'");
        }
        $params = new SearchParamsBuilder();
        //设置config子句的start值
        $params->setStart(0);
        //设置config子句的hit值
        $params->setHits(20);
        // 指定一个应用用于搜索
        $params->setAppName('shop');
        // 指定搜索关键词

        // 指定返回的搜索结果的格式为json
        $params->setFormat("fulljson");
        //添加排序字段
        $params->addSort('RANK', SearchParamsBuilder::SORT_DECREASE);


        $res = $this->searchClient->execute($params->build());
        return $res;
    }

    /**
     * For suggestion
     *
     * @param Builder $builder
     *
     * @return OpenSearchResult
     */
    public function getSuggestSearch(Builder $builder)
    {
        $params = SuggestParamsBuilder::build(
                $this->appName,
                $this->suggestName,
                $builder->query, 10
        );

        return $this->suggestClient->execute($params);
    }

    /**
     * @param $results
     *
     * @return mixed
     */
    protected function checkResults($results)
    {
        $result = [];
        if ($results instanceof OpenSearchResult) {
            $result = json_decode($results->result, true);
        }

        return $result;
    }

    public function flush($model)
    {

    }
}

<?php

namespace Wangzd\OpenSearch;

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use OpenSearch\Client\AppClient;
use OpenSearch\Client\DocumentClient;
use OpenSearch\Client\OpenSearchClient;
use OpenSearch\Client\SuggestClient;
use OpenSearch\Util\SuggestParamsBuilder;
use OpenSearch\Client\SearchClient;
use OpenSearch\Generated\Common\OpenSearchResult;
use OpenSearch\Util\SearchParamsBuilder;
use Illuminate\Database\Eloquent\Model;


class OpenSearchEngine extends Engine
{
    protected $client;
    protected $documentClient;
    protected $searchClient;
    protected $suggestClient;
    protected $config;
    protected $suggestName;

    public function __construct(Repository $config)
    {
        $accessKeyID          = $config->get('scout.opensearch.accessKey');
        $accessKeySecret      = $config->get('scout.opensearch.accessSecret');
        $host                 = $config->get('scout.opensearch.host');
        $option['debug']      = $config->get('scout.opensearch.debug');
        $option['timeout']    = $config->get('scout.opensearch.timeout');

        $this->suggestName    = $config->get('scout.opensearch.suggestName');

        $this->client         = new OpenSearchClient($accessKeyID, $accessKeySecret, $host, $option);
        $this->documentClient = new DocumentClient($this->client);
        $this->searchClient   = new SearchClient($this->client);
        $this->suggestClient  = new SuggestClient($this->client);
    }

    public function update($models){
        if(!$models->count()) {
            return false;
        }
        //更新
        $docs = [];

        $models->each(function($model) use (&$docs)
        {

            $item['cmd'] = "ADD";
            $item["fields"] = collect($model->toSearchableArray())->except(['deleted_at']);
            $docs[] = $item;
        });
        $ok = $this->documentClient->push(json_encode($docs),$models->first()->searchableAs(),$models->first()->getTable());
    }

    public function delete($models){
        if(!$models->count()) {
            return false;
        }
        //更新
        $docs = [];

        $models->each(function($model) use (&$docs)
        {

            $item['cmd'] = "delete";
            $item["fields"] = collect($model->toSearchableArray())->except(['deleted_at']);
            $docs[] = $item;
        });
        $ok = $this->documentClient->push(json_encode($docs),$models->first()->searchableAs(),$models->first()->getTable());
    }

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
        $keys   = collect(array_get($result, 'result.items'))->pluck('fields.' . $model->getKeyName())->values()->all();
        $models = $model->whereIn($model->getQualifiedKeyName(), $keys)->get()->keyBy($model->getKeyName());
        $res = collect(array_get($result, 'result.items'))->map(function ($item) use ($model, $models) {
            $key = $item['fields'][$model->getKeyName()]; // todo
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
        //设置config子句的start值

        $params->setStart($from);
        //设置config子句的hit值

        $params->setHits($count);
        $params->setAppName($builder->model->searchableAs());
        if ($builder->index) {
            $params->setQuery("$builder->index:'$builder->query'");
        } else {
            $params->setQuery("default:'{$builder->query}'");
        }
        if (isset($builder->fields)) {
            //设置需返回哪些字段
            $params->setFetchFields($builder->fields);
        }
        if (isset($builder->wheres) && count($builder->wheres)>0) {
            //目前只支持 等于
            foreach ($builder->wheres as $key=>$value) {
                $arr[] = $key . '=' . $value;
            }
            $params->setFilter(implode(' AND ',$arr));
        }
        // 指定返回的搜索结果的格式为json
        $params->setFormat("fulljson");
        //添加排序字段
        if (count($builder->orders) == 0) {
            $params->addSort('RANK', SearchParamsBuilder::SORT_DECREASE);
        }else{
            foreach ($builder->orders as $value) {
                $params->addSort($value['column'], $value['column']=='direction' ? SearchParamsBuilder::SORT_DECREASE : SearchParamsBuilder::SORT_INCREASE);
            }
        }

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

        $this->createOrUpdateApp($model);
        $keyName = $model->getKeyName();
        $appName = $model->searchableAs();
        $table = $model->getTable();
        //创建文档
        $lastId = 0;
        while (true) {
            $data = $model->where($model->getKeyName(),'>',$lastId)->take(100)->get();
            if(!$data->count()) {
                break;
            }
            $docs = [];
            foreach ($data as $item) {

                $tmpAdd['cmd'] = "ADD";
                $tmpAdd["fields"] = collect($item->toSearchableArray())->except(['deleted_at'])->toArray();
                $docs[] = $tmpAdd;
                $lastId = $item->{$keyName};
            };
            $ok = $this->documentClient->push(json_encode($docs),$appName,$table);
            info('成功生成' . count($docs) . "索引...\r\n");
        }
    }

    /**
     * 创建APP
     * @param $model
     * @throws \Exception
     */
    protected function createOrUpdateApp(Model $model) {



        $appClient = new AppClient($this->client);
        $appName = $model->searchableAs();



        $ret = $this->checkResults($appClient->getById($model->searchableAs()));
        if(isset($ret['errors'][0]['code']) && $ret['errors'][0]['code'] == 2001) {
            $schemaTableFields = $this->getSchemaTableFields($model);
            $fields = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
            $tableName = $model->getTable();
            $addApp =
                [
                    "description" => $model->getTable(),
                    "status" => 1,
                    "fetch_fields" => $fields,
                    "type" => "standard",
                    "schema" => [
                        "tables" => [$tableName=> [
                            'fields' => $schemaTableFields,
                            "primary_table" => true,
                            "name" => $tableName
                        ]],
                        "indexes" => [
                            "search_fields" =>[
                                $model->getKeyName() => [
                                    "fields" => [
                                        $model->getKeyName()
                                    ],
                                ],
                                "default" => [
                                    "fields" => $this->getDefaultIndex($schemaTableFields),
                                    "analyzer" => "chn_standard",
                                ]


                            ],
                            "filter_fields" => $this->getFilterFields($schemaTableFields)
                        ],
                        "plugin_info" => [],
                        "route_field" => null
                    ],
                    "quota" => [
                        "doc_size" => 1,
                        "compute_resource" => 20,
                        "spec" => "opensearch.share.common",
//
                    ],
                    "created" => time(),
                    "name" => $appName,
                ]
            ;
//
//

            $ok = $appClient->save(json_encode($addApp));
        }else if(isset($ret['status']) && $ret['status']=='FAIL') {
            $msg = isset($ret['errors'][0]['message']) ? $ret['errors'][0]['message'] : "请求接口出错！！！";
            throw new \Exception($msg);
        }
    }




    public function getSchemaTableFields(Model $model)
    {

        $tableSchema = $model->getConnection()->getDatabaseName();
        $keyName = $model->getKeyName();
        $tableName = $model->getTable();

        function getDataType($type)
        {
            if (in_array($type, ['tinyint', 'smallint', 'int', 'integer', 'bigint'])) {
                $str = 'INT';
            } else if (in_array($type, ['float', 'decimal', 'numeric'])) {
                $str = 'FLOAT';
            } else if (in_array($type, ['double'])) {
                $str = 'DOUBLE';
            } else if (in_array($type, ['char', 'time', 'timestamp', 'year'])) {
                $str = 'LITERAL';
            } else {
                $str = 'TEXT';
            }
            return $str;
        }

        $structure = \DB::select('select column_name as `name`,data_type from information_schema.columns where  table_schema = :table_schema AND table_name = :table_name;', ['table_schema' => $tableSchema, 'table_name' => $tableName]);
        $fields = [];
        foreach ($structure as $item) {
            $item->primary_key = strtolower($keyName) == strtolower($item->name);
            $item->type = getDataType($item->data_type);
            unset($item->data_type);
            $fields[$item->name] = $item;
        }

        return $fields;
    }


    public function getDefaultIndex($schemaTableFields) {
        $defaultIndex = [];
        foreach ($schemaTableFields as $field=>$item) {
            if($item->type=='TEXT') {
                $defaultIndex[] = $field;
            }
        }

        return array_slice($defaultIndex,0,8);
    }

    public function getFilterFields($schemaTableFields){
        $arr = [];
        foreach ($schemaTableFields as $field=>$item) {
            if($item->type!='TEXT') {
                $arr[] = $field;
            }
        }

        return $arr;
    }
}

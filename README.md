# opensearch/laravel-opensearch

基于 laravel/scout 的 OpenSearch 驱动扩展

## Installation

建议使用 composer 方式安装此包

    composer require opensearch/laravel-opensearch

## Usage

1. 在阿里云 申请OpenSearch 功能，获取 access_key access_secret；

2. Laravel 5.5 以下，`config/app.php`  中添加 `service provider`
```
        Wangzd\\OpenSearch\\OpenSearchServiceProvider

    Laravel 5.5 及以上，自动加载 `service provider`，无需手动添加。
```
3.发布配置项;  
```bash
php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"
```
4. 在 config/scout.php 添加配置

    ```
        'opensearch'    => [
                'accessKey'    => env('OPENSEARCH_ACCESS_KEY'),
                'accessSecret' => env('OPENSEARCH_ACCESS_SECRET'),
                'host'         => env('OPENSEARCH_HOST'),
                'debug'        => env('OPENSEARCH_DEBUG'),
                'timeout'      => env('OPENSEARCH_TIMEOUT'),
        ],
    ```

5. 修改 `.env` 配置 

        SCOUT_DRIVER=opensearch
        SCOUT_PREFIX=local

        OPENSEARCH_ACCESS_KEY=ACCESS_KEY
        OPENSEARCH_ACCESS_SECRET=ACCESS_SECRET
        OPENSEARCH_HOST=HOST
        OPENSEARCH_DEBUG=true
        
        
6. 在你的Model里面引用  Searchable   如
   ```
   namespace App\Models;
   
   use Illuminate\Database\Eloquent\Model;
   use Laravel\Scout\Searchable;
   
   class ShopSearchModel extends Model
   {
       use Searchable;
       /**
        * 数据表名
        */
       protected $table = "shop_search";
   
       /**
        * 主键
        */
       protected $primaryKey = "goods_id";
   }
   
   ```     

7. 执行全量索引创建 该操作会自动创建阿里云APP
  ``` 
    php artisan scout:flush "App\Models\ShopSearchModel"
 
  ``` 
8.执行搜索

```php
use App\Models\ShopSearchModel;
    
    Route::get('search', function () {
        // 为查看方便都转成数组
        dump(ShopSearchModel::search('搜索关键字')->get()->toArray());
    });
```

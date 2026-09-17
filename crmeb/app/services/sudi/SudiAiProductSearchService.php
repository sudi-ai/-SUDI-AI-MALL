<?php

declare(strict_types=1);
namespace app\services\sudi;
use app\services\product\product\StoreProductServices;

class SudiAiProductSearchService
{
    /** @var StoreProductServices */
    private $products;
    public function __construct(StoreProductServices $products){$this->products=$products;}
    public function search(string $keyword,int $page=1,int $limit=12):array
    {
        $keyword=trim($keyword);$page=max(1,$page);$limit=max(1,min(30,$limit));
        if($keyword==='')return ['list'=>[],'page'=>$page,'limit'=>$limit,'keyword'=>''];
        $where=['store_name'=>$keyword,'is_show'=>1,'is_del'=>0];
        $fields=['id','store_name','image','price','ot_price','sales','stock','cate_id'];
        $list=$this->products->getSearchList($where,$page,$limit,$fields);
        return ['list'=>is_array($list)?$list:[],'page'=>$page,'limit'=>$limit,'keyword'=>$keyword];
    }
}

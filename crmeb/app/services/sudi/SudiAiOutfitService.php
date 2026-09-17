<?php

declare(strict_types=1);
namespace app\services\sudi;
class SudiAiOutfitService
{
    /** @var SudiAiProductSearchService */ private $search;
    public function __construct(SudiAiProductSearchService $search){$this->search=$search;}
    public function recommend(string $anchor,array $categories=[]):array
    {
        $anchor=trim($anchor);$categories=$categories?:['上衣','裤子','外套','鞋'];$groups=[];
        foreach(array_slice($categories,0,4) as $category){$keyword=trim($anchor.' '.(string)$category);$result=$this->search->search($keyword,1,4);$groups[]=['category'=>(string)$category,'products'=>$result['list']];}
        return ['anchor'=>$anchor,'groups'=>$groups,'note'=>'搭配仅从商城真实在售商品中生成，最终价格和库存以CRMEB结算页为准'];
    }
}

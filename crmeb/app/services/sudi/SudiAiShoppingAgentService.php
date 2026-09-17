<?php

declare(strict_types=1);
namespace app\services\sudi;
class SudiAiShoppingAgentService
{
    /** @var SudiAiIntentService */ private $intent;
    /** @var SudiAiProductSearchService */ private $search;
    public function __construct(SudiAiIntentService $intent,SudiAiProductSearchService $search){$this->intent=$intent;$this->search=$search;}
    public function recommend(string $query,int $page=1,int $limit=12):array
    {
        $intent=$this->intent->parse($query);$result=$this->search->search($intent['keyword'],$page,$limit);$list=$result['list'];
        $min=$intent['price_min'];$max=$intent['price_max'];
        $list=array_values(array_filter($list,function($item)use($min,$max){$price=isset($item['price'])?(float)$item['price']:null;if($price===null)return false;if($min!==null&&$price<$min)return false;if($max!==null&&$price>$max)return false;return true;}));
        if($intent['sort']==='sales')usort($list,function($a,$b){return (int)($b['sales']??0)<=>(int)($a['sales']??0);});
        elseif($intent['sort']==='price_asc')usort($list,function($a,$b){return (float)($a['price']??PHP_FLOAT_MAX)<=>(float)($b['price']??PHP_FLOAT_MAX);});
        return ['intent'=>$intent,'products'=>$list,'count'=>count($list),'transaction_owner'=>'CRMEB'];
    }
}

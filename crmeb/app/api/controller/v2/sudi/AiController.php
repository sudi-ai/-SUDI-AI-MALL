<?php

declare(strict_types=1);
namespace app\api\controller\v2\sudi;
use app\Request;
use app\services\sudi\SudiAiCapabilityService;
use app\services\sudi\SudiAiCustomerService;
use app\services\sudi\SudiAiGuardService;
use app\services\sudi\SudiAiOutfitService;
use app\services\sudi\SudiAiProductSearchService;
use app\services\sudi\SudiAiShoppingAgentService;
use app\services\sudi\SudiAiSizeAdvisorService;
use app\services\sudi\SudiAiStoreManagerService;
class AiController{
 public function capabilities(){ $s=app()->make(SudiAiCapabilityService::class); return app('json')->success(['version'=>'1.0.0','capabilities'=>$s->all()]); }
 public function capability(string $name){$c=app()->make(SudiAiCapabilityService::class)->get($name);return $c?app('json')->success($c):app('json')->fail('AI能力不存在');}
 public function productSearch(Request $r){[$k,$p,$l]=$r->getMore([['keyword',''],['page',1],['limit',12]],true);$k=trim((string)$k);if($k==='')return app('json')->fail('请输入商品关键词');return app('json')->success(app()->make(SudiAiProductSearchService::class)->search($k,(int)$p,(int)$l));}
 public function shopping(Request $r){[$q,$p,$l]=$r->postMore([['query',''],['page',1],['limit',12]],true);$q=trim((string)$q);if($q==='')return app('json')->fail('请描述你想买什么');return app('json')->success(app()->make(SudiAiShoppingAgentService::class)->recommend($q,(int)$p,(int)$l));}
 public function sizeAdvice(Request $r){[$p,$c]=$r->postMore([['profile',[]],['size_chart',[]]],true);if(!is_array($p)||!is_array($c))return app('json')->fail('尺码参数格式错误');return app('json')->success(app()->make(SudiAiSizeAdvisorService::class)->advise($p,$c));}
 public function outfit(Request $r){[$a,$c]=$r->postMore([['anchor',''],['categories',[]]],true);$a=trim((string)$a);if($a==='')return app('json')->fail('请输入搭配需求');return app('json')->success(app()->make(SudiAiOutfitService::class)->recommend($a,is_array($c)?$c:[]));}
 public function customer(Request $r){[$m]=$r->postMore([['message','']],true);$m=trim((string)$m);if($m==='')return app('json')->fail('请输入咨询内容');return app('json')->success(app()->make(SudiAiCustomerService::class)->route($m));}
 public function manager(Request $r){[$m]=$r->postMore([['metrics',[]]],true);if(!is_array($m))return app('json')->fail('经营指标格式错误');return app('json')->success(app()->make(SudiAiStoreManagerService::class)->diagnose($m));}
 public function guard(Request $r){[$a]=$r->postMore([['action','']],true);$a=strtolower(trim((string)$a));if($a==='')return app('json')->fail('缺少action参数');$g=app()->make(SudiAiGuardService::class);return app('json')->success(['action'=>$a,'allowed'=>$g->canExecute($a)]);}
}

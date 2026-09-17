<?php

declare(strict_types=1);
use think\facade\Route;

Route::group('sudi/ai', function () {
    Route::get('capabilities','v2.sudi.AiController/capabilities')->option(['real_name'=>'苏迪AI能力列表','mark'=>'sudi_ai','mark_name'=>'苏迪AI']);
    Route::get('capability/:name','v2.sudi.AiController/capability')->option(['real_name'=>'苏迪AI能力详情','mark'=>'sudi_ai','mark_name'=>'苏迪AI']);
    Route::get('products/search','v2.sudi.AiController/productSearch')->option(['real_name'=>'苏迪AI商品搜索','mark'=>'sudi_ai','mark_name'=>'苏迪AI']);
    Route::post('shopping','v2.sudi.AiController/shopping')->option(['real_name'=>'苏迪AI购物代理','mark'=>'sudi_ai','mark_name'=>'苏迪AI']);
    Route::post('size-advice','v2.sudi.AiController/sizeAdvice')->option(['real_name'=>'苏迪AI尺码助手','mark'=>'sudi_ai','mark_name'=>'苏迪AI']);
    Route::post('outfit','v2.sudi.AiController/outfit')->option(['real_name'=>'苏迪AI搭配购买','mark'=>'sudi_ai','mark_name'=>'苏迪AI']);
    Route::post('guard','v2.sudi.AiController/guard')->option(['real_name'=>'苏迪AI交易安全检查','mark'=>'sudi_ai','mark_name'=>'苏迪AI']);
});

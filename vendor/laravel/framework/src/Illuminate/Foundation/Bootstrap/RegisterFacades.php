<?php

namespace Illuminate\Foundation\Bootstrap;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Contracts\Foundation\Application;

class RegisterFacades
{
    /**
     * Bootstrap the given application.
     * 注册外观别名
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function bootstrap(Application $app)
    {
        Facade::clearResolvedInstances();

        Facade::setFacadeApplication($app);

        // register即向自动加载栈中注册一个外观自动加载函数并置于栈的最前面
        // composer提供的自动加载方法和外观自动加载方法都注册在这个栈里而且外观自动加载在最前面,
        // 因此当一个类实现自动加载时会按照自动加载栈的顺序加载, 即所有类的自动加载会先经过外观自动加载函数处理
        // 外观自动加载无法找到之后才会走到composer自动加载
        AliasLoader::getInstance(array_merge(
            // 获取config\app.php文件下的aliases数组值
            $app->make('config')->get('app.aliases', []),
            $app->make(PackageManifest::class)->aliases()
        ))->register();
    }
}

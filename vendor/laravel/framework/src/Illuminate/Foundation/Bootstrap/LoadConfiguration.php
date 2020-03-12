<?php

namespace Illuminate\Foundation\Bootstrap;

use Exception;
use SplFileInfo;
use Illuminate\Config\Repository;
use Symfony\Component\Finder\Finder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Config\Repository as RepositoryContract;

class LoadConfiguration
{
    /**
     * Bootstrap the given application.
     * 加载配置文件
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function bootstrap(Application $app)
    {
        $items = [];

        // First we will see if we have a cache configuration file. If we do, we'll load
        // the configuration items from that file so that it is very quick. Otherwise
        // we will need to spin through every configuration file and load them all.
        // 
        // step1: 判断是否有缓存配置文件, 如果有直接加载
        //

        if (file_exists($cached = $app->getCachedConfigPath())) {
            $items = require $cached;

            $loadedFromCache = true;
        }

        // Next we will spin through all of the configuration files in the configuration
        // directory and load each one into the repository. This will make all of the
        // options available to the developer for use in various parts of this app.
        //
        // step2: 遍历配置目录下的所有配置文件并加载
        //
        // 实例化一个配置对象repository(Illuminate\Config\Repository.php)
        $app->instance('config', $config = new Repository($items));

        if (! isset($loadedFromCache)) {
            // 如果没有配置缓存, 则加载目录下的所有配置文件, 并设置到repository对象上
            $this->loadConfigurationFiles($app, $config);
        }

        // Finally, we will set the application's environment based on the configuration
        // values that were loaded. We will pass a callback which will be used to get
        // the environment in a web context where an "--env" switch is not present.
        //
        // step3: 根据配置设置环境
        //
        $app->detectEnvironment(function () use ($config) {
            // 设置env遍历, 如果不存在则为production
            return $config->get('app.env', 'production');
        });

        // 设置timezone
        date_default_timezone_set($config->get('app.timezone', 'UTC'));

        mb_internal_encoding('UTF-8');
    }

    /**
     * Load the configuration items from all of the files.
     * 从配置目录下的所有文件加载配置项
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  \Illuminate\Contracts\Config\Repository  $repository
     * @return void
     *
     * @throws \Exception
     */
    protected function loadConfigurationFiles(Application $app, RepositoryContract $repository)
    {
        // 获取目录下的所有配置文件
        $files = $this->getConfigurationFiles($app);

        // 校验是否有最基本的config/app.php文件
        if (! isset($files['app'])) {
            throw new Exception('Unable to load the "app" configuration file.');
        }

        // 遍历并将值设置到$repository对象中
        foreach ($files as $key => $path) {
            // 对于配置文件, 每个文件返回一个数组
            // 在这里通过'require 文件路径'的方式获取配置项数组
            // 此处的$key=>$path 形如 'app' => 'config\app.php' 
            $repository->set($key, require $path);
        }
    }

    /**
     * Get all of the configuration files for the application.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return array
     */
    protected function getConfigurationFiles(Application $app)
    {
        $files = [];

        $configPath = realpath($app->configPath());

        foreach (Finder::create()->files()->name('*.php')->in($configPath) as $file) {
            $directory = $this->getNestedDirectory($file, $configPath);

            $files[$directory.basename($file->getRealPath(), '.php')] = $file->getRealPath();
        }

        ksort($files, SORT_NATURAL);

        return $files;
    }

    /**
     * Get the configuration file nesting path.
     *
     * @param  \SplFileInfo  $file
     * @param  string  $configPath
     * @return string
     */
    protected function getNestedDirectory(SplFileInfo $file, $configPath)
    {
        $directory = $file->getPath();

        if ($nested = trim(str_replace($configPath, '', $directory), DIRECTORY_SEPARATOR)) {
            $nested = str_replace(DIRECTORY_SEPARATOR, '.', $nested).'.';
        }

        return $nested;
    }
}
